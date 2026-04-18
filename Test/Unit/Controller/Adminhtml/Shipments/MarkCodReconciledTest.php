<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Controller\Adminhtml\Shipments;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Controller\Adminhtml\Shipments\MarkCodReconciled;

class MarkCodReconciledTest extends TestCase
{
    private MarkCodReconciled $controller;

    /** @var ShipmentRepositoryInterface&MockObject */
    private ShipmentRepositoryInterface $repository;

    /** @var EventManagerInterface&MockObject */
    private EventManagerInterface $eventManager;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var MessageManagerInterface&MockObject */
    private MessageManagerInterface $messageManager;

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->messageManager = $this->createMock(MessageManagerInterface::class);
        $this->request = $this->createMock(RequestInterface::class);

        $redirectResult = $this->createMock(Redirect::class);
        $redirectResult->method('setPath')->willReturnSelf();

        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirectResult);

        /** @var Context&MockObject $context */
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getMessageManager')->willReturn($this->messageManager);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);

        $this->controller = new MarkCodReconciled(
            $context,
            $this->repository,
            $this->eventManager,
            $this->logger,
        );
    }

    public function testExecuteDispatchesCodReconciledEvent(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('55');
        $shipment = $this->makeShipment(55, 500, null);

        $shipment->expects($this->once())
            ->method('setCodReconciledAt')
            ->with($this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'));

        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->expects($this->once())->method('save');

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('shubo_shipping_cod_reconciled', [
                'shipment' => $shipment,
                'invoice_line' => null,
            ]);

        $this->messageManager->expects($this->once())->method('addSuccessMessage');

        $this->controller->execute();
    }

    public function testExecuteRejectsShipmentWithoutCod(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('12');
        $shipment = $this->makeShipment(12, 0, null);

        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->expects($this->never())->method('save');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('COD not applicable'));

        $this->controller->execute();
    }

    public function testExecuteRejectsAlreadyReconciled(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('33');
        $shipment = $this->makeShipment(33, 500, '2026-04-17 12:00:00');

        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->expects($this->never())->method('save');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('already reconciled'));

        $this->controller->execute();
    }

    public function testExecuteRejectsInvalidId(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('0');
        $this->repository->expects($this->never())->method('getById');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->controller->execute();
    }

    public function testExecuteHandlesNotFound(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('44');
        $this->repository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('Not found')));
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('not found'));
        $this->controller->execute();
    }

    public function testExecuteHandlesSaveException(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('21');
        $shipment = $this->makeShipment(21, 500, null);
        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->method('save')->willThrowException(new \RuntimeException('x'));
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('MarkCodReconciled'), $this->anything());
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('Could not mark COD reconciled'));
        $this->controller->execute();
    }

    /**
     * @return ShipmentInterface&MockObject
     */
    private function makeShipment(int $id, int $codCents, ?string $reconciledAt): ShipmentInterface
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getShipmentId')->willReturn($id);
        $shipment->method('getStatus')->willReturn(ShipmentInterface::STATUS_IN_TRANSIT);
        $shipment->method('getCodAmountCents')->willReturn($codCents);
        $shipment->method('getCodReconciledAt')->willReturn($reconciledAt);
        return $shipment;
    }
}
