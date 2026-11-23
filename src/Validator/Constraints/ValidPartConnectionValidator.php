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

declare(strict_types=1);namespace App\Validator\Constraints;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\ProjectSystem\Project;
use App\Services\ProjectSystem\BuildPartReach;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidPartConnectionValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPartConnection) {
            throw new UnexpectedTypeException($constraint, ValidPartConnection::class);
        }

        if (!$value instanceof PartConnection) {
            throw new UnexpectedTypeException($value, PartConnection::class);
        }

        if ($value->getMetaPart() instanceof Part && !$value->getMetaPart()->getKind()->allowsConnections()) {
            $this->context->buildViolation('validator.part_connection.first_end_must_allow_connections')
                ->atPath('meta_part')
                ->addViolation();
        }

        if ($value->getConnectedPart() instanceof Part && !$value->getConnectedPart()->getKind()->canBeConnected()) {
            $this->context->buildViolation('validator.part_connection.second_end_must_be_connectable')
                ->atPath('connected_part')
                ->addViolation();
        }

        //A total that adds grams to pieces would mean nothing
        if ($value->getMetaPart() instanceof Part && $value->getConnectedPart() instanceof Part
            && $value->getMetaPart()->getPartUnit() !== $value->getConnectedPart()->getPartUnit()) {
            $this->context->buildViolation('validator.part_connection.units_must_match')
                ->atPath('connected_part')
                ->addViolation();
        }

        //The connection must not let a project reach its own build part: check the BOM entries whose reach it widens
        if ($value->getMetaPart() instanceof Part && $value->getConnectedPart() instanceof Part && $this->wouldReachOwnBuildPart($value, $value->getConnectedPart())) {
            $this->context->buildViolation('validator.part_connection.would_let_project_reach_own_build_part')
                ->atPath('connected_part')
                ->addViolation();
        }

        //A change of part is a removal and a new connection, which keeps the log readable
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($value);
        foreach (['meta_part' => $value->getMetaPart(), 'connected_part' => $value->getConnectedPart()] as $field => $part) {
            if (isset($original[$field]) && $original[$field] !== $part) {
                $this->context->buildViolation('validator.part_connection.ends_are_fixed')
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }

    /**
     * Whether a BOM entry on the meta part, on the connected part or on another meta part of the connected part reaches
     * the build part of its own project or of an ancestor, counting the given connection. These are the entries whose
     * reach the connection can widen: one on the meta part now reaches the connected part and the meta parts above
     * it, and one on the connected part or on such a meta part now reaches the meta part.
     */
    private function wouldReachOwnBuildPart(PartConnection $connection, Part $connected): bool
    {
        foreach (BuildPartReach::buildPartCandidates($connected, $connection) as $part) {
            foreach ($part->getProjectBomEntries() as $bom_entry) {
                if ($bom_entry->getProject() instanceof Project
                    && BuildPartReach::reachesOwnBuildPart($bom_entry->getProject(), $part, $connection)) {
                    return true;
                }
            }
        }

        return false;
    }
}
