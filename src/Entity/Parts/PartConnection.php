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

declare(strict_types=1);namespace App\Entity\Parts;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Serializer\Filter\PropertyFilter;
use App\ApiPlatform\Filter\EntityFilter;
use App\Entity\Base\AbstractDBElement;
use App\Entity\Base\TimestampTrait;
use App\Entity\Contracts\NamedElementInterface;
use App\Entity\Contracts\TimeStampableInterface;
use App\Repository\DBElementRepository;
use App\Validator\Constraints\ValidPartConnection;
use App\Validator\UniqueValidatableInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Connects a meta part to a manufacturing part that fulfills its specification, with what belongs to that one
 * relationship: its priority, its status and a note.
 */
#[ORM\Entity(repositoryClass: DBElementRepository::class)]
#[ORM\Table('part_connections')]
#[ORM\UniqueConstraint(name: 'part_connections_uniq_pair', columns: ['id_meta_part', 'id_connected_part'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['meta_part', 'connected_part'], message: 'validator.part_connection.already_exists')]
#[ValidPartConnection]
#[ApiResource(
    operations: [
        new Get(security: 'is_granted("read", object)'),
        new GetCollection(security: 'is_granted("@parts.read")'),
        new Post(securityPostDenormalize: 'is_granted("create", object)'),
        new Patch(security: 'is_granted("edit", object)'),
        new Delete(security: 'is_granted("delete", object)'),
    ],
    normalizationContext: ['groups' => ['part_connection:read', 'api:basic:read'], 'openapi_definition_name' => 'Read'],
    denormalizationContext: ['groups' => ['part_connection:write', 'api:basic:write'], 'openapi_definition_name' => 'Write'],
    order: ['position' => 'ASC', 'id' => 'ASC'],
)]
#[ApiFilter(PropertyFilter::class)]
#[ApiFilter(EntityFilter::class, properties: ['meta_part', 'connected_part'])]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['position', 'addedDate', 'lastModified'])]
class PartConnection extends AbstractDBElement implements TimeStampableInterface, NamedElementInterface, UniqueValidatableInterface
{
    use TimestampTrait;

    #[ORM\ManyToOne(targetEntity: Part::class, inversedBy: 'part_connections')]
    #[ORM\JoinColumn(name: 'id_meta_part', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['part_connection:read', 'part_connection:write'])]
    protected ?Part $meta_part = null;

    #[ORM\ManyToOne(targetEntity: Part::class, inversedBy: 'connections_as_part')]
    #[ORM\JoinColumn(name: 'id_connected_part', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['part_connection:read', 'part_connection:write'])]
    protected ?Part $connected_part = null;

    /**
     * @var int|null The priority of the connection, the lowest first. If it is null, the next free position is set when saved.
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    #[Groups(['part_connection:read', 'part_connection:write'])]
    protected ?int $position = null;

    #[ORM\Column(type: Types::STRING, length: 255, enumType: PartConnectionStatus::class, options: ['default' => 'approved'])]
    #[Groups(['part_connection:read', 'part_connection:write'])]
    protected PartConnectionStatus $status = PartConnectionStatus::APPROVED;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['part_connection:read', 'part_connection:write'])]
    protected string $note = '';

    public function __clone()
    {
        if ($this->id) {
            $this->addedDate = null;
        }
        parent::__clone();
    }

    /**
     * REST sets the two ends through setters, so the place to give a new connection its position is here. It
     * must not stay empty: position 0 or none would sort first, and make the part the primary source.
     */
    #[ORM\PrePersist]
    public function setNextFreePositionIfEmpty(): void
    {
        if ($this->position !== null) {
            return;
        }

        $highest = 0;
        foreach ($this->meta_part?->getPartConnections() ?? [] as $connection) {
            if ($connection !== $this && $connection->getPosition() !== null) {
                $highest = max($highest, $connection->getPosition());
            }
        }
        $this->position = $highest + 1;
    }

    public function getMetaPart(): ?Part
    {
        return $this->meta_part;
    }

    public function setMetaPart(?Part $meta_part): self
    {
        $this->meta_part = $meta_part;

        return $this;
    }

    public function getConnectedPart(): ?Part
    {
        return $this->connected_part;
    }

    public function setConnectedPart(?Part $connected_part): self
    {
        $this->connected_part = $connected_part;

        return $this;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getStatus(): PartConnectionStatus
    {
        return $this->status;
    }

    public function setStatus(PartConnectionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isUsable(): bool
    {
        return $this->status === PartConnectionStatus::APPROVED;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Whether the connected part has another footprint than its meta part, when both have one. Worth a warning, not a refusal.
     */
    public function hasDifferentFootprint(): bool
    {
        $own = $this->meta_part?->getFootprint();
        $other = $this->connected_part?->getFootprint();

        return $own !== null && $other !== null && $own !== $other;
    }

    /**
     * Named after the connected part, so that a log entry says more than an ID.
     */
    public function getName(): string
    {
        return $this->connected_part?->getName() ?? '';
    }

    public function getComparableFields(): array
    {
        return ['connected_part' => $this->connected_part?->getID()];
    }
}
