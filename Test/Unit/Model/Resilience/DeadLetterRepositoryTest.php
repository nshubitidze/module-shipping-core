<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Resilience;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Model\Data\DeadLetterEntry;
use Shubo\ShippingCore\Model\Resilience\DeadLetterRepository;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry as DeadLetterEntryResource;
use Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry\Collection as DeadLetterEntryCollection;
use Shubo\ShippingCore\Test\Unit\Fake\StubDeadLetterEntryCollectionFactory;

/**
 * Unit tests for {@see DeadLetterRepository}.
 *
 * Covers save/read path contracts and the two list helpers used by the
 * Phase 10 CLI commands. Mirrors the
 * {@see \Shubo\ShippingCore\Model\Shipment\ShipmentEventRepository} test style.
 *
 * BUG-SHIPPINGCORE-DLQ-TEST-1 fix: the collection factory collaboration is
 * done through the named fake {@see StubDeadLetterEntryCollectionFactory}
 * that wraps a single pre-built collection mock. This keeps the test hermetic
 * — it does not depend on the Magento-generated
 * {@see \Shubo\ShippingCore\Model\ResourceModel\DeadLetterEntry\CollectionFactory}
 * being present at autoload time, which fails inside the duka container
 * when `generated/code/` has been wiped.
 */
class DeadLetterRepositoryTest extends TestCase
{
    /** @var DeadLetterEntryResource&MockObject */
    private DeadLetterEntryResource $resource;

    /** @var DeadLetterEntryCollection&MockObject */
    private DeadLetterEntryCollection $collection;

    private StubDeadLetterEntryCollectionFactory $collectionFactory;

    private DeadLetterRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(DeadLetterEntryResource::class);
        $this->collection = $this->createMock(DeadLetterEntryCollection::class);
        $this->collectionFactory = new StubDeadLetterEntryCollectionFactory($this->collection);

        $this->repository = new DeadLetterRepository(
            $this->resource,
            $this->collectionFactory,
        );
    }

    public function testSavePersistsNewEntryViaResource(): void
    {
        $entry = $this->newEntry();
        $entry->setSource(DeadLetterEntryInterface::SOURCE_DISPATCH);
        $entry->setErrorClass(\RuntimeException::class);
        $entry->setErrorMessage('failed to dispatch');
        $entry->setPayload(['k' => 'v']);

        $this->resource->expects($this->once())
            ->method('save')
            ->with($entry)
            ->willReturnSelf();

        $result = $this->repository->save($entry);

        self::assertSame($entry, $result);
    }

    public function testSaveWrapsResourceExceptionInCouldNotSave(): void
    {
        $entry = $this->newEntry();
        $entry->setSource(DeadLetterEntryInterface::SOURCE_DISPATCH);
        $entry->setErrorClass(\RuntimeException::class);
        $entry->setErrorMessage('x');

        $this->resource->expects($this->once())
            ->method('save')
            ->with($entry)
            ->willThrowException(new \RuntimeException('write failure'));

        $this->expectException(CouldNotSaveException::class);
        $this->expectExceptionMessageMatches('/write failure/');

        $this->repository->save($entry);
    }

    public function testSaveRejectsForeignInterfaceImplementation(): void
    {
        $foreign = $this->createMock(DeadLetterEntryInterface::class);

        $this->resource->expects($this->never())->method('save');

        $this->expectException(CouldNotSaveException::class);
        $this->repository->save($foreign);
    }

    public function testGetByIdReturnsFoundEntry(): void
    {
        $entry = $this->newEntry();
        $entry->setData(DeadLetterEntryInterface::FIELD_DLQ_ID, 17);
        $entry->setSource(DeadLetterEntryInterface::SOURCE_WEBHOOK);
        $entry->setErrorClass(\RuntimeException::class);
        $entry->setErrorMessage('x');

        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with(DeadLetterEntryInterface::FIELD_DLQ_ID, ['eq' => 17])
            ->willReturnSelf();
        $this->collection->expects($this->once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $this->collection->expects($this->once())
            ->method('getFirstItem')
            ->willReturn($entry);

        $result = $this->repository->getById(17);

        self::assertSame(17, $result->getDlqId());
    }

    public function testGetByIdThrowsWhenNotFound(): void
    {
        $emptyEntry = $this->newEntry(); // dlq_id is null

        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('getFirstItem')->willReturn($emptyEntry);

        $this->expectException(NoSuchEntityException::class);
        $this->repository->getById(404);
    }

    public function testListPendingAppliesNullReprocessedAtFilter(): void
    {
        $items = [$this->newEntry(), $this->newEntry()];

        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with(
                DeadLetterEntryInterface::FIELD_REPROCESSED_AT,
                ['null' => true],
            )
            ->willReturnSelf();
        $this->collection->expects($this->once())
            ->method('setOrder')
            ->with(DeadLetterEntryInterface::FIELD_FAILED_AT, 'DESC')
            ->willReturnSelf();
        $this->collection->expects($this->once())
            ->method('setPageSize')
            ->with(25)
            ->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $result = $this->repository->listPending(25);

        self::assertCount(2, $result);
    }

    public function testListPendingCapsZeroLimitToOne(): void
    {
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->expects($this->once())
            ->method('setPageSize')
            ->with(1)
            ->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $result = $this->repository->listPending(0);

        self::assertSame([], $result);
    }

    public function testListBySourceExcludesReprocessedByDefault(): void
    {
        $addFilterArgs = [];
        $this->collection->expects($this->exactly(2))
            ->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, mixed $value) use (&$addFilterArgs): DeadLetterEntryCollection {
                $addFilterArgs[] = [$field, $value];
                return $this->collection;
            });
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->repository->listBySource(DeadLetterEntryInterface::SOURCE_WEBHOOK);

        self::assertSame(
            [DeadLetterEntryInterface::FIELD_SOURCE, DeadLetterEntryInterface::SOURCE_WEBHOOK],
            $addFilterArgs[0],
        );
        self::assertSame(
            [DeadLetterEntryInterface::FIELD_REPROCESSED_AT, ['null' => true]],
            $addFilterArgs[1],
        );
    }

    public function testListBySourceIncludesReprocessedWhenFlagged(): void
    {
        $this->collection->expects($this->once()) // only the source filter
            ->method('addFieldToFilter')
            ->with(DeadLetterEntryInterface::FIELD_SOURCE, DeadLetterEntryInterface::SOURCE_WEBHOOK)
            ->willReturnSelf();
        $this->collection->method('setOrder')->willReturnSelf();
        $this->collection->method('setPageSize')->willReturnSelf();
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->repository->listBySource(
            DeadLetterEntryInterface::SOURCE_WEBHOOK,
            50,
            includeReprocessed: true,
        );
    }

    private function newEntry(): DeadLetterEntry
    {
        $entry = $this->getMockBuilder(DeadLetterEntry::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        /** @var DeadLetterEntry $entry */
        return $entry;
    }
}
