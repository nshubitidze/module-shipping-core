<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry;

use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for {@see Collection}.
 *
 * Hand-written companion to the Magento-generated factory — shipped so the
 * class exists both standalone (open-source-first invariant) and inside the
 * duka container when `generated/code/` has been wiped. Fixes
 * BUG-SHIPPINGCORE-DLQ-TEST-1 for {@see \Shubo\ShippingCore\Model\Resilience\DeadLetterRepository}.
 *
 * @api
 */
class CollectionFactory
{
    /**
     * @param ObjectManagerInterface $objectManager
     * @param class-string           $instanceName
     */
    public function __construct(
        protected readonly ObjectManagerInterface $objectManager,
        protected readonly string $instanceName = Collection::class,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): Collection
    {
        /** @var Collection $instance */
        $instance = $this->objectManager->create($this->instanceName, $data);
        return $instance;
    }
}
