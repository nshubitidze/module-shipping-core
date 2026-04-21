<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Model\Data\DeadLetterEntry;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry as DeadLetterEntryResource;

/**
 * Collection for {@see \Shubo\ShippingCore\Model\Data\DeadLetterEntry}.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = DeadLetterEntryInterface::FIELD_DLQ_ID;

    protected function _construct()
    {
        $this->_init(DeadLetterEntry::class, DeadLetterEntryResource::class);
    }
}
