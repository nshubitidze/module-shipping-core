<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Resilience;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Exception\AuthException;
use Shubo\ShippingCore\Exception\NetworkException;
use Shubo\ShippingCore\Exception\RateLimitedException;
use Shubo\ShippingCore\Exception\TransientHttpException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\RetryPolicy;
use Shubo\ShippingCore\Model\Resilience\Sleeper;

/**
 * Unit tests for {@see RetryPolicy}. Covers retry classification, backoff
 * bounds, Retry-After honour, max-attempts exhaustion, and the DLQ-callback
 * contract. Sleep durations are captured through a MockObject of
 * {@see Sleeper} so no real waiting occurs.
 */
class RetryPolicyTest extends TestCase
{
    private const CARRIER = 'trackings_ge';
    private const OP = 'createShipment';

    /** @var Sleeper&MockObject */
    private Sleeper $sleeper;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    /** @var list<int> Captured sleep durations in ms. */
    private array $sleeps = [];

    private RetryPolicy $policy;

    protected function setUp(): void
    {
        $this->sleeper = $this->createMock(Sleeper::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->sleeps = [];
        $this->sleeper->method('sleepMs')->willReturnCallback(
            function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
        $this->policy = new RetryPolicy($this->sleeper, $this->logger);
    }

    public function testRetryOn5xx(): void
    {
        $attempts = 0;
        $result = $this->policy->execute(
            self::CARRIER,
            self::OP,
            function () use (&$attempts): string {
                $attempts++;
                if ($attempts === 1) {
                    throw TransientHttpException::create(503, 'service unavailable');
                }
                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(2, $attempts, '5xx should trigger a retry; attempt 2 succeeds.');
    }

    public function testRetryOn429HonorsRetryAfter(): void
    {
        $attempts = 0;
        $this->policy->execute(
            self::CARRIER,
            self::OP,
            function () use (&$attempts): string {
                $attempts++;
                if ($attempts === 1) {
                    throw RateLimitedException::create(3, 'rate limited');
                }
                return 'ok';
            },
        );

        self::assertSame(2, $attempts);
        self::assertSame(
            [3000],
            $this->sleeps,
            'Retry-After=3 seconds => Sleeper should be called once with 3000ms (not the backoff).',
        );
    }

    public function testRetryOn429WithoutRetryAfterUsesBackoff(): void
    {
        $attempts = 0;
        $this->policy->execute(
            self::CARRIER,
            self::OP,
            function () use (&$attempts): string {
                $attempts++;
                if ($attempts === 1) {
                    throw RateLimitedException::create(null, 'rate limited no header');
                }
                return 'ok';
            },
        );

        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(500, $this->sleeps[0], 'base=1000ms, jitter window [500, 1500]');
        self::assertLessThanOrEqual(1500, $this->sleeps[0]);
    }

    public function testRetryOnNetworkFailure(): void
    {
        $attempts = 0;
        $result = $this->policy->execute(
            self::CARRIER,
            self::OP,
            function () use (&$attempts): string {
                $attempts++;
                if ($attempts < 2) {
                    throw new NetworkException(new \Magento\Framework\Phrase('socket reset'));
                }
                return 'ok';
            },
        );
        self::assertSame('ok', $result);
        self::assertSame(2, $attempts);
    }

    public function testNoRetryOn4xx(): void
    {
        $attempts = 0;
        $this->expectException(TransientHttpException::class);
        try {
            $this->policy->execute(
                self::CARRIER,
                self::OP,
                function () use (&$attempts): void {
                    $attempts++;
                    throw TransientHttpException::create(400, 'bad request');
                },
            );
        } finally {
            self::assertSame(1, $attempts, '4xx must not retry.');
            self::assertSame([], $this->sleeps);
        }
    }

    public function testNoRetryOnAuthError(): void
    {
        $attempts = 0;
        $this->expectException(AuthException::class);
        try {
            $this->policy->execute(
                self::CARRIER,
                self::OP,
                function () use (&$attempts): void {
                    $attempts++;
                    throw new AuthException(new \Magento\Framework\Phrase('invalid signature'));
                },
            );
        } finally {
            self::assertSame(1, $attempts);
        }
    }

    public function testNoRetryOnGenericThrowable(): void
    {
        $attempts = 0;
        $this->expectException(\RuntimeException::class);
        try {
            $this->policy->execute(
                self::CARRIER,
                self::OP,
                function () use (&$attempts): void {
                    $attempts++;
                    throw new \RuntimeException('unknown');
                },
            );
        } finally {
            self::assertSame(1, $attempts);
        }
    }

    public function testMaxAttemptsExhaustion(): void
    {
        $attempts = 0;
        /** @var \Throwable|null $captured */
        $captured = null;
        $this->expectException(TransientHttpException::class);
        try {
            $this->policy->execute(
                self::CARRIER,
                self::OP,
                function () use (&$attempts): void {
                    $attempts++;
                    throw TransientHttpException::create(500, 'perma 500');
                },
                function (\Throwable $e) use (&$captured): void {
                    $captured = $e;
                },
            );
        } finally {
            self::assertSame(5, $attempts, 'max_attempts must be exactly 5.');
            self::assertInstanceOf(TransientHttpException::class, $captured);
            // 4 sleeps between 5 attempts.
            self::assertCount(4, $this->sleeps);
        }
    }

    public function testBackoffSequence(): void
    {
        $attempts = 0;
        try {
            $this->policy->execute(
                self::CARRIER,
                self::OP,
                function () use (&$attempts): void {
                    $attempts++;
                    throw TransientHttpException::create(500, '5xx');
                },
            );
        } catch (TransientHttpException) {
            // Expected after 5 attempts.
        }

        self::assertSame(5, $attempts);
        self::assertCount(4, $this->sleeps);
        $expectedBases = [1000, 2000, 4000, 8000];
        foreach ($expectedBases as $i => $base) {
            self::assertGreaterThanOrEqual($base - 500, $this->sleeps[$i], "attempt {$i} lower bound");
            self::assertLessThanOrEqual($base + 500, $this->sleeps[$i], "attempt {$i} upper bound");
        }
    }

    public function testBackoffCapsAtSixtySeconds(): void
    {
        // Direct access: any attempt number must produce a sleep <= 60000ms.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $ms = RetryPolicy::computeBackoffMs($attempt, null, 0);
            self::assertLessThanOrEqual(60_000, $ms, "attempt {$attempt} backoff must cap at 60s");
            self::assertGreaterThanOrEqual(0, $ms);
        }
        // Retry-After (in ms) above cap is clamped.
        self::assertSame(
            60_000,
            RetryPolicy::computeBackoffMs(1, 9_999_000, 0),
            'Retry-After above cap must be clamped to 60s.',
        );
        // Retry-After below cap passes through unchanged.
        self::assertSame(
            9_999,
            RetryPolicy::computeBackoffMs(1, 9_999, 0),
            'Retry-After below cap passes through unchanged.',
        );
    }

    public function testRetryAfterZeroIsTreatedAsZero(): void
    {
        $attempts = 0;
        $this->policy->execute(
            self::CARRIER,
            self::OP,
            function () use (&$attempts): string {
                $attempts++;
                if ($attempts === 1) {
                    throw RateLimitedException::create(0, 'immediate');
                }
                return 'ok';
            },
        );
        self::assertSame([0], $this->sleeps);
    }
}
