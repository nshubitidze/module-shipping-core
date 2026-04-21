<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Resilience;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Model\Data\DeadLetterEntry;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry as DeadLetterEntryResource;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry\Collection as DeadLetterEntryCollection;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry\CollectionFactory as DeadLetterEntryCollectionFactory;

/**
 * Durable repository for dead-letter entries.
 *
 * `save()` accepts any DeadLetterEntryInterface implementation produced by
 * the Magento factory or a hand-built {@see DeadLetterEntry}. Listing
 * helpers are tight-scoped to the few queries CLI commands (Phase 10) need —
 * any richer listing goes through a full SearchCriteria-based repository in
 * a follow-up.
 */
class DeadLetterRepository implements DeadLetterRepositoryInterface
{
    public function __construct(
        private readonly DeadLetterEntryResource $resource,
        private readonly DeadLetterEntryCollectionFactory $collectionFactory,
    ) {
    }

    public function save(DeadLetterEntryInterface $entry): DeadLetterEntryInterface
    {
        if (!$entry instanceof DeadLetterEntry) {
            throw new CouldNotSaveException(
                __('DeadLetterRepository::save requires the Model\\Data implementation.'),
            );
        }
        try {
            $this->resource->save($entry);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(
                __('Could not save DLQ entry: %1', $e->getMessage()),
                $e,
            );
        }
        return $entry;
    }

    public function getById(int $dlqId): DeadLetterEntryInterface
    {
        $collection = $this->newCollection();
        $collection->addFieldToFilter(
            DeadLetterEntryInterface::FIELD_DLQ_ID,
            ['eq' => $dlqId],
        );
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        if (!$item instanceof DeadLetterEntry || $item->getDlqId() === null) {
            throw new NoSuchEntityException(
                __('DLQ entry %1 does not exist.', $dlqId),
            );
        }
        return $item;
    }

    /**
     * @return list<DeadLetterEntryInterface>
     */
    public function listPending(int $limit = 50): array
    {
        $collection = $this->newCollection();
        $collection->addFieldToFilter(
            DeadLetterEntryInterface::FIELD_REPROCESSED_AT,
            ['null' => true],
        );
        $collection->setOrder(DeadLetterEntryInterface::FIELD_FAILED_AT, 'DESC');
        $collection->setPageSize(max(1, $limit));
        return array_values(iterator_to_array($collection));
    }

    /**
     * @return list<DeadLetterEntryInterface>
     */
    public function listBySource(string $source, int $limit = 50, bool $includeReprocessed = false): array
    {
        $collection = $this->newCollection();
        $collection->addFieldToFilter(DeadLetterEntryInterface::FIELD_SOURCE, $source);
        if (!$includeReprocessed) {
            $collection->addFieldToFilter(
                DeadLetterEntryInterface::FIELD_REPROCESSED_AT,
                ['null' => true],
            );
        }
        $collection->setOrder(DeadLetterEntryInterface::FIELD_FAILED_AT, 'DESC');
        $collection->setPageSize(max(1, $limit));
        return array_values(iterator_to_array($collection));
    }

    private function newCollection(): DeadLetterEntryCollection
    {
        return $this->collectionFactory->create();
    }
}
