<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Controller\Adminhtml\Shipments;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;

/**
 * Admin row action: mark a shipment as delivered.
 *
 * Updates the shipment status to {@see ShipmentInterface::STATUS_DELIVERED}
 * and dispatches the `shubo_shipping_delivered` event so Shubo_Payout
 * observers can react.
 *
 * Terminal-state guard: shipments already in a terminal status (delivered,
 * returned, cancelled, failed) are rejected with a user-facing error — once
 * a shipment is terminal, status transitions must go through admin manual
 * intervention, not the one-click row action.
 */
class MarkDelivered extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_ShippingCore::shipments_manage';

    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        ShipmentInterface::STATUS_DELIVERED,
        ShipmentInterface::STATUS_RETURNED_TO_SENDER,
        ShipmentInterface::STATUS_CANCELLED,
        ShipmentInterface::STATUS_FAILED,
    ];

    public function __construct(
        Context $context,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly EventManagerInterface $eventManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('shubo_shipping_admin/shipments/index');

        $shipmentId = (int)$this->getRequest()->getParam('shipment_id');
        if ($shipmentId <= 0) {
            $this->messageManager->addErrorMessage((string)__('Invalid shipment ID.'));
            return $redirect;
        }

        try {
            $shipment = $this->shipmentRepository->getById($shipmentId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage((string)__('Shipment not found.'));
            return $redirect;
        }

        if (in_array($shipment->getStatus(), self::TERMINAL_STATUSES, true)) {
            $this->messageManager->addErrorMessage(
                (string)__('Shipment is already in a terminal state (%1).', $shipment->getStatus()),
            );
            return $redirect;
        }

        try {
            $shipment->setStatus(ShipmentInterface::STATUS_DELIVERED);
            $this->shipmentRepository->save($shipment);

            $this->eventManager->dispatch(
                'shubo_shipping_delivered',
                ['shipment' => $shipment],
            );

            $this->messageManager->addSuccessMessage(
                (string)__('Shipment #%1 marked as delivered.', $shipmentId),
            );
        } catch (\Throwable $e) {
            $this->logger->error('MarkDelivered controller failed.', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                (string)__('Could not mark shipment delivered: %1', $e->getMessage()),
            );
        }

        return $redirect;
    }
}
