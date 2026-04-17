<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

/**
 * Classifier output for {@see RetryPolicy::classify()}. Presence of the
 * object signals "this exception is retryable"; the `retryAfterMs` property
 * holds the explicit delay (from Retry-After) or NULL to use the default
 * backoff formula.
 *
 * @internal
 */
class RetryDecision
{
    public function __construct(
        public readonly ?int $retryAfterMs,
    ) {
    }
}
