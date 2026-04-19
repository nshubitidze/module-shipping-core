<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Per-carrier circuit breaker.
 *
 * Implements the three-state machine from design-doc §9.2 — closed -> open
 * -> half_open -> closed. State is persisted in `shubo_shipping_circuit_breaker`
 * so it survives Redis flushes and can be inspected from the admin UI.
 *
 * Config paths (with inline defaults):
 * - `shubo_shipping/breaker/failure_threshold` (5)
 * - `shubo_shipping/breaker/failure_window_seconds` (120)
 * - `shubo_shipping/breaker/cooldown_seconds` (60)
 * - `shubo_shipping/breaker/success_threshold` (3)
 *
 * When transitioning to open, dispatches `shubo_shipping_carrier_breaker_opened`
 * so admin alerting can fire.
 */
class CircuitBreaker implements CircuitBreakerInterface
{
    private const CONFIG_FAILURE_THRESHOLD = 'shubo_shipping/breaker/failure_threshold';
    private const CONFIG_FAILURE_WINDOW = 'shubo_shipping/breaker/failure_window_seconds';
    private const CONFIG_COOLDOWN = 'shubo_shipping/breaker/cooldown_seconds';
    private const CONFIG_SUCCESS_THRESHOLD = 'shubo_shipping/breaker/success_threshold';

    private const DEFAULT_FAILURE_THRESHOLD = 5;
    private const DEFAULT_FAILURE_WINDOW = 120;
    private const DEFAULT_COOLDOWN = 60;
    private const DEFAULT_SUCCESS_THRESHOLD = 3;

    private const VALID_STATES = [
        CircuitBreakerStateInterface::STATE_CLOSED,
        CircuitBreakerStateInterface::STATE_OPEN,
        CircuitBreakerStateInterface::STATE_HALF_OPEN,
    ];

    public function __construct(
        private readonly CircuitBreakerStateRepository $repository,
        private readonly EventManager $eventManager,
        private readonly StructuredLogger $logger,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
    ) {
    }

    public function execute(string $carrierCode, callable $fn): mixed
    {
        $state = $this->repository->getByCarrierCode($carrierCode);
        $now = $this->nowTs();

        if ($state->getState() === CircuitBreakerStateInterface::STATE_OPEN) {
            $cooldownUntil = $this->parseTs($state->getCooldownUntil());
            if ($cooldownUntil !== null && $cooldownUntil > $now) {
                throw CircuitOpenException::create(
                    (string)__('Circuit open for carrier %1', $carrierCode),
                );
            }
            // Cooldown has elapsed — transition to half_open and allow the trial call.
            $previousState = $state->getState();
            $state->setState(CircuitBreakerStateInterface::STATE_HALF_OPEN);
            $state->setSuccessCountSinceHalfopen(0);
            $this->repository->save($state);
            $this->logger->logBreakerTransition(
                $carrierCode,
                $previousState,
                CircuitBreakerStateInterface::STATE_HALF_OPEN,
            );
        }

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->recordFailure($state, $now);
            throw $e;
        }

        $this->recordSuccess($state, $now);
        return $result;
    }

    public function stateOf(string $carrierCode): string
    {
        return $this->repository->getByCarrierCode($carrierCode)->getState();
    }

    /**
     * {@inheritDoc}
     *
     * Implementation note: fully idle carriers (e.g. a Trackings.ge mailbox
     * that sees no new shipments overnight) would otherwise stay "open" on
     * admin dashboards long after they have recovered, because
     * {@see self::execute()} only performs the transition on the next guarded
     * call. Called from {@see \Shubo\ShippingCore\Cron\ReapCircuitBreakers}
     * every 10 minutes.
     */
    public function reapExpired(): int
    {
        $nowTs = $this->nowTs();
        $nowGmt = $this->formatTs($nowTs);
        $expired = $this->repository->findExpiredOpenStates($nowGmt);
        $reaped = 0;
        foreach ($expired as $state) {
            $previous = $state->getState();
            if ($previous !== CircuitBreakerStateInterface::STATE_OPEN) {
                // Row changed state between query and loop — skip.
                continue;
            }
            $state->setState(CircuitBreakerStateInterface::STATE_HALF_OPEN);
            $state->setSuccessCountSinceHalfopen(0);
            $this->repository->save($state);
            $this->logger->logBreakerTransition(
                $state->getCarrierCode(),
                $previous,
                CircuitBreakerStateInterface::STATE_HALF_OPEN,
                ['reason' => 'cron_reap'],
            );
            $reaped++;
        }
        return $reaped;
    }

    public function forceState(string $carrierCode, string $state, string $adminNote): void
    {
        if (!in_array($state, self::VALID_STATES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid circuit breaker state "%s".', $state)
            );
        }

        $row = $this->repository->getByCarrierCode($carrierCode);
        $previous = $row->getState();
        $row->setState($state);
        $row->setFailureCount(0);
        $row->setSuccessCountSinceHalfopen(0);
        if ($state === CircuitBreakerStateInterface::STATE_OPEN) {
            $row->setOpenedAt($this->formatTs($this->nowTs()));
            $row->setCooldownUntil($this->formatTs($this->nowTs() + $this->getCooldownSeconds()));
        } else {
            $row->setOpenedAt(null);
            $row->setCooldownUntil(null);
        }

        $this->repository->save($row);
        $this->logger->logBreakerTransition(
            $carrierCode,
            $previous,
            $state,
            ['forced' => true, 'admin_note' => $adminNote],
        );
    }

    private function recordSuccess(CircuitBreakerStateInterface $state, int $now): void
    {
        $carrierCode = $state->getCarrierCode();

        if ($state->getState() === CircuitBreakerStateInterface::STATE_HALF_OPEN) {
            $state->setSuccessCountSinceHalfopen($state->getSuccessCountSinceHalfopen() + 1);
            $state->setLastSuccessAt($this->formatTs($now));
            if ($state->getSuccessCountSinceHalfopen() >= $this->getSuccessThreshold()) {
                $previous = $state->getState();
                $state->setState(CircuitBreakerStateInterface::STATE_CLOSED);
                $state->setFailureCount(0);
                $state->setSuccessCountSinceHalfopen(0);
                $state->setOpenedAt(null);
                $state->setCooldownUntil(null);
                $this->logger->logBreakerTransition(
                    $carrierCode,
                    $previous,
                    CircuitBreakerStateInterface::STATE_CLOSED,
                );
            }
            $this->repository->save($state);
            return;
        }

        // Closed state: tick last_success_at; reset failure counter if the rolling window rolled over.
        $lastFailure = $this->parseTs($state->getLastFailureAt());
        if ($lastFailure !== null && ($now - $lastFailure) > $this->getFailureWindow()) {
            $state->setFailureCount(0);
        }
        $state->setLastSuccessAt($this->formatTs($now));
        $this->repository->save($state);
    }

    private function recordFailure(CircuitBreakerStateInterface $state, int $now): void
    {
        $carrierCode = $state->getCarrierCode();

        if ($state->getState() === CircuitBreakerStateInterface::STATE_HALF_OPEN) {
            $previous = $state->getState();
            $state->setState(CircuitBreakerStateInterface::STATE_OPEN);
            $state->setOpenedAt($this->formatTs($now));
            $state->setCooldownUntil($this->formatTs($now + ($this->getCooldownSeconds() * 2)));
            $state->setFailureCount(0);
            $state->setSuccessCountSinceHalfopen(0);
            $state->setLastFailureAt($this->formatTs($now));
            $this->repository->save($state);
            $this->logger->logBreakerTransition(
                $carrierCode,
                $previous,
                CircuitBreakerStateInterface::STATE_OPEN,
                ['reason' => 'half_open_trial_failed', 'cooldown_seconds' => $this->getCooldownSeconds() * 2],
            );
            $this->eventManager->dispatch(
                'shubo_shipping_carrier_breaker_opened',
                ['carrier_code' => $carrierCode, 'opened_at' => $now],
            );
            return;
        }

        // Closed state.
        $lastFailure = $this->parseTs($state->getLastFailureAt());
        if ($lastFailure !== null && ($now - $lastFailure) > $this->getFailureWindow()) {
            // Window rolled; this failure is the first of a new window.
            $state->setFailureCount(1);
        } else {
            $state->setFailureCount($state->getFailureCount() + 1);
        }
        $state->setLastFailureAt($this->formatTs($now));

        if ($state->getFailureCount() >= $this->getFailureThreshold()) {
            $previous = $state->getState();
            $state->setState(CircuitBreakerStateInterface::STATE_OPEN);
            $state->setOpenedAt($this->formatTs($now));
            $state->setCooldownUntil($this->formatTs($now + $this->getCooldownSeconds()));
            $state->setFailureCount(0);
            $this->repository->save($state);
            $this->logger->logBreakerTransition(
                $carrierCode,
                $previous,
                CircuitBreakerStateInterface::STATE_OPEN,
                ['reason' => 'failure_threshold_reached', 'cooldown_seconds' => $this->getCooldownSeconds()],
            );
            $this->eventManager->dispatch(
                'shubo_shipping_carrier_breaker_opened',
                ['carrier_code' => $carrierCode, 'opened_at' => $now],
            );
            return;
        }

        $this->repository->save($state);
    }

    private function nowTs(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }

    private function formatTs(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function parseTs(?string $ts): ?int
    {
        if ($ts === null || $ts === '') {
            return null;
        }
        $parsed = strtotime($ts . ' UTC');
        return $parsed === false ? null : $parsed;
    }

    private function getFailureThreshold(): int
    {
        return $this->readConfigInt(self::CONFIG_FAILURE_THRESHOLD, self::DEFAULT_FAILURE_THRESHOLD);
    }

    private function getFailureWindow(): int
    {
        return $this->readConfigInt(self::CONFIG_FAILURE_WINDOW, self::DEFAULT_FAILURE_WINDOW);
    }

    private function getCooldownSeconds(): int
    {
        return $this->readConfigInt(self::CONFIG_COOLDOWN, self::DEFAULT_COOLDOWN);
    }

    private function getSuccessThreshold(): int
    {
        return $this->readConfigInt(self::CONFIG_SUCCESS_THRESHOLD, self::DEFAULT_SUCCESS_THRESHOLD);
    }

    private function readConfigInt(string $path, int $default): int
    {
        $value = $this->scopeConfig->getValue($path);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int)$value;
    }
}
