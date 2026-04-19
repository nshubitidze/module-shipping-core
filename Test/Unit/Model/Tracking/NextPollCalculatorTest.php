<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Tracking;

use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\Tracking\NextPollCalculator;

/**
 * Unit tests for {@see NextPollCalculator}.
 *
 * Covers design doc §10.2 adaptive backoff table plus the terminal-status
 * short-circuits from §10.1. Time is frozen via a mocked DateTime so the
 * exact ISO string is deterministic.
 */
class NextPollCalculatorTest extends TestCase
{
    /** @var DateTime&MockObject */
    private DateTime $dateTime;

    /** Frozen "now" (Unix epoch) — 2024-01-01 12:00:00 UTC. */
    private int $now = 1_704_110_400;

    private NextPollCalculator $calculator;

    protected function setUp(): void
    {
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);
        $this->calculator = new NextPollCalculator($this->dateTime);
    }

    public function testAgeUnderOneHourSchedulesFifteenMinuteBucket(): void
    {
        $shipment = $this->shipmentWithAge(30 * 60); // 30 minutes old
        $expected = gmdate('Y-m-d H:i:s', $this->now + 15 * 60);

        self::assertSame($expected, $this->calculator->computeNextPollAt($shipment));
    }

    public function testAgeUnderTwentyFourHoursSchedulesHourlyBucket(): void
    {
        $shipment = $this->shipmentWithAge(6 * 3600); // 6 hours old
        $expected = gmdate('Y-m-d H:i:s', $this->now + 3600);

        self::assertSame($expected, $this->calculator->computeNextPollAt($shipment));
    }

    public function testAgeUnderSeventyTwoHoursSchedulesFourHourBucket(): void
    {
        $shipment = $this->shipmentWithAge(48 * 3600); // 48 hours old
        $expected = gmdate('Y-m-d H:i:s', $this->now + 4 * 3600);

        self::assertSame($expected, $this->calculator->computeNextPollAt($shipment));
    }

    public function testAgeBeyondSeventyTwoHoursSchedulesTwelveHourBucket(): void
    {
        $shipment = $this->shipmentWithAge(100 * 3600); // 100 hours old
        $expected = gmdate('Y-m-d H:i:s', $this->now + 12 * 3600);

        self::assertSame($expected, $this->calculator->computeNextPollAt($shipment));
    }

    public function testOutForDeliveryCompressesToFifteenMinutesRegardlessOfAge(): void
    {
        $shipment = $this->shipmentWithAge(100 * 3600); // would otherwise be 12h
        $expected = gmdate('Y-m-d H:i:s', $this->now + 15 * 60);

        $result = $this->calculator->computeNextPollAt($shipment, ShipmentInterface::STATUS_OUT_FOR_DELIVERY);

        self::assertSame($expected, $result);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'delivered' => [ShipmentInterface::STATUS_DELIVERED],
            'returned_to_sender' => [ShipmentInterface::STATUS_RETURNED_TO_SENDER],
            'cancelled' => [ShipmentInterface::STATUS_CANCELLED],
            'failed' => [ShipmentInterface::STATUS_FAILED],
        ];
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testNewTerminalStatusReturnsNull(string $terminalStatus): void
    {
        $shipment = $this->shipmentWithAge(6 * 3600);

        self::assertNull($this->calculator->computeNextPollAt($shipment, $terminalStatus));
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testCurrentTerminalStatusReturnsNullWithoutOverride(string $terminalStatus): void
    {
        $shipment = $this->shipmentWithAge(6 * 3600, $terminalStatus);

        self::assertNull($this->calculator->computeNextPollAt($shipment));
    }

    public function testNullCreatedAtIsTreatedAsZeroAge(): void
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getCreatedAt')->willReturn(null);
        $shipment->method('getStatus')->willReturn(ShipmentInterface::STATUS_IN_TRANSIT);

        $expected = gmdate('Y-m-d H:i:s', $this->now + 15 * 60);
        self::assertSame($expected, $this->calculator->computeNextPollAt($shipment));
    }

    public function testIsTerminalReturnsTrueForTerminalStatuses(): void
    {
        self::assertTrue($this->calculator->isTerminal(ShipmentInterface::STATUS_DELIVERED));
        self::assertTrue($this->calculator->isTerminal(ShipmentInterface::STATUS_RETURNED_TO_SENDER));
        self::assertTrue($this->calculator->isTerminal(ShipmentInterface::STATUS_CANCELLED));
        self::assertTrue($this->calculator->isTerminal(ShipmentInterface::STATUS_FAILED));
    }

    public function testIsTerminalReturnsFalseForNonTerminalStatuses(): void
    {
        self::assertFalse($this->calculator->isTerminal(ShipmentInterface::STATUS_PENDING));
        self::assertFalse($this->calculator->isTerminal(ShipmentInterface::STATUS_IN_TRANSIT));
        self::assertFalse($this->calculator->isTerminal(ShipmentInterface::STATUS_OUT_FOR_DELIVERY));
        self::assertFalse($this->calculator->isTerminal(ShipmentInterface::STATUS_PICKED_UP));
    }

    /**
     * @return ShipmentInterface&MockObject
     */
    private function shipmentWithAge(int $ageSeconds, string $status = ShipmentInterface::STATUS_IN_TRANSIT): ShipmentInterface
    {
        $createdAt = gmdate('Y-m-d H:i:s', $this->now - $ageSeconds);
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getCreatedAt')->willReturn($createdAt);
        $shipment->method('getStatus')->willReturn($status);
        return $shipment;
    }
}
