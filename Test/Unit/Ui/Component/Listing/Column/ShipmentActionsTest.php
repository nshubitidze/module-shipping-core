<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\Data\Processor;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor as UiProcessor;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Ui\Component\Listing\Column\ShipmentActions;

/**
 * Unit tests for {@see ShipmentActions}.
 *
 * The component must add exactly four row actions per shipment:
 *   - mark_delivered
 *   - mark_returned
 *   - mark_cod_reconciled
 *   - mark_cancelled
 *
 * Each must point at the right admin route and carry a confirm dialog.
 * Rows with a non-positive `shipment_id` are skipped (guards against
 * orphan or placeholder rows that might sneak into the data source
 * during fixture replay).
 */
class ShipmentActionsTest extends TestCase
{
    /** @var UrlInterface&MockObject */
    private UrlInterface $urlBuilder;

    private ShipmentActions $column;

    protected function setUp(): void
    {
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $this->urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params): string =>
                '/admin/' . $route . '?shipment_id=' . ($params['shipment_id'] ?? '')
        );

        /** @var ContextInterface&MockObject $uiContext */
        $uiContext = $this->createMock(ContextInterface::class);
        $uiContext->method('getProcessor')->willReturn($this->createMock(UiProcessor::class));

        $uiComponentFactory = $this->createMock(UiComponentFactory::class);

        $this->column = new ShipmentActions(
            $uiContext,
            $uiComponentFactory,
            $this->urlBuilder,
            [],
            ['name' => 'actions'],
        );
    }

    public function testPrepareDataSourceAddsFourActionsPerRow(): void
    {
        $dataSource = [
            'data' => [
                'items' => [
                    ['shipment_id' => 42],
                ],
            ],
        ];

        $processed = $this->column->prepareDataSource($dataSource);

        $actions = $processed['data']['items'][0]['actions'];
        $this->assertArrayHasKey('mark_delivered', $actions);
        $this->assertArrayHasKey('mark_returned', $actions);
        $this->assertArrayHasKey('mark_cod_reconciled', $actions);
        $this->assertArrayHasKey('mark_cancelled', $actions);

        $this->assertStringContainsString(
            'shubo_shipping_admin/shipments/markDelivered',
            $actions['mark_delivered']['href'],
        );
        $this->assertStringContainsString(
            'shubo_shipping_admin/shipments/markReturned',
            $actions['mark_returned']['href'],
        );
        $this->assertStringContainsString(
            'shubo_shipping_admin/shipments/markCodReconciled',
            $actions['mark_cod_reconciled']['href'],
        );
        $this->assertStringContainsString(
            'shubo_shipping_admin/shipments/markCancelled',
            $actions['mark_cancelled']['href'],
        );

        foreach ($actions as $action) {
            $this->assertArrayHasKey('confirm', $action, 'All row actions must carry a confirm dialog.');
        }
    }

    public function testPrepareDataSourceSkipsRowsWithInvalidId(): void
    {
        $dataSource = [
            'data' => [
                'items' => [
                    ['shipment_id' => 0],
                    ['shipment_id' => 77],
                ],
            ],
        ];

        $processed = $this->column->prepareDataSource($dataSource);

        $this->assertArrayNotHasKey('actions', $processed['data']['items'][0]);
        $this->assertArrayHasKey('actions', $processed['data']['items'][1]);
    }

    public function testPrepareDataSourceHandlesEmptyItems(): void
    {
        $dataSource = ['data' => ['items' => []]];
        $processed = $this->column->prepareDataSource($dataSource);
        $this->assertSame($dataSource, $processed);
    }

    public function testPrepareDataSourceReturnsUnchangedWhenNoItemsKey(): void
    {
        $dataSource = ['data' => []];
        $processed = $this->column->prepareDataSource($dataSource);
        $this->assertSame($dataSource, $processed);
    }
}
