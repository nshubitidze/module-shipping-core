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
use Shubo\ShippingCore\Controller\Adminhtml\Shipments\MarkDelivered;

/**
 * Unit tests for {@see MarkDelivered}.
 *
 * Covers:
 *  - Happy path: status set to delivered, event dispatched, flash success.
 *  - Invalid shipment_id (0 or missing).
 *  - Shipment not found.
 *  - Already-terminal status: action rejected.
 *  - Repository save throws: error flashed, no exception bubbles.
 */
class MarkDeliveredTest extends TestCase
{
    private MarkDelivered $controller;

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

        $this->controller = new MarkDelivered(
            $context,
            $this->repository,
            $this->eventManager,
            $this->logger,
        );
    }

    public function testExecuteDispatchesEventAndSavesShipment(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('42');

        $shipment = $this->makeShipment(42, ShipmentInterface::STATUS_IN_TRANSIT);
        $shipment->expects($this->once())
            ->method('setStatus')
            ->with(ShipmentInterface::STATUS_DELIVERED);

        $this->repository->method('getById')->with(42)->willReturn($shipment);
        $this->repository->expects($this->once())->method('save')->with($shipment)->willReturn($shipment);

        $this->eventManager->expects($this->once())
            ->method('dispatch')
            ->with('shubo_shipping_delivered', ['shipment' => $shipment]);

        $this->messageManager->expects($this->once())->method('addSuccessMessage');

        $this->controller->execute();
    }

    public function testExecuteRejectsInvalidShipmentId(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('0');

        $this->repository->expects($this->never())->method('getById');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('Invalid shipment ID'));

        $this->controller->execute();
    }

    public function testExecuteReturnsErrorWhenShipmentNotFound(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('99');

        $this->repository->method('getById')
            ->with(99)
            ->willThrowException(new NoSuchEntityException(__('Not found')));

        $this->eventManager->expects($this->never())->method('dispatch');
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('not found'));

        $this->controller->execute();
    }

    public function testExecuteRejectsTerminalStatus(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('7');

        $shipment = $this->makeShipment(7, ShipmentInterface::STATUS_DELIVERED);
        $shipment->expects($this->never())->method('setStatus');
        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->expects($this->never())->method('save');
        $this->eventManager->expects($this->never())->method('dispatch');

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('terminal state'));

        $this->controller->execute();
    }

    public function testExecuteHandlesRepositorySaveException(): void
    {
        $this->request->method('getParam')->with('shipment_id')->willReturn('13');

        $shipment = $this->makeShipment(13, ShipmentInterface::STATUS_IN_TRANSIT);
        $this->repository->method('getById')->willReturn($shipment);
        $this->repository->method('save')
            ->willThrowException(new \RuntimeException('DB down'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('MarkDelivered'), $this->anything());

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($this->stringContains('Could not mark shipment delivered'));

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
