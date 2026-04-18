<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Api\Data\Search\ShipmentSearchResultsInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Model\ResourceModel\Shipment\Collection as ShipmentCollection;
use Shubo\ShippingCore\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Shubo\ShippingCore\Ui\DataProvider\ShipmentsGridDataProvider;

/**
 * Unit tests for {@see ShipmentsGridDataProvider}.
 *
 * Key behaviours covered:
 *   - sales_order LEFT JOIN applied to the collection at construction time.
 *   - addFilter rewrites `order_increment_id` to the joined alias and
 *     prefixes other fields with `main_table.` to avoid ambiguous columns.
 *   - getListByCriteria builds a SearchCriteria from the request and calls
 *     the repository.
 */
class ShipmentsGridDataProviderTest extends TestCase
{
    /** @var ShipmentCollection&MockObject */
    private ShipmentCollection $collection;

    /** @var ShipmentCollectionFactory&MockObject */
    private ShipmentCollectionFactory $collectionFactory;

    /** @var ShipmentRepositoryInterface&MockObject */
    private ShipmentRepositoryInterface $repository;

    /** @var SearchCriteriaBuilder&MockObject */
    private SearchCriteriaBuilder $criteriaBuilder;

    /** @var FilterBuilder&MockObject */
    private FilterBuilder $filterBuilder;

    /** @var RequestInterface&MockObject */
    private RequestInterface $request;

    /** @var Select&MockObject */
    private Select $select;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('joinLeft')->willReturnSelf();

        $this->collection = $this->createMock(ShipmentCollection::class);
        $this->collection->method('getSelect')->willReturn($this->select);
        $this->collection->method('getTable')->willReturnCallback(static fn (string $t): string => $t);

        $this->collectionFactory = $this->createMock(ShipmentCollectionFactory::class);
        $this->collectionFactory->method('create')->willReturn($this->collection);

        $this->repository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->filterBuilder = $this->createMock(FilterBuilder::class);
        $this->request = $this->createMock(RequestInterface::class);
    }

    public function testConstructorAppliesSalesOrderJoin(): void
    {
        $this->select->expects($this->once())
            ->method('joinLeft')
            ->with(
                ['sales_order' => 'sales_order'],
                'main_table.order_id = sales_order.entity_id',
                ['order_increment_id' => 'increment_id'],
            )
            ->willReturnSelf();

        $this->makeProvider();
    }

    public function testAddFilterRewritesOrderIncrementIdToJoinedAlias(): void
    {
        $provider = $this->makeProvider();

        $filter = $this->createMock(Filter::class);
        $filter->method('getField')->willReturn('order_increment_id');
        $filter->method('getValue')->willReturn('100000042');
        $filter->method('getConditionType')->willReturn('like');

        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('sales_order.increment_id', ['like' => '100000042']);

        $provider->addFilter($filter);
    }

    public function testAddFilterPrefixesMainTableForRegularFields(): void
    {
        $provider = $this->makeProvider();

        $filter = $this->createMock(Filter::class);
        $filter->method('getField')->willReturn(ShipmentInterface::FIELD_STATUS);
        $filter->method('getValue')->willReturn('pending');
        $filter->method('getConditionType')->willReturn('eq');

        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('main_table.' . ShipmentInterface::FIELD_STATUS, ['eq' => 'pending']);

        $provider->addFilter($filter);
    }

    public function testGetListByCriteriaCallsRepository(): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $k): ?string => $k === 'merchant_id' ? '7' : null,
        );

        $filter = $this->createMock(Filter::class);
        $this->filterBuilder->method('setField')->willReturnSelf();
        $this->filterBuilder->method('setConditionType')->willReturnSelf();
        $this->filterBuilder->method('setValue')->willReturnSelf();
        $this->filterBuilder->method('create')->willReturn($filter);

        $this->criteriaBuilder->expects($this->once())
            ->method('setPageSize')
            ->with(20)
            ->willReturnSelf();
        $this->criteriaBuilder->expects($this->once())
            ->method('setCurrentPage')
            ->with(1)
            ->willReturnSelf();
        $this->criteriaBuilder->expects($this->once())
            ->method('addFilters')
            ->with([$filter])
            ->willReturnSelf();

        $criteria = $this->createMock(SearchCriteriaInterface::class);
        $this->criteriaBuilder->method('create')->willReturn($criteria);

        $result = $this->createMock(ShipmentSearchResultsInterface::class);
        $this->repository->expects($this->once())
            ->method('getList')
            ->with($criteria)
            ->willReturn($result);

        $provider = $this->makeProvider();
        $this->assertSame($result, $provider->getListByCriteria());
    }

    public function testGetDataReturnsRowsWithShapeForGrid(): void
    {
        $item1 = $this->createItem(['shipment_id' => 1, 'status' => 'pending']);
        $item2 = $this->createItem(['shipment_id' => 2, 'status' => 'delivered']);
        $this->collection->method('getItems')->willReturn([$item1, $item2]);
        $this->collection->method('getSize')->willReturn(2);

        $provider = $this->makeProvider();

        $result = $provider->getData();
        $this->assertSame(2, $result['totalRecords']);
        $this->assertCount(2, $result['items']);
        $this->assertSame(1, $result['items'][0]['shipment_id']);
    }

    private function makeProvider(): ShipmentsGridDataProvider
    {
        return new ShipmentsGridDataProvider(
            'shubo_shipping_shipments_listing_data_source',
            'shipment_id',
            'shipment_id',
            $this->collectionFactory,
            $this->repository,
            $this->criteriaBuilder,
            $this->filterBuilder,
            $this->request,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createItem(array $data): \Magento\Framework\DataObject
    {
        return new \Magento\Framework\DataObject($data);
    }
}
