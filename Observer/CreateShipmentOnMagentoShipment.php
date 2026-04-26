<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Shipment as MagentoShipment;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\ShipmentOrchestratorInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;

/**
 * Observer on `sales_order_shipment_save_after`.
 *
 * Creates a {@see \Shubo\ShippingCore\Api\Data\ShipmentInterface} row via the
 * orchestrator when a Magento shipment is saved. The observer is intentionally
 * minimal:
 *
 *   1. Extract `magento_shipment_id`, `order_id` from the saved shipment.
 *   2. Resolve `merchant_id` by dispatching `shubo_shipping_resolve_merchant_for_order`
 *      with a mutable DataObject — duka-side observers (e.g. Shubo_Merchant)
 *      answer by writing `merchant_id` onto the object. This preserves the
 *      open-source-first invariant: Core has no concrete dependency on Shubo_Merchant.
 *   3. Build a {@see ShipmentRequest} and call
 *      {@see ShipmentOrchestratorInterface::dispatch()}.
 *
 * Idempotency: `client_tracking_code` is derived deterministically from the
 * Magento shipment id (`mshp_<id>`), so Magento firing the event twice cannot
 * create two rows — the orchestrator's idempotency store returns the existing
 * row on the second call.
 *
 * Failure handling: the observer never throws. Any failure during orchestrator
 * dispatch is logged but does not bubble, so the Magento shipment save cannot
 * be rolled back by a carrier outage.
 */
class CreateShipmentOnMagentoShipment implements ObserverInterface
{
    public const EVENT_RESOLVE_MERCHANT = 'shubo_shipping_resolve_merchant_for_order';

    public function __construct(
        private readonly ShipmentOrchestratorInterface $orchestrator,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly EventManagerInterface $eventManager,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function execute(Observer $observer): void
    {
        $rawShipment = $observer->getEvent()->getData('shipment');
        if (!$rawShipment instanceof MagentoShipment) {
            return;
        }
        $magentoShipmentId = (int)($rawShipment->getEntityId() ?? 0);
        if ($magentoShipmentId === 0) {
            return;
        }

        $order = $rawShipment->getOrder();
        if (!$order instanceof SalesOrder) {
            return;
        }
        $orderId = (int)($order->getEntityId() ?? 0);
        if ($orderId === 0) {
            return;
        }

        // Short-circuit: if a Core shipment row already exists for this Magento
        // shipment, there is nothing to do. The idempotency key in the
        // orchestrator also enforces this, but checking up front avoids a
        // redundant DB write + event dispatch on repeated saves.
        $clientTrackingCode = sprintf('mshp_%d', $magentoShipmentId);
        try {
            $this->shipmentRepository->getByClientTrackingCode($clientTrackingCode);
            return;
        } catch (NoSuchEntityException) {
            // expected: no existing row — continue and create one.
        }

        $merchantId = $this->resolveMerchantId($orderId);
        if ($merchantId === null) {
            // No merchant resolved — expected for non-marketplace orders
            // (e.g. admin-placed orders on the default scope). Stay silent.
            return;
        }

        $carrierCode = $this->resolveCarrierCode($order);
        if ($carrierCode === null || $carrierCode === '') {
            // No mapped carrier — a future phase will wire carrier selection
            // at checkout. For now an absent carrier means we can't dispatch,
            // but should not fail the Magento save.
            $this->logger->logDispatchFailed(
                'unknown',
                'observer.create_shipment',
                new \RuntimeException(sprintf(
                    'No carrier resolved for order %d / magento shipment %d',
                    $orderId,
                    $magentoShipmentId,
                )),
            );
            return;
        }

        try {
            $request = $this->buildRequest(
                $order,
                $rawShipment,
                $orderId,
                $merchantId,
                $carrierCode,
                $clientTrackingCode,
                $magentoShipmentId,
            );
            $this->orchestrator->dispatch($request);
        } catch (\Throwable $e) {
            // Observer must never throw — a carrier outage shouldn't roll back
            // the Magento shipment save. Orchestrator has already logged and
            // published to DLQ; we add one more log line so the lineage to the
            // observer is explicit.
            $this->logger->logDispatchFailed(
                $carrierCode,
                'observer.create_shipment',
                $e,
            );
        }
    }

    /**
     * Dispatch `shubo_shipping_resolve_merchant_for_order` so duka-side
     * observers (e.g. Shubo_Merchant) can answer. The event carries a mutable
     * DataObject; the answering observer sets `merchant_id` on it.
     *
     * Returns null if no observer answered.
     */
    private function resolveMerchantId(int $orderId): ?int
    {
        $payload = new DataObject(['order_id' => $orderId, 'merchant_id' => null]);
        $this->eventManager->dispatch(
            self::EVENT_RESOLVE_MERCHANT,
            ['order_id' => $orderId, 'result' => $payload],
        );
        $resolved = $payload->getData('merchant_id');
        if ($resolved === null) {
            return null;
        }
        $asInt = (int)$resolved;
        return $asInt > 0 ? $asInt : null;
    }

    /**
     * Extract the carrier code from the Magento order's shipping method.
     *
     * Magento shipping_method is stored as `<carrier>_<method>`; we only care
     * about the carrier part which matches our CarrierRegistry keys.
     */
    private function resolveCarrierCode(SalesOrder $order): ?string
    {
        $method = $order->getShippingMethod();
        if (!is_string($method) || $method === '') {
            return null;
        }
        $parts = explode('_', $method, 2);
        $code = $parts[0] ?? '';
        return $code === '' ? null : $code;
    }

    private function buildRequest(
        SalesOrder $order,
        MagentoShipment $magentoShipment,
        int $orderId,
        int $merchantId,
        string $carrierCode,
        string $clientTrackingCode,
        int $magentoShipmentId,
    ): ShipmentRequest {
        $dest = $this->addressFromOrder($order);
        $origin = new ContactAddress(
            name: '',
            phone: '',
            email: null,
            country: '',
            subdivision: '',
            city: '',
            district: null,
            street: '',
            building: null,
            floor: null,
            apartment: null,
            postcode: null,
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $weightGrams = (int)round(((float)($magentoShipment->getTotalWeight() ?? 0.0)) * 1000.0);
        $declaredValueCents = (int)round(((float)($order->getGrandTotal() ?? 0.0)) * 100.0);

        $parcel = new ParcelSpec(
            weightGrams: max(0, $weightGrams),
            lengthMm: 0,
            widthMm: 0,
            heightMm: 0,
            declaredValueCents: max(0, $declaredValueCents),
        );

        $codEnabled = false;
        $codAmountCents = 0;

        return new ShipmentRequest(
            orderId: $orderId,
            merchantId: $merchantId,
            clientTrackingCode: $clientTrackingCode,
            origin: $origin,
            destination: $dest,
            parcel: $parcel,
            codEnabled: $codEnabled,
            codAmountCents: $codAmountCents,
            preferredCarrierCode: $carrierCode,
            metadata: [
                'magento_shipment_id' => $magentoShipmentId,
                'order_increment_id' => (string)($order->getIncrementId() ?? ''),
                'shippo_rate_object_id' => (string)($order->getData('shippo_rate_object_id') ?? ''),
            ],
        );
    }

    private function addressFromOrder(SalesOrder $order): ContactAddress
    {
        $address = $order->getShippingAddress();
        if ($address === null) {
            return new ContactAddress(
                name: '',
                phone: '',
                email: null,
                country: '',
                subdivision: '',
                city: '',
                district: null,
                street: '',
                building: null,
                floor: null,
                apartment: null,
                postcode: null,
                latitude: null,
                longitude: null,
                instructions: null,
            );
        }

        $street = $address->getStreet();
        $streetLine = is_array($street) ? trim(implode(' ', $street)) : (string)($street ?? '');

        $firstname = (string)($address->getFirstname() ?? '');
        $lastname = (string)($address->getLastname() ?? '');
        $name = trim($firstname . ' ' . $lastname);

        $email = $address->getEmail();
        $postcode = $address->getPostcode();

        return new ContactAddress(
            name: $name,
            phone: (string)($address->getTelephone() ?? ''),
            email: $email !== null ? (string)$email : null,
            country: (string)($address->getCountryId() ?? ''),
            subdivision: (string)($address->getRegionCode() ?? ''),
            city: (string)($address->getCity() ?? ''),
            district: null,
            street: $streetLine,
            building: null,
            floor: null,
            apartment: null,
            postcode: $postcode !== null ? (string)$postcode : null,
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }
}
