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

namespace App\ApiPlatform\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Parts\PartKind;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Filters parts by their kind. An unknown value is refused, as ignoring it would return the parts of all kinds to a
 * client that asked for only one.
 */
class PartKindFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if (!$this->isPropertyEnabled($property, $resourceClass)) {
            return;
        }

        $kind = is_string($value) ? PartKind::tryFrom($value) : null;
        if (!$kind instanceof PartKind) {
            throw new BadRequestHttpException(sprintf('Not a kind of part. Valid values are: %s', implode(', ', array_column(PartKind::cases(), 'value'))));
        }

        $parameterName = $queryNameGenerator->generateParameterName($property);
        $queryBuilder
            ->andWhere(sprintf('%s.%s = :%s', $queryBuilder->getRootAliases()[0], $property, $parameterName))
            ->setParameter($parameterName, $kind->value);
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];
        foreach (array_keys($this->properties ?? []) as $property) {
            $description[(string) $property] = [
                'property' => $property,
                'type' => 'string',
                'required' => false,
                'description' => 'Only parts of this kind',
                'schema' => ['type' => 'string', 'enum' => array_column(PartKind::cases(), 'value')],
            ];
        }

        return $description;
    }
}
