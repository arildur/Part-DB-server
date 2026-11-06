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

declare(strict_types=1);namespace App\Entity\Parts\PartTraits;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Parts\PartConnection;
use App\Validator\Constraints\UniqueObjectCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints\Valid;

trait ConnectionTrait
{
    /**
     * @var Collection<int, PartConnection> The connections of this part as a meta part: the parts it stands above.
     */
    #[Valid]
    #[UniqueObjectCollection(fields: ['connected_part'], message: 'validator.part_connection.already_exists')]
    #[ORM\OneToMany(targetEntity: PartConnection::class, mappedBy: 'meta_part', cascade: ['persist', 'remove'], orphanRemoval: true)]
    protected Collection $part_connections;

    /**
     * @var Collection<int, PartConnection> The connections of this part as a connected part: the meta parts above it.
     */
    #[ORM\OneToMany(targetEntity: PartConnection::class, mappedBy: 'connected_part', cascade: ['remove'])]
    protected Collection $connections_as_part;

    /**
     * @return Collection<int, PartConnection>
     */
    public function getPartConnections(): Collection
    {
        return $this->part_connections;
    }

    public function addPartConnection(PartConnection $connection): self
    {
        $connection->setMetaPart($this);
        $this->part_connections->add($connection);

        return $this;
    }

    public function removePartConnection(PartConnection $connection): self
    {
        $this->part_connections->removeElement($connection);

        return $this;
    }

    /**
     * @return Collection<int, PartConnection>
     */
    public function getConnectionsAsPart(): Collection
    {
        return $this->connections_as_part;
    }

    /**
     * Returns the connections of this meta part in their order: usable before do not use, then by position, then by
     * ID, so that the order is the same on every request.
     * @return list<PartConnection>
     */
    public function getOrderedConnections(): array
    {
        $connections = $this->part_connections->toArray();
        usort($connections, static fn (PartConnection $a, PartConnection $b): int =>
            [$a->isUsable() ? 0 : 1, $a->getPosition() ?? PHP_INT_MAX, $a->getID() ?? PHP_INT_MAX]
            <=> [$b->isUsable() ? 0 : 1, $b->getPosition() ?? PHP_INT_MAX, $b->getID() ?? PHP_INT_MAX]);

        return $connections;
    }

    /**
     * What clients and export files read about the connections of this part: a fixed list of fields, so that a connected
     * part is never written out in full and the output ends there. Connections are written through their own resource,
     * and are not read from an import file. A part that can not hold connections has none, and the check saves it a query.
     * @return list<array<string, mixed>>
     */
    #[Groups(['full', 'part:read'])]
    #[SerializedName('part_connections')]
    #[ApiProperty(
        description: 'The manufacturing parts connected to this meta part, in the order they are used. Empty for a manufacturing part.',
        schema: [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'position' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['approved', 'do_not_use']],
                    'note' => ['type' => 'string'],
                    'connected_part' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'kind' => ['type' => 'string'],
                            'total_instock' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ],
    )]
    public function getConnectionsOverview(): array
    {
        if (!$this->getKind()->allowsConnections()) {
            return [];
        }

        $overview = [];
        foreach ($this->getOrderedConnections() as $connection) {
            $part = $connection->getConnectedPart();
            $overview[] = [
                'id' => $connection->getID(),
                'position' => $connection->getPosition(),
                'status' => $connection->getStatus()->value,
                'note' => $connection->getNote(),
                'connected_part' => $part === null ? null : [
                    'id' => $part->getID(),
                    'name' => $part->getName(),
                    'kind' => $part->getKind()->value,
                    'total_instock' => $part->getAmountSum(),
                ],
            ];
        }

        return $overview;
    }
}
