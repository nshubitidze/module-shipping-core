<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterfaceFactory;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Publishes dispatch-failure records.
 *
 * Two persistence paths, both best-effort:
 *
 *   1. Durable DB row in `shubo_shipping_dead_letter` via the repository.
 *      This is the source of truth for the Phase 10 CLI commands
 *      (`shubo:shipping:dlq:list`, `:reprocess`). A DB failure is logged
 *      but does not block the queue publish.
 *
 *   2. Queue publish on `shubo_shipping_dispatch_failed` for async processing.
 *      Retained for backward compatibility with the Phase 3 scaffolding.
 *      A publisher failure is logged and swallowed — the DB row is the
 *      durable record that survives a broker outage.
 */
class DeadLetterPublisher
{
    public const TOPIC = 'shubo_shipping_dispatch_failed';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly DeadLetterRepositoryInterface $repository,
        private readonly DeadLetterEntryInterfaceFactory $entryFactory,
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
        $payload = [
            'shipment_id' => $shipmentId,
            'carrier_code' => $carrierCode,
            'operation' => $operation,
            'reason' => $reason,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $this->persistDurable($shipmentId, $carrierCode, $operation, $reason, $payload);
        $this->publishToQueue($payload);

        $this->logger->logDispatchFailed(
            $carrierCode,
            $operation,
            new \RuntimeException('published to DLQ: ' . $reason),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistDurable(
        int $shipmentId,
        string $carrierCode,
        string $operation,
        string $reason,
        array $payload,
    ): void {
        try {
            /** @var DeadLetterEntryInterface $entry */
            $entry = $this->entryFactory->create();
            $entry->setSource(DeadLetterEntryInterface::SOURCE_DISPATCH);
            $entry->setCarrierCode($carrierCode === '' ? null : $carrierCode);
            $entry->setShipmentId($shipmentId > 0 ? $shipmentId : null);
            $entry->setPayload($payload);
            $entry->setErrorClass(\RuntimeException::class);
            $entry->setErrorMessage(sprintf('%s: %s', $operation, $reason));
            $this->repository->save($entry);
        } catch (CouldNotSaveException | \Throwable $e) {
            // Durable write is best-effort; the queue publish below is the
            // fallback. Log via StructuredLogger so the failure is not silent.
            $this->logger->logDispatchFailed(
                $carrierCode,
                'dlq.persist_durable',
                $e,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publishToQueue(array $payload): void
    {
        $encoded = (string)json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        try {
            $this->publisher->publish(self::TOPIC, $encoded);
        } catch (\Throwable $e) {
            // Queue publish is the legacy path; the DB row above is durable.
            $this->logger->logDispatchFailed(
                (string)($payload['carrier_code'] ?? 'unknown'),
                'dlq.publish_queue',
                $e,
            );
        }
    }
}
