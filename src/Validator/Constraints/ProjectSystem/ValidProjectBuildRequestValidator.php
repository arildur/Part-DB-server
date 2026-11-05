<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
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
namespace App\Validator\Constraints\ProjectSystem;

use App\Entity\Parts\PartLot;
use App\Entity\ProjectSystem\ProjectBOMEntry;
use App\Form\ProjectSystem\ProjectBuildType;
use App\Helpers\Projects\ProjectBuildRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class ValidProjectBuildRequestValidator extends ConstraintValidator
{
    public function __construct(private readonly Security $security)
    {
    }

    private function buildViolationForLot(ProjectBOMEntry $entry, PartLot $partLot, string $message): ConstraintViolationBuilderInterface
    {
        return $this->context->buildViolation($message)
            ->atPath(ProjectBuildType::fieldName($entry->getID(), $partLot->getID()))
            ->setParameter('{{ lot }}', $partLot->getName());
    }

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidProjectBuildRequest) {
            throw new UnexpectedTypeException($constraint, ValidProjectBuildRequest::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof ProjectBuildRequest) {
            throw new UnexpectedTypeException($value, ProjectBuildRequest::class);
        }

        foreach ($value->getPartBomEntries() as $bom_entry) {
            $this->validateEntry($value, $bom_entry);
        }

        foreach ($value->getHeldPairsOutOfReach() as [$bom_entry, $lot]) {
            $this->context->buildViolation('validator.project_build.lot_out_of_reach')
                ->setParameter('%entry%', $bom_entry->getPart()?->getName() ?? '')
                ->setParameter('%lot%', $lot->getName())
                ->addViolation();
        }

        $this->validateLots($value);
    }

    private function validateEntry(ProjectBuildRequest $request, ProjectBOMEntry $bom_entry): void
    {
        $lots = $request->getPartLotsForBOMEntry($bom_entry);
        $check_quantity = !$request->isDontCheckQuantity();

        //A meta part with nothing to offer has no field to show the problem at. A direct entry without lots builds, as it always did.
        if ($lots === [] && $check_quantity && $bom_entry->getPart()?->getKind()->allowsConnections()) {
            $this->context->buildViolation('validator.project_build.no_lots_offered')
                ->setParameter('%entry%', $bom_entry->getPart()->getName())
                ->addViolation();

            return;
        }

        $withdraw_sum = $request->getWithdrawAmountSum($bom_entry);
        $needed_amount = $request->getNeededAmountForBOMEntry($bom_entry);

        foreach ($lots as $lot) {
            if ($request->getWithdrawAmount($bom_entry, $lot) < 0) {
                $this->buildViolationForLot($bom_entry, $lot, 'validator.project_build.lot_must_not_smaller_0')
                    ->addViolation();
            }

            if ($withdraw_sum > $needed_amount && $check_quantity) {
                $this->buildViolationForLot($bom_entry, $lot, 'validator.project_build.lot_bigger_than_needed')
                    ->addViolation();
            }

            if ($withdraw_sum < $needed_amount && $check_quantity) {
                $this->buildViolationForLot($bom_entry, $lot, 'validator.project_build.lot_smaller_than_needed')
                    ->addViolation();
            }
        }
    }

    /**
     * What is checked per lot looks at all entries together, and holds whether the quantity is checked or not.
     */
    private function validateLots(ProjectBuildRequest $request): void
    {
        foreach ($request->getLotsOfBuild() as $lot) {
            $total = $request->getLotWithdrawAmount($lot);
            if ($total <= 0) {
                continue;
            }

            $entry = $this->firstEntryTakingFromLot($request, $lot);

            if ($total > round($lot->getAmount(), ProjectBuildRequest::AMOUNT_PRECISION)) {
                $this->buildViolationForLot($entry, $lot, 'validator.project_build.lot_must_not_bigger_than_stock')
                    ->addViolation();
            }

            if (!$this->security->isGranted('withdraw', $lot)) {
                $this->buildViolationForLot($entry, $lot, 'validator.project_build.lot_not_withdrawable')
                    ->addViolation();
            }
        }
    }

    private function firstEntryTakingFromLot(ProjectBuildRequest $request, PartLot $lot): ProjectBOMEntry
    {
        foreach ($request->getPartBomEntries() as $bom_entry) {
            if (in_array($lot, $request->getPartLotsForBOMEntry($bom_entry), true) && $request->getWithdrawAmount($bom_entry, $lot) > 0) {
                return $bom_entry;
            }
        }

        throw new \LogicException('A lot with a positive total must have an entry taking from it!');
    }
}
