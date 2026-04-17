<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel\CircuitBreakerState;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Shubo\ShippingCore\Model\Data\CircuitBreakerState as CircuitBreakerStateModel;
use Shubo\ShippingCore\Model\ResourceModel\CircuitBreakerState as CircuitBreakerStateResource;

/**
 * Collection for {@see CircuitBreakerStateModel}.
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
        $this->_init(CircuitBreakerStateModel::class, CircuitBreakerStateResource::class);
    }
}
