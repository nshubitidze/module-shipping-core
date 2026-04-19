<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * TODO(Phase 5b / Phase 4 gap): The concrete
 * {@see \Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface} implementation
 * (Model/Shipment/ShipmentEventRepository) and
 * {@see \Shubo\ShippingCore\Model\Data\ShipmentEvent} are not yet in the
 * repository. Runtime wiring for this poller depends on both. Unit tests
 * cover the poller via interface mocks; a follow-up task must land the
 * concrete implementations and a `<preference>` entry in etc/di.xml before
 * the cron can execute in production. See design doc §7.12.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Tracking;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Shubo\ShippingCore\Api\CarrierGatewayInterface;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Api\Data\Dto\StatusResponse;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterfaceFactory;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\RateLimiterInterface;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Api\TrackingPollerInterface;
use Shubo\ShippingCore\Exception\CircuitOpenException;
use Shubo\ShippingCore\Exception\NoCarrierAvailableException;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Drains the shipment poll queue across all enabled carriers.
 *
 * Implements design-doc §10.3 pseudocode: for each enabled carrier the
 * loop gates on circuit-breaker state, probes the rate limiter for
 * available tokens, then pulls due shipments via
 * {@see ShipmentRepositoryInterface::getDuePolls()}. Every carrier call
 * is wrapped in the circuit breaker so failures contribute to the
 * per-carrier failure window. Every shipment operation produces an append-
 * only row on the event stream so status history is auditable.
 *
 * Rate-limiter interaction: the limiter is probed at two different
 * granularities:
 * - {@see RateLimiterInterface::windowTokens()} gives a snapshot used to
 *   cap the per-carrier batch size. This avoids asking the DB for 500
 *   shipments when only 20 tokens are left.
 * - {@see RateLimiterInterface::acquire()} consumes one token per
 *   shipment. When it returns false mid-batch the loop breaks for this
 *   carrier and lets the next carrier in the registry run.
 *
 * Event dispatching: a status change fires `shubo_shipping_shipment_status_changed`
 * with `['shipment', 'old_status', 'new_status', 'source' => 'poll']`
 * so downstream observers (e.g. merchant notifications) can react.
 * A poll-noop does NOT fire that event — it is purely a timestamp refresh.
 */
class TrackingPoller implements TrackingPollerInterface
{
    private const EVENT_STATUS_CHANGED = 'shubo_shipping_shipment_status_changed';
    private const SOURCE_POLL = 'poll';

    public function __construct(
        private readonly CarrierRegistryInterface $registry,
        private readonly CircuitBreakerInterface $circuitBreaker,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShipmentEventRepositoryInterface $eventRepository,
        private readonly NextPollCalculator $nextPollCalculator,
        private readonly EventManagerInterface $eventManager,
        private readonly StructuredLogger $logger,
        private readonly ShipmentEventInterfaceFactory $eventFactory,
        private readonly DateTime $dateTime,
    ) {
    }

    public function drainBatch(int $maxShipments = 500): int
    {
        $enabled = $this->registry->enabled();
        if ($enabled === []) {
            return 0;
        }

        $polled = 0;
        $carrierCount = count($enabled);
        $perCarrierCap = max(1, intdiv($maxShipments, $carrierCount));

        foreach ($enabled as $code => $gateway) {
            if ($this->circuitBreaker->stateOf($code) === CircuitBreakerStateInterface::STATE_OPEN) {
                $this->logSkip($code, 'skip_open');
                continue;
            }

            $cap = $this->rateLimiter->windowTokens($code);
            if ($cap === 0) {
                $this->logSkip($code, 'skip_rate_limited');
                continue;
            }

            $limit = max(1, min($cap, $perCarrierCap));
            $dueShipments = $this->shipmentRepository->getDuePolls($limit, $code);

            $polled += $this->pollCarrierBatch($code, $gateway, $dueShipments);
        }

        return $polled;
    }

    public function pollOne(int $shipmentId): ShipmentInterface
    {
        $shipment = $this->shipmentRepository->getById($shipmentId);
        $code = $shipment->getCarrierCode();
        if (!$this->registry->has($code)) {
            throw new NoCarrierAvailableException(
                __('Carrier "%1" is not registered; cannot refresh status.', $code),
            );
        }

        $gateway = $this->registry->get($code);
        $trackingId = (string)$shipment->getCarrierTrackingId();
        $response = $gateway->getShipmentStatus($trackingId);

        $this->applyPollResult($shipment, $response, $code);

        return $shipment;
    }

    /**
     * @param array<int, ShipmentInterface> $dueShipments
     */
    private function pollCarrierBatch(string $code, CarrierGatewayInterface $gateway, array $dueShipments): int
    {
        $polled = 0;

        foreach ($dueShipments as $shipment) {
            if (!$this->rateLimiter->acquire($code, 1)) {
                break;
            }

            try {
                /** @var StatusResponse $response */
                $response = $this->circuitBreaker->execute(
                    $code,
                    fn (): StatusResponse => $gateway->getShipmentStatus(
                        (string)$shipment->getCarrierTrackingId(),
                    ),
                );
            } catch (CircuitOpenException) {
                break;
            } catch (\Throwable $e) {
                $this->recordPollFailure($shipment, $code, $e);
                continue;
            }

            $this->applyPollResult($shipment, $response, $code);
            $polled++;
        }

        return $polled;
    }

    /**
     * Apply a successful poll response: either a status-change or a noop
     * event + shipment save. Mirrors §10.3 pseudocode.
     */
    private function applyPollResult(
        ShipmentInterface $shipment,
        StatusResponse $response,
        string $code,
    ): void {
        $old = $shipment->getStatus();
        $new = $response->normalizedStatus;
        $nowTimestamp = gmdate('Y-m-d H:i:s', (int)$this->dateTime->gmtTimestamp());

        if ($old === $new) {
            $this->saveEvent(
                shipment: $shipment,
                carrierCode: $code,
                eventType: ShipmentEventInterface::EVENT_TYPE_POLL_NOOP,
                carrierStatusRaw: $response->carrierStatusRaw,
                normalizedStatus: $new,
                occurredAt: $response->occurredAt,
                rawPayload: $response->raw,
            );
            $shipment->setLastPolledAt($nowTimestamp);
            $shipment->setNextPollAt($this->nextPollCalculator->computeNextPollAt($shipment));
            $this->shipmentRepository->save($shipment);
            return;
        }

        $this->saveEvent(
            shipment: $shipment,
            carrierCode: $code,
            eventType: ShipmentEventInterface::EVENT_TYPE_STATUS_CHANGE,
            carrierStatusRaw: $response->carrierStatusRaw,
            normalizedStatus: $new,
            occurredAt: $response->occurredAt,
            rawPayload: $response->raw,
        );

        $shipment->setStatus($new);
        $shipment->setLastPolledAt($nowTimestamp);
        $shipment->setNextPollAt($this->nextPollCalculator->computeNextPollAt($shipment, $new));
        $this->shipmentRepository->save($shipment);

        $this->eventManager->dispatch(
            self::EVENT_STATUS_CHANGED,
            [
                'shipment' => $shipment,
                'old_status' => $old,
                'new_status' => $new,
                'source' => self::SOURCE_POLL,
            ],
        );
    }

    /**
     * Persist a FAILED event without changing the shipment status. Reschedule
     * next_poll_at with the same adaptive bucket so a transient upstream
     * blip is retried on the next cron tick.
     */
    private function recordPollFailure(ShipmentInterface $shipment, string $code, \Throwable $e): void
    {
        $this->logger->logDispatchFailed($code, 'poll_carrier_exception', $e);

        $this->saveEvent(
            shipment: $shipment,
            carrierCode: $code,
            eventType: ShipmentEventInterface::EVENT_TYPE_FAILED,
            carrierStatusRaw: $e->getMessage(),
            normalizedStatus: $shipment->getStatus(),
            occurredAt: null,
            rawPayload: ['exception_class' => $e::class],
        );

        $shipment->setNextPollAt($this->nextPollCalculator->computeNextPollAt($shipment));
        $this->shipmentRepository->save($shipment);
    }

    /**
     * @param array<string, mixed> $rawPayload
     */
    private function saveEvent(
        ShipmentInterface $shipment,
        string $carrierCode,
        string $eventType,
        ?string $carrierStatusRaw,
        ?string $normalizedStatus,
        ?string $occurredAt,
        array $rawPayload,
    ): void {
        $event = $this->eventFactory->create();
        $event->setShipmentId((int)$shipment->getShipmentId());
        $event->setCarrierCode($carrierCode);
        $event->setEventType($eventType);
        $event->setCarrierStatusRaw($carrierStatusRaw);
        $event->setNormalizedStatus($normalizedStatus);
        $event->setOccurredAt($occurredAt);
        $event->setSource(ShipmentEventInterface::SOURCE_POLL);
        $event->setRawPayload($rawPayload);

        $this->eventRepository->save($event);
    }

    /**
     * Log a per-carrier skip decision. `skip_rate_limited` re-uses the rate-
     * limit channel (tokens=0); `skip_open` defers to the breaker which
     * logged the transition at the moment it tripped.
     */
    private function logSkip(string $code, string $reason): void
    {
        if ($reason === 'skip_rate_limited') {
            $this->logger->logRateLimit($code, 0);
        }
        // skip_open: breaker already emitted a transition log when it tripped;
        // we intentionally do not duplicate that here.
    }
}
