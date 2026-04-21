<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\Model\AbstractModel;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry as DeadLetterEntryResource;

/**
 * Active-record model for a DLQ row.
 *
 * `payload_json` is transparently encoded/decoded by the resource model so
 * callers always deal with associative arrays through {@see self::getPayload()}.
 */
class DeadLetterEntry extends AbstractModel implements DeadLetterEntryInterface
{
    protected function _construct()
    {
        $this->_init(DeadLetterEntryResource::class);
        $this->setIdFieldName(self::FIELD_DLQ_ID);
    }

    public function getDlqId(): ?int
    {
        $v = $this->getData(self::FIELD_DLQ_ID);
        return $v === null ? null : (int)$v;
    }

    public function getSource(): string
    {
        return (string)$this->getData(self::FIELD_SOURCE);
    }

    public function setSource(string $source): self
    {
        $this->setData(self::FIELD_SOURCE, $source);
        return $this;
    }

    public function getCarrierCode(): ?string
    {
        $v = $this->getData(self::FIELD_CARRIER_CODE);
        return $v === null ? null : (string)$v;
    }

    public function setCarrierCode(?string $carrierCode): self
    {
        $this->setData(self::FIELD_CARRIER_CODE, $carrierCode);
        return $this;
    }

    public function getShipmentId(): ?int
    {
        $v = $this->getData(self::FIELD_SHIPMENT_ID);
        return $v === null ? null : (int)$v;
    }

    public function setShipmentId(?int $shipmentId): self
    {
        $this->setData(self::FIELD_SHIPMENT_ID, $shipmentId);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        $v = $this->getData(self::FIELD_PAYLOAD_JSON);
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $decoded = json_decode($v, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): self
    {
        $this->setData(self::FIELD_PAYLOAD_JSON, $payload);
        return $this;
    }

    public function getErrorClass(): string
    {
        return (string)$this->getData(self::FIELD_ERROR_CLASS);
    }

    public function setErrorClass(string $errorClass): self
    {
        $this->setData(self::FIELD_ERROR_CLASS, $errorClass);
        return $this;
    }

    public function getErrorMessage(): string
    {
        return (string)$this->getData(self::FIELD_ERROR_MESSAGE);
    }

    public function setErrorMessage(string $errorMessage): self
    {
        $this->setData(self::FIELD_ERROR_MESSAGE, $errorMessage);
        return $this;
    }

    public function getFailedAt(): ?string
    {
        $v = $this->getData(self::FIELD_FAILED_AT);
        return $v === null ? null : (string)$v;
    }

    public function getReprocessedAt(): ?string
    {
        $v = $this->getData(self::FIELD_REPROCESSED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setReprocessedAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_REPROCESSED_AT, $timestamp);
        return $this;
    }

    public function getReprocessAttempts(): int
    {
        return (int)$this->getData(self::FIELD_REPROCESS_ATTEMPTS);
    }

    public function setReprocessAttempts(int $attempts): self
    {
        $this->setData(self::FIELD_REPROCESS_ATTEMPTS, $attempts);
        return $this;
    }
}
