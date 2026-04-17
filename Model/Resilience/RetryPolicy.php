<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Shubo\ShippingCore\Exception\AuthException;
use Shubo\ShippingCore\Exception\NetworkException;
use Shubo\ShippingCore\Exception\RateLimitedException;
use Shubo\ShippingCore\Exception\TransientHttpException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Retry policy with exponential backoff and jitter for carrier calls.
 *
 * Backoff formula (design-doc §9.3):
 * - Base delays 1s, 2s, 4s, 8s, 16s for attempts 1..5
 * - ±500ms jitter
 * - Capped at 60s per attempt
 * - Max attempts: 5
 *
 * Classification:
 * - TransientHttpException(5xx)   -> retry
 * - RateLimitedException (429)    -> retry; honor Retry-After if set
 * - NetworkException              -> retry
 * - AuthException                 -> DO NOT retry (creds/sig are wrong)
 * - TransientHttpException(4xx)   -> DO NOT retry (business error)
 * - Any other Throwable           -> DO NOT retry (conservative)
 *
 * On exhaustion the optional `$onExhausted` callback is invoked with the
 * last exception, then the exception is rethrown so the orchestrator can
 * mark the shipment failed and publish to the DLQ topic.
 */
class RetryPolicy
{
    private const MAX_ATTEMPTS = 5;
    private const BACKOFF_CAP_MS = 60_000;

    /** @var list<int> Base delays in ms for attempts 1..5. */
    private const BASE_DELAYS_MS = [1_000, 2_000, 4_000, 8_000, 16_000];

    public function __construct(
        private readonly Sleeper $sleeper,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Execute `$fn` with automatic retry. Returns the callable's return
     * value on success. Rethrows on max attempts exhaustion (after calling
     * `$onExhausted` if provided).
     *
     * @template T
     * @param string                       $carrierCode
     * @param string                       $operation
     * @param callable(int $attempt): T    $fn
     * @param (callable(\Throwable): void)|null $onExhausted
     * @return T
     * @throws \Throwable
     */
    public function execute(
        string $carrierCode,
        string $operation,
        callable $fn,
        ?callable $onExhausted = null,
    ): mixed {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $fn($attempt);
            } catch (\Throwable $e) {
                $decision = $this->classify($e);
                if ($decision === null) {
                    // Non-retryable: propagate immediately.
                    throw $e;
                }
                if ($attempt >= self::MAX_ATTEMPTS) {
                    if ($onExhausted !== null) {
                        $onExhausted($e);
                    }
                    throw $e;
                }
                $this->logger->logRetry($carrierCode, $operation, $attempt, $e->getMessage());
                // $decision is null when the classifier wants the default
                // backoff formula; otherwise it is the explicit Retry-After
                // in ms.
                $delayMs = self::computeBackoffMs(
                    $attempt,
                    $decision->retryAfterMs,
                    random_int(-500, 500),
                );
                $this->sleeper->sleepMs($delayMs);
            }
        }
        // Unreachable: the loop either returns, propagates, or exhausts.
        throw new \LogicException('RetryPolicy reached a branch that should be unreachable.');
    }

    /**
     * Classify a throwable. Returns a {@see RetryDecision} on retry, null
     * when the exception is not retryable.
     */
    private function classify(\Throwable $e): ?RetryDecision
    {
        if ($e instanceof AuthException) {
            return null;
        }
        if ($e instanceof RateLimitedException) {
            $retryAfter = $e->getRetryAfterSeconds();
            if ($retryAfter === null) {
                return new RetryDecision(null);
            }
            return new RetryDecision(max(0, $retryAfter) * 1000);
        }
        if ($e instanceof TransientHttpException) {
            return $e->getStatusCode() >= 500 ? new RetryDecision(null) : null;
        }
        if ($e instanceof NetworkException) {
            return new RetryDecision(null);
        }
        return null;
    }

    /**
     * Pure function: compute the sleep duration in ms for a given attempt.
     *
     * @param int      $attempt    1-based attempt number.
     * @param int|null $retryAfterMs When set (Retry-After header), this is
     *                               the target delay. Clamped to [0, 60000].
     * @param int      $jitterMs   Jitter offset in ms (test-time injected,
     *                               production uses random_int(-500, 500)).
     *
     * @internal Exposed static so it can be unit-tested directly without a
     *           fully-constructed policy instance.
     */
    public static function computeBackoffMs(int $attempt, ?int $retryAfterMs, int $jitterMs): int
    {
        if ($retryAfterMs !== null) {
            if ($retryAfterMs <= 0) {
                return 0;
            }
            return min($retryAfterMs, self::BACKOFF_CAP_MS);
        }
        // Select base: attempts 1..5 map to BASE_DELAYS_MS[0..4]; for any
        // larger attempt use the last base value, clamped at the cap.
        $idx = max(0, min(count(self::BASE_DELAYS_MS) - 1, $attempt - 1));
        $base = self::BASE_DELAYS_MS[$idx];
        $withJitter = $base + $jitterMs;
        if ($withJitter < 0) {
            $withJitter = 0;
        }
        return min($withJitter, self::BACKOFF_CAP_MS);
    }
}
