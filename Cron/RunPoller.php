<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Shubo\ShippingCore\Api\TrackingPollerInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Cron entry point that drains the tracking-poll queue.
 *
 * Scheduled every 5 minutes (see etc/crontab.xml). Reads the per-run budget
 * from `shubo_shipping/poller/max_shipments_per_run` and delegates the real
 * work to {@see TrackingPollerInterface::drainBatch()}.
 *
 * Exceptions are caught and logged — Magento's cron queue is a shared
 * resource and a single bad run must not poison future invocations. The
 * next tick will see fresh shipments in `shubo_shipping_shipment` and try
 * again; idempotent because the poller only saves append-only events and
 * updates in-place fields on the shipment row.
 */
class RunPoller
{
    private const CONFIG_MAX_SHIPMENTS_PER_RUN = 'shubo_shipping/poller/max_shipments_per_run';
    private const DEFAULT_MAX_SHIPMENTS_PER_RUN = 500;

    public function __construct(
        private readonly TrackingPollerInterface $poller,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function execute(): void
    {
        $max = $this->readMaxShipments();
        try {
            $polled = $this->poller->drainBatch($max);
            $this->logger->logCronRun(
                'shubo_shipping.poller.run',
                $polled,
                ['max_shipments' => $max],
            );
        } catch (\Throwable $e) {
            $this->logger->logDispatchFailed('shubo_shipping.poller', 'drainBatch', $e);
        }
    }

    private function readMaxShipments(): int
    {
        $value = $this->scopeConfig->getValue(self::CONFIG_MAX_SHIPMENTS_PER_RUN);
        if ($value === null || $value === '') {
            return self::DEFAULT_MAX_SHIPMENTS_PER_RUN;
        }
        $int = (int)$value;
        return $int > 0 ? $int : self::DEFAULT_MAX_SHIPMENTS_PER_RUN;
    }
}
