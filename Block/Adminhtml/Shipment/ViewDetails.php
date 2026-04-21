<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Block\Adminhtml\Shipment;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Shubo\ShippingCore\Api\Data\ShipmentEventInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentEventRepositoryInterface;
use Shubo\ShippingCore\Controller\Adminhtml\Shipments\View as ViewController;

/**
 * Admin block backing the shipment detail page.
 *
 * Pulls the current shipment from the Registry (written by
 * {@see ViewController}) and looks up its event timeline via the
 * ShipmentEvent repository. Template renders a two-column layout: top
 * summary + event log.
 */
class ViewDetails extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $coreRegistry,
        private readonly ShipmentEventRepositoryInterface $eventRepository,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getShipment(): ?ShipmentInterface
    {
        $shipment = $this->coreRegistry->registry(ViewController::REGISTRY_KEY);
        return $shipment instanceof ShipmentInterface ? $shipment : null;
    }

    /**
     * Event timeline in insertion order (newest first).
     *
     * @return list<ShipmentEventInterface>
     */
    public function getEvents(): array
    {
        $shipment = $this->getShipment();
        if ($shipment === null) {
            return [];
        }
        $id = $shipment->getShipmentId();
        if ($id === null || $id <= 0) {
            return [];
        }
        try {
            return $this->eventRepository->getByShipmentId($id, 200);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Decoded delivery address for template rendering.
     *
     * @return array<string, mixed>
     */
    public function getDeliveryAddress(): array
    {
        $shipment = $this->getShipment();
        if ($shipment === null) {
            return [];
        }
        return $shipment->getDeliveryAddress();
    }

    /**
     * Human-readable status chip class for the current shipment status.
     */
    public function getStatusBadgeClass(): string
    {
        $shipment = $this->getShipment();
        $status = $shipment?->getStatus() ?? '';
        return match ($status) {
            ShipmentInterface::STATUS_DELIVERED => 'grid-severity-notice',
            ShipmentInterface::STATUS_CANCELLED,
            ShipmentInterface::STATUS_RETURNED_TO_SENDER,
            ShipmentInterface::STATUS_FAILED => 'grid-severity-critical',
            default => 'grid-severity-minor',
        };
    }

    /**
     * Convert a tetri integer to a GEL-formatted string (2 decimals).
     */
    public function formatMoneyTetri(int $cents): string
    {
        return bcdiv((string)$cents, '100', 2);
    }
}
