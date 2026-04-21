<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Fake;

use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterfaceFactory as RealFactory;

/**
 * Named fake factory that hands out fresh {@see InMemoryDeadLetterEntry}
 * instances. Replaces {@see RealFactory} in unit tests so they never
 * depend on the ObjectManager plumbing — fixes
 * BUG-SHIPPINGCORE-DLQ-TEST-1.
 *
 * Extends the real factory so PHP's type system accepts it wherever the
 * real class is typed in a constructor. The parent constructor is
 * bypassed (no ObjectManager needed); `create()` is overridden to
 * return a fresh {@see InMemoryDeadLetterEntry}.
 *
 * Pattern mirrors {@see InMemoryShipmentEvent} and the BUG-TOOLING-1 fix.
 */
class InMemoryDeadLetterEntryFactory extends RealFactory
{
    public function __construct()
    {
        // Intentionally skip parent::__construct — we never use the
        // ObjectManager; `create()` instantiates the fake directly.
    }

    /**
     * @param array<string, mixed> $data Ignored; present to mirror the
     *                                   generated factory signature.
     */
    public function create(array $data = []): DeadLetterEntryInterface
    {
        return new InMemoryDeadLetterEntry();
    }
}
