<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);namespace App\Tests\Entity\Parts;

use App\Entity\Parts\Category;
use App\Entity\Parts\InfoProviderReference;
use App\Entity\Parts\Manufacturer;
use App\Entity\Parts\ManufacturingStatus;
use App\Entity\Parts\MeasurementUnit;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PartKindValidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    private function hasKindViolation(Part $part): bool
    {
        foreach ($this->validator->validate($part) as $violation) {
            if ($violation->getPropertyPath() === 'kind') {
                return true;
            }
        }

        return false;
    }

    public function testUnsavedPartCanHaveAnyKind(): void
    {
        foreach (PartKind::cases() as $kind) {
            $this->assertFalse($this->hasKindViolation((new Part())->setKind($kind)));
        }
    }

    public function testSavedPartAcceptsTheSameKind(): void
    {
        $part = $this->em->find(Part::class, 1);
        $part->setKind(PartKind::MANUFACTURING);

        $this->assertFalse($this->hasKindViolation($part));
    }

    public function testSavedPartRefusesAnotherKind(): void
    {
        $part = $this->em->find(Part::class, 1);
        $part->setKind(PartKind::META);

        $this->assertTrue($this->hasKindViolation($part));
    }

    /**
     * Right after a flush, Doctrine's original data for the part can hold the value of the enum, not the enum.
     */
    public function testPartJustSavedInThisRequestAcceptsTheSameKindAndRefusesAnother(): void
    {
        $this->em->beginTransaction();
        try {
            $part = new Part();
            $part->setName('Kind test');
            $part->setCategory($this->em->find(Category::class, 1));
            $this->em->persist($part);
            $this->em->flush();

            $this->assertFalse($this->hasKindViolation($part));

            $part->setKind(PartKind::META);
            $this->assertTrue($this->hasKindViolation($part));
        } finally {
            $this->em->rollback();
        }
    }

    public function testSavedPartMarkedByTheConverterAcceptsAnotherKind(): void
    {
        $part = $this->em->find(Part::class, 1);
        $part->allowKindConversion();
        $part->setKind(PartKind::META);
        $this->assertFalse($this->hasKindViolation($part));

        $part->endKindConversion();
        $this->assertTrue($this->hasKindViolation($part));
    }

    public function testTheMarkCanNotBeSetThroughTheSerializer(): void
    {
        $part = $this->em->find(Part::class, 1);
        self::getContainer()->get(DenormalizerInterface::class)->denormalize(
            ['kindConversionAllowed' => true, 'kind_conversion_allowed' => true, 'name' => $part->getName()],
            Part::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $part, 'groups' => ['part:write', 'import', 'full']]);

        $this->assertFalse($part->isKindConversionAllowed());
    }

    public function testCloneOfASavedPartCanHaveAnotherKind(): void
    {
        $clone = clone $this->em->find(Part::class, 1);
        $clone->setKind(PartKind::META);

        $this->assertFalse($this->hasKindViolation($clone));
    }

    /** @return list<string> */
    private function violatedPaths(object $object): array
    {
        $paths = [];
        foreach ($this->validator->validate($object) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return $paths;
    }

    /** @return array<string, array{string, callable(Part): mixed}> */
    public static function manufacturerData(): array
    {
        return [
            'manufacturer' => ['manufacturer', static fn (Part $p) => $p->setManufacturer(new Manufacturer())],
            'part number' => ['manufacturer_product_number', static fn (Part $p) => $p->setManufacturerProductNumber('X1')],
            'product URL' => ['manufacturer_product_url', static fn (Part $p) => $p->setManufacturerProductURL('https://example.invalid/x')],
            'status' => ['manufacturing_status', static fn (Part $p) => $p->setManufacturingStatus(ManufacturingStatus::ACTIVE)],
            'GTIN' => ['gtin', static fn (Part $p) => $p->setGtin('4006381333931')],
            'provider reference' => ['providerReference', static fn (Part $p) => $p->setProviderReference(InfoProviderReference::providerReference('test', 'id'))],
        ];
    }

    #[DataProvider('manufacturerData')]
    public function testAMetaPartRefusesManufacturerData(string $path, callable $set): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $set($meta);
        $this->assertContains($path, $this->violatedPaths($meta));

        $part = new Part();
        $set($part);
        $this->assertNotContains($path, $this->violatedPaths($part));
    }

    public function testAMetaPartKeepsTheOtherFields(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $meta->setName('Meta');
        $meta->setCategory($this->em->find(Category::class, 1));
        $meta->setComment('A comment');
        $meta->setMinAmount(10);
        $meta->setMass(1.5);
        $meta->setFavorite(true);
        $meta->setNeedsReview(true);

        $this->assertSame([], $this->violatedPaths($meta));
    }

    public function testAMetaPartAcceptsABuildProject(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $meta->setBuiltProject(new Project());

        $this->assertNotContains('built_project', $this->violatedPaths($meta));
    }

    public function testAnOrderDetailRefusesAMetaPart(): void
    {
        $orderdetail = new Orderdetail();
        $meta = (new Part())->setKind(PartKind::META);
        $meta->addOrderdetail($orderdetail);

        $this->assertContains('part', $this->violatedPaths($orderdetail));
        $this->assertContains('orderdetails[0].part', $this->violatedPaths($meta));

        $accepted = new Orderdetail();
        (new Part())->addOrderdetail($accepted);
        $this->assertNotContains('part', $this->violatedPaths($accepted));
    }

    public function testAConnectionRefusesDifferentUnits(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $part = new Part();
        $connection = (new PartConnection())->setMetaPart($meta)->setConnectedPart($part);
        $this->assertNotContains('connected_part', $this->violatedPaths($connection));

        $part->setPartUnit(new MeasurementUnit());
        $this->assertContains('connected_part', $this->violatedPaths($connection));
    }

    public function testChangingTheUnitOfAConnectedPartIsRefusedOnce(): void
    {
        $meta = (new Part())->setKind(PartKind::META);
        $part = new Part();
        $part->setName('Part');
        $part->setCategory($this->em->find(Category::class, 1));
        $meta->setName('Meta');
        $meta->setCategory($this->em->find(Category::class, 1));
        $connection = (new PartConnection())->setConnectedPart($part);
        $meta->addPartConnection($connection);
        $part->getConnectionsAsPart()->add($connection);
        $this->assertNotContains('partUnit', $this->violatedPaths($part));

        $part->setPartUnit(new MeasurementUnit());

        $this->assertSame(['partUnit'], array_values(array_filter($this->violatedPaths($part), static fn (string $p): bool => $p === 'partUnit')));
    }

    public function testBothKindsCanHaveAnInternalPartNumberButNotTheSameOne(): void
    {
        $saved = $this->em->find(Part::class, 1);
        $saved->setIpn('SAME-1');
        $this->em->flush();

        $meta = (new Part())->setKind(PartKind::META);
        $meta->setName('Meta');
        $meta->setCategory($this->em->find(Category::class, 1));
        $meta->setIpn('OTHER-1');
        $this->assertNotContains('ipn', $this->violatedPaths($meta));

        $meta->setIpn('SAME-1');
        $this->assertContains('ipn', $this->violatedPaths($meta));
    }

    public function testMetaPartIsAcceptedInABom(): void
    {
        $entry = new ProjectBOMEntry();
        $entry->setPart((new Part())->setKind(PartKind::META));
        $entry->setQuantity(1);

        $paths = [];
        foreach ($this->validator->validate($entry) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        $this->assertNotContains('part', $paths);
    }
}
