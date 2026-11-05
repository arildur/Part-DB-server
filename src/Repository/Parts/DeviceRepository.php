<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
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
namespace App\Repository\Parts;


use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Repository\StructuralDBElementRepository;
use InvalidArgumentException;

class DeviceRepository extends StructuralDBElementRepository
{
    /**
     * Loads what working out the stock of a whole BOM reads, in a number of queries independent of its entries: the parts
     * with their lots, and for meta parts the connected parts with their lots.
     */
    public function preloadBomStock(Project $project): void
    {
        $em = $this->getEntityManager();

        $parts = $em->createQueryBuilder()
            ->select('part', 'lots', 'location')
            ->from(Part::class, 'part')
            ->innerJoin('part.project_bom_entries', 'entry')
            ->leftJoin('part.partLots', 'lots')
            ->leftJoin('lots.storage_location', 'location')
            ->where('entry.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        $em->createQueryBuilder()
            ->select('part', 'entry')
            ->from(Project::class, 'part')
            ->leftJoin('part.bom_entries', 'entry')
            ->where('part = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        $metaParts = array_values(array_filter($parts, static fn (Part $part): bool => $part->getKind()->allowsConnections()));
        if ($metaParts !== []) {
            $em->createQueryBuilder()
                ->select('PARTIAL meta.{id}', 'connections', 'connected', 'lots', 'location')
                ->from(Part::class, 'meta')
                ->leftJoin('meta.part_connections', 'connections')
                ->leftJoin('connections.connected_part', 'connected')
                ->leftJoin('connected.partLots', 'lots')
                ->leftJoin('lots.storage_location', 'location')
                ->where('meta IN (:parts)')
                ->setParameter('parts', $metaParts)
                ->getQuery()
                ->getResult();

            //Apart from the lots, so that the rows do not multiply. What a meta part costs comes from the prices of these parts.
            $em->createQueryBuilder()
                ->select('PARTIAL connected.{id}', 'orderdetails', 'pricedetails')
                ->from(Part::class, 'connected')
                ->innerJoin('connected.connections_as_part', 'connection')
                ->leftJoin('connected.orderdetails', 'orderdetails')
                ->leftJoin('orderdetails.pricedetails', 'pricedetails')
                ->where('connection.meta_part IN (:parts)')
                ->setParameter('parts', $metaParts)
                ->getQuery()
                ->getResult();
        }
    }


    public function getParts(object $element, array $order_by = ['name' => 'ASC']): array
    {
        if (!$element instanceof Project) {
            throw new InvalidArgumentException('$element must be an Device!');
        }


        //TODO: Change this later, when properly implemented devices
        return [];
    }

    public function getPartsCount(object $element): int
    {
        if (!$element instanceof Project) {
            throw new InvalidArgumentException('$element must be an Device!');
        }

        //TODO: Change this later, when properly implemented devices
        //Prevent user from deleting devices, to not accidentally remove filled devices from old versions
        return 1;
    }
}
