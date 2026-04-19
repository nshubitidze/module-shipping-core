<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Fake;

use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;

/**
 * Trivial in-memory implementation of {@see CircuitBreakerInterface} used
 * by tests in Phase 4+ where the orchestrator just needs a breaker that
 * reports a programmable state.
 *
 * This is NOT used by {@see CircuitBreakerTest} — that test exercises the
 * real production class.
 */
class InMemoryCircuitBreaker implements CircuitBreakerInterface
{
    /** @var array<string, string> carrier_code => state */
    private array $states = [];

    public function execute(string $carrierCode, callable $fn): mixed
    {
        if ($this->stateOf($carrierCode) === CircuitBreakerStateInterface::STATE_OPEN) {
            throw CircuitOpenException::create('Circuit open for carrier ' . $carrierCode);
        }
        return $fn();
    }

    public function stateOf(string $carrierCode): string
    {
        return $this->states[$carrierCode] ?? CircuitBreakerStateInterface::STATE_CLOSED;
    }

    public function forceState(string $carrierCode, string $state, string $adminNote): void
    {
        $this->states[$carrierCode] = $state;
    }

    public function reapExpired(): int
    {
        // In-memory fake has no cooldown_until column — the real reap logic
        // is covered by {@see \Shubo\ShippingCore\Test\Unit\Model\Resilience\CircuitBreakerTest}.
        // Returning 0 keeps the interface contract honest without pretending
        // to simulate expiry semantics the fake cannot model.
        return 0;
    }
}
