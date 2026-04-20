<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Model\Webhook;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\Data\Dto\DispatchResult;
use Shubo\ShippingCore\Api\Data\Dto\WebhookResult;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterfaceFactory;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Api\WebhookHandlerInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Central webhook dispatcher (design-doc §11.2).
 *
 * Adapter modules register one {@see WebhookHandlerInterface} per carrier
 * code via the DI `handlers` array argument (see §8 composition pattern).
 * This class owns every side-effect that follows a webhook: idempotency
 * check, event persistence, shipment mutation, event bus dispatch.
 *
 * Handlers are intentionally side-effect-free — they return a
 * {@see WebhookResult} describing the intended effect and the dispatcher
 * decides whether/how to apply it.
 *
 * Error philosophy: the dispatcher translates its own logical outcomes into
 * a {@see DispatchResult} enum. Handler exceptions are rethrown so the HTTP
 * boundary (the controller) can produce a 5xx response and the carrier will
 * retry. Silently swallowing a handler exception would be bad — it hides
 * upstream bugs and eats the carrier's retry budget.
 */
class WebhookDispatcher
{
    /**
     * Shared cap on the raw request body size that carrier webhook entrypoints
     * will read before handing off to {@see self::dispatch()}. Kept here so the
     * frontend controller ({@see \Shubo\ShippingCore\Controller\Webhook\Receive})
     * and the REST entrypoint ({@see WebhookReceiver}) enforce exactly the same
     * budget and any future tuning lives in one place. Bumping this value
     * affects memory exposure for every carrier simultaneously.
     */
    public const MAX_RAW_BODY_BYTES = 1_048_576;

    private const EVENT_STATUS_CHANGED = 'shubo_shipping_shipment_status_changed';
    private const SOURCE_WEBHOOK = 'webhook';

    /**
     * @param array<string, WebhookHandlerInterface> $handlers Keyed by carrier code.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        private readonly CarrierRegistryInterface $registry,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShipmentEventRepositoryInterface $eventRepository,
        private readonly WebhookIdempotencyGuard $idempotencyGuard,
        private readonly EventManagerInterface $eventManager,
        private readonly StructuredLogger $logger,
        private readonly ShipmentEventInterfaceFactory $eventFactory,
        private readonly DateTime $dateTime,
        private readonly array $handlers = [],
    ) {
    }

    /**
     * Dispatch a raw webhook payload for the given carrier.
     *
     * @param array<string, mixed> $headers
     */
    public function dispatch(string $carrierCode, string $rawBody, array $headers): DispatchResult
    {
        $handler = $this->handlers[$carrierCode] ?? null;
        if (!$handler instanceof WebhookHandlerInterface) {
            $this->logEvent(
                'webhook_unknown_carrier',
                [
                    'carrier_code' => $carrierCode,
                    'registered_in_registry' => $this->registry->has($carrierCode),
                ],
            );
            return DispatchResult::unknownCarrier();
        }

        $result = $handler->handle($rawBody, $headers);

        if ($result->status === WebhookResult::STATUS_REJECTED) {
            $this->logEvent(
                'webhook_rejected',
                [
                    'carrier_code' => $carrierCode,
                    'reason' => $result->rejectionReason,
                ],
            );
            return DispatchResult::rejected($result->rejectionReason);
        }

        if ($result->status === WebhookResult::STATUS_DUPLICATE) {
            $this->logEvent(
                'webhook_duplicate',
                [
                    'carrier_code' => $carrierCode,
                    'source' => 'handler',
                ],
            );
            return DispatchResult::duplicate($result->externalEventId);
        }

        // STATUS_ACCEPTED path
        $resolvedId = $this->idempotencyGuard->resolveExternalEventId($result, $rawBody);
        if ($this->idempotencyGuard->isDuplicate($carrierCode, $resolvedId)) {
            $this->logEvent(
                'webhook_duplicate',
                [
                    'carrier_code' => $carrierCode,
                    'source' => 'db',
                    'external_event_id' => $resolvedId,
                ],
            );
            return DispatchResult::duplicate($resolvedId);
        }

        $trackingId = (string)$result->carrierTrackingId;
        try {
            $shipment = $this->shipmentRepository->getByCarrierTrackingId($carrierCode, $trackingId);
        } catch (NoSuchEntityException) {
            $this->logEvent(
                'webhook_shipment_not_found',
                [
                    'carrier_code' => $carrierCode,
                    'carrier_tracking_id' => $trackingId,
                    'external_event_id' => $resolvedId,
                ],
            );
            return DispatchResult::rejected('shipment_not_found');
        }

        try {
            $this->persistWebhookEvent($shipment, $carrierCode, $result, $resolvedId);
        } catch (WebhookDuplicateRaceException) {
            // Concurrent webhook delivered the same (carrier_code, external_event_id)
            // between the pre-save isDuplicate() probe and the INSERT. The unique
            // index on shubo_shipping_shipment_event already did its job — answer
            // 200 DUPLICATE so the carrier does not retry a payload that is in fact
            // persisted.
            return DispatchResult::duplicate($resolvedId);
        }

        $old = $shipment->getStatus();
        $new = $result->normalizedStatus;
        if ($new !== null && $new !== $old) {
            $shipment->setStatus($new);
            $this->shipmentRepository->save($shipment);

            $this->eventManager->dispatch(
                self::EVENT_STATUS_CHANGED,
                [
                    'shipment' => $shipment,
                    'old_status' => $old,
                    'new_status' => $new,
                    'source' => self::SOURCE_WEBHOOK,
                ],
            );
        }

        $this->logEvent(
            'webhook_accepted',
            [
                'carrier_code' => $carrierCode,
                'shipment_id' => $shipment->getShipmentId(),
                'external_event_id' => $resolvedId,
                'status_changed' => $new !== null && $new !== $old,
            ],
        );

        return DispatchResult::accepted($resolvedId);
    }

    /**
     * Persist a webhook_received row on the event stream.
     *
     * A {@see CouldNotSaveException} from the event repository is interpreted
     * as a concurrent-duplicate race when a post-catch re-probe of
     * {@see WebhookIdempotencyGuard::isDuplicate()} returns true — i.e. the
     * sibling request won the INSERT between our pre-save probe and our save.
     * In that case we raise the internal
     * {@see WebhookDuplicateRaceException} which {@see self::dispatch()} maps
     * to a 200 DUPLICATE. Every other save failure is rethrown so the HTTP
     * boundary can produce a 5xx and the carrier retries.
     *
     * @throws WebhookDuplicateRaceException When the save failure is a race.
     * @throws CouldNotSaveException         When the save failure is not a race.
     */
    private function persistWebhookEvent(
        ShipmentInterface $shipment,
        string $carrierCode,
        WebhookResult $result,
        string $resolvedExternalEventId,
    ): void {
        $event = $this->eventFactory->create();
        $event->setShipmentId((int)$shipment->getShipmentId());
        $event->setCarrierCode($carrierCode);
        $event->setEventType(ShipmentEventInterface::EVENT_TYPE_WEBHOOK_RECEIVED);
        $event->setCarrierStatusRaw(null);
        $event->setNormalizedStatus($result->normalizedStatus);
        $event->setOccurredAt($result->occurredAt);
        $event->setSource(ShipmentEventInterface::SOURCE_WEBHOOK);
        $event->setExternalEventId($resolvedExternalEventId);
        $event->setRawPayload($this->decodePayload($result->rawPayload));

        try {
            $this->eventRepository->save($event);
        } catch (CouldNotSaveException $e) {
            if ($this->idempotencyGuard->isDuplicate($carrierCode, $resolvedExternalEventId)) {
                $this->logEvent(
                    'webhook_duplicate_race_resolved',
                    [
                        'carrier_code' => $carrierCode,
                        'external_event_id' => $resolvedExternalEventId,
                    ],
                );
                throw new WebhookDuplicateRaceException(
                    sprintf(
                        'Duplicate race resolved for carrier "%s" event "%s".',
                        $carrierCode,
                        $resolvedExternalEventId,
                    ),
                    0,
                    $e,
                );
            }
            // Not a race — a real save failure. Let it bubble so the HTTP
            // boundary produces 5xx and the carrier retries.
            throw $e;
        }
    }

    /**
     * Return the webhook payload as an associative array suitable for the
     * `raw_payload_json` column. Falls back to wrapping the raw string
     * when it is not valid JSON (some carriers ship form-encoded or
     * text payloads).
     *
     * @return array<string, mixed>
     */
    private function decodePayload(string $rawPayload): array
    {
        if ($rawPayload === '') {
            return ['raw' => ''];
        }
        $decoded = json_decode($rawPayload, true);
        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }
        return ['raw' => $rawPayload];
    }

    /**
     * Tiny logger bridge — keeps each dispatcher decision on its own JSON
     * line in var/log/shubo_shipping.log so the receive flow is auditable
     * end to end. Uses DateTime for the `received_at` stamp so tests can
     * freeze time.
     *
     * @param array<string, mixed> $ctx
     */
    private function logEvent(string $event, array $ctx): void
    {
        $this->logger->logWebhook(
            event: $event,
            context: $ctx + [
                'received_at' => gmdate('Y-m-d H:i:s', (int)$this->dateTime->gmtTimestamp()),
            ],
        );
    }
}
