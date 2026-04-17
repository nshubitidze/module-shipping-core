<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Shubo\ShippingCore\Api\RateLimiterInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\ResourceModel\RateLimitState;

/**
 * Per-carrier token-bucket rate limiter.
 *
 * Primary backing: Magento cache frontend (Redis in production). Fallback:
 * {@see RateLimitState} resource model — an atomic conditional UPDATE that
 * prevents over-issue under concurrent callers.
 *
 * Window is 1 minute aligned to the epoch (`floor(time()/60)*60`).
 * Default RPM is read from `shubo_shipping/rate_limit/default_rpm` (60).
 */
class RateLimiter implements RateLimiterInterface
{
    private const CONFIG_DEFAULT_RPM = 'shubo_shipping/rate_limit/default_rpm';
    private const DEFAULT_RPM = 60;
    private const BLOCKING_TICK_MS = 100;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RateLimitState $dbResource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DateTime $dateTime,
        private readonly Sleeper $sleeper,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function acquire(string $carrierCode, int $tokens = 1): bool
    {
        $rpm = $this->getRpm();
        $now = $this->nowTs();
        $key = $this->cacheKey($carrierCode, $now);

        // Redis path (best-effort).
        try {
            $existing = $this->cache->load($key);
            $used = $existing === false || $existing === null ? 0 : (int)$existing;
            if ($used + $tokens > $rpm) {
                $this->logger->logRateLimit($carrierCode, max(0, $rpm - $used));
                return false;
            }
            $this->cache->save((string)($used + $tokens), $key, [], 60);
            $this->logger->logRateLimit($carrierCode, max(0, $rpm - ($used + $tokens)));
            return true;
        } catch (\Throwable $e) {
            $this->logger->logRateLimit($carrierCode, -1);
        }

        // DB fallback path.
        $ok = $this->dbResource->incrementTokens($carrierCode, $tokens, $rpm, $now);
        $this->logger->logRateLimit($carrierCode, $ok ? 0 : -1);
        return $ok;
    }

    public function acquireBlocking(string $carrierCode, int $tokens = 1, int $maxWaitMs = 2000): int
    {
        $waited = 0;
        while (true) {
            if ($this->acquire($carrierCode, $tokens)) {
                return $waited;
            }
            if ($waited >= $maxWaitMs) {
                return $maxWaitMs;
            }
            $sleepMs = min(self::BLOCKING_TICK_MS, max(1, $maxWaitMs - $waited));
            $this->sleeper->sleepMs($sleepMs);
            $waited += $sleepMs;
        }
    }

    public function windowTokens(string $carrierCode): int
    {
        $now = $this->nowTs();
        $key = $this->cacheKey($carrierCode, $now);
        try {
            $existing = $this->cache->load($key);
            if ($existing === false || $existing === null) {
                return 0;
            }
            return (int)$existing;
        } catch (\Throwable) {
            return $this->dbResource->fetchTokensUsed($carrierCode, $now);
        }
    }

    private function getRpm(): int
    {
        $value = $this->scopeConfig->getValue(self::CONFIG_DEFAULT_RPM);
        if ($value === null || $value === '') {
            return self::DEFAULT_RPM;
        }
        return (int)$value;
    }

    private function cacheKey(string $carrierCode, int $nowTs): string
    {
        $windowEpoch = $nowTs - ($nowTs % 60);
        return 'shubo_shipping_rl_' . $carrierCode . '_' . $windowEpoch;
    }

    private function nowTs(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }
}
