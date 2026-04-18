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
use Shubo\ShippingCore\Controller\Adminhtml\Shipments\MarkReturned;

class MarkReturnedTest extends TestCase
{
    private MarkReturned $controller;

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

        $this->controller = new MarkReturned(
            $context,
            $this->repository,
            $this->eventManager,
            $this->logger,
        );
    }

    public function testExecuteSetsReturnedStatusAndDispatchesEvent(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('99');
        $shipment = $this->makeShipment(99, ShipmentInterface::STATUS_IN_TRANSIT);
        $shipment->expects($this->once())
            ->method('setStatus')
            ->with(ShipmentInterface::STATUS_RETURNED_TO_SENDER);

        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->expects($this->once())->method('save')->willReturn($shipment);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('shubo_shipping_returned', ['shipment' => $shipment]);

        $this->messageManager->expects($this->once())->method('addSuccessMessage');

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
        $this->request->method('getParam')->with('shipment_id')->willReturn('77');
        $this->repository->method('getById')
            ->willThrowException(new NoSuchEntityException(__('Not found')));
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('not found'));
        $this->controller->execute();
    }

    public function testExecuteRejectsTerminal(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('5');
        $shipment = $this->makeShipment(5, ShipmentInterface::STATUS_CANCELLED);
        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->expects($this->never())->method('save');
        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('terminal state'));
        $this->controller->execute();
    }

    public function testExecuteHandlesSaveException(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('9');
        $shipment = $this->makeShipment(9, ShipmentInterface::STATUS_IN_TRANSIT);
        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->method('save')->willThrowException(new \RuntimeException('x'));
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('MarkReturned'), $this->anything());
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('Could not mark shipment returned'));
        $this->controller->execute();
    }

    /**
     * @return ShipmentInterface&MockObject
     */
    private function makeShipment(int $id, string $status): ShipmentInterface
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getShipmentId')->willReturn($id);
        $shipment->method('getStatus')->willReturn($status);
        return $shipment;
    }
}
