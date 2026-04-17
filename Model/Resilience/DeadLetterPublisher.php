<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\MessageQueue\PublisherInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Publishes dispatch-failure records to the `shubo_shipping_dispatch_failed`
 * topic so an admin can retry later. Payload is a JSON string so the queue
 * consumer can decode it without a bespoke DTO.
 */
class DeadLetterPublisher
{
    public const TOPIC = 'shubo_shipping_dispatch_failed';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * @param int    $shipmentId   Persisted shipment row ID (may be 0 if the
     *                             row was never created — e.g. DB failure).
     * @param string $carrierCode
     * @param string $operation    Which adapter method failed.
     * @param string $reason       Short human reason (last exception message).
     */
    public function publish(int $shipmentId, string $carrierCode, string $operation, string $reason): void
    {
        $payload = (string)json_encode(
            [
                'shipment_id' => $shipmentId,
                'carrier_code' => $carrierCode,
                'operation' => $operation,
                'reason' => $reason,
                'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $this->publisher->publish(self::TOPIC, $payload);
        $this->logger->logDispatchFailed(
            $carrierCode,
            $operation,
            new \RuntimeException('published to DLQ: ' . $reason),
        );
    }
}
