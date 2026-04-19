<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Tracking;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;

/**
 * Pure, side-effect-free scheduler for `shipment.next_poll_at`.
 *
 * Implements design-doc §10.2 adaptive backoff:
 *
 * | age_hours | next_poll |
 * |-----------|-----------|
 * | `< 1`     | +15 min   |
 * | `< 24`    | +1 h      |
 * | `< 72`    | +4 h      |
 * | `>= 72`   | +12 h     |
 *
 * Short-circuits:
 * - A transition to `out_for_delivery` compresses to +15 min regardless of age.
 * - A terminal status (`delivered`, `returned_to_sender`, `cancelled`, `failed`)
 *   returns NULL — terminal rows are no longer polled.
 *
 * Pure: no DB, no logging, no side effects. Used by
 * {@see \Shubo\ShippingCore\Model\Tracking\TrackingPoller} during drain loops
 * and by admin "refresh" flows.
 */
class NextPollCalculator
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        ShipmentInterface::STATUS_DELIVERED,
        ShipmentInterface::STATUS_RETURNED_TO_SENDER,
        ShipmentInterface::STATUS_CANCELLED,
        ShipmentInterface::STATUS_FAILED,
    ];

    private const BUCKET_QUARTER_HOUR_SECONDS = 15 * 60;
    private const BUCKET_ONE_HOUR_SECONDS = 3600;
    private const BUCKET_FOUR_HOURS_SECONDS = 4 * 3600;
    private const BUCKET_TWELVE_HOURS_SECONDS = 12 * 3600;

    private const AGE_THRESHOLD_ONE_HOUR = 3600;
    private const AGE_THRESHOLD_ONE_DAY = 24 * 3600;
    private const AGE_THRESHOLD_THREE_DAYS = 72 * 3600;

    public function __construct(
        private readonly DateTime $dateTime,
    ) {
    }

    /**
     * Compute the next_poll_at timestamp for a shipment.
     *
     * @param ShipmentInterface $shipment
     * @param string|null       $newStatus Status transition about to be applied
     *                                     (overrides `$shipment->getStatus()`
     *                                     for terminal + out_for_delivery checks).
     * @return string|null GMT `Y-m-d H:i:s` timestamp, or NULL if terminal.
     */
    public function computeNextPollAt(ShipmentInterface $shipment, ?string $newStatus = null): ?string
    {
        $effectiveStatus = $newStatus ?? $shipment->getStatus();
        if ($this->isTerminal($effectiveStatus)) {
            return null;
        }

        $now = (int)$this->dateTime->gmtTimestamp();

        if ($newStatus === ShipmentInterface::STATUS_OUT_FOR_DELIVERY) {
            return gmdate('Y-m-d H:i:s', $now + self::BUCKET_QUARTER_HOUR_SECONDS);
        }

        $ageSeconds = $this->ageSeconds($shipment, $now);
        $bucket = $this->bucketForAge($ageSeconds);
        return gmdate('Y-m-d H:i:s', $now + $bucket);
    }

    /**
     * Whether a normalized status is terminal (no further polls scheduled).
     */
    public function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }

    private function ageSeconds(ShipmentInterface $shipment, int $now): int
    {
        $createdAt = $shipment->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            return 0;
        }
        $parsed = strtotime($createdAt . ' UTC');
        if ($parsed === false) {
            return 0;
        }
        $age = $now - $parsed;
        return $age < 0 ? 0 : $age;
    }

    private function bucketForAge(int $ageSeconds): int
    {
        if ($ageSeconds < self::AGE_THRESHOLD_ONE_HOUR) {
            return self::BUCKET_QUARTER_HOUR_SECONDS;
        }
        if ($ageSeconds < self::AGE_THRESHOLD_ONE_DAY) {
            return self::BUCKET_ONE_HOUR_SECONDS;
        }
        if ($ageSeconds < self::AGE_THRESHOLD_THREE_DAYS) {
            return self::BUCKET_FOUR_HOURS_SECONDS;
        }
        return self::BUCKET_TWELVE_HOURS_SECONDS;
    }
}
