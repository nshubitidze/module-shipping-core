<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Logging;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Unit tests for {@see StructuredLogger}.
 *
 * Focused on the new {@see StructuredLogger::logCronRun()} helper (FIX #3 —
 * stop misusing logRateLimit() as a poller counter). Asserts that the PSR
 * logger receives the canonical channel message and structured context so
 * log filters by `event=cron_run` and `job=<name>` work correctly.
 */
class StructuredLoggerTest extends TestCase
{
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $psrLogger;

    private StructuredLogger $logger;

    protected function setUp(): void
    {
        $this->psrLogger = $this->createMock(LoggerInterface::class);
        $this->logger = new StructuredLogger($this->psrLogger);
    }

    public function testLogCronRunEmitsCanonicalChannelAndStructuredContext(): void
    {
        $this->psrLogger->expects(self::once())
            ->method('info')
            ->with(
                'shubo_shipping.cron_run',
                self::callback(static function (array $context): bool {
                    return ($context['event'] ?? null) === 'cron_run'
                        && ($context['job'] ?? null) === 'shubo_shipping.poller.run'
                        && ($context['count'] ?? null) === 17
                        && ($context['max_shipments'] ?? null) === 500;
                }),
            );

        $this->logger->logCronRun(
            'shubo_shipping.poller.run',
            17,
            ['max_shipments' => 500],
        );
    }

    public function testLogCronRunWorksWithEmptyContext(): void
    {
        $captured = null;
        $this->psrLogger->expects(self::once())
            ->method('info')
            ->willReturnCallback(function (string $message, array $context) use (&$captured): void {
                $captured = ['message' => $message, 'context' => $context];
            });

        $this->logger->logCronRun('shubo_shipping.reap_breakers', 3);

        self::assertSame('shubo_shipping.cron_run', $captured['message']);
        self::assertSame('cron_run', $captured['context']['event']);
        self::assertSame('shubo_shipping.reap_breakers', $captured['context']['job']);
        self::assertSame(3, $captured['context']['count']);
    }

    public function testLogCronRunContextCannotOverwriteReservedKeys(): void
    {
        // The base keys (event, job, count) are reserved and must win over
        // anything passed via $context, so log filters remain reliable even
        // if a caller accidentally passes a key collision.
        $captured = null;
        $this->psrLogger->expects(self::once())
            ->method('info')
            ->willReturnCallback(function (string $message, array $context) use (&$captured): void {
                $captured = $context;
            });

        $this->logger->logCronRun(
            'shubo_shipping.poller.run',
            42,
            [
                'event' => 'hijack',
                'job' => 'spoof',
                'count' => -1,
                'extra' => 'value',
            ],
        );

        self::assertSame('cron_run', $captured['event']);
        self::assertSame('shubo_shipping.poller.run', $captured['job']);
        self::assertSame(42, $captured['count']);
        self::assertSame('value', $captured['extra']);
    }
}
