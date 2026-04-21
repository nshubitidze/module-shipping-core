<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api\Data;

use Magento\Framework\ObjectManagerInterface;
use Shubo\ShippingCore\Model\Data\DeadLetterEntry;

/**
 * Factory for {@see DeadLetterEntryInterface}.
 *
 * Magento's di:compile pipeline normally produces this under
 * `generated/code/`. We ship an explicit hand-written copy here for the same
 * reasons {@see \Shubo\ShippingCore\Model\Data\ShipmentFactory} is hand-written:
 *
 *   - Unit tests that don't boot the code generator can still load the class
 *     (fixes BUG-SHIPPINGCORE-DLQ-TEST-1 — the generated copy is absent
 *     whenever `generated/` has been wiped, e.g. after `setup:di:compile`).
 *   - The module compiles standalone outside a Magento install, preserving
 *     the open-source-first invariant from CLAUDE.md.
 *
 * Semantics mirror the generator output exactly — `create()` delegates to the
 * ObjectManager and accepts optional constructor arguments.
 *
 * @api
 */
class DeadLetterEntryInterfaceFactory
{
    /**
     * @param ObjectManagerInterface $objectManager
     * @param class-string           $instanceName
     */
    public function __construct(
        protected readonly ObjectManagerInterface $objectManager,
        protected readonly string $instanceName = DeadLetterEntry::class,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): DeadLetterEntryInterface
    {
        /** @var DeadLetterEntryInterface $instance */
        $instance = $this->objectManager->create($this->instanceName, $data);
        return $instance;
    }
}
