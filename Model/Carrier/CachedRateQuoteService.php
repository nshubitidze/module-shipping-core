<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Carrier;

use Magento\Framework\App\CacheInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\RateQuoteServiceInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * 10-minute in-cache wrapper around {@see RateQuoteService}.
 *
 * Design doc §12.4 calls for a rate-quote cache keyed by origin + destination +
 * parcel + carrier. Cart refreshes during checkout hit the same merchant→
 * customer rate four or five times; without a cache each refresh burns
 * carrier API quota. This wrapper:
 *
 *   1. Builds a deterministic cache key from the QuoteRequest fields that
 *      actually affect the rate (country, subdivision, city, postcode,
 *      parcel weight + declared value, merchant id).
 *   2. On hit, deserializes and returns the cached list of RateOption DTOs.
 *   3. On miss, delegates to the inner service and writes the result back.
 *   4. On cache failure (read or write), silently falls through — cache
 *      outages must never block checkout.
 *
 * The cache TTL is fixed at 600s. A future release can make this per-carrier
 * via CarrierConfig if one carrier has materially more volatile rates than
 * the rest.
 */
class CachedRateQuoteService implements RateQuoteServiceInterface
{
    public const CACHE_TAG = 'SHUBO_SHIPPING_RATE_QUOTE';
    public const CACHE_TTL_SECONDS = 600;

    public function __construct(
        private readonly RateQuoteServiceInterface $inner,
        private readonly CacheInterface $cache,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * @return list<RateOption>
     */
    public function quote(QuoteRequest $request): array
    {
        $cacheKey = $this->buildKey($request);

        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = $this->inner->quote($request);

        $this->writeCache($cacheKey, $fresh);

        return $fresh;
    }

    private function buildKey(QuoteRequest $request): string
    {
        return sprintf(
            'shubo_shipping_rate:m%d:%s',
            $request->merchantId,
            hash('sha256', (string)json_encode([
                'origin' => $this->normalizeAddress($request->origin),
                'destination' => $this->normalizeAddress($request->destination),
                'parcel' => $this->normalizeParcel($request->parcel),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function normalizeAddress(ContactAddress $address): array
    {
        return [
            'country' => $address->country,
            'subdivision' => $address->subdivision,
            'city' => $address->city,
            'district' => $address->district,
            'postcode' => $address->postcode,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function normalizeParcel(ParcelSpec $parcel): array
    {
        return [
            'weight_grams' => $parcel->weightGrams,
            'length_mm' => $parcel->lengthMm,
            'width_mm' => $parcel->widthMm,
            'height_mm' => $parcel->heightMm,
            'declared_value_cents' => $parcel->declaredValueCents,
        ];
    }

    /**
     * @return list<RateOption>|null
     */
    private function readCache(string $key): ?array
    {
        try {
            $raw = $this->cache->load($key);
        } catch (\Throwable $e) {
            $this->logger->logDispatchFailed('cache', 'rate_quote.cache_read', $e);
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $options = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $option = $this->hydrateRateOption($entry);
            if ($option !== null) {
                $options[] = $option;
            }
        }
        return $options;
    }

    /**
     * @param list<RateOption> $options
     */
    private function writeCache(string $key, array $options): void
    {
        if ($options === []) {
            // Do not cache empty results — a transient carrier outage should
            // not freeze an empty quote for 10 minutes.
            return;
        }
        $payload = [];
        foreach ($options as $option) {
            $payload[] = [
                'carrier_code' => $option->carrierCode,
                'method_code' => $option->methodCode,
                'price_cents' => $option->priceCents,
                'eta_days' => $option->etaDays,
                'service_level' => $option->serviceLevel,
                'rationale' => $option->rationale,
                'pudo_external_id' => $option->pudoExternalId,
            ];
        }

        try {
            $this->cache->save(
                (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $key,
                [self::CACHE_TAG],
                self::CACHE_TTL_SECONDS,
            );
        } catch (\Throwable $e) {
            $this->logger->logDispatchFailed('cache', 'rate_quote.cache_write', $e);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function hydrateRateOption(array $entry): ?RateOption
    {
        if (!isset($entry['carrier_code'], $entry['method_code'], $entry['price_cents'])) {
            return null;
        }
        return new RateOption(
            carrierCode: (string)$entry['carrier_code'],
            methodCode: (string)$entry['method_code'],
            priceCents: (int)$entry['price_cents'],
            etaDays: (int)($entry['eta_days'] ?? 0),
            serviceLevel: (string)($entry['service_level'] ?? ''),
            rationale: (string)($entry['rationale'] ?? 'cached'),
            pudoExternalId: isset($entry['pudo_external_id'])
                ? (string)$entry['pudo_external_id']
                : null,
        );
    }
}
