<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Unit\Model\Orchestrator;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shubo\ShippingCore\Model\Logging\StructuredLogger;
use Shubo\ShippingCore\Model\Orchestrator\IdempotencyStore;

/**
 * Unit tests for {@see IdempotencyStore}. Verifies that the primitive runs
 * a `SELECT ... FOR UPDATE` against `shubo_shipping_shipment` keyed by
 * (carrier_code, client_tracking_code) and returns either the persisted
 * shipment_id or null.
 */
class IdempotencyStoreTest extends TestCase
{
    /** @var ResourceConnection&MockObject */
    private ResourceConnection $resource;

    /** @var AdapterInterface&MockObject */
    private AdapterInterface $connection;

    /** @var StructuredLogger&MockObject */
    private StructuredLogger $logger;

    private IdempotencyStore $store;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(StructuredLogger::class);

        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')
            ->willReturnCallback(static fn (string $name): string => $name);
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn (string $id): string => '`' . $id . '`');

        $this->store = new IdempotencyStore($this->resource, $this->logger);
    }

    public function testReturnsExistingShipmentId(): void
    {
        $this->connection->method('fetchOne')->willReturn('42');

        self::assertSame(42, $this->store->findExisting('trackings_ge', 'abc-123'));
    }

    public function testReturnsNullWhenNotFound(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);

        self::assertNull($this->store->findExisting('trackings_ge', 'missing'));
    }

    public function testUsesSelectForUpdateQuery(): void
    {
        $capturedSql = '';
        $capturedParams = [];
        $this->connection->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params) use (&$capturedSql, &$capturedParams): string|false {
                $capturedSql = $sql;
                $capturedParams = $params;
                return '7';
            },
        );

        $this->store->findExisting('trackings_ge', 'abc-123');

        self::assertStringContainsStringIgnoringCase('FOR UPDATE', $capturedSql);
        self::assertStringContainsStringIgnoringCase('shubo_shipping_shipment', $capturedSql);
        self::assertSame(['trackings_ge', 'abc-123'], $capturedParams);
    }
}
