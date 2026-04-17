<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Resilience;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\CircuitBreaker;
use Shubo\ShippingCore\Model\Resilience\CircuitBreakerStateRepository;

/**
 * Unit tests for {@see CircuitBreaker}. Covers every state transition from
 * the design-doc §9.2 table: closed -> open -> half_open -> closed plus
 * half_open -> open (with doubled cooldown) and the admin force override.
 */
class CircuitBreakerTest extends TestCase
{
    private const CARRIER = 'trackings_ge';
    private const FAILURE_THRESHOLD = 5;
    private const FAILURE_WINDOW = 120;
    private const COOLDOWN = 60;
    private const SUCCESS_THRESHOLD = 3;

    /** @var CircuitBreakerStateRepository&MockObject */
    private CircuitBreakerStateRepository $repo;

    /** @var EventManager&MockObject */
    private EventManager $eventManager;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var DateTime&MockObject */
    private DateTime $dateTime;

    private CircuitBreaker $breaker;

    /** @var int Seconds-since-epoch value returned by the mocked DateTime::gmtTimestamp. */
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(CircuitBreakerStateRepository::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->dateTime = $this->createMock(DateTime::class);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path): ?string {
                return match ($path) {
                    'shubo_shipping/breaker/failure_threshold' => (string)self::FAILURE_THRESHOLD,
                    'shubo_shipping/breaker/failure_window_seconds' => (string)self::FAILURE_WINDOW,
                    'shubo_shipping/breaker/cooldown_seconds' => (string)self::COOLDOWN,
                    'shubo_shipping/breaker/success_threshold' => (string)self::SUCCESS_THRESHOLD,
                    default => null,
                };
            },
        );

        $this->dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);
        $this->dateTime->method('gmtDate')->willReturnCallback(
            fn (?string $format, ?int $input = null): string
                => gmdate($format ?? 'Y-m-d H:i:s', $input ?? $this->now),
        );

        $this->breaker = new CircuitBreaker(
            $this->repo,
            $this->eventManager,
            $this->logger,
            $this->scopeConfig,
            $this->dateTime,
        );
    }

    public function testClosedStateAllowsCalls(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_CLOSED);
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);
        $this->repo->expects(self::once())->method('save');

        $result = $this->breaker->execute(self::CARRIER, static fn (): string => 'ok');

        self::assertSame('ok', $result);
    }

    public function testFailureThresholdTransitionsToOpen(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_CLOSED);
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with('shubo_shipping_carrier_breaker_opened', self::callback(
                fn (array $data): bool => ($data['carrier_code'] ?? null) === self::CARRIER,
            ));

        // Drive 5 consecutive failures.
        for ($i = 0; $i < self::FAILURE_THRESHOLD; $i++) {
            try {
                $this->breaker->execute(self::CARRIER, static function (): never {
                    throw new \RuntimeException('boom');
                });
                self::fail('Expected the callable\'s RuntimeException to propagate.');
            } catch (\RuntimeException) {
                // Expected.
            }
        }

        self::assertSame(CircuitBreakerStateInterface::STATE_OPEN, $state->getState());
        self::assertNotNull($state->getCooldownUntil());
    }

    public function testOpenStateRejectsCallsBeforeCooldown(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_OPEN);
        $state->setCooldownUntil(gmdate('Y-m-d H:i:s', $this->now + 30));
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        $callableInvoked = false;

        $this->expectException(CircuitOpenException::class);
        try {
            $this->breaker->execute(self::CARRIER, static function () use (&$callableInvoked): void {
                $callableInvoked = true;
            });
        } finally {
            self::assertFalse($callableInvoked, 'Callable must NOT run while breaker is open.');
        }
    }

    public function testOpenStateTransitionsToHalfOpenAfterCooldown(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_OPEN);
        $state->setCooldownUntil(gmdate('Y-m-d H:i:s', $this->now - 10));
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        $result = $this->breaker->execute(self::CARRIER, static fn (): string => 'trial');

        self::assertSame('trial', $result);
        self::assertSame(
            CircuitBreakerStateInterface::STATE_HALF_OPEN,
            $state->getState(),
            'After cooldown the first execute() must flip the row to half_open.',
        );
    }

    public function testHalfOpenSuccessThresholdClosesBreaker(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_HALF_OPEN);
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        for ($i = 0; $i < self::SUCCESS_THRESHOLD; $i++) {
            $this->breaker->execute(self::CARRIER, static fn (): string => 'ok');
        }

        self::assertSame(CircuitBreakerStateInterface::STATE_CLOSED, $state->getState());
        self::assertSame(0, $state->getFailureCount());
    }

    public function testHalfOpenFailureReopensBreakerWithLongerCooldown(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_HALF_OPEN);
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with('shubo_shipping_carrier_breaker_opened');

        try {
            $this->breaker->execute(self::CARRIER, static function (): never {
                throw new \RuntimeException('half-open trial failed');
            });
            self::fail('Expected the callable\'s RuntimeException to propagate.');
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame(CircuitBreakerStateInterface::STATE_OPEN, $state->getState());
        $cooldown = $state->getCooldownUntil();
        self::assertNotNull($cooldown);
        $parsed = strtotime($cooldown . ' UTC');
        self::assertNotFalse($parsed);
        $cooldownSecs = $parsed - $this->now;
        self::assertGreaterThan(
            self::COOLDOWN,
            $cooldownSecs,
            'Re-open from half_open must use a longer cooldown than the initial COOLDOWN.',
        );
    }

    public function testFailureWindowExpiryResetsFailureCount(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_CLOSED);
        $state->setFailureCount(3);
        // Last failure well outside the rolling window.
        $state->setLastFailureAt(gmdate('Y-m-d H:i:s', $this->now - (self::FAILURE_WINDOW + 1)));

        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        try {
            $this->breaker->execute(self::CARRIER, static function (): never {
                throw new \RuntimeException('fresh failure');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame(
            1,
            $state->getFailureCount(),
            'Failure outside rolling window must reset counter to 1 (not increment to 4).',
        );
        self::assertSame(CircuitBreakerStateInterface::STATE_CLOSED, $state->getState());
    }

    public function testForceStateOverridesCurrentState(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_CLOSED);
        $state->setFailureCount(4);
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);
        $this->repo->expects(self::once())->method('save');

        $this->breaker->forceState(
            self::CARRIER,
            CircuitBreakerStateInterface::STATE_OPEN,
            'manual test',
        );

        self::assertSame(CircuitBreakerStateInterface::STATE_OPEN, $state->getState());
        self::assertSame(0, $state->getFailureCount());
    }

    public function testForceStateRejectsInvalidState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->breaker->forceState(self::CARRIER, 'bogus', 'oops');
    }

    public function testEventFiredOnTransitionToOpen(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_CLOSED);
        $state->setFailureCount(self::FAILURE_THRESHOLD - 1);
        $state->setLastFailureAt(gmdate('Y-m-d H:i:s', $this->now - 10));

        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with(
                'shubo_shipping_carrier_breaker_opened',
                self::callback(static function (array $data): bool {
                    return ($data['carrier_code'] ?? null) === self::CARRIER
                        && array_key_exists('opened_at', $data);
                }),
            );

        try {
            $this->breaker->execute(self::CARRIER, static function (): never {
                throw new \RuntimeException('tipping failure');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        self::assertSame(CircuitBreakerStateInterface::STATE_OPEN, $state->getState());
    }

    public function testStateOfReturnsPersistedState(): void
    {
        $state = $this->newState(CircuitBreakerStateInterface::STATE_HALF_OPEN);
        $this->repo->method('getByCarrierCode')->with(self::CARRIER)->willReturn($state);

        self::assertSame(CircuitBreakerStateInterface::STATE_HALF_OPEN, $this->breaker->stateOf(self::CARRIER));
    }

    private function newState(string $initialState): CircuitBreakerStateInterface
    {
        $state = new class implements CircuitBreakerStateInterface {
            private string $carrierCode = '';
            private string $state = CircuitBreakerStateInterface::STATE_CLOSED;
            private int $failureCount = 0;
            private int $successCountSinceHalfopen = 0;
            private ?string $lastFailureAt = null;
            private ?string $lastSuccessAt = null;
            private ?string $openedAt = null;
            private ?string $cooldownUntil = null;
            private ?string $updatedAt = null;

            public function getCarrierCode(): string
            {
                return $this->carrierCode;
            }

            public function setCarrierCode(string $carrierCode): self
            {
                $this->carrierCode = $carrierCode;
                return $this;
            }

            public function getState(): string
            {
                return $this->state;
            }

            public function setState(string $state): self
            {
                $this->state = $state;
                return $this;
            }

            public function getFailureCount(): int
            {
                return $this->failureCount;
            }

            public function setFailureCount(int $count): self
            {
                $this->failureCount = $count;
                return $this;
            }

            public function getSuccessCountSinceHalfopen(): int
            {
                return $this->successCountSinceHalfopen;
            }

            public function setSuccessCountSinceHalfopen(int $count): self
            {
                $this->successCountSinceHalfopen = $count;
                return $this;
            }

            public function getLastFailureAt(): ?string
            {
                return $this->lastFailureAt;
            }

            public function setLastFailureAt(?string $timestamp): self
            {
                $this->lastFailureAt = $timestamp;
                return $this;
            }

            public function getLastSuccessAt(): ?string
            {
                return $this->lastSuccessAt;
            }

            public function setLastSuccessAt(?string $timestamp): self
            {
                $this->lastSuccessAt = $timestamp;
                return $this;
            }

            public function getOpenedAt(): ?string
            {
                return $this->openedAt;
            }

            public function setOpenedAt(?string $timestamp): self
            {
                $this->openedAt = $timestamp;
                return $this;
            }

            public function getCooldownUntil(): ?string
            {
                return $this->cooldownUntil;
            }

            public function setCooldownUntil(?string $timestamp): self
            {
                $this->cooldownUntil = $timestamp;
                return $this;
            }

            public function getUpdatedAt(): ?string
            {
                return $this->updatedAt;
            }
        };
        $state->setCarrierCode(self::CARRIER);
        $state->setState($initialState);
        return $state;
    }
}
