<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Carrier;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingCore\Api\RateQuoteServiceInterface;
use Shubo\ShippingCore\Model\Carrier\CachedRateQuoteService;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Unit tests for {@see CachedRateQuoteService}.
 *
 * Covers the four critical paths per design doc §12.4:
 *  1. Cache hit → inner service not called.
 *  2. Cache miss → inner service called + result written back.
 *  3. Empty inner result → NOT written to cache (avoid freezing 10-min zeros).
 *  4. Cache read/write failure → silent fall-through, checkout succeeds.
 */
class CachedRateQuoteServiceTest extends TestCase
{
    /** @var RateQuoteServiceInterface&MockObject */
    private RateQuoteServiceInterface $inner;

    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private CachedRateQuoteService $service;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(RateQuoteServiceInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->service = new CachedRateQuoteService(
            $this->inner,
            $this->cache,
            $this->logger,
        );
    }

    public function testCacheHitShortCircuitsAndDoesNotCallInner(): void
    {
        $cached = [[
            'carrier_code' => 'shuboflat',
            'method_code' => 'standard',
            'price_cents' => 500,
            'eta_days' => 2,
            'service_level' => 'standard',
            'rationale' => 'cached',
            'pudo_external_id' => null,
        ]];

        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn((string)json_encode($cached));

        $this->inner->expects($this->never())->method('quote');
        $this->cache->expects($this->never())->method('save');

        $result = $this->service->quote($this->buildRequest());

        self::assertCount(1, $result);
        self::assertInstanceOf(RateOption::class, $result[0]);
        self::assertSame('shuboflat', $result[0]->carrierCode);
        self::assertSame(500, $result[0]->priceCents);
    }

    public function testCacheMissCallsInnerAndWritesBack(): void
    {
        $this->cache->expects($this->once())->method('load')->willReturn(false);

        $option = new RateOption(
            carrierCode: 'shuboflat',
            methodCode: 'standard',
            priceCents: 500,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );
        $this->inner->expects($this->once())
            ->method('quote')
            ->willReturn([$option]);

        $this->cache->expects($this->once())
            ->method('save')
            ->with(
                $this->isType('string'),
                $this->isType('string'),
                [CachedRateQuoteService::CACHE_TAG],
                CachedRateQuoteService::CACHE_TTL_SECONDS,
            );

        $result = $this->service->quote($this->buildRequest());

        self::assertCount(1, $result);
        self::assertSame('shuboflat', $result[0]->carrierCode);
    }

    public function testEmptyInnerResultIsNotCached(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->inner->method('quote')->willReturn([]);

        $this->cache->expects($this->never())->method('save');

        $result = $this->service->quote($this->buildRequest());
        self::assertSame([], $result);
    }

    public function testCacheReadFailureFallsThroughToInner(): void
    {
        $this->cache->method('load')
            ->willThrowException(new \RuntimeException('redis down'));

        $this->logger->expects($this->atLeastOnce())->method('logDispatchFailed');

        $this->inner->expects($this->once())
            ->method('quote')
            ->willReturn([]);

        $this->service->quote($this->buildRequest());
    }

    public function testCacheWriteFailureDoesNotPropagate(): void
    {
        $this->cache->method('load')->willReturn(false);
        $option = new RateOption(
            carrierCode: 'shuboflat',
            methodCode: 'standard',
            priceCents: 500,
            etaDays: 2,
            serviceLevel: 'standard',
            rationale: 'chosen',
        );
        $this->inner->method('quote')->willReturn([$option]);

        $this->cache->method('save')
            ->willThrowException(new \RuntimeException('redis down'));

        // Must not re-throw.
        $result = $this->service->quote($this->buildRequest());
        self::assertCount(1, $result);
    }

    public function testCacheKeyDiffersPerMerchant(): void
    {
        $keys = [];
        $this->cache->method('load')
            ->willReturnCallback(function (string $key) use (&$keys): string {
                $keys[] = $key;
                return '[]';
            });

        $this->service->quote($this->buildRequest(merchantId: 1));
        $this->service->quote($this->buildRequest(merchantId: 2));

        self::assertCount(2, $keys);
        self::assertNotSame($keys[0], $keys[1]);
    }

    public function testCacheKeyStableForSameInputs(): void
    {
        $keys = [];
        $this->cache->method('load')
            ->willReturnCallback(function (string $key) use (&$keys): string {
                $keys[] = $key;
                return '[]';
            });

        $req = $this->buildRequest();
        $this->service->quote($req);
        $this->service->quote($req);

        self::assertSame($keys[0], $keys[1]);
    }

    private function buildRequest(int $merchantId = 7): QuoteRequest
    {
        return new QuoteRequest(
            merchantId: $merchantId,
            origin: new ContactAddress(
                name: '',
                phone: '',
                email: null,
                country: 'GE',
                subdivision: 'TB',
                city: 'Tbilisi',
                district: null,
                street: 'Rustaveli 1',
                building: null,
                floor: null,
                apartment: null,
                postcode: '0108',
                latitude: null,
                longitude: null,
                instructions: null,
            ),
            destination: new ContactAddress(
                name: '',
                phone: '',
                email: null,
                country: 'GE',
                subdivision: 'KA',
                city: 'Kutaisi',
                district: null,
                street: 'Paliashvili 5',
                building: null,
                floor: null,
                apartment: null,
                postcode: '4600',
                latitude: null,
                longitude: null,
                instructions: null,
            ),
            parcel: new ParcelSpec(
                weightGrams: 500,
                lengthMm: 0,
                widthMm: 0,
                heightMm: 0,
                declaredValueCents: 5000,
            ),
        );
    }
}
