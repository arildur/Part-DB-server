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
use App\Entity\Parts\PartKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidPartKindValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPartKind) {
            throw new UnexpectedTypeException($constraint, ValidPartKind::class);
        }

        if (!$value instanceof Part) {
            throw new UnexpectedTypeException($value, Part::class);
        }

        //PartKindConverter marks the part whose kind it changes; nothing else can set the mark
        if ($value->isKindConversionAllowed()) {
            return;
        }

        //A part that is not saved yet has no original data, and can have any kind
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($value);
        if (!isset($original['kind'])) {
            return;
        }

        //Right after a flush the original data can hold the value of the enum, instead of the enum
        $stored = $original['kind'] instanceof PartKind ? $original['kind'] : PartKind::tryFrom((string) $original['kind']);
        if ($stored !== $value->getKind()) {
            $this->context->buildViolation($constraint->message)
                ->atPath('kind')
                ->addViolation();
        }
    }
}
