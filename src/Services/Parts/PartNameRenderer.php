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

namespace App\Services\Parts;

use App\Entity\Parts\Part;
use App\Services\EntityURLGenerator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Renders a part's name as HTML for the web interface: the icon of its kind before the name, so that a meta part is
 * told apart from a manufacturing part wherever its name is shown. A manufacturing part has no icon and shows its
 * name as upstream does. Templates use it through the Twig function part_name(), the tables through this service.
 * Plain text (the page title, flash messages, select options, labels and exports) stays without the icon.
 */
final readonly class PartNameRenderer
{
    public function __construct(
        private TranslatorInterface $translator,
        private EntityURLGenerator $entityURLGenerator,
    ) {
    }

    /**
     * The icon of the part's kind with the kind as its title, or an empty string for a kind without an icon.
     */
    public function renderKindIcon(Part $part): string
    {
        $icon = $part->getKind()->getIconClass();
        if ($icon === null) {
            return '';
        }

        return sprintf('<i class="fa-solid %s fa-fw me-1" title="%s"></i>',
            $icon,
            htmlspecialchars($this->translator->trans($part->getKind()->getTranslationKey())));
    }

    /**
     * The icon of the part's kind and its escaped name, as a link to its info page unless $link is false.
     * @param array<string, string|int> $attributes further attributes of the link, such as target or class, or
     *                                              another href
     */
    public function render(Part $part, bool $link = true, array $attributes = []): string
    {
        $html = $this->renderKindIcon($part).htmlspecialchars($part->getName());
        if (!$link) {
            return $html;
        }

        $attributes += ['href' => $this->entityURLGenerator->infoURL($part)];
        $attributeHtml = '';
        foreach ($attributes as $name => $value) {
            $attributeHtml .= sprintf(' %s="%s"', htmlspecialchars($name), htmlspecialchars((string) $value));
        }

        return sprintf('<a%s>%s</a>', $attributeHtml, $html);
    }
}
