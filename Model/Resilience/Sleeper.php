<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

/**
 * Thin wrapper around {@see usleep()} so retry/rate-limiter delays can be
 * mocked in unit tests. Production code injects the real Sleeper; unit tests
 * inject a MockObject that captures sleep durations.
 */
class Sleeper
{
    /**
     * Sleep for the given number of milliseconds.
     */
    public function sleepMs(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        usleep($ms * 1000);
    }
}
