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

declare(strict_types=1);

namespace App\Tests\Entity\Parts;

use App\Entity\Parts\PartConnectionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartConnectionStatusTest extends TestCase
{
    public static function statusProvider(): \Generator
    {
        yield 'approved' => [PartConnectionStatus::APPROVED, 'approved', true, 0, false];
        yield 'use up' => [PartConnectionStatus::USE_UP, 'use_up', true, 1, true];
        yield 'under evaluation' => [PartConnectionStatus::UNDER_EVALUATION, 'under_evaluation', false, 2, false];
        yield 'do not use' => [PartConnectionStatus::DO_NOT_USE, 'do_not_use', false, 3, false];
    }

    #[DataProvider('statusProvider')]
    public function testEachStatusAnswers(PartConnectionStatus $status, string $value, bool $usable, int $rank, bool $usedFirst): void
    {
        $this->assertSame($value, $status->value);
        $this->assertSame($usable, $status->isUsable());
        $this->assertSame($rank, $status->restrictionRank());
        $this->assertSame($usedFirst, $status->isUsedFirst());
        $this->assertSame('part_connection.status.'.$value, $status->getTranslationKey());
    }

    /**
     * Every case must answer each method: a case added with no answer fails here with an UnhandledMatchError.
     */
    public function testEveryCaseAnswersEveryMethod(): void
    {
        $ranks = [];
        foreach (PartConnectionStatus::cases() as $status) {
            $this->assertIsBool($status->isUsable());
            $this->assertIsBool($status->isUsedFirst());
            $ranks[] = $status->restrictionRank();
        }
        $this->assertSame(count($ranks), count(array_unique($ranks)), 'Each status has its own rank');
    }

    public function testAStatusUsedFirstIsUsable(): void
    {
        foreach (PartConnectionStatus::cases() as $status) {
            $this->assertTrue(!$status->isUsedFirst() || $status->isUsable(), $status->value);
        }
    }

    public function testUsableValuesMatchIsUsable(): void
    {
        $this->assertSame(['approved', 'use_up'], PartConnectionStatus::usableValues());
    }
}
