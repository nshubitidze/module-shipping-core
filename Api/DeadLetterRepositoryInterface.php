<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;

/**
 * Repository for dead-letter entries (Phase 10 DLQ).
 *
 * @api
 */
interface DeadLetterRepositoryInterface
{
    /**
     * @throws CouldNotSaveException
     */
    public function save(DeadLetterEntryInterface $entry): DeadLetterEntryInterface;

    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $dlqId): DeadLetterEntryInterface;

    /**
     * List pending (unprocessed) entries, newest first.
     *
     * @return list<DeadLetterEntryInterface>
     */
    public function listPending(int $limit = 50): array;

    /**
     * List entries for a specific source, newest first.
     *
     * @return list<DeadLetterEntryInterface>
     */
    public function listBySource(string $source, int $limit = 50, bool $includeReprocessed = false): array;
}
