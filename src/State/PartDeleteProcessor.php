<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2025 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);


namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Parts\Part;
use App\Services\Parts\PartDeletionGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The processor of DELETE /api/parts/{id}: asks PartDeletionGuard before API Platform's Doctrine remove processor
 * removes the part. A refusal throws PartUsedInBomException, which config/packages/api_platform.yaml maps to 409.
 * @implements ProcessorInterface<Part, null>
 */
final readonly class PartDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private PartDeletionGuard $deletionGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Part) {
            $this->deletionGuard->assertDeletable($data);
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
