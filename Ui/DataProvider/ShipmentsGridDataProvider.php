<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Shubo\ShippingCore\Api\Data\Search\ShipmentSearchResultsInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Model\ResourceModel\Shipment\Collection as ShipmentCollection;
use Shubo\ShippingCore\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Data provider for the admin shipments grid.
 *
 * Wraps {@see ShipmentRepositoryInterface::getList()} for programmatic
 * consumers (via {@see self::getListByCriteria()}) while the grid itself
 * reads directly from the collection to pick up the `order_increment_id`
 * column from a LEFT JOIN on `sales_order`.
 *
 * The join is applied here (not in a virtualType-wrapped SearchResult) so
 * the repository remains agnostic of admin-UI concerns — unit tests can
 * drive the provider by mocking the CollectionFactory and the repository
 * independently.
 */
class ShipmentsGridDataProvider extends AbstractDataProvider
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ShipmentCollectionFactory $collectionFactory,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly RequestInterface $request,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $collection = $collectionFactory->create();
        $this->applyOrderJoin($collection);
        $this->collection = $collection;
    }

    /**
     * Apply the `sales_order.increment_id` join to the grid collection.
     */
    private function applyOrderJoin(ShipmentCollection $collection): void
    {
        $collection->getSelect()->joinLeft(
            ['sales_order' => $collection->getTable('sales_order')],
            'main_table.order_id = sales_order.entity_id',
            ['order_increment_id' => 'increment_id'],
        );
    }

    /**
     * Grid-friendly data array: totalRecords + items.
     *
     * @return array{totalRecords: int, items: list<array<string, mixed>>}
     */
    public function getData(): array
    {
        $items = [];
        foreach ($this->getCollection()->getItems() as $item) {
            /** @var \Magento\Framework\DataObject $item */
            $row = $item->getData();
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $rowAssoc */
            $rowAssoc = $row;
            $items[] = $rowAssoc;
        }

        return [
            'totalRecords' => (int)$this->getCollection()->getSize(),
            'items' => array_values($items),
        ];
    }

    /**
     * @inheritDoc
     *
     * The `order_increment_id` virtual column lives on the join alias,
     * not the shipment table — rewrite its FQ column name so filters
     * don't hit an ambiguous-column error.
     */
    public function addFilter(Filter $filter): void
    {
        $field = $filter->getField();
        $conditionType = $filter->getConditionType() ?: 'eq';

        if ($field === 'order_increment_id') {
            $this->getCollection()->addFieldToFilter(
                'sales_order.increment_id',
                [$conditionType => $filter->getValue()],
            );
            return;
        }

        $this->getCollection()->addFieldToFilter(
            'main_table.' . $field,
            [$conditionType => $filter->getValue()],
        );
    }

    /**
     * Return a service-contract search result equivalent to the current
     * request parameters.
     *
     * Exposed for programmatic callers (e.g. future REST controllers) and
     * covered by {@see \Shubo\ShippingCore\Test\Unit\Ui\DataProvider\ShipmentsGridDataProviderTest}
     * so the wiring from request -> SearchCriteria -> repository is
     * explicitly locked down.
     */
    public function getListByCriteria(): ShipmentSearchResultsInterface
    {
        $this->searchCriteriaBuilder->setPageSize(20);
        $this->searchCriteriaBuilder->setCurrentPage(1);

        $merchantId = $this->request->getParam('merchant_id');
        if ($merchantId !== null && is_numeric($merchantId)) {
            $filter = $this->filterBuilder
                ->setField(ShipmentInterface::FIELD_MERCHANT_ID)
                ->setConditionType('eq')
                ->setValue((string)(int)$merchantId)
                ->create();
            $this->searchCriteriaBuilder->addFilters([$filter]);
        }

        $criteria = $this->searchCriteriaBuilder->create();
        return $this->shipmentRepository->getList($criteria);
    }
}
