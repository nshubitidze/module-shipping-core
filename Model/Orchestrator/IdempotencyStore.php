<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Orchestrator;

use Magento\Framework\App\ResourceConnection;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Idempotency primitive for shipment creation.
 *
 * `findExisting()` looks up a shipment by `(carrier_code, client_tracking_code)`
 * using `SELECT ... FOR UPDATE`. Callers are expected to wrap this in their
 * own transaction so the row lock is held until the shipment INSERT (or the
 * decision to reuse the existing row) completes. See design-doc §9.1.
 *
 * Phase 4's ShipmentOrchestrator wraps this in a full transaction +
 * create-if-missing. Phase 3 provides the primitive and proves it emits the
 * correct SQL.
 */
class IdempotencyStore
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Returns the existing shipment_id for the (carrier_code, client_tracking_code)
     * pair, or null when no row exists.
     */
    public function findExisting(string $carrierCode, string $clientTrackingCode): ?int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(ShipmentInterface::TABLE);
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ? AND %s = ? FOR UPDATE',
            $connection->quoteIdentifier(ShipmentInterface::FIELD_SHIPMENT_ID),
            $connection->quoteIdentifier($table),
            $connection->quoteIdentifier(ShipmentInterface::FIELD_CARRIER_CODE),
            $connection->quoteIdentifier(ShipmentInterface::FIELD_CLIENT_TRACKING_CODE),
        );

        try {
            $result = $connection->fetchOne($sql, [$carrierCode, $clientTrackingCode]);
        } catch (\Throwable $e) {
            $this->logger->logDispatchFailed($carrierCode, 'idempotency_lookup', $e);
            throw $e;
        }

        if ($result === false || $result === null || $result === '') {
            return null;
        }
        return (int)$result;
    }
}
