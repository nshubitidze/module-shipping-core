<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Fake;

use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry\Collection as DeadLetterEntryCollection;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry\CollectionFactory as RealCollectionFactory;

/**
 * Named fake replacement for the hand-written
 * {@see RealCollectionFactory}. Wraps a test-provided collection instance
 * and returns it from `create()`.
 *
 * Extends the real factory so PHP's type system is satisfied when the fake
 * is passed to {@see \Shubo\ShippingCore\Model\Resilience\DeadLetterRepository}.
 * The parent constructor is bypassed (we inject our own collection), and
 * `create()` is overridden to return the stored instance.
 *
 * Rationale (BUG-SHIPPINGCORE-DLQ-TEST-1):
 *   Before the fix, the DLQ tests called
 *   `$this->createMock(CollectionFactory::class)` which fails inside the
 *   duka container whenever `generated/code/` is missing the generated
 *   factory. We now ship a hand-written companion factory (so the class
 *   always exists) AND this stub so the tests can inject a pre-built
 *   collection without going through Magento's ObjectManager.
 */
class StubDeadLetterEntryCollectionFactory extends RealCollectionFactory
{
    public function __construct(
        private readonly DeadLetterEntryCollection $cannedCollection,
    ) {
        // Intentionally skip parent::__construct — we never use the
        // ObjectManager; `create()` returns the injected collection directly.
    }

    /**
     * @param array<string, mixed> $data Ignored; present to mirror the factory
     *                                   signature.
     */
    public function create(array $data = []): DeadLetterEntryCollection
    {
        return $this->cannedCollection;
    }
}
