<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Console\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Console\Command\DlqListCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DlqListCommandTest extends TestCase
{
    /** @var DeadLetterRepositoryInterface&MockObject */
    private DeadLetterRepositoryInterface $repository;

    private DlqListCommand $command;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DeadLetterRepositoryInterface::class);
        $this->command = new DlqListCommand($this->repository);
        $this->tester = new CommandTester($this->command);
    }

    public function testEmptyResultPrintsInfoMessage(): void
    {
        $this->repository->expects($this->once())
            ->method('listPending')
            ->with(50)
            ->willReturn([]);

        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('No DLQ entries', $this->tester->getDisplay());
    }

    public function testDefaultListCallsListPending(): void
    {
        $entry = $this->makeEntry(1, 'dispatch', 'fake', 42, 'boom', '2026-04-20 10:00:00', null);

        $this->repository->expects($this->once())
            ->method('listPending')
            ->with(50)
            ->willReturn([$entry]);

        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $out = $this->tester->getDisplay();
        self::assertStringContainsString('dispatch', $out);
        self::assertStringContainsString('fake', $out);
        self::assertStringContainsString('42', $out);
    }

    public function testSourceFilterCallsListBySource(): void
    {
        $entry = $this->makeEntry(2, 'webhook', 'wolt', null, 'jwt-bad', '2026-04-20 11:00:00', null);

        $this->repository->expects($this->once())
            ->method('listBySource')
            ->with('webhook', 25, false)
            ->willReturn([$entry]);

        $exit = $this->tester->execute(['--source' => 'webhook', '--limit' => '25']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('webhook', $this->tester->getDisplay());
    }

    public function testAllFlagPassesIncludeReprocessed(): void
    {
        $this->repository->expects($this->once())
            ->method('listBySource')
            ->with('webhook', 50, true)
            ->willReturn([]);

        $exit = $this->tester->execute(['--source' => 'webhook', '--all' => true]);

        self::assertSame(Command::SUCCESS, $exit);
    }

    public function testLongErrorMessageIsTruncated(): void
    {
        $longMsg = str_repeat('x', 200);
        $entry = $this->makeEntry(3, 'dispatch', 'fake', 1, $longMsg, '2026-04-20 12:00:00', null);

        $this->repository->method('listPending')->willReturn([$entry]);

        $this->tester->execute([]);

        $out = $this->tester->getDisplay();
        // Truncated to 59 chars + ellipsis.
        self::assertStringContainsString('…', $out);
        self::assertStringNotContainsString(str_repeat('x', 100), $out);
    }

    private function makeEntry(
        int $id,
        string $source,
        ?string $carrier,
        ?int $shipmentId,
        string $error,
        string $failedAt,
        ?string $reprocessedAt,
    ): DeadLetterEntryInterface {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('getDlqId')->willReturn($id);
        $entry->method('getSource')->willReturn($source);
        $entry->method('getCarrierCode')->willReturn($carrier);
        $entry->method('getShipmentId')->willReturn($shipmentId);
        $entry->method('getErrorMessage')->willReturn($error);
        $entry->method('getFailedAt')->willReturn($failedAt);
        $entry->method('getReprocessedAt')->willReturn($reprocessedAt);
        return $entry;
    }
}
