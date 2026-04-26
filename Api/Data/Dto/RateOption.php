<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data\Dto;

/**
 * Single rate option DTO.
 *
 * One row in a carrier quote response. `rationale` is a short string
 * logged for debugging ("circuit-open-skipped", "weight-exceeded",
 * "chosen"). `priceCents` is integer tetri.
 *
 * @api
 */
class RateOption
{
    public function __construct(
        public readonly string $carrierCode,
        public readonly string $methodCode,
        public readonly int $priceCents,
        public readonly int $etaDays,
        public readonly string $serviceLevel,
        public readonly string $rationale,
        public readonly ?string $pudoExternalId = null,
        /** @var array<string, scalar>|null Carrier-specific opaque data (e.g. Shippo rate_object_id). */
        public readonly ?array $adapterMetadata = null,
    ) {
    }
}
