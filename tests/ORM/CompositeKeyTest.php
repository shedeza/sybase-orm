<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\UniqueEntity;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\Type\TypeCaster;

#[Entity(table: 'order_items')]
#[UniqueEntity(fields: ['sku'])]
class OrderItem
{
    #[Id]
    #[Column(type: 'integer')]
    public int $orderId;

    #[Id]
    #[Column(type: 'integer')]
    public int $itemId;

    #[Column(type: 'string')]
    public string $sku = '';

    #[Column(type: 'integer')]
    public int $quantity = 1;

    public function __construct(int $orderId, int $itemId, string $sku, int $quantity = 1)
    {
        $this->orderId = $orderId;
        $this->itemId = $itemId;
        $this->sku = $sku;
        $this->quantity = $quantity;
    }
}

/**
 * @covers \SybaseORM\ORM\UnitOfWork
 * @covers \SybaseORM\ORM\EntityManager
 * @covers \SybaseORM\Metadata\ClassMetadata
 */
final class CompositeKeyTest extends TestCase
{
    private MetadataReader $metadataReader;
    private SybaseDialect $dialect;
    private TypeCaster $typeCaster;
    private IdentityMap $identityMap;

    protected function setUp(): void
    {
        $this->metadataReader = new MetadataReader();
        $this->dialect = new SybaseDialect();
        $this->typeCaster = new TypeCaster();
        $this->identityMap = new IdentityMap();
    }

    public function testMetadataIdentifiesCompositeKey(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(OrderItem::class);

        $this->assertTrue($metadata->hasCompositeId());
        $idColumns = $metadata->getIdColumns();
        $this->assertCount(2, $idColumns);

        $idFieldNames = array_map(fn($c) => $c->propertyName, $idColumns);
        $this->assertContains('orderId', $idFieldNames);
        $this->assertContains('itemId', $idFieldNames);
    }

    public function testUnitOfWorkRegistersCompositeKeyInIdentityMapOnInsert(): void
    {
        $executedSql = null;
        $connMock = $this->createMock(ConnectionManagerInterface::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$executedSql) {
                $executedSql = $sql;
                return 1;
            });

        $uow = new UnitOfWork(
            connectionManager: $connMock,
            metadataReader: $this->metadataReader,
            dialect: $this->dialect,
            typeCaster: $this->typeCaster,
            identityMap: $this->identityMap,
        );

        $item = new OrderItem(100, 1, 'SKU-ABC', 2);
        $uow->registerNew($item);

        $compositeKey = ['orderId' => 100, 'itemId' => 1];
        $this->assertFalse($this->identityMap->contains(OrderItem::class, $compositeKey));

        $uow->commit();

        // Entity must be registered in IdentityMap after commit
        $this->assertTrue($this->identityMap->contains(OrderItem::class, $compositeKey));
        $this->assertSame($item, $this->identityMap->get(OrderItem::class, $compositeKey));
    }

    public function testUnitOfWorkUpdatesWithCompositeWhereClause(): void
    {
        $executedSql = null;
        $executedParams = null;
        $connMock = $this->createMock(ConnectionManagerInterface::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedSql, &$executedParams) {
                $executedSql = $sql;
                $executedParams = $params;
                return 1;
            });

        $uow = new UnitOfWork(
            connectionManager: $connMock,
            metadataReader: $this->metadataReader,
            dialect: $this->dialect,
            typeCaster: $this->typeCaster,
            identityMap: $this->identityMap,
        );

        $item = new OrderItem(100, 1, 'SKU-ABC', 2);
        $uow->registerClean($item);

        // Modify quantity
        $item->quantity = 5;

        $uow->commit();

        $this->assertNotNull($executedSql);
        $this->assertNotNull($executedParams);
        $this->assertIsString($executedSql);
        $this->assertIsArray($executedParams);

        // Verify UPDATE contains composite WHERE: [order_id] = ? AND [item_id] = ?
        $this->assertStringContainsString('WHERE', $executedSql);
        $this->assertStringContainsString('[order_id] = ?', $executedSql);
        $this->assertStringContainsString('[item_id] = ?', $executedSql);

        // Value 5 for quantity, and 100, 1 for where clause
        $this->assertSame([5, 100, 1], $executedParams);
    }

    public function testUnitOfWorkDeletesWithCompositeWhereClauseAndRemovesFromIdentityMap(): void
    {
        $executedSql = null;
        $executedParams = null;
        $connMock = $this->createMock(ConnectionManagerInterface::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedSql, &$executedParams) {
                $executedSql = $sql;
                $executedParams = $params;
                return 1;
            });

        $uow = new UnitOfWork(
            connectionManager: $connMock,
            metadataReader: $this->metadataReader,
            dialect: $this->dialect,
            typeCaster: $this->typeCaster,
            identityMap: $this->identityMap,
        );

        $item = new OrderItem(100, 1, 'SKU-ABC', 2);
        $uow->registerClean($item);
        $compositeKey = ['orderId' => 100, 'itemId' => 1];
        $this->identityMap->put(OrderItem::class, $compositeKey, $item);

        $this->assertTrue($this->identityMap->contains(OrderItem::class, $compositeKey));

        $uow->registerDeleted($item);
        $uow->commit();

        $this->assertNotNull($executedSql);
        $this->assertIsString($executedSql);

        // Verify DELETE contains composite WHERE
        $this->assertStringContainsString('DELETE FROM [order_items]', $executedSql);
        $this->assertStringContainsString('[order_id] = ? AND [item_id] = ?', $executedSql);
        $this->assertSame([100, 1], $executedParams);

        // Must be removed from IdentityMap
        $this->assertFalse($this->identityMap->contains(OrderItem::class, $compositeKey));
    }
}
