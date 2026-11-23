<?php
/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
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

declare(strict_types=1);namespace App\Tests\Security\Voter;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartConnection;
use App\Entity\Parts\PartKind;
use App\Entity\UserSystem\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class PartConnectionVoterTest extends KernelTestCase
{
    private AuthorizationCheckerInterface $checker;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->checker = self::getContainer()->get(AuthorizationCheckerInterface::class);
    }

    /** @param array<string, bool> $operations */
    private function loginWith(array $operations): void
    {
        $user = new User();
        $user->setName('voter test');
        foreach ($operations as $operation => $value) {
            $user->getPermissions()->setPermissionValue('parts', $operation, $value);
        }
        self::getContainer()->get(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($user, 'main', $user->getRoles())
        );
    }

    private function connection(): PartConnection
    {
        return (new PartConnection())->setMetaPart((new Part())->setKind(PartKind::META))->setConnectedPart(new Part());
    }

    /** @return array<string, array{array<string, bool>, array<string, bool>}> */
    public static function rights(): array
    {
        return [
            'edit only' => [['edit' => true, 'read' => false, 'create' => false, 'delete' => false],
                ['read' => false, 'create' => true, 'edit' => true, 'delete' => true]],
            'read only' => [['read' => true, 'edit' => false, 'create' => false, 'delete' => false],
                ['read' => true, 'create' => false, 'edit' => false, 'delete' => false]],
            'none' => [['read' => false, 'edit' => false, 'create' => false, 'delete' => false],
                ['read' => false, 'create' => false, 'edit' => false, 'delete' => false]],
        ];
    }

    /**
     * @param array<string, bool> $operations
     * @param array<string, bool> $expected
     */
    #[DataProvider('rights')]
    public function testCreateEditAndDeleteFollowEditOnTheMetaPart(array $operations, array $expected): void
    {
        $this->loginWith($operations);
        foreach ($expected as $attribute => $granted) {
            $this->assertSame($granted, $this->checker->isGranted($attribute, $this->connection()), $attribute);
        }
    }

    public function testHistoryAndRevertAnswerAsForAPart(): void
    {
        $this->loginWith(['show_history' => true, 'revert_element' => false]);

        $this->assertTrue($this->checker->isGranted('show_history', $this->connection()));
        $this->assertFalse($this->checker->isGranted('revert_element', $this->connection()));
        $this->assertTrue($this->checker->isGranted('show_history', PartConnection::class));
        $this->assertFalse($this->checker->isGranted('revert_element', PartConnection::class));
    }
}
