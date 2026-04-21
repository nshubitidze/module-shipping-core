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
use Shubo\ShippingCore\Api\DeadLetterRepositoryInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Resilience\DeadLetterPublisher;
use Shubo\ShippingCore\Test\Unit\Fake\InMemoryDeadLetterEntry;
use Shubo\ShippingCore\Test\Unit\Fake\InMemoryDeadLetterEntryFactory;

/**
 * Unit tests for the updated {@see DeadLetterPublisher} that writes a durable
 * DB row via the repository in addition to the legacy queue publish.
 *
 * BUG-SHIPPINGCORE-DLQ-TEST-1 fix: factory collaboration is done through the
 * named fake {@see InMemoryDeadLetterEntryFactory} which hands out
 * {@see InMemoryDeadLetterEntry} instances. This keeps the test hermetic —
 * it does not depend on the Magento-generated
 * {@see \Shubo\ShippingCore\Api\Data\DeadLetterEntryInterfaceFactory} class
 * being present at autoload time, which fails inside the duka container when
 * `generated/code/` has been wiped.
 */
class DeadLetterPublisherTest extends TestCase
{
    /** @var PublisherInterface&MockObject */
    private PublisherInterface $queuePublisher;

    /** @var DeadLetterRepositoryInterface&MockObject */
    private DeadLetterRepositoryInterface $repository;

    private InMemoryDeadLetterEntryFactory $entryFactory;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private DeadLetterPublisher $publisher;

    protected function setUp(): void
    {
        $this->queuePublisher = $this->createMock(PublisherInterface::class);
        $this->repository = $this->createMock(DeadLetterRepositoryInterface::class);
        $this->entryFactory = new InMemoryDeadLetterEntryFactory();
        $this->logger = $this->createMock(StructuredLogger::class);

        // The SUT's constructor types $entryFactory as
        // DeadLetterEntryInterfaceFactory. Our named fake EXTENDS that real
        // (hand-written) class so PHP's type system passes, and `create()`
        // returns a concrete InMemoryDeadLetterEntry whose state we can
        // assert on directly rather than through chained setter mock
        // expectations.
        $this->publisher = new DeadLetterPublisher(
            $this->queuePublisher,
            $this->repository,
            $this->entryFactory,
            $this->logger,
        );
    }

    public function testPublishWritesDurableRowAndPublishesToQueue(): void
    {
        $savedEntry = null;
        $this->repository->expects($this->once())
            ->method('save')
            ->willReturnCallback(function (DeadLetterEntryInterface $e) use (&$savedEntry): DeadLetterEntryInterface {
                $savedEntry = $e;
                return $e;
            });

        $this->queuePublisher->expects($this->once())
            ->method('publish')
            ->with(
                DeadLetterPublisher::TOPIC,
                $this->isType('string'),
            );

        $this->publisher->publish(42, 'fake', 'createShipment', 'boom');

        self::assertInstanceOf(InMemoryDeadLetterEntry::class, $savedEntry);
        self::assertSame(DeadLetterEntryInterface::SOURCE_DISPATCH, $savedEntry->getSource());
        self::assertSame('fake', $savedEntry->getCarrierCode());
        self::assertSame(42, $savedEntry->getShipmentId());
        self::assertSame(\RuntimeException::class, $savedEntry->getErrorClass());
        self::assertSame('createShipment: boom', $savedEntry->getErrorMessage());

        $payload = $savedEntry->getPayload();
        self::assertSame(42, $payload['shipment_id']);
        self::assertSame('fake', $payload['carrier_code']);
        self::assertSame('createShipment', $payload['operation']);
        self::assertSame('boom', $payload['reason']);
    }

    public function testPublishStillPublishesToQueueWhenDurableSaveThrows(): void
    {
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
        $this->repository->method('save')
            ->willReturnCallback(static fn (DeadLetterEntryInterface $e): DeadLetterEntryInterface => $e);

        $this->queuePublisher->method('publish')
            ->willThrowException(new \RuntimeException('broker down'));

        // Must not re-throw.
        $this->publisher->publish(5, 'fake', 'createShipment', 'stall');

        self::assertTrue(true);
    }

    public function testPublishConvertsEmptyCarrierCodeToNull(): void
    {
        $savedEntry = null;
        $this->repository->method('save')
            ->willReturnCallback(function (DeadLetterEntryInterface $e) use (&$savedEntry): DeadLetterEntryInterface {
                $savedEntry = $e;
                return $e;
            });
        $this->queuePublisher->method('publish');

        $this->publisher->publish(0, '', 'op', 'reason');

        self::assertInstanceOf(InMemoryDeadLetterEntry::class, $savedEntry);
        self::assertNull($savedEntry->getCarrierCode(), 'empty string carrier_code must normalise to null');
    }

    public function testPublishConvertsZeroShipmentIdToNull(): void
    {
        $savedEntry = null;
        $this->repository->method('save')
            ->willReturnCallback(function (DeadLetterEntryInterface $e) use (&$savedEntry): DeadLetterEntryInterface {
                $savedEntry = $e;
                return $e;
            });
        $this->queuePublisher->method('publish');

        $this->publisher->publish(0, 'fake', 'op', 'reason');

        self::assertInstanceOf(InMemoryDeadLetterEntry::class, $savedEntry);
        self::assertNull($savedEntry->getShipmentId(), 'shipment_id=0 must normalise to null');
    }
}
