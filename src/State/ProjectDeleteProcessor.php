<?php
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

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Services\Parts\PartDeletionGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The processor of DELETE /api/projects/{id}: deleting a project deletes its build part, so PartDeletionGuard is asked
 * about it before API Platform's Doctrine remove processor removes the project. REST deletes only the project itself,
 * not its sub-projects. A refusal throws PartUsedInBomException, which config/packages/api_platform.yaml maps to 409.
 * @implements ProcessorInterface<Project, null>
 */
final readonly class ProjectDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private ProcessorInterface $removeProcessor,
        private PartDeletionGuard $deletionGuard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Project && $data->getBuildPart() instanceof Part) {
            $this->deletionGuard->assertDeletable($data->getBuildPart(), [$data]);
        }

        return $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}
