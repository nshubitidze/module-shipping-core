<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Resilience;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterface;
use Shubo\ShippingCore\Api\Data\DeadLetterEntryInterfaceFactory;
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\DeadLetterPublisher;

/**
 * Unit tests for the updated {@see DeadLetterPublisher} that writes a durable
 * DB row via the repository in addition to the legacy queue publish.
 */
class DeadLetterPublisherTest extends TestCase
{
    /** @var PublisherInterface&MockObject */
    private PublisherInterface $queuePublisher;

    /** @var DeadLetterRepositoryInterface&MockObject */
    private DeadLetterRepositoryInterface $repository;

    /** @var DeadLetterEntryInterfaceFactory&MockObject */
    private DeadLetterEntryInterfaceFactory $entryFactory;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private DeadLetterPublisher $publisher;

    protected function setUp(): void
    {
        $this->queuePublisher = $this->createMock(PublisherInterface::class);
        $this->repository = $this->createMock(DeadLetterRepositoryInterface::class);
        $this->entryFactory = $this->createMock(DeadLetterEntryInterfaceFactory::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->publisher = new DeadLetterPublisher(
            $this->queuePublisher,
            $this->repository,
            $this->entryFactory,
            $this->logger,
        );
    }

    public function testPublishWritesDurableRowAndPublishesToQueue(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->expects($this->once())
            ->method('setSource')
            ->with(DeadLetterEntryInterface::SOURCE_DISPATCH)
            ->willReturnSelf();
        $entry->expects($this->once())
            ->method('setCarrierCode')
            ->with('fake')
            ->willReturnSelf();
        $entry->expects($this->once())
            ->method('setShipmentId')
            ->with(42)
            ->willReturnSelf();
        $entry->expects($this->once())
            ->method('setPayload')
            ->with($this->callback(function (array $payload): bool {
                return $payload['shipment_id'] === 42
                    && $payload['carrier_code'] === 'fake'
                    && $payload['operation'] === 'createShipment'
                    && $payload['reason'] === 'boom';
            }))
            ->willReturnSelf();
        $entry->expects($this->once())
            ->method('setErrorClass')
            ->with(\RuntimeException::class)
            ->willReturnSelf();
        $entry->expects($this->once())
            ->method('setErrorMessage')
            ->with('createShipment: boom')
            ->willReturnSelf();

        $this->entryFactory->expects($this->once())
            ->method('create')
            ->willReturn($entry);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($entry)
            ->willReturn($entry);

        $this->queuePublisher->expects($this->once())
            ->method('publish')
            ->with(
                DeadLetterPublisher::TOPIC,
                $this->isType('string'),
            );

        $this->publisher->publish(42, 'fake', 'createShipment', 'boom');
    }

    public function testPublishStillPublishesToQueueWhenDurableSaveThrows(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('setSource')->willReturnSelf();
        $entry->method('setCarrierCode')->willReturnSelf();
        $entry->method('setShipmentId')->willReturnSelf();
        $entry->method('setPayload')->willReturnSelf();
        $entry->method('setErrorClass')->willReturnSelf();
        $entry->method('setErrorMessage')->willReturnSelf();

        $this->entryFactory->method('create')->willReturn($entry);
        $this->repository->method('save')
            ->willThrowException(new CouldNotSaveException(__('db outage')));

        // Queue publish must still happen even when the durable write failed.
        $this->queuePublisher->expects($this->once())
            ->method('publish')
            ->with(DeadLetterPublisher::TOPIC, $this->isType('string'));

        // StructuredLogger is called at least once (for the save-failed log)
        // plus once more at the end with the summary publish-ok log.
        $this->logger->expects($this->atLeast(2))->method('logDispatchFailed');

        $this->publisher->publish(1, 'fake', 'createShipment', 'bang');
    }

    public function testPublishSwallowsQueuePublishFailure(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('setSource')->willReturnSelf();
        $entry->method('setCarrierCode')->willReturnSelf();
        $entry->method('setShipmentId')->willReturnSelf();
        $entry->method('setPayload')->willReturnSelf();
        $entry->method('setErrorClass')->willReturnSelf();
        $entry->method('setErrorMessage')->willReturnSelf();

        $this->entryFactory->method('create')->willReturn($entry);
        $this->repository->method('save')->willReturn($entry);

        $this->queuePublisher->method('publish')
            ->willThrowException(new \RuntimeException('broker down'));

        // Must not re-throw.
        $this->publisher->publish(5, 'fake', 'createShipment', 'stall');

        self::assertTrue(true);
    }

    public function testPublishConvertsEmptyCarrierCodeToNull(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('setSource')->willReturnSelf();
        $entry->expects($this->once())
            ->method('setCarrierCode')
            ->with(null)
            ->willReturnSelf();
        $entry->method('setShipmentId')->willReturnSelf();
        $entry->method('setPayload')->willReturnSelf();
        $entry->method('setErrorClass')->willReturnSelf();
        $entry->method('setErrorMessage')->willReturnSelf();

        $this->entryFactory->method('create')->willReturn($entry);
        $this->repository->method('save')->willReturn($entry);
        $this->queuePublisher->method('publish');

        $this->publisher->publish(0, '', 'op', 'reason');
    }

    public function testPublishConvertsZeroShipmentIdToNull(): void
    {
        $entry = $this->createMock(DeadLetterEntryInterface::class);
        $entry->method('setSource')->willReturnSelf();
        $entry->method('setCarrierCode')->willReturnSelf();
        $entry->expects($this->once())
            ->method('setShipmentId')
            ->with(null)
            ->willReturnSelf();
        $entry->method('setPayload')->willReturnSelf();
        $entry->method('setErrorClass')->willReturnSelf();
        $entry->method('setErrorMessage')->willReturnSelf();

        $this->entryFactory->method('create')->willReturn($entry);
        $this->repository->method('save')->willReturn($entry);
        $this->queuePublisher->method('publish');

        $this->publisher->publish(0, 'fake', 'op', 'reason');
    }
}
