<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Console\Command;

use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Api\ShipmentOrchestratorInterface;
use Shubo\ShippingCore\Console\Command\DlqReprocessCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for {@see DlqReprocessCommand}.
 *
 * Covers: success path, already-reprocessed short-circuit, unsupported source,
 * missing shipment_id, retry-throws, and not-found id.
 */
class DlqReprocessCommandTest extends TestCase
{
    /** @var DeadLetterRepositoryInterface&MockObject */
    private DeadLetterRepositoryInterface $repository;

    /** @var ShipmentOrchestratorInterface&MockObject */
    private ShipmentOrchestratorInterface $orchestrator;

    private DlqReprocessCommand $command;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DeadLetterRepositoryInterface::class);
        $this->orchestrator = $this->createMock(ShipmentOrchestratorInterface::class);
        $this->command = new DlqReprocessCommand($this->repository, $this->orchestrator);
        $this->tester = new CommandTester($this->command);
    }

    public function testReprocessDispatchSourceCallsRetryAndMarksReprocessed(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('getReprocessedAt')->willReturn(null);
        $entry->method('getSource')->willReturn(DeadLetterEntryInterface::SOURCE_DISPATCH);
        $entry->method('getShipmentId')->willReturn(42);
        $entry->method('getReprocessAttempts')->willReturn(0);
        $entry->expects($this->once())->method('setReprocessAttempts')->with(1)->willReturnSelf();
        $entry->expects($this->once())
            ->method('setReprocessedAt')
            ->with($this->isType('string'))
            ->willReturnSelf();

        $this->repository->method('getById')->with(17)->willReturn($entry);

        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getStatus')->willReturn('dispatched');

        $this->orchestrator->expects($this->once())
            ->method('retry')
            ->with(42)
            ->willReturn($shipment);

        $this->repository->expects($this->once())->method('save')->with($entry);

        $exit = $this->tester->execute(['dlq_id' => '17']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('succeeded', $this->tester->getDisplay());
        self::assertStringContainsString('dispatched', $this->tester->getDisplay());
    }

    public function testReprocessShortCircuitsWhenAlreadyReprocessed(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('getReprocessedAt')->willReturn('2026-04-20 00:00:00');
        $entry->expects($this->never())->method('getSource');

        $this->repository->method('getById')->with(5)->willReturn($entry);
        $this->orchestrator->expects($this->never())->method('retry');
        $this->repository->expects($this->never())->method('save');

        $exit = $this->tester->execute(['dlq_id' => '5']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('already reprocessed', $this->tester->getDisplay());
    }

    public function testReprocessFailsOnUnsupportedSource(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('getReprocessedAt')->willReturn(null);
        $entry->method('getSource')->willReturn(DeadLetterEntryInterface::SOURCE_WEBHOOK);
        $entry->method('getReprocessAttempts')->willReturn(2);
        $entry->expects($this->once())->method('setReprocessAttempts')->with(3);

        $this->repository->method('getById')->with(9)->willReturn($entry);
        $this->orchestrator->expects($this->never())->method('retry');
        $this->repository->expects($this->once())->method('save')->with($entry);

        $exit = $this->tester->execute(['dlq_id' => '9']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not auto-reprocessable', $this->tester->getDisplay());
    }

    public function testReprocessFailsWhenShipmentIdMissing(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('getReprocessedAt')->willReturn(null);
        $entry->method('getSource')->willReturn(DeadLetterEntryInterface::SOURCE_DISPATCH);
        $entry->method('getShipmentId')->willReturn(null);
        $entry->method('getReprocessAttempts')->willReturn(0);
        $entry->expects($this->once())->method('setReprocessAttempts');

        $this->repository->method('getById')->with(11)->willReturn($entry);
        $this->orchestrator->expects($this->never())->method('retry');
        $this->repository->expects($this->once())->method('save');

        $exit = $this->tester->execute(['dlq_id' => '11']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('nothing to retry', $this->tester->getDisplay());
    }

    public function testReprocessFailsWhenOrchestratorThrows(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('getReprocessedAt')->willReturn(null);
        $entry->method('getSource')->willReturn(DeadLetterEntryInterface::SOURCE_DISPATCH);
        $entry->method('getShipmentId')->willReturn(99);
        $entry->method('getReprocessAttempts')->willReturn(0);
        $entry->expects($this->once())->method('setReprocessAttempts');
        $entry->expects($this->once())
            ->method('setErrorMessage')
            ->with($this->stringContains('bang'));
        $entry->expects($this->never())->method('setReprocessedAt');

        $this->repository->method('getById')->with(21)->willReturn($entry);
        $this->orchestrator->expects($this->once())
            ->method('retry')
            ->with(99)
            ->willThrowException(new \RuntimeException('bang'));
        $this->repository->expects($this->once())->method('save');

        $exit = $this->tester->execute(['dlq_id' => '21']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('failed', strtolower($this->tester->getDisplay()));
    }

    public function testReprocessReturnsFailureWhenIdNotFound(): void
    {
        $this->repository->method('getById')
            ->with(404)
            ->willThrowException(new NoSuchEntityException(__('gone')));

        $exit = $this->tester->execute(['dlq_id' => '404']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('does not exist', $this->tester->getDisplay());
    }

    public function testReprocessRejectsZeroId(): void
    {
        $exit = $this->tester->execute(['dlq_id' => '0']);

        self::assertSame(Command::INVALID, $exit);
        self::assertStringContainsString('positive integer', $this->tester->getDisplay());
    }
}
