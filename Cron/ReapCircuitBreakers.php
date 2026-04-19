<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Cron;

use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Cron entry point that proactively flips expired OPEN breakers to HALF_OPEN.
 *
 * Scheduled every 10 minutes (see etc/crontab.xml). Delegates to
 * {@see CircuitBreakerInterface::reapExpired()} which iterates
 * {@see \Shubo\ShippingCore\Model\Resilience\CircuitBreakerStateRepository::findExpiredOpenStates()}.
 *
 * The {@see CarrierRegistryInterface} is injected so this cron is discoverable
 * from the admin "Registered Carriers" audit page (Phase 9), but the reap
 * itself is driven by the DB — breakers for carriers that have been un-enabled
 * at config level still get reaped so the admin dashboard is accurate.
 *
 * Exceptions are logged and swallowed so a single bad reap does not poison
 * the cron queue.
 */
class ReapCircuitBreakers
{
    public function __construct(
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly CarrierRegistryInterface $registry,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function execute(): void
    {
        try {
            $reaped = $this->circuitBreaker->reapExpired();
            $this->logger->logCronRun('shubo_shipping.reap_breakers', $reaped);
            // Touch the registry so DI eagerly loads it — ensures any future
            // registry-level lazy init (e.g. Phase 9 audit log) runs on the
            // cron schedule, not on the first checkout request.
            $this->registry->registeredCodes();
        } catch (\Throwable $e) {
            $this->logger->logDispatchFailed('shubo_shipping.breaker.reap', 'reapExpired', $e);
        }
    }
}
