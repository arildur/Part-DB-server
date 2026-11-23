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

declare(strict_types=1);namespace App\Doctrine\Functions;

use App\Entity\Parts\PartConnectionStatus;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * part_stock_total(part.id): the stock of a part in a query, as Part::getAmountSum works it out: its own lots and those of
 * its usable connections, leaving out lots of unknown amount and expired lots.
 *
 * This is SQL and not DQL because it adds two subselects, which DQL's grammar does not allow. The second always starts from
 * the connections, which a manufacturing part does not have, so that it costs next to nothing there. Forms that let the
 * database start from the lots (an OR with IN, an IN alone, or a join) can scan the lots for every part: sorting 20,000 parts
 * then took 65 to 201 seconds on SQLite and over 90 seconds on MySQL, against a few hundredths of a second. On MySQL the index
 * is also named, for the reason given below. tests/Doctrine/Functions/PartStockTotalTest.php fails if that index is renamed.
 * The name has an underscore on purpose: FilterTrait takes a name of only letters for an aggregate.
 */
class PartStockTotal extends FunctionNode
{
    /** The index Doctrine names for the foreign key part_lots.id_part */
    private const PART_LOTS_PART_INDEX = 'IDX_EBC8F943C22F6CC4';

    private ?Node $partId = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->partId = $parser->SingleValuedPathExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        assert($this->partId !== null, 'The part is not set');

        $platform = $sqlWalker->getConnection()->getDatabasePlatform();
        $part = $this->partId->dispatch($sqlWalker);
        $counted = sprintf(
            'pst.instock_unknown = %s AND (pst.expiration_date IS NULL OR pst.expiration_date > %s)',
            $platform->convertBooleans(false),
            $platform->getCurrentDateSQL()
        );

        //With two subqueries on part_lots, MySQL can scan the table for every part, though either one alone uses the index
        $lots = 'part_lots pst'.($platform instanceof AbstractMySQLPlatform ? ' FORCE INDEX ('.self::PART_LOTS_PART_INDEX.')' : '');

        //The values are the enum's own, so that SQL and PHP agree on what is usable
        $usable = implode(', ', array_map(static fn (string $value): string => "'".$value."'", PartConnectionStatus::usableValues()));

        //The sum over a subquery leaves the database no other starting point than the connections
        return sprintf(
            "((SELECT COALESCE(SUM(pst.amount), 0.0) FROM %3\$s WHERE pst.id_part = %1\$s AND %2\$s)"
            ." + (SELECT COALESCE(SUM((SELECT COALESCE(SUM(pst.amount), 0.0) FROM %3\$s WHERE pst.id_part = psc.id_connected_part AND %2\$s)), 0.0)"
            ." FROM part_connections psc WHERE psc.id_meta_part = %1\$s AND psc.status IN (%4\$s)))",
            $part,
            $counted,
            $lots,
            $usable
        );
    }
}
