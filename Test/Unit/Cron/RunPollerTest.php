<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\TrackingPollerInterface;
use Shubo\ShippingCore\Cron\RunPoller;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Unit tests for {@see RunPoller}.
 *
 * Verifies the FIX #3 migration from {@see StructuredLogger::logRateLimit()}
 * to {@see StructuredLogger::logCronRun()}, configuration of the per-run
 * budget, and the "swallow exceptions to protect the cron queue" contract.
 */
class RunPollerTest extends TestCase
{
    private const CONFIG_MAX_SHIPMENTS = 'shubo_shipping/poller/max_shipments_per_run';
    private const DEFAULT_MAX_SHIPMENTS = 500;

    /** @var TrackingPollerInterface&MockObject */
    private TrackingPollerInterface $poller;

    /** @var ScopeConfigInterface&MockObject */
    private ScopeConfigInterface $scopeConfig;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private RunPoller $cron;

    protected function setUp(): void
    {
        $this->poller = $this->createMock(TrackingPollerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->cron = new RunPoller(
            $this->poller,
            $this->scopeConfig,
            $this->logger,
        );
    }

    public function testExecuteLogsCronRunWithPollerJobNameAndBudget(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(self::CONFIG_MAX_SHIPMENTS)
            ->willReturn('250');

        $this->poller->expects(self::once())
            ->method('drainBatch')
            ->with(250)
            ->willReturn(37);

        $this->logger->expects(self::once())
            ->method('logCronRun')
            ->with(
                'shubo_shipping.poller.run',
                37,
                ['max_shipments' => 250],
            );

        $this->logger->expects(self::never())->method('logRateLimit');
        $this->logger->expects(self::never())->method('logDispatchFailed');

        $this->cron->execute();
    }

    public function testExecuteFallsBackToDefaultBudgetWhenConfigIsMissing(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->poller->expects(self::once())
            ->method('drainBatch')
            ->with(self::DEFAULT_MAX_SHIPMENTS)
            ->willReturn(0);

        $this->logger->expects(self::once())
            ->method('logCronRun')
            ->with(
                'shubo_shipping.poller.run',
                0,
                ['max_shipments' => self::DEFAULT_MAX_SHIPMENTS],
            );

        $this->cron->execute();
    }

    public function testExecuteFallsBackToDefaultWhenConfigIsZeroOrNegative(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('-5');

        $this->poller->expects(self::once())
            ->method('drainBatch')
            ->with(self::DEFAULT_MAX_SHIPMENTS)
            ->willReturn(0);

        $this->cron->execute();
    }

    public function testExecuteSwallowsExceptionsAndLogsDispatchFailure(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $boom = new \RuntimeException('poll storm');
        $this->poller->expects(self::once())
            ->method('drainBatch')
            ->willThrowException($boom);

        $this->logger->expects(self::never())->method('logCronRun');
        $this->logger->expects(self::once())
            ->method('logDispatchFailed')
            ->with('shubo_shipping.poller', 'drainBatch', $boom);

        // Cron must swallow so Magento's queue is not poisoned.
        $this->cron->execute();
    }
}
