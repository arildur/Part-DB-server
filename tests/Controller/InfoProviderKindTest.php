<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);namespace App\Tests\Controller;

use App\Entity\InfoProviderSystem\BulkInfoProviderImportJob;
use App\Entity\InfoProviderSystem\BulkInfoProviderImportJobPart;
use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartKind;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A supplier's data describes one product, so a part whose kind does not allow that can not receive it.
 */
final class InfoProviderKindTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private Part $meta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $this->client->loginUser($this->admin);
        $this->meta = (new Part())->setKind(PartKind::META);
        $this->meta->setName('The meta part');
        $this->meta->setCategory($this->em->find(Category::class, 1));
        $this->em->persist($this->meta);
        $this->em->flush();
    }

    private function jobWith(Part $part): BulkInfoProviderImportJob
    {
        $job = new BulkInfoProviderImportJob();
        $job->setCreatedBy($this->admin);
        $job->addJobPart(new BulkInfoProviderImportJobPart($job, $part));
        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    public function testUpdateRoutesAreRefusedWithAMessage(): void
    {
        foreach (['/en/part/'.$this->meta->getID().'/from_info_provider/test/1/update', '/en/tools/info_providers/update/'.$this->meta->getID()] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseRedirects('/en/part/'.$this->meta->getID().'/info', message: $url);
        }

        $this->assertSame('', $this->em->find(Part::class, $this->meta->getID())->getManufacturerProductNumber());
    }

    public function testQuickApplyIsRefusedBeforeAnyProviderIsAsked(): void
    {
        $job = $this->jobWith($this->meta);

        $this->client->jsonRequest('POST', '/en/tools/bulk_info_provider_import/job/'.$job->getId().'/part/'.$this->meta->getID().'/quick-apply', [
            'providerKey' => 'test',
            'providerId' => '1',
        ]);

        $this->assertResponseStatusCodeSame(400);
    }

    public function testQuickApplyAllReportsThePartAndLeavesIt(): void
    {
        $job = $this->jobWith($this->meta);

        $this->client->jsonRequest('POST', '/en/tools/bulk_info_provider_import/job/'.$job->getId().'/quick-apply-all');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['failed']);
        $this->assertSame(0, $data['applied']);
        $this->assertStringContainsString('The meta part', implode(' ', $data['errors']));
    }

    public function testABulkJobLeavesOutAMetaPart(): void
    {
        $part = $this->em->find(Part::class, 1);

        $this->client->request('GET', '/en/tools/bulk_info_provider_import/refresh?ids='.$this->meta->getID().','.$part->getID());

        $job = $this->em->getRepository(BulkInfoProviderImportJob::class)->findOneBy([], ['id' => 'DESC']);
        $this->assertNotNull($job);
        $ids = array_map(static fn (BulkInfoProviderImportJobPart $jobPart): ?int => $jobPart->getPart()->getID(), $job->getJobParts()->toArray());
        $this->assertSame([$part->getID()], array_values($ids));
    }
}
