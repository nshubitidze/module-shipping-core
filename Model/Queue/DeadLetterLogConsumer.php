<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Queue;

use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Placeholder consumer for the `shubo_shipping_dispatch_failed` queue.
 *
 * Phase 3 only: decodes the payload and writes a structured log line so
 * admins can see failures in var/log/shubo_shipping.log. Phase 10 replaces
 * this with an admin-grid-backed retry workflow.
 */
class DeadLetterLogConsumer
{
    public function __construct(
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Queue entry point: the dispatch payload is a JSON string.
     */
    public function process(string $payload): void
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $data = ['raw' => $payload];
        }

        $carrierCode = isset($data['carrier_code']) && is_string($data['carrier_code'])
            ? $data['carrier_code']
            : 'unknown';
        $operation = isset($data['operation']) && is_string($data['operation'])
            ? $data['operation']
            : 'unknown';
        $reason = isset($data['reason']) && is_string($data['reason'])
            ? $data['reason']
            : 'no reason supplied';

        $this->logger->logDispatchFailed(
            $carrierCode,
            $operation,
            new \RuntimeException($reason),
        );
    }
}
