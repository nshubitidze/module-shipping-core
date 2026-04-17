<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Resilience;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\RateLimiter;
use Shubo\ShippingCore\Model\Resilience\Sleeper;
use Shubo\ShippingCore\Model\ResourceModel\RateLimitState;

/**
 * Unit tests for {@see RateLimiter}. Covers under-limit, at-limit, window
 * rollover, Redis -> DB fallback, sequential "no over-issue" invariant, and
 * the blocking acquire path.
 */
class RateLimiterTest extends TestCase
{
    private const CARRIER = 'trackings_ge';
    private const RPM = 60;

    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var RateLimitState&MockObject */
    private RateLimitState $dbResource;

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var Sleeper&MockObject */
    private Sleeper $sleeper;

    /** @var DateTime&MockObject */
    private DateTime $dateTime;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private int $now = 1_700_000_000;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->dbResource = $this->createMock(RateLimitState::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->sleeper = $this->createMock(Sleeper::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): ?string => $path === 'shubo_shipping/rate_limit/default_rpm'
                ? (string)self::RPM
                : null,
        );
        $this->dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $this->limiter = new RateLimiter(
            $this->cache,
            $this->dbResource,
            $this->scopeConfig,
            $this->dateTime,
            $this->sleeper,
            $this->logger,
        );
    }

    public function testAcquireUnderLimitSucceeds(): void
    {
        $saved = null;
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturnCallback(
            function (string $data) use (&$saved): bool {
                $saved = (int)$data;
                return true;
            },
        );

        self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
        self::assertSame(1, $saved);
    }

    public function testAcquireAtLimitFails(): void
    {
        $this->cache->method('load')->willReturn((string)self::RPM);
        $this->cache->expects(self::never())->method('save');

        self::assertFalse($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testWindowRolloverResetsTokens(): void
    {
        // Each minute produces a different cache key, so a second window
        // starts from 0 regardless of what the first window held.
        $savedByKey = [];
        $this->cache->method('load')->willReturnCallback(
            function (string $key) use (&$savedByKey): string|false {
                return $savedByKey[$key] ?? false;
            },
        );
        $this->cache->method('save')->willReturnCallback(
            function (string $data, string $key) use (&$savedByKey): bool {
                $savedByKey[$key] = $data;
                return true;
            },
        );

        // Fill the first window to the cap.
        for ($i = 0; $i < self::RPM; $i++) {
            self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
        }
        self::assertFalse($this->limiter->acquire(self::CARRIER, 1));

        // Advance time one minute — new window key.
        $this->now += 60;
        self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testDBFallbackWhenRedisThrows(): void
    {
        $this->cache->method('load')->willThrowException(new \RuntimeException('redis down'));
        // The limiter must fall through to the DB path.
        $this->dbResource->expects(self::once())
            ->method('incrementTokens')
            ->with(self::CARRIER, 1, self::RPM)
            ->willReturn(true);
        $this->logger->expects(self::atLeastOnce())->method('logRateLimit');

        self::assertTrue($this->limiter->acquire(self::CARRIER, 1));
    }

    public function testConcurrentInvariantNoOverIssueSequential(): void
    {
        $saved = 0;
        $this->cache->method('load')->willReturnCallback(
            static function () use (&$saved): string|false {
                return $saved === 0 ? false : (string)$saved;
            },
        );
        $this->cache->method('save')->willReturnCallback(
            static function (string $data) use (&$saved): bool {
                $saved = (int)$data;
                return true;
            },
        );

        $successes = 0;
        $rejects = 0;
        for ($i = 0; $i < self::RPM + 5; $i++) {
            if ($this->limiter->acquire(self::CARRIER, 1)) {
                $successes++;
            } else {
                $rejects++;
            }
        }

        self::assertSame(self::RPM, $successes, 'Exactly RPM calls must succeed.');
        self::assertSame(5, $rejects, 'Exactly the extras must be rejected.');
    }

    public function testAcquireBlockingReturnsZeroOnImmediateSuccess(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
        $this->sleeper->expects(self::never())->method('sleepMs');

        self::assertSame(0, $this->limiter->acquireBlocking(self::CARRIER, 1, 1000));
    }

    public function testAcquireBlockingTimeout(): void
    {
        $this->cache->method('load')->willReturn((string)self::RPM);
        // Count captured sleep ms to prove we actually blocked.
        $total = 0;
        $this->sleeper->method('sleepMs')->willReturnCallback(
            function (int $ms) use (&$total): void {
                $total += $ms;
            },
        );

        $waited = $this->limiter->acquireBlocking(self::CARRIER, 1, 250);
        self::assertSame(250, $waited, 'On timeout returns exactly $maxWaitMs.');
        self::assertGreaterThan(0, $total);
    }

    public function testWindowTokensReadsRedis(): void
    {
        $this->cache->method('load')->willReturn('42');
        self::assertSame(42, $this->limiter->windowTokens(self::CARRIER));
    }

    public function testWindowTokensFallsBackToDbWhenRedisUnavailable(): void
    {
        $this->cache->method('load')->willThrowException(new \RuntimeException('redis down'));
        $this->dbResource->expects(self::once())->method('fetchTokensUsed')
            ->with(self::CARRIER)
            ->willReturn(17);

        self::assertSame(17, $this->limiter->windowTokens(self::CARRIER));
    }
}
