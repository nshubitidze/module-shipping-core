<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Data;

use Magento\Framework\Model\AbstractModel;
use Shubo\ShippingCore\Model\ResourceModel\RateLimitState as RateLimitStateResource;

/**
 * Per-carrier rate-limit window state (DB fallback path for the token
 * bucket). Not exposed via Api/ — internal to the rate limiter.
 *
 * Primary backing is Redis via {@see \Magento\Framework\App\CacheInterface};
 * this model is used only when the cache backend is unavailable.
 */
class RateLimitState extends AbstractModel
{
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_WINDOW_START = 'window_start';
    public const FIELD_TOKENS_USED = 'tokens_used';
    public const FIELD_UPDATED_AT = 'updated_at';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(RateLimitStateResource::class);
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

    public function getWindowStart(): ?string
    {
        $v = $this->getData(self::FIELD_WINDOW_START);
        return $v === null ? null : (string)$v;
    }

    public function setWindowStart(string $timestamp): self
    {
        $this->setData(self::FIELD_WINDOW_START, $timestamp);
        return $this;
    }

    public function getTokensUsed(): int
    {
        return (int)$this->getData(self::FIELD_TOKENS_USED);
    }

    public function setTokensUsed(int $used): self
    {
        $this->setData(self::FIELD_TOKENS_USED, $used);
        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        $v = $this->getData(self::FIELD_UPDATED_AT);
        return $v === null ? null : (string)$v;
    }
}
