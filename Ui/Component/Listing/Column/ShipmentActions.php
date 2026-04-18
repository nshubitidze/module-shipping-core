<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row-action column for the shipments grid.
 *
 * Renders four links per row: Mark Delivered / Returned / COD Reconciled /
 * Cancelled. Each points at the matching admin controller and carries a
 * confirmation dialog so accidental clicks don't fire `shubo_shipping_*`
 * events.
 *
 * The URLs are relative to the admin frontend — Magento's UrlBuilder
 * appends the secret/store keys for us.
 */
class ShipmentActions extends Column
{
    private const ROUTE_DELIVERED = 'shubo_shipping_admin/shipments/markDelivered';
    private const ROUTE_RETURNED = 'shubo_shipping_admin/shipments/markReturned';
    private const ROUTE_COD = 'shubo_shipping_admin/shipments/markCodReconciled';
    private const ROUTE_CANCELLED = 'shubo_shipping_admin/shipments/markCancelled';

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = [],
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = (string)$this->getData('name');

        /** @var array<int, array<string, mixed>> $items */
        $items = $dataSource['data']['items'];

        foreach ($items as $index => $item) {
            $shipmentId = (int)($item['shipment_id'] ?? 0);
            if ($shipmentId <= 0) {
                continue;
            }

            $items[$index][$name] = [
                'mark_delivered' => [
                    'href'    => $this->urlBuilder->getUrl(
                        self::ROUTE_DELIVERED,
                        ['shipment_id' => $shipmentId],
                    ),
                    'label'   => __('Mark Delivered'),
                    'confirm' => [
                        'title'   => __('Mark shipment delivered'),
                        'message' => __(
                            'Mark shipment #%1 as delivered? This dispatches shubo_shipping_delivered.',
                            $shipmentId,
                        ),
                    ],
                ],
                'mark_returned' => [
                    'href'    => $this->urlBuilder->getUrl(
                        self::ROUTE_RETURNED,
                        ['shipment_id' => $shipmentId],
                    ),
                    'label'   => __('Mark Returned'),
                    'confirm' => [
                        'title'   => __('Mark shipment returned'),
                        'message' => __(
                            'Mark shipment #%1 as returned to sender?',
                            $shipmentId,
                        ),
                    ],
                ],
                'mark_cod_reconciled' => [
                    'href'    => $this->urlBuilder->getUrl(
                        self::ROUTE_COD,
                        ['shipment_id' => $shipmentId],
                    ),
                    'label'   => __('Mark COD Collected'),
                    'confirm' => [
                        'title'   => __('Mark COD reconciled'),
                        'message' => __(
                            'Mark COD for shipment #%1 as collected and reconciled?',
                            $shipmentId,
                        ),
                    ],
                ],
                'mark_cancelled' => [
                    'href'    => $this->urlBuilder->getUrl(
                        self::ROUTE_CANCELLED,
                        ['shipment_id' => $shipmentId],
                    ),
                    'label'   => __('Mark Cancelled'),
                    'confirm' => [
                        'title'   => __('Cancel shipment'),
                        'message' => __(
                            'Cancel shipment #%1? This dispatches shubo_shipping_cancelled.',
                            $shipmentId,
                        ),
                    ],
                ],
            ];
        }

        $dataSource['data']['items'] = $items;
        return $dataSource;
    }
}
