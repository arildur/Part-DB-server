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


namespace App\Exceptions;

use Symfony\Component\Translation\TranslatableMessage;

/**
 * Thrown by PartKindConverter when it refuses a conversion between the kinds, before anything is changed or saved.
 * The message is plain English for logs; the web page shows getTranslatableMessage().
 * @see \App\Services\Parts\PartKindConverter
 */
class PartConversionException extends \RuntimeException
{
    /**
     * @param array<string, string> $parameters the parameters of the translation
     * @param list<string> $connectedPartNames for a meta part with connected parts, their names in their order
     */
    private function __construct(string $message, private readonly string $translationKey, private readonly array $parameters = [],
        private readonly array $connectedPartNames = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function cannotBeConvertedToMeta(string $partName): self
    {
        return new self(sprintf('The part "%s" can not be converted to use a meta part, as its kind does not allow it.', $partName),
            'part.convert.error.not_to_meta');
    }

    public static function cannotBeConvertedToManufacturing(string $partName): self
    {
        return new self(sprintf('The part "%s" can not be converted to a manufacturing part, as its kind does not allow it.', $partName),
            'part.convert.error.not_to_manufacturing');
    }

    /**
     * @param list<string> $connectedPartNames
     */
    public static function hasConnectedParts(string $partName, array $connectedPartNames): self
    {
        $names = implode(', ', $connectedPartNames);

        return new self(sprintf('The meta part "%s" can not be converted to a manufacturing part while parts are connected to it: %s. Remove these connections first.', $partName, $names),
            'part.convert.error.has_connections', ['%parts%' => $names], $connectedPartNames);
    }

    /**
     * @param list<string> $violations the messages of the violations, each with the path it was found at
     */
    public static function invalid(array $violations): self
    {
        $text = implode('; ', $violations);

        return new self('Nothing was converted, as the result would not be valid: '.$text, 'part.convert.error.invalid', ['%violations%' => $text]);
    }

    public static function savingFailed(\Throwable $previous): self
    {
        return new self('Nothing was converted, as saving failed: '.$previous->getMessage(), 'part.convert.error.saving_failed',
            ['%error%' => $previous->getMessage()], [], $previous);
    }

    public function getTranslatableMessage(): TranslatableMessage
    {
        return new TranslatableMessage($this->translationKey, $this->parameters);
    }

    /**
     * @return list<string>
     */
    public function getConnectedPartNames(): array
    {
        return $this->connectedPartNames;
    }
}
