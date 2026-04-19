<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Cron\ReapCircuitBreakers;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Unit tests for {@see ReapCircuitBreakers}.
 *
 * Verifies:
 *  - The cron delegates to {@see CircuitBreakerInterface::reapExpired()} and
 *    emits a {@see StructuredLogger::logCronRun()} line (FIX #3).
 *  - The carrier registry is "touched" for DI eager-load.
 *  - Exceptions from reapExpired are caught and logged as dispatch failures,
 *    not rethrown — the cron queue must not be poisoned.
 */
class ReapCircuitBreakersTest extends TestCase
{
    /** @var CircuitBreakerInterface&MockObject */
    private CircuitBreakerInterface $circuitBreaker;

    /** @var CarrierRegistryInterface&MockObject */
    private CarrierRegistryInterface $registry;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private ReapCircuitBreakers $cron;

    protected function setUp(): void
    {
        $this->circuitBreaker = $this->createMock(CircuitBreakerInterface::class);
        $this->registry = $this->createMock(CarrierRegistryInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->cron = new ReapCircuitBreakers(
            $this->circuitBreaker,
            $this->registry,
            $this->logger,
        );
    }

    public function testExecuteDelegatesToInterfaceAndLogsCronRun(): void
    {
        $this->circuitBreaker->expects(self::once())
            ->method('reapExpired')
            ->willReturn(4);

        $this->logger->expects(self::once())
            ->method('logCronRun')
            ->with('shubo_shipping.reap_breakers', 4);

        $this->logger->expects(self::never())->method('logRateLimit');
        $this->logger->expects(self::never())->method('logDispatchFailed');

        $this->registry->expects(self::once())
            ->method('registeredCodes')
            ->willReturn([]);

        $this->cron->execute();
    }

    public function testExecuteSwallowsReapExceptionsAndLogsDispatchFailure(): void
    {
        $boom = new \RuntimeException('db gone');
        $this->circuitBreaker->expects(self::once())
            ->method('reapExpired')
            ->willThrowException($boom);

        $this->logger->expects(self::once())
            ->method('logDispatchFailed')
            ->with('shubo_shipping.breaker.reap', 'reapExpired', $boom);

        // Cron must swallow the exception so the queue is not poisoned.
        $this->cron->execute();
    }
}
