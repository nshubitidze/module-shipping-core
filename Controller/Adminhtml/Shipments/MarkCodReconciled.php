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
 * Admin row action: mark a shipment's COD as reconciled.
 *
 * Sets `cod_reconciled_at` on the shipment and dispatches
 * `shubo_shipping_cod_reconciled` so Shubo_Payout's
 * `RecordCodCollectedObserver` writes the ledger entry.
 *
 * Guard: the button is only meaningful for shipments with
 * `cod_amount_cents > 0`. For shipments without COD the action fails cleanly
 * with "COD not applicable" — the same result as the downstream observer's
 * guard, surfaced to the admin earlier for better UX.
 */
class MarkCodReconciled extends Action
{
    public const ADMIN_RESOURCE = 'Shubo_ShippingCore::shipments_manage';

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

        if ($shipment->getCodAmountCents() <= 0) {
            $this->messageManager->addErrorMessage((string)__('COD not applicable for this shipment.'));
            return $redirect;
        }

        if ($shipment->getCodReconciledAt() !== null) {
            $this->messageManager->addErrorMessage(
                (string)__('COD already reconciled at %1.', $shipment->getCodReconciledAt()),
            );
            return $redirect;
        }

        try {
            $shipment->setCodReconciledAt(gmdate('Y-m-d H:i:s'));
            $this->shipmentRepository->save($shipment);

            $this->eventManager->dispatch(
                'shubo_shipping_cod_reconciled',
                [
                    'shipment' => $shipment,
                    'invoice_line' => null,
                ],
            );

            $this->messageManager->addSuccessMessage(
                (string)__('COD for shipment #%1 marked reconciled.', $shipmentId),
            );
        } catch (\Throwable $e) {
            $this->logger->error('MarkCodReconciled controller failed.', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                (string)__('Could not mark COD reconciled: %1', $e->getMessage()),
            );
        }

        return $redirect;
    }
}
