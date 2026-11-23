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

namespace App\Tests\Security;

use App\Entity\Parts\Part;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Only in the test environment: denies edit on the parts a test names. Part-DB's rights on parts are the same for every
 * part, so this is how a test gets a user who may edit one part but not another.
 */
final class PartEditDenyingDecisionManager implements AccessDecisionManagerInterface
{
    /** @var list<int> The IDs of the parts that may not be edited, set by a test and cleared after it */
    public static array $deniedPartIds = [];

    public function __construct(private readonly AccessDecisionManagerInterface $inner)
    {
    }

    public function decide(TokenInterface $token, array $attributes, mixed $object = null, bool|AccessDecision|null $accessDecision = null, bool $allowMultipleAttributes = false): bool
    {
        if ($object instanceof Part && in_array('edit', $attributes, true) && in_array($object->getID(), self::$deniedPartIds, true)) {
            return false;
        }

        return $this->inner->decide($token, $attributes, $object, $accessDecision, $allowMultipleAttributes);
    }
}
