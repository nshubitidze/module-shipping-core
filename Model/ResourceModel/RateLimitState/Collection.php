<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel\RateLimitState;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Shubo\ShippingCore\Model\Data\RateLimitState as RateLimitStateModel;
use Shubo\ShippingCore\Model\ResourceModel\RateLimitState as RateLimitStateResource;

/**
 * Collection for rate-limit state rows. Rarely used in hot paths (the
 * limiter goes through Redis or a direct SQL increment) but kept for
 * admin dashboards.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'carrier_code';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(RateLimitStateModel::class, RateLimitStateResource::class);
    }
}
