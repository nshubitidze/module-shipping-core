<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Tracking;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Api\Data\Dto\StatusResponse;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterfaceFactory;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\RateLimiterInterface;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;
use Shubo\ShippingCore\Exception\NoCarrierAvailableException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Tracking\NextPollCalculator;
use Shubo\ShippingCore\Model\Tracking\TrackingPoller;
use Shubo\ShippingCore\Test\Unit\Fake\FakeCarrierGateway;
use Shubo\ShippingCore\Test\Unit\Fake\InMemoryCircuitBreaker;

/**
 * Unit tests for {@see TrackingPoller}. Exercises the §10.3 pseudocode for
 * every branch: happy path (status change + noop), circuit-open skip,
 * rate-limited skip, mid-batch rate-limiter drain, carrier exception
 * handling, terminal transition, and the admin `pollOne` bypass.
 */
class TrackingPollerTest extends TestCase
{
    private const CARRIER_CODE = 'fake';

    /** @var CarrierRegistryInterface&MockObject */
    private CarrierRegistryInterface $registry;

    /** @var RateLimiterInterface&MockObject */
    private RateLimiterInterface $rateLimiter;

    /** @var ShipmentRepositoryInterface&MockObject */
    private ShipmentRepositoryInterface $shipmentRepository;

    /** @var ShipmentEventRepositoryInterface&MockObject */
    private ShipmentEventRepositoryInterface $eventRepository;

    /** @var EventManagerInterface&MockObject */
    private EventManagerInterface $eventManager;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    /** @var DateTime&MockObject */
    private DateTime $dateTime;

    /** @var ShipmentEventInterfaceFactory&MockObject */
    private ShipmentEventInterfaceFactory $eventFactory;

    private InMemoryCircuitBreaker $circuitBreaker;

    private FakeCarrierGateway $gateway;

    private NextPollCalculator $nextPollCalculator;

    /** Frozen "now" — 2024-01-01 12:00:00 UTC. */
    private int $now = 1_704_110_400;

    /** @var list<array{name:string, data:array<string,mixed>}> */
    private array $capturedEvents = [];

    /** @var list<ShipmentEventInterface> */
    private array $savedEvents = [];

    /** @var list<ShipmentInterface> */
    private array $savedShipments = [];

    protected function setUp(): void
    {
        $this->registry = $this->createMock(CarrierRegistryInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->eventRepository = $this->createMock(ShipmentEventRepositoryInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->eventFactory = $this->createMock(ShipmentEventInterfaceFactory::class);

        $this->circuitBreaker = new InMemoryCircuitBreaker();
        $this->gateway = new FakeCarrierGateway(self::CARRIER_CODE);

        $this->dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);
        $this->nextPollCalculator = new NextPollCalculator($this->dateTime);

        $this->eventFactory->method('create')->willReturnCallback(
            fn (): ShipmentEventInterface => $this->buildFreshEvent(),
        );

        $this->capturedEvents = [];
        $this->eventManager->method('dispatch')->willReturnCallback(
            function (string $name, array $data = []): void {
                $this->capturedEvents[] = ['name' => $name, 'data' => $data];
            },
        );

        $this->savedEvents = [];
        $this->eventRepository->method('save')->willReturnCallback(
            function (ShipmentEventInterface $event): ShipmentEventInterface {
                $this->savedEvents[] = $event;
                return $event;
            },
        );

        $this->savedShipments = [];
        $this->shipmentRepository->method('save')->willReturnCallback(
            function (ShipmentInterface $s): ShipmentInterface {
                $this->savedShipments[] = $s;
                return $s;
            },
        );
    }

    public function testDrainBatchHappyPathDispatchesStatusChange(): void
    {
        $shipment = $this->newShipment(
            id: 100,
            status: ShipmentInterface::STATUS_IN_TRANSIT,
            ageSeconds: 6 * 3600,
        );
        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->with(self::CARRIER_CODE)->willReturn(10);
        $this->rateLimiter->method('acquire')->willReturn(true);

        $this->shipmentRepository->expects(self::once())
            ->method('getDuePolls')
            ->with(self::callback(static fn (int $limit): bool => $limit >= 1), self::CARRIER_CODE)
            ->willReturn([$shipment]);

        $this->gateway->setNextResponse('getShipmentStatus', new StatusResponse(
            normalizedStatus: ShipmentInterface::STATUS_OUT_FOR_DELIVERY,
            carrierStatusRaw: 'OUT_FOR_DELIVERY',
            occurredAt: gmdate('Y-m-d H:i:s', $this->now),
            codCollectedAt: null,
            raw: ['foo' => 'bar'],
        ));

        $poller = $this->poller();
        $result = $poller->drainBatch(500);

        self::assertSame(1, $result);
        self::assertCount(1, $this->savedEvents);
        self::assertSame(
            ShipmentEventInterface::EVENT_TYPE_STATUS_CHANGE,
            $this->savedEvents[0]->getEventType(),
        );
        self::assertSame(ShipmentEventInterface::SOURCE_POLL, $this->savedEvents[0]->getSource());
        self::assertSame(ShipmentInterface::STATUS_OUT_FOR_DELIVERY, $shipment->getStatus());
        self::assertNotNull($shipment->getNextPollAt());
        // out_for_delivery -> +15 min bucket
        self::assertSame(
            gmdate('Y-m-d H:i:s', $this->now + 15 * 60),
            $shipment->getNextPollAt(),
        );

        $this->assertEventFired('shubo_shipping_shipment_status_changed');
    }

    public function testDrainBatchPollNoopWhenStatusUnchanged(): void
    {
        $shipment = $this->newShipment(
            id: 200,
            status: ShipmentInterface::STATUS_IN_TRANSIT,
            ageSeconds: 6 * 3600,
        );
        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->willReturn(10);
        $this->rateLimiter->method('acquire')->willReturn(true);
        $this->shipmentRepository->method('getDuePolls')->willReturn([$shipment]);

        $this->gateway->setNextResponse('getShipmentStatus', new StatusResponse(
            normalizedStatus: ShipmentInterface::STATUS_IN_TRANSIT,
            carrierStatusRaw: 'IN_TRANSIT',
            occurredAt: gmdate('Y-m-d H:i:s', $this->now),
            codCollectedAt: null,
        ));

        $poller = $this->poller();
        $result = $poller->drainBatch(500);

        self::assertSame(1, $result);
        self::assertCount(1, $this->savedEvents);
        self::assertSame(
            ShipmentEventInterface::EVENT_TYPE_POLL_NOOP,
            $this->savedEvents[0]->getEventType(),
        );
        self::assertSame(ShipmentInterface::STATUS_IN_TRANSIT, $shipment->getStatus());
        // shipment saved with updated last_polled_at + next_poll_at
        self::assertNotEmpty($this->savedShipments);
        self::assertSame(
            gmdate('Y-m-d H:i:s', $this->now),
            $shipment->getLastPolledAt(),
        );
        self::assertNotNull($shipment->getNextPollAt());
        $this->assertEventNotFired('shubo_shipping_shipment_status_changed');
    }

    public function testDrainBatchSkipsWhenCircuitOpen(): void
    {
        $this->circuitBreaker->forceState(
            self::CARRIER_CODE,
            CircuitBreakerStateInterface::STATE_OPEN,
            'test',
        );
        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->shipmentRepository->expects(self::never())->method('getDuePolls');
        $this->gateway->setNextError(
            'getShipmentStatus',
            new \LogicException('gateway must not be called when breaker is open'),
        );

        $poller = $this->poller();
        $result = $poller->drainBatch(500);

        self::assertSame(0, $result);
        self::assertEmpty($this->savedEvents);
        self::assertEmpty($this->capturedEvents);
    }

    public function testDrainBatchSkipsWhenRateLimitBudgetIsZero(): void
    {
        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->with(self::CARRIER_CODE)->willReturn(0);
        $this->shipmentRepository->expects(self::never())->method('getDuePolls');

        $poller = $this->poller();
        self::assertSame(0, $poller->drainBatch(500));
    }

    public function testDrainBatchStopsWhenRateLimiterDrainsMidBatch(): void
    {
        $first = $this->newShipment(id: 301, status: ShipmentInterface::STATUS_IN_TRANSIT, ageSeconds: 6 * 3600);
        $second = $this->newShipment(id: 302, status: ShipmentInterface::STATUS_IN_TRANSIT, ageSeconds: 6 * 3600);

        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->willReturn(10);
        $acquireCalls = 0;
        $this->rateLimiter->method('acquire')->willReturnCallback(
            function () use (&$acquireCalls): bool {
                $acquireCalls++;
                return $acquireCalls === 1;
            },
        );
        $this->shipmentRepository->method('getDuePolls')->willReturn([$first, $second]);

        $this->gateway->setNextResponse('getShipmentStatus', new StatusResponse(
            normalizedStatus: ShipmentInterface::STATUS_IN_TRANSIT,
            carrierStatusRaw: 'IN_TRANSIT',
            occurredAt: null,
            codCollectedAt: null,
        ));

        $poller = $this->poller();
        $result = $poller->drainBatch(500);

        self::assertSame(1, $result);
        self::assertCount(1, $this->savedEvents);
    }

    public function testDrainBatchRecordsFailureOnCarrierException(): void
    {
        $shipment = $this->newShipment(
            id: 400,
            status: ShipmentInterface::STATUS_IN_TRANSIT,
            ageSeconds: 6 * 3600,
        );
        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->willReturn(10);
        $this->rateLimiter->method('acquire')->willReturn(true);
        $this->shipmentRepository->method('getDuePolls')->willReturn([$shipment]);

        $this->gateway->setNextError(
            'getShipmentStatus',
            new \RuntimeException('upstream 500'),
        );

        $poller = $this->poller();
        $result = $poller->drainBatch(500);

        self::assertSame(0, $result, 'Failed polls do not count toward the polled total.');
        self::assertCount(1, $this->savedEvents);
        self::assertSame(
            ShipmentEventInterface::EVENT_TYPE_FAILED,
            $this->savedEvents[0]->getEventType(),
        );
        self::assertSame('upstream 500', $this->savedEvents[0]->getCarrierStatusRaw());
        self::assertSame(
            ShipmentInterface::STATUS_IN_TRANSIT,
            $shipment->getStatus(),
            'A failed poll must NOT change the shipment status.',
        );
        self::assertNotNull($shipment->getNextPollAt(), 'Failed poll must still reschedule next_poll_at.');
        $this->assertEventNotFired('shubo_shipping_shipment_status_changed');
    }

    public function testDrainBatchBreaksInnerLoopOnCircuitOpenException(): void
    {
        $first = $this->newShipment(id: 501, status: ShipmentInterface::STATUS_IN_TRANSIT, ageSeconds: 6 * 3600);
        $second = $this->newShipment(id: 502, status: ShipmentInterface::STATUS_IN_TRANSIT, ageSeconds: 6 * 3600);

        // Use a custom in-memory breaker that throws CircuitOpenException on execute.
        $throwingBreaker = new class extends InMemoryCircuitBreaker {
            public int $executeCalls = 0;

            public function execute(string $carrierCode, callable $fn): mixed
            {
                $this->executeCalls++;
                throw CircuitOpenException::create('opened mid-batch');
            }
        };

        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->willReturn(10);
        $this->rateLimiter->method('acquire')->willReturn(true);
        $this->shipmentRepository->method('getDuePolls')->willReturn([$first, $second]);

        $poller = new TrackingPoller(
            $this->registry,
            $throwingBreaker,
            $this->rateLimiter,
            $this->shipmentRepository,
            $this->eventRepository,
            $this->nextPollCalculator,
            $this->eventManager,
            $this->logger,
            $this->eventFactory,
            $this->dateTime,
        );

        $result = $poller->drainBatch(500);

        self::assertSame(0, $result);
        self::assertSame(1, $throwingBreaker->executeCalls, 'Must break out after the first CircuitOpenException.');
    }

    public function testDrainBatchTerminalStatusTransitionClearsNextPollAt(): void
    {
        $shipment = $this->newShipment(
            id: 600,
            status: ShipmentInterface::STATUS_OUT_FOR_DELIVERY,
            ageSeconds: 6 * 3600,
        );
        $this->registry->method('enabled')->willReturn([self::CARRIER_CODE => $this->gateway]);
        $this->rateLimiter->method('windowTokens')->willReturn(10);
        $this->rateLimiter->method('acquire')->willReturn(true);
        $this->shipmentRepository->method('getDuePolls')->willReturn([$shipment]);

        $this->gateway->setNextResponse('getShipmentStatus', new StatusResponse(
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            carrierStatusRaw: 'DELIVERED',
            occurredAt: gmdate('Y-m-d H:i:s', $this->now),
            codCollectedAt: null,
        ));

        $poller = $this->poller();
        $result = $poller->drainBatch(500);

        self::assertSame(1, $result);
        self::assertSame(ShipmentInterface::STATUS_DELIVERED, $shipment->getStatus());
        self::assertNull($shipment->getNextPollAt(), 'Terminal status must clear next_poll_at.');
        $this->assertEventFired('shubo_shipping_shipment_status_changed');
    }

    public function testPollOneBypassesBreakerAndLimiterAndReturnsShipment(): void
    {
        $shipment = $this->newShipment(
            id: 700,
            status: ShipmentInterface::STATUS_IN_TRANSIT,
            ageSeconds: 6 * 3600,
        );
        $this->shipmentRepository->method('getById')->with(700)->willReturn($shipment);
        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(true);
        $this->registry->method('get')->with(self::CARRIER_CODE)->willReturn($this->gateway);

        // These are wired but must NOT be hit on pollOne.
        $this->rateLimiter->expects(self::never())->method('acquire');

        $this->gateway->setNextResponse('getShipmentStatus', new StatusResponse(
            normalizedStatus: ShipmentInterface::STATUS_DELIVERED,
            carrierStatusRaw: 'DELIVERED',
            occurredAt: gmdate('Y-m-d H:i:s', $this->now),
            codCollectedAt: null,
        ));

        $poller = $this->poller();
        $result = $poller->pollOne(700);

        self::assertSame($shipment, $result);
        self::assertSame(ShipmentInterface::STATUS_DELIVERED, $result->getStatus());
        self::assertNull($result->getNextPollAt());
        $this->assertEventFired('shubo_shipping_shipment_status_changed');
    }

    public function testPollOneThrowsWhenCarrierUnregistered(): void
    {
        $shipment = $this->newShipment(
            id: 800,
            status: ShipmentInterface::STATUS_IN_TRANSIT,
            ageSeconds: 6 * 3600,
        );
        $this->shipmentRepository->method('getById')->with(800)->willReturn($shipment);
        $this->registry->method('has')->with(self::CARRIER_CODE)->willReturn(false);

        $poller = $this->poller();
        $this->expectException(NoCarrierAvailableException::class);
        $poller->pollOne(800);
    }

    // -- helpers ------------------------------------------------------------

    private function poller(): TrackingPoller
    {
        return new TrackingPoller(
            $this->registry,
            $this->circuitBreaker,
            $this->rateLimiter,
            $this->shipmentRepository,
            $this->eventRepository,
            $this->nextPollCalculator,
            $this->eventManager,
            $this->logger,
            $this->eventFactory,
            $this->dateTime,
        );
    }

    /**
     * Build an anonymous in-memory ShipmentInterface implementation with just
     * the fields the poller reads/writes. Mirrors the style used in
     * CircuitBreakerTest — avoids AbstractModel resource-model lookups.
     */
    private function newShipment(int $id, string $status, int $ageSeconds): ShipmentInterface
    {
        $createdAt = gmdate('Y-m-d H:i:s', $this->now - $ageSeconds);
        $shipment = new class implements ShipmentInterface {
            public ?int $shipmentId = null;
            public string $carrierCode = '';
            public ?string $carrierTrackingId = null;
            public string $clientTrackingCode = '';
            public string $status = ShipmentInterface::STATUS_PENDING;
            public ?string $createdAt = null;
            public ?string $lastPolledAt = null;
            public ?string $nextPollAt = null;

            public function getShipmentId(): ?int
            {
                return $this->shipmentId;
            }
            public function getMagentoShipmentId(): ?int
            {
                return null;
            }
            public function setMagentoShipmentId(?int $magentoShipmentId): self
            {
                return $this;
            }
            public function getOrderId(): int
            {
                return 0;
            }
            public function setOrderId(int $orderId): self
            {
                return $this;
            }
            public function getMerchantId(): int
            {
                return 0;
            }
            public function setMerchantId(int $merchantId): self
            {
                return $this;
            }
            public function getCarrierCode(): string
            {
                return $this->carrierCode;
            }
            public function setCarrierCode(string $carrierCode): self
            {
                $this->carrierCode = $carrierCode;
                return $this;
            }
            public function getCarrierTrackingId(): ?string
            {
                return $this->carrierTrackingId;
            }
            public function setCarrierTrackingId(?string $carrierTrackingId): self
            {
                $this->carrierTrackingId = $carrierTrackingId;
                return $this;
            }
            public function getClientTrackingCode(): string
            {
                return $this->clientTrackingCode;
            }
            public function setClientTrackingCode(string $clientTrackingCode): self
            {
                $this->clientTrackingCode = $clientTrackingCode;
                return $this;
            }
            public function getStatus(): string
            {
                return $this->status;
            }
            public function setStatus(string $status): self
            {
                $this->status = $status;
                return $this;
            }
            public function getPickupAddressId(): ?int
            {
                return null;
            }
            public function setPickupAddressId(?int $pickupAddressId): self
            {
                return $this;
            }
            public function getDeliveryAddress(): array
            {
                return [];
            }
            public function setDeliveryAddress(array $address): self
            {
                return $this;
            }
            public function getParcelWeightGrams(): int
            {
                return 0;
            }
            public function setParcelWeightGrams(int $grams): self
            {
                return $this;
            }
            public function getParcelValueCents(): int
            {
                return 0;
            }
            public function setParcelValueCents(int $cents): self
            {
                return $this;
            }
            public function isCodEnabled(): bool
            {
                return false;
            }
            public function setCodEnabled(bool $enabled): self
            {
                return $this;
            }
            public function getCodAmountCents(): int
            {
                return 0;
            }
            public function setCodAmountCents(int $cents): self
            {
                return $this;
            }
            public function getCodCollectedAt(): ?string
            {
                return null;
            }
            public function setCodCollectedAt(?string $timestamp): self
            {
                return $this;
            }
            public function getCodReconciledAt(): ?string
            {
                return null;
            }
            public function setCodReconciledAt(?string $timestamp): self
            {
                return $this;
            }
            public function getLabelUrl(): ?string
            {
                return null;
            }
            public function setLabelUrl(?string $url): self
            {
                return $this;
            }
            public function getLabelPdfStoredAt(): ?string
            {
                return null;
            }
            public function setLabelPdfStoredAt(?string $path): self
            {
                return $this;
            }
            public function getCreatedAt(): ?string
            {
                return $this->createdAt;
            }
            public function getUpdatedAt(): ?string
            {
                return null;
            }
            public function getLastPolledAt(): ?string
            {
                return $this->lastPolledAt;
            }
            public function setLastPolledAt(?string $timestamp): self
            {
                $this->lastPolledAt = $timestamp;
                return $this;
            }
            public function getNextPollAt(): ?string
            {
                return $this->nextPollAt;
            }
            public function setNextPollAt(?string $timestamp): self
            {
                $this->nextPollAt = $timestamp;
                return $this;
            }
            public function getPollStrategy(): string
            {
                return ShipmentInterface::POLL_STRATEGY_ADAPTIVE;
            }
            public function setPollStrategy(string $strategy): self
            {
                return $this;
            }
            public function getWebhookSecret(): ?string
            {
                return null;
            }
            public function setWebhookSecret(?string $secret): self
            {
                return $this;
            }
            public function getFailedAt(): ?string
            {
                return null;
            }
            public function setFailedAt(?string $timestamp): self
            {
                return $this;
            }
            public function getFailureReason(): ?string
            {
                return null;
            }
            public function setFailureReason(?string $reason): self
            {
                return $this;
            }
            public function getMetadata(): array
            {
                return [];
            }
            public function setMetadata(array $metadata): self
            {
                return $this;
            }
        };
        $shipment->shipmentId = $id;
        $shipment->carrierCode = self::CARRIER_CODE;
        $shipment->carrierTrackingId = 'TRK-' . $id;
        $shipment->clientTrackingCode = 'trk-' . $id;
        $shipment->status = $status;
        $shipment->createdAt = $createdAt;
        return $shipment;
    }

    private function buildFreshEvent(): ShipmentEventInterface
    {
        $factory = new \Shubo\ShippingCore\Api\Data\ShipmentEventInterfaceFactory();
        return $factory->create();
    }

    private function assertEventFired(string $name): void
    {
        foreach ($this->capturedEvents as $event) {
            if ($event['name'] === $name) {
                return;
            }
        }
        self::fail(sprintf(
            'Expected event "%s" to be dispatched. Got: [%s]',
            $name,
            implode(', ', array_column($this->capturedEvents, 'name')),
        ));
    }

    private function assertEventNotFired(string $name): void
    {
        foreach ($this->capturedEvents as $event) {
            if ($event['name'] === $name) {
                self::fail(sprintf('Did not expect event "%s" to be dispatched.', $name));
            }
        }
    }
}
