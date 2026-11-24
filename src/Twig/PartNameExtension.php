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

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Parts\Part;
use App\Services\Parts\PartNameRenderer;
use Twig\Attribute\AsTwigFunction;

final readonly class PartNameExtension
{
    public function __construct(private PartNameRenderer $renderer)
    {
    }

    /**
     * A part's name with the icon of its kind before it, as a link to its info page unless link is false.
     * @param array<string, string|int> $attributes further attributes of the link, such as target or class, or another href
     */
    #[AsTwigFunction('part_name', isSafe: ['html'])]
    public function partName(Part $part, bool $link = true, array $attributes = []): string
    {
        return $this->renderer->render($part, $link, $attributes);
    }

    /**
     * Only the icon of the part's kind, for a place that shows the name in another form (like its name before an edit).
     */
    #[AsTwigFunction('part_kind_icon', isSafe: ['html'])]
    public function partKindIcon(Part $part): string
    {
        return $this->renderer->renderKindIcon($part);
    }
}
