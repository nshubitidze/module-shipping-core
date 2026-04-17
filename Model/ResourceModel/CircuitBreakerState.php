<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;

/**
 * Resource model for {@see \Shubo\ShippingCore\Model\Data\CircuitBreakerState}.
 *
 * PK is carrier_code (no identity column).
 */
class CircuitBreakerState extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(CircuitBreakerStateInterface::TABLE, CircuitBreakerStateInterface::FIELD_CARRIER_CODE);
        $this->_isPkAutoIncrement = false;
    }

    public function getIdFieldName(): string
    {
        return CircuitBreakerStateInterface::FIELD_CARRIER_CODE;
    }
}
