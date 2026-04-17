<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\Model\AbstractModel;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Model\ResourceModel\CircuitBreakerState as CircuitBreakerStateResource;

/**
 * Persisted circuit-breaker state for a single carrier.
 *
 * PK is the carrier_code (no surrogate auto-increment). Field semantics are
 * in {@see CircuitBreakerStateInterface}.
 */
class CircuitBreakerState extends AbstractModel implements CircuitBreakerStateInterface
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(CircuitBreakerStateResource::class);
        $this->setIdFieldName(self::FIELD_CARRIER_CODE);
    }

    public function getCarrierCode(): string
    {
        return (string)$this->getData(self::FIELD_CARRIER_CODE);
    }

    public function setCarrierCode(string $carrierCode): self
    {
        $this->setData(self::FIELD_CARRIER_CODE, $carrierCode);
        return $this;
    }

    public function getState(): string
    {
        $state = $this->getData(self::FIELD_STATE);
        return $state === null ? self::STATE_CLOSED : (string)$state;
    }

    public function setState(string $state): self
    {
        $this->setData(self::FIELD_STATE, $state);
        return $this;
    }

    public function getFailureCount(): int
    {
        return (int)$this->getData(self::FIELD_FAILURE_COUNT);
    }

    public function setFailureCount(int $count): self
    {
        $this->setData(self::FIELD_FAILURE_COUNT, $count);
        return $this;
    }

    public function getSuccessCountSinceHalfopen(): int
    {
        return (int)$this->getData(self::FIELD_SUCCESS_COUNT_SINCE_HALFOPEN);
    }

    public function setSuccessCountSinceHalfopen(int $count): self
    {
        $this->setData(self::FIELD_SUCCESS_COUNT_SINCE_HALFOPEN, $count);
        return $this;
    }

    public function getLastFailureAt(): ?string
    {
        $v = $this->getData(self::FIELD_LAST_FAILURE_AT);
        return $v === null ? null : (string)$v;
    }

    public function setLastFailureAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_LAST_FAILURE_AT, $timestamp);
        return $this;
    }

    public function getLastSuccessAt(): ?string
    {
        $v = $this->getData(self::FIELD_LAST_SUCCESS_AT);
        return $v === null ? null : (string)$v;
    }

    public function setLastSuccessAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_LAST_SUCCESS_AT, $timestamp);
        return $this;
    }

    public function getOpenedAt(): ?string
    {
        $v = $this->getData(self::FIELD_OPENED_AT);
        return $v === null ? null : (string)$v;
    }

    public function setOpenedAt(?string $timestamp): self
    {
        $this->setData(self::FIELD_OPENED_AT, $timestamp);
        return $this;
    }

    public function getCooldownUntil(): ?string
    {
        $v = $this->getData(self::FIELD_COOLDOWN_UNTIL);
        return $v === null ? null : (string)$v;
    }

    public function setCooldownUntil(?string $timestamp): self
    {
        $this->setData(self::FIELD_COOLDOWN_UNTIL, $timestamp);
        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        $v = $this->getData(self::FIELD_UPDATED_AT);
        return $v === null ? null : (string)$v;
    }
}
