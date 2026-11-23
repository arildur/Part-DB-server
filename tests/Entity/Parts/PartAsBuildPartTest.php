<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
namespace App\Tests\Entity\Parts;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\ProjectSystem\Project;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A meta part as the build part of a project: the loop through its connected parts is refused from the connection and
 * from the part (TPART-54).
 */
final class PartAsBuildPartTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /**
     * @return list<string> The violations as path: message
     */
    private function violations(object $object): array
    {
        $found = [];
        foreach ($this->validator->validate($object) as $violation) {
            $found[] = $violation->getPropertyPath().': '.$violation->getMessageTemplate();
        }

        return $found;
    }

    private function meta(): Part
    {
        return (new Part())->setKind(PartKind::META);
    }

    /**
     * Puts the part in the BOM of the project, on both sides, as Doctrine does when it loads them.
     */
    private function useInBom(Project $project, Part $part): void
    {
        $entry = new ProjectBOMEntry();
        $entry->setPart($part);
        $entry->setQuantity(1);
        $project->addBomEntry($entry);
        $part->getProjectBomEntries()->add($entry);
    }

    public function testAConnectionToAPartInTheBomOfTheMetaBuildPartsProjectIsRefused(): void
    {
        $metaBuild = $this->meta();
        $x = new Project();
        $x->setBuildPart($metaBuild);
        $sub = new Project();
        $sub->setParent($x);

        $inX = new Part();
        $this->useInBom($x, $inX);
        $inSub = new Part();
        $this->useInBom($sub, $inSub);
        $unrelated = new Part();
        $this->useInBom(new Project(), $unrelated);

        $refused = 'connected_part: validator.part_connection.would_let_project_reach_own_build_part';
        foreach (['in the BOM of X' => [$inX, true], 'in a sub-project' => [$inSub, true], 'elsewhere' => [$unrelated, false]] as $case => [$part, $expected]) {
            $connection = (new PartConnection())->setMetaPart($metaBuild)->setConnectedPart($part);
            $this->assertSame($expected, in_array($refused, $this->violations($connection), true), $case);
        }
    }

    public function testAConnectionToAPartWhoseOtherMetaPartIsInTheBomIsRefused(): void
    {
        $metaBuild = $this->meta();
        $x = new Project();
        $x->setBuildPart($metaBuild);
        $part = new Part();
        $otherMeta = $this->meta();
        $existing = (new PartConnection())->setConnectedPart($part);
        $otherMeta->addPartConnection($existing);
        $part->getConnectionsAsPart()->add($existing);
        $this->useInBom($x, $otherMeta);

        $connection = (new PartConnection())->setMetaPart($metaBuild)->setConnectedPart($part);
        $this->assertContains('connected_part: validator.part_connection.would_let_project_reach_own_build_part', $this->violations($connection));
    }

    public function testAMetaPartBecomingTheBuildPartOfAProjectUsingItsConnectedPartIsRefused(): void
    {
        $connected = new Part();
        $meta = $this->meta();
        $meta->addPartConnection((new PartConnection())->setConnectedPart($connected));
        $x = new Project();
        $sub = new Project();
        $sub->setParent($x);
        $this->useInBom($sub, $connected);

        $refused = 'built_project: validator.build_part.reached_by_own_bom';
        $this->assertNotContains($refused, $this->violations($meta));

        $x->setBuildPart($meta);
        $this->assertContains($refused, $this->violations($meta));
        $this->assertNotContains('built_project: validator.part.kind_can_not_be_build_part', $this->violations($meta));
    }

    public function testAMetaPartWithoutALoopMayBeABuildPart(): void
    {
        $meta = $this->meta();
        $meta->addPartConnection((new PartConnection())->setConnectedPart(new Part()));
        $x = new Project();
        $x->setBuildPart($meta);
        $this->useInBom($x, new Part());

        $this->assertSame([], array_values(array_filter($this->violations($meta), static fn (string $v): bool => str_starts_with($v, 'built_project'))));
    }
}
