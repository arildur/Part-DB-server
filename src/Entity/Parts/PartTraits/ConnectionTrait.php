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
use App\Entity\Parts\Part;
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
     * Connects this part to the meta part of the connection, from this side. The connection is added to the meta part
     * too, whose side persists it, and goes last in its order of priority unless it already has a position.
     */
    public function addConnectionsAsPart(PartConnection $connection): self
    {
        $connection->setConnectedPart($this);
        $this->connections_as_part->add($connection);

        $metaPart = $connection->getMetaPart();
        if ($metaPart instanceof Part && !$metaPart->getPartConnections()->contains($connection)) {
            $metaPart->addPartConnection($connection);
            $connection->setNextFreePositionIfEmpty();
        }

        return $this;
    }

    /**
     * Removes the connection from this side and from its meta part, whose side deletes it. Both parts stay.
     */
    public function removeConnectionsAsPart(PartConnection $connection): self
    {
        $this->connections_as_part->removeElement($connection);
        $connection->getMetaPart()?->removePartConnection($connection);

        return $this;
    }

    /**
     * Returns the connections of this meta part in their order (see PartConnection::compareByPriority()).
     * @return list<PartConnection>
     */
    public function getOrderedConnections(): array
    {
        $connections = $this->part_connections->toArray();
        usort($connections, PartConnection::compareByPriority(...));

        return $connections;
    }

    /**
     * What REST clients read about the connections of this part: a fixed list of fields, so that a connected part is
     * never written out in full and the output ends there. Connections are written through their own resource. Export
     * files hold the linked parts instead (getLinkedPartsList). A part that can not hold connections has none, and the
     * check saves it a query.
     * @return list<array<string, mixed>>
     */
    #[Groups(['part:read'])]
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
                    'status' => ['type' => 'string', 'enum' => ['approved', 'under_evaluation', 'use_up', 'do_not_use']],
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

    /**
     * The number of parts this part is linked to, as an export file gives it: the connected parts of a meta part, or
     * the meta parts a manufacturing part is connected to. Not in REST, which has part_connections, nor read on import.
     */
    #[Groups(['simple', 'extended', 'full'])]
    #[SerializedName('linked_parts_count')]
    public function getLinkedPartsCount(): int
    {
        return count($this->getLinkedPartsNames());
    }

    /**
     * The parts this part is linked to, as an export file gives it, joined by "; ": for a meta part its connected parts
     * in their order, each as "name (status)" with the stored status value, the same in every language; for a
     * manufacturing part the names of its meta parts, by name and then ID. Empty for a part with no links. Not
     * escaped, as the column is for reading and import does not read it.
     */
    #[Groups(['simple', 'extended', 'full'])]
    #[SerializedName('linked_parts')]
    public function getLinkedPartsList(): string
    {
        return implode('; ', $this->getLinkedPartsNames());
    }

    /**
     * @return list<string>
     */
    private function getLinkedPartsNames(): array
    {
        if ($this->getKind()->allowsConnections()) {
            return array_map(static fn (PartConnection $connection): string =>
                ($connection->getConnectedPart()?->getName() ?? '').' ('.$connection->getStatus()->value.')',
                $this->getOrderedConnections());
        }

        if (!$this->getKind()->canBeConnected()) {
            return [];
        }

        $metaParts = [];
        foreach ($this->connections_as_part as $connection) {
            if ($connection->getMetaPart() !== null) {
                $metaParts[] = $connection->getMetaPart();
            }
        }
        usort($metaParts, static fn (Part $a, Part $b): int =>
            strnatcasecmp($a->getName(), $b->getName()) ?: ($a->getID() ?? PHP_INT_MAX) <=> ($b->getID() ?? PHP_INT_MAX));

        return array_map(static fn (Part $part): string => $part->getName(), $metaParts);
    }
}
