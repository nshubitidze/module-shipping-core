<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

/**
 * Per-carrier circuit breaker.
 *
 * States are closed / open / half_open (see
 * {@see \Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface}).
 *
 * @api
 */
interface CircuitBreakerInterface
{
    /**
     * Execute a callable guarded by the breaker. Records success or
     * failure and updates state accordingly.
     *
     * @param string   $carrierCode
     * @param callable $fn
     * @return mixed The callable's return value.
     * @throws \Shubo\ShippingCore\Exception\CircuitOpenException
     */
    public function execute(string $carrierCode, callable $fn): mixed;

    /**
     * Current breaker state for the carrier. Returns one of the
     * {@see \Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface}
     * STATE_* constants.
     *
     * @param string $carrierCode
     * @return string
     */
    public function stateOf(string $carrierCode): string;

    /**
     * Force breaker state (admin-only).
     *
     * @param string $carrierCode
     * @param string $state
     * @param string $adminNote
     * @return void
     */
    public function forceState(string $carrierCode, string $state, string $adminNote): void;

    /**
     * Proactively flip OPEN breakers whose cooldown has elapsed to HALF_OPEN.
     *
     * {@see self::execute()} already performs this transition lazily on the
     * next guarded call, but fully idle carriers would otherwise stay "open"
     * on admin dashboards long after they have recovered. Called by the
     * `shubo_shipping_reap_breakers` cron (design-doc §10.4).
     *
     * Implementations must iterate the OPEN rows whose `cooldown_until <= now`
     * and flip each to HALF_OPEN, returning the total count reaped.
     *
     * @return int Number of breakers reaped.
     */
    public function reapExpired(): int;
}
