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

declare(strict_types=1);namespace App\Security\Voter;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Services\UserSystem\VoterHelper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A connection is part of its meta part, so a user who may edit the meta part may change its connections, with
 * no separate approval step and no create or delete right on parts.
 * @phpstan-extends Voter<non-empty-string, PartConnection|class-string>
 */
final class PartConnectionVoter extends Voter
{
    private const ALLOWED_PERMS = ['read', 'edit', 'create', 'delete', 'show_history', 'revert_element'];

    public function __construct(private readonly Security $security, private readonly VoterHelper $helper)
    {
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!is_string($subject) && !$subject instanceof PartConnection) {
            throw new \RuntimeException('Invalid subject type!');
        }

        $operation = match ($attribute) {
            'read' => 'read',
            'edit', 'create', 'delete' => 'edit',
            'show_history' => 'show_history',
            'revert_element' => 'revert_element',
            default => throw new \RuntimeException('Encountered unknown operation "'.$attribute.'"!'),
        };

        if (is_string($subject) || !$subject->getMetaPart() instanceof Part) {
            return $this->helper->isGranted($token, 'parts', $operation, $vote);
        }

        return $this->security->isGranted($operation, $subject->getMetaPart());
    }

    protected function supports($attribute, $subject): bool
    {
        return is_a($subject, PartConnection::class, true) && in_array($attribute, self::ALLOWED_PERMS, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return $subjectType === 'string' || is_a($subjectType, PartConnection::class, true);
    }

    public function supportsAttribute(string $attribute): bool
    {
        return in_array($attribute, self::ALLOWED_PERMS, true);
    }
}
