<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Logging;

use Psr\Log\LoggerInterface;

/**
 * Structured logger for Shubo_ShippingCore.
 *
 * Wraps an injected {@see LoggerInterface} (the virtual-typed Monolog
 * instance that writes to var/log/shubo_shipping.log) and provides typed
 * helpers that emit a short human message plus a rich structured context
 * array. Monolog's default formatter serializes the context as JSON on the
 * same log line.
 *
 * Pattern mirrors {@see \Shubo\Payout}'s logger virtual-type wiring (see
 * Shubo_Payout etc/di.xml:42-55).
 */
class StructuredLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record a circuit-breaker state transition.
     *
     * @param array<string, mixed> $ctx
     */
    public function logBreakerTransition(string $carrierCode, string $from, string $to, array $ctx = []): void
    {
        $this->logger->info(
            'shubo_shipping.breaker.transition',
            $this->mergeContext(
                [
                    'event' => 'breaker_transition',
                    'carrier_code' => $carrierCode,
                    'from' => $from,
                    'to' => $to,
                ],
                $ctx,
            ),
        );
    }

    /**
     * Record a retry attempt.
     */
    public function logRetry(string $carrierCode, string $op, int $attempt, string $reason): void
    {
        $this->logger->warning(
            'shubo_shipping.retry',
            [
                'event' => 'retry_attempt',
                'carrier_code' => $carrierCode,
                'operation' => $op,
                'attempt' => $attempt,
                'reason' => $reason,
            ],
        );
    }

    /**
     * Record a rate-limit decision.
     */
    public function logRateLimit(string $carrierCode, int $tokensRemaining): void
    {
        $this->logger->info(
            'shubo_shipping.rate_limit',
            [
                'event' => 'rate_limit',
                'carrier_code' => $carrierCode,
                'tokens_remaining' => $tokensRemaining,
            ],
        );
    }

    /**
     * Record a dispatch failure after retry exhaustion.
     */
    public function logDispatchFailed(string $carrierCode, string $op, \Throwable $e): void
    {
        $this->logger->error(
            'shubo_shipping.dispatch_failed',
            [
                'event' => 'dispatch_failed',
                'carrier_code' => $carrierCode,
                'operation' => $op,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ],
        );
    }

    /**
     * Record a webhook dispatcher decision (accepted / rejected /
     * duplicate / unknown_carrier / shipment_not_found / unhandled).
     * Single helper so every webhook log line has the same `event` key
     * shape.
     *
     * @param array<string, mixed> $context
     */
    public function logWebhook(string $event, array $context = []): void
    {
        $this->logger->info(
            'shubo_shipping.webhook',
            $this->mergeContext(
                ['event' => $event],
                $context,
            ),
        );
    }

    /**
     * Record a cron-job run outcome. Fields include the job name, a numeric
     * primary count (shipments polled, breakers reaped), and arbitrary
     * additional context.
     *
     * Use this instead of {@see self::logRateLimit()} for cron-run
     * accounting — the rate-limit helper's "carrier_code" slot would be
     * populated with a non-carrier string and mislead anyone filtering
     * var/log/shubo_shipping.log by carrier.
     *
     * @param string               $jobName  e.g. "shubo_shipping.poller.run".
     * @param int                  $count    Primary numeric outcome for this run.
     * @param array<string, mixed> $context  Additional structured context.
     */
    public function logCronRun(string $jobName, int $count, array $context = []): void
    {
        $this->logger->info(
            'shubo_shipping.cron_run',
            $this->mergeContext(
                [
                    'event' => 'cron_run',
                    'job' => $jobName,
                    'count' => $count,
                ],
                $context,
            ),
        );
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function mergeContext(array $base, array $extra): array
    {
        foreach ($extra as $k => $v) {
            if (!array_key_exists($k, $base)) {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
