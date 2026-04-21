<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\TrackingPollerInterface;
use Shubo\ShippingCore\Console\Command\PollCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PollCommandTest extends TestCase
{
    /** @var TrackingPollerInterface&MockObject */
    private TrackingPollerInterface $poller;

    private PollCommand $command;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->poller = $this->createMock(TrackingPollerInterface::class);
        $this->command = new PollCommand($this->poller);
        $this->tester = new CommandTester($this->command);
    }

    public function testDefaultLimitIsPassedThrough(): void
    {
        $this->poller->expects($this->once())
            ->method('drainBatch')
            ->with(500)
            ->willReturn(3);

        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Polled 3 shipment', $this->tester->getDisplay());
    }

    public function testCustomLimitIsPassedThrough(): void
    {
        $this->poller->expects($this->once())
            ->method('drainBatch')
            ->with(25)
            ->willReturn(0);

        $exit = $this->tester->execute(['--limit' => '25']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Polled 0', $this->tester->getDisplay());
    }

    public function testZeroOrNegativeLimitIsCappedToOne(): void
    {
        $this->poller->expects($this->once())
            ->method('drainBatch')
            ->with(1)
            ->willReturn(0);

        $exit = $this->tester->execute(['--limit' => '0']);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testExitsFailureOnPollerException(): void
    {
        $this->poller->expects($this->once())
            ->method('drainBatch')
            ->willThrowException(new \RuntimeException('adapter down'));

        $exit = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('adapter down', $this->tester->getDisplay());
    }
}
