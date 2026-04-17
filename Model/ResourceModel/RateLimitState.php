<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the rate-limit state table. Owns the atomic
 * conditional-UPDATE used as the token-bucket DB fallback so no caller
 * can over-issue tokens under concurrent invocation.
 */
class RateLimitState extends AbstractDb
{
    public const TABLE = 'shubo_shipping_rate_limit';
    public const FIELD_CARRIER_CODE = 'carrier_code';
    public const FIELD_WINDOW_START = 'window_start';
    public const FIELD_TOKENS_USED = 'tokens_used';

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init(self::TABLE, self::FIELD_CARRIER_CODE);
        $this->_isPkAutoIncrement = false;
    }

    public function getIdFieldName(): string
    {
        return self::FIELD_CARRIER_CODE;
    }

    /**
     * Compute the 1-minute aligned window start for `$nowTs` (seconds since
     * epoch) as a MySQL UTC timestamp string.
     */
    public static function computeWindowStart(int $nowTs): string
    {
        $aligned = $nowTs - ($nowTs % 60);
        return gmdate('Y-m-d H:i:s', $aligned);
    }

    /**
     * Attempt to increment the carrier's token counter within its current
     * window. If the increment would exceed `$rpm`, the row is NOT updated
     * and the method returns false. A new window (detected by `nowTs`
     * crossing a minute boundary relative to the persisted `window_start`)
     * resets `tokens_used` to `$tokens`.
     *
     * Runs inside a DB transaction with a conditional UPDATE. The SELECT is
     * performed with `FOR UPDATE` so concurrent callers serialize on the
     * row rather than racing each other.
     *
     * @param string   $carrierCode
     * @param int      $tokens How many tokens this caller wants to consume.
     * @param int      $rpm    Max tokens per window.
     * @param int|null $nowTs  Current timestamp (seconds since epoch).
     *                         Defaults to `time()` in production — tests
     *                         pass an explicit value for determinism.
     * @return bool True if the increment succeeded, false if it would
     *              have exceeded the RPM cap.
     */
    public function incrementTokens(
        string $carrierCode,
        int $tokens,
        int $rpm,
        ?int $nowTs = null,
    ): bool {
        $windowStart = self::computeWindowStart($nowTs ?? time());
        $connection = $this->getConnection();
        if ($connection === false) {
            throw new \RuntimeException('No database connection available for rate-limit state.');
        }
        $table = $this->getMainTable();

        $connection->beginTransaction();
        try {
            // Upsert the row (INSERT ... ON DUPLICATE KEY UPDATE) so the
            // SELECT FOR UPDATE below always finds something to lock.
            $connection->query(
                sprintf(
                    'INSERT INTO %s (%s, %s, %s) VALUES (?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE %s = %s',
                    $connection->quoteIdentifier($table),
                    $connection->quoteIdentifier(self::FIELD_CARRIER_CODE),
                    $connection->quoteIdentifier(self::FIELD_WINDOW_START),
                    $connection->quoteIdentifier(self::FIELD_TOKENS_USED),
                    $connection->quoteIdentifier(self::FIELD_CARRIER_CODE),
                    $connection->quoteIdentifier(self::FIELD_CARRIER_CODE),
                ),
                [$carrierCode, $windowStart, 0],
            );

            $row = $connection->fetchRow(
                sprintf(
                    'SELECT %s, %s FROM %s WHERE %s = ? FOR UPDATE',
                    $connection->quoteIdentifier(self::FIELD_WINDOW_START),
                    $connection->quoteIdentifier(self::FIELD_TOKENS_USED),
                    $connection->quoteIdentifier($table),
                    $connection->quoteIdentifier(self::FIELD_CARRIER_CODE),
                ),
                [$carrierCode],
            );

            $currentWindow = is_array($row) ? (string)($row[self::FIELD_WINDOW_START] ?? '') : '';
            $currentTokens = is_array($row) ? (int)($row[self::FIELD_TOKENS_USED] ?? 0) : 0;

            if ($currentWindow !== $windowStart) {
                // New window — reset counter to $tokens
                $connection->update(
                    $table,
                    [
                        self::FIELD_WINDOW_START => $windowStart,
                        self::FIELD_TOKENS_USED => $tokens,
                    ],
                    [self::FIELD_CARRIER_CODE . ' = ?' => $carrierCode],
                );
                $connection->commit();
                return $tokens <= $rpm;
            }

            if ($currentTokens + $tokens > $rpm) {
                $connection->commit();
                return false;
            }

            $connection->update(
                $table,
                [self::FIELD_TOKENS_USED => $currentTokens + $tokens],
                [self::FIELD_CARRIER_CODE . ' = ?' => $carrierCode],
            );
            $connection->commit();
            return true;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Fetch the current tokens_used for the carrier's window. Returns 0
     * when no row exists or when the window_start differs from the caller's
     * expected window.
     */
    public function fetchTokensUsed(string $carrierCode, ?int $nowTs = null): int
    {
        $windowStart = self::computeWindowStart($nowTs ?? time());
        $connection = $this->getConnection();
        if ($connection === false) {
            throw new \RuntimeException('No database connection available for rate-limit state.');
        }
        $row = $connection->fetchRow(
            sprintf(
                'SELECT %s, %s FROM %s WHERE %s = ?',
                $connection->quoteIdentifier(self::FIELD_WINDOW_START),
                $connection->quoteIdentifier(self::FIELD_TOKENS_USED),
                $connection->quoteIdentifier($this->getMainTable()),
                $connection->quoteIdentifier(self::FIELD_CARRIER_CODE),
            ),
            [$carrierCode],
        );
        if (!is_array($row)) {
            return 0;
        }
        $persistedWindow = (string)($row[self::FIELD_WINDOW_START] ?? '');
        if ($persistedWindow !== $windowStart) {
            return 0;
        }
        return (int)($row[self::FIELD_TOKENS_USED] ?? 0);
    }
}
