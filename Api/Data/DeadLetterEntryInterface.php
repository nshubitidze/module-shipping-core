<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

/**
 * Dead-letter entry data interface.
 *
 * Rows in {@see self::TABLE} are durable records of dispatch, webhook,
 * poll, or reconcile failures that exceeded their retry budget. Operators
 * inspect + replay via the `shubo:shipping:dlq:*` CLI commands (Phase 10).
 *
 * @api
 */
interface DeadLetterEntryInterface
{
    public const TABLE = 'shubo_shipping_dead_letter';

    public const FIELD_DLQ_ID = 'dlq_id';
    public const FIELD_SOURCE = 'source';
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_SHIPMENT_ID = 'shipment_id';
    public const FIELD_PAYLOAD_JSON = 'payload_json';
    public const FIELD_ERROR_CLASS = 'error_class';
    public const FIELD_ERROR_MESSAGE = 'error_message';
    public const FIELD_FAILED_AT = 'failed_at';
    public const FIELD_REPROCESSED_AT = 'reprocessed_at';
    public const FIELD_REPROCESS_ATTEMPTS = 'reprocess_attempts';

    public const SOURCE_DISPATCH = 'dispatch';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_POLL = 'poll';
    public const SOURCE_RECONCILE = 'reconcile';

    public function getDlqId(): ?int;

    public function getSource(): string;

    public function setSource(string $source): self;

    public function getCarrierCode(): ?string;

    public function setCarrierCode(?string $carrierCode): self;

    public function getShipmentId(): ?int;

    public function setShipmentId(?int $shipmentId): self;

    /**
     * Payload as decoded array (repository handles JSON encode/decode).
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): self;

    public function getErrorClass(): string;

    public function setErrorClass(string $errorClass): self;

    public function getErrorMessage(): string;

    public function setErrorMessage(string $errorMessage): self;

    public function getFailedAt(): ?string;

    public function getReprocessedAt(): ?string;

    public function setReprocessedAt(?string $timestamp): self;

    public function getReprocessAttempts(): int;

    public function setReprocessAttempts(int $attempts): self;
}
