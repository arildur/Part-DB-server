<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
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


namespace App\Serializer;

use App\Entity\Parts\Part;
use App\Services\Parts\PricedetailHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds the read-only price of a meta part, worked out by its price method for one unit in the base currency. Only for
 * a single part (the groups hold pricedetail:read), so that a list does not work out a price for each row.
 * @see \App\Tests\API\Endpoints\PartEndpointTest
 */
#[AutoconfigureTag('serializer.normalizer', ['serializer' => ['*']])]
class MetaPartPriceNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'META_PART_PRICE_NORMALIZER_ALREADY_CALLED';

    public function __construct(private readonly PricedetailHelper $pricedetailHelper)
    {
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        if (!$object instanceof Part) {
            throw new \InvalidArgumentException('This normalizer only supports Part objects!');
        }

        $context[self::ALREADY_CALLED][spl_object_id($object)] = true;
        $data = $this->normalizer->normalize($object, $format, $context);

        if (is_array($data)) {
            $data['price'] = $this->pricedetailHelper->calculateMetaPartPrice($object)?->__toString();
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Part
            && !isset($context[self::ALREADY_CALLED][spl_object_id($data)])
            && $data->getKind()->allowsPriceMethod()
            && in_array('pricedetail:read', (array) ($context[AbstractNormalizer::GROUPS] ?? []), true);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            //We depend on the context to determine if we should normalize or not
            Part::class => false,
        ];
    }
}
