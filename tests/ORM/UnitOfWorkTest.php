<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\Tests\ORM\Fixtures\CompositeKeyEntity;
use SybaseORM\Tests\ORM\Fixtures\CustomerEntity;
use SybaseORM\Tests\ORM\Fixtures\OrderEntity;
use SybaseORM\Tests\ORM\Fixtures\OrderItemEntity;
use SybaseORM\Type\TypeCaster;

/**
 * @covers \SybaseORM\ORM\UnitOfWork
 */
final class UnitOfWorkTest extends TestCase
{
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReader $metadataReader;
    private SybaseDialect $dialect;
    private TypeCaster $typeCaster;
    private IdentityMap $identityMap;
    private UnitOfWork $unitOfWork;

    /** @var list<array{sql: string, params: array}> */
    private array $executedStatements = [];

    /** @var list<array{sql: string, params: array}> */
    private array $executedQueries = [];

    private int $identityCounter = 0;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();

        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = new MetadataReader();
        $this->dialect = new SybaseDialect();
        $this->typeCaster = new TypeCaster();
        $this->identityMap = new IdentityMap();

        $this->executedStatements = [];
        $this->executedQueries = [];
        $this->identityCounter = 0;

        // Track all executeStatement calls
        $this->connectionManager
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []): int {
                $this->executedStatements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        // Track executeQuery calls and return @@identity results
        $this->connectionManager
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params = []): \PDOStatement {
                $this->executedQueries[] = ['sql' => $sql, 'params' => $params];
                $this->identityCounter++;

                $stmt = $this->createMock(\PDOStatement::class);
                $stmt->method('fetch')->willReturn([$this->identityCounter]);
                $stmt->method('closeCursor')->willReturn(true);

                return $stmt;
            });

        $this->unitOfWork = new UnitOfWork(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->identityMap,
        );
    }

    // ---------------------------------------------------------------
    // Task 13.1: Dirty checking tests
    // ---------------------------------------------------------------

    public function testRegisterCleanTakesSnapshot(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Original');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);

        // No changes yet — changeset should be empty
        $changeset = $this->unitOfWork->computeChangeset($order);
        $this->assertEmpty($changeset);
    }

    public function testComputeChangesetDetectsModifiedProperty(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Original');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);

        // Modify a property
        $order->setDescription('Modified');

        $changeset = $this->unitOfWork->computeChangeset($order);

        $this->assertArrayHasKey('description', $changeset);
        $this->assertSame('Original', $changeset['description']['old']);
        $this->assertSame('Modified', $changeset['description']['new']);
    }

    public function testComputeChangesetDetectsMultipleChanges(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Original');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);

        $order->setDescription('Modified');
        $order->setTotal(250.50);

        $changeset = $this->unitOfWork->computeChangeset($order);

        $this->assertCount(2, $changeset);
        $this->assertArrayHasKey('description', $changeset);
        $this->assertArrayHasKey('total', $changeset);
        $this->assertEquals(100.0, $changeset['total']['old']);
        $this->assertEquals(250.50, $changeset['total']['new']);
    }

    public function testComputeChangesetReturnsEmptyForUnregisteredEntity(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Test');

        $changeset = $this->unitOfWork->computeChangeset($order);
        $this->assertEmpty($changeset);
    }

    public function testComputeChangesetIgnoresUnchangedProperties(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Same');
        $order->setTotal(50.0);

        $this->unitOfWork->registerClean($order);

        // Only change description, not total
        $order->setDescription('Different');

        $changeset = $this->unitOfWork->computeChangeset($order);

        $this->assertCount(1, $changeset);
        $this->assertArrayHasKey('description', $changeset);
        $this->assertArrayNotHasKey('total', $changeset);
    }

    // ---------------------------------------------------------------
    // Task 13.2: Commit (flush) with transaction tests
    // ---------------------------------------------------------------

    public function testCommitExecutesInsertForNewEntity(): void
    {
        $this->connectionManager->expects($this->once())->method('beginTransaction');
        $this->connectionManager->expects($this->once())->method('commit');

        $order = new OrderEntity();
        $order->setDescription('New Order');
        $order->setTotal(99.99);

        $this->unitOfWork->registerNew($order);
        $this->unitOfWork->commit();

        // Should have one INSERT statement
        $this->assertCount(1, $this->executedStatements);
        $this->assertStringContainsString('INSERT INTO', $this->executedStatements[0]['sql']);
        $this->assertStringContainsString('[orders]', $this->executedStatements[0]['sql']);

        // Should have queried @@identity
        $this->assertCount(1, $this->executedQueries);
        $this->assertSame('SELECT @@identity', $this->executedQueries[0]['sql']);

        // Identity should be set on entity
        $this->assertSame(1, $order->getId());
    }

    public function testCommitExecutesUpdateForDirtyEntity(): void
    {
        $this->connectionManager->expects($this->once())->method('beginTransaction');
        $this->connectionManager->expects($this->once())->method('commit');

        $order = new OrderEntity();
        $order->setId(42);
        $order->setDescription('Original');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);

        // Modify entity
        $order->setDescription('Updated');

        $this->unitOfWork->commit();

        // Should have one UPDATE statement
        $this->assertCount(1, $this->executedStatements);
        $this->assertStringContainsString('UPDATE', $this->executedStatements[0]['sql']);
        $this->assertStringContainsString('[orders]', $this->executedStatements[0]['sql']);
        $this->assertStringContainsString('[description]', $this->executedStatements[0]['sql']);
    }

    public function testCommitExecutesPartialUpdateOnlyChangedColumns(): void
    {
        $order = new OrderEntity();
        $order->setId(10);
        $order->setDescription('Original');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);

        // Only change description
        $order->setDescription('Changed');

        $this->unitOfWork->commit();

        $this->assertCount(1, $this->executedStatements);
        $sql = $this->executedStatements[0]['sql'];

        // Should contain description column but NOT total column
        $this->assertStringContainsString('[description]', $sql);
        $this->assertStringNotContainsString('[total]', $sql);
    }

    public function testCommitExecutesDeleteForDeletedEntity(): void
    {
        $this->connectionManager->expects($this->once())->method('beginTransaction');
        $this->connectionManager->expects($this->once())->method('commit');

        $order = new OrderEntity();
        $order->setId(5);
        $order->setDescription('To Delete');

        $this->unitOfWork->registerClean($order);
        $this->unitOfWork->registerDeleted($order);
        $this->unitOfWork->commit();

        // Should have one DELETE statement
        $this->assertCount(1, $this->executedStatements);
        $this->assertStringContainsString('DELETE FROM', $this->executedStatements[0]['sql']);
        $this->assertStringContainsString('[orders]', $this->executedStatements[0]['sql']);
    }

    public function testCommitOperationOrder_InsertUpdateDelete(): void
    {
        // New entity
        $newOrder = new OrderEntity();
        $newOrder->setDescription('New');
        $newOrder->setTotal(10.0);

        // Dirty entity
        $dirtyOrder = new OrderEntity();
        $dirtyOrder->setId(20);
        $dirtyOrder->setDescription('Original');
        $dirtyOrder->setTotal(50.0);
        $this->unitOfWork->registerClean($dirtyOrder);
        $dirtyOrder->setDescription('Modified');

        // Deleted entity
        $deletedOrder = new OrderEntity();
        $deletedOrder->setId(30);
        $deletedOrder->setDescription('To Remove');
        $this->unitOfWork->registerClean($deletedOrder);

        $this->unitOfWork->registerNew($newOrder);
        $this->unitOfWork->registerDeleted($deletedOrder);

        $this->unitOfWork->commit();

        // Verify order: INSERT → UPDATE → DELETE
        $this->assertCount(3, $this->executedStatements);
        $this->assertStringContainsString('INSERT INTO', $this->executedStatements[0]['sql']);
        $this->assertStringContainsString('UPDATE', $this->executedStatements[1]['sql']);
        $this->assertStringContainsString('DELETE FROM', $this->executedStatements[2]['sql']);
    }

    public function testCommitRetrievesIdentityAfterInsert(): void
    {
        $this->identityCounter = 41; // Next identity will be 42

        $order = new OrderEntity();
        $order->setDescription('Test');
        $order->setTotal(10.0);

        $this->unitOfWork->registerNew($order);
        $this->unitOfWork->commit();

        $this->assertSame(42, $order->getId());
    }

    public function testCommitRollsBackAndThrowsPersistenceExceptionOnError(): void
    {
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())->method('beginTransaction');
        $connectionManager->expects($this->never())->method('commit');
        $connectionManager->method('executeStatement')
            ->willThrowException(new \RuntimeException('DB error'));
        $connectionManager->expects($this->once())->method('rollback');

        $uow = new UnitOfWork(
            $connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->identityMap,
        );

        $order = new OrderEntity();
        $order->setDescription('Fail');
        $order->setTotal(10.0);

        $uow->registerNew($order);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessageMatches('/DB error/');
        $uow->commit();
    }

    public function testCommitSkipsUpdateWhenNoChanges(): void
    {
        $order = new OrderEntity();
        $order->setId(1);
        $order->setDescription('Unchanged');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);

        // Don't modify anything
        $this->unitOfWork->commit();

        // No statements should be executed (no dirty entities)
        $this->assertEmpty($this->executedStatements);
    }

    // ---------------------------------------------------------------
    // Task 13.3: Cascade persist tests
    // ---------------------------------------------------------------

    public function testCascadePersistRegistersRelatedEntities(): void
    {
        $customer = new CustomerEntity();
        $customer->setName('Alice');

        $order = new OrderEntity();
        $order->setDescription('Cascade Order');
        $order->setTotal(200.0);
        $order->setCustomer($customer);

        // Only register the order as new — customer should be cascaded
        $this->unitOfWork->registerNew($order);
        $this->unitOfWork->commit();

        // Should have 2 INSERTs: customer first (dependency), then order
        $insertStatements = array_filter(
            $this->executedStatements,
            fn(array $s) => str_contains($s['sql'], 'INSERT INTO'),
        );

        $this->assertCount(2, $insertStatements);
        $insertSqls = array_values(array_column($insertStatements, 'sql'));

        // Customer should be inserted before order (FK dependency)
        $this->assertStringContainsString('[customers]', $insertSqls[0]);
        $this->assertStringContainsString('[orders]', $insertSqls[1]);
    }

    public function testCascadePersistOrdersEntitiesRespectingForeignKeys(): void
    {
        $customer = new CustomerEntity();
        $customer->setName('Bob');

        $order = new OrderEntity();
        $order->setDescription('Order 1');
        $order->setTotal(50.0);
        $order->setCustomer($customer);

        $item = new OrderItemEntity();
        $item->setProductName('Widget');
        $item->setQuantity(3);
        $item->setOrder($order);

        // Register only the item — cascade should discover order and customer
        $this->unitOfWork->registerNew($item);
        $this->unitOfWork->commit();

        $insertStatements = array_filter(
            $this->executedStatements,
            fn(array $s) => str_contains($s['sql'], 'INSERT INTO'),
        );

        $this->assertCount(3, $insertStatements);
        $insertSqls = array_values(array_column($insertStatements, 'sql'));

        // Customer → Order → OrderItem (respecting FK dependencies)
        $this->assertStringContainsString('[customers]', $insertSqls[0]);
        $this->assertStringContainsString('[orders]', $insertSqls[1]);
        $this->assertStringContainsString('[order_items]', $insertSqls[2]);
    }

    public function testCascadePersistDoesNotDuplicateAlreadyTrackedEntities(): void
    {
        $customer = new CustomerEntity();
        $customer->setName('Charlie');

        $order = new OrderEntity();
        $order->setDescription('Order');
        $order->setTotal(75.0);
        $order->setCustomer($customer);

        // Register both explicitly
        $this->unitOfWork->registerNew($customer);
        $this->unitOfWork->registerNew($order);
        $this->unitOfWork->commit();

        $insertStatements = array_filter(
            $this->executedStatements,
            fn(array $s) => str_contains($s['sql'], 'INSERT INTO'),
        );

        // Should still be exactly 2 INSERTs, not 3
        $this->assertCount(2, $insertStatements);
    }

    // ---------------------------------------------------------------
    // Task 13.4: Additional unit tests
    // ---------------------------------------------------------------

    public function testClearResetsAllState(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Test');
        $order->setTotal(10.0);

        $this->unitOfWork->registerNew($order);

        $order2 = new OrderEntity();
        $order2->setId(1);
        $order2->setDescription('Clean');
        $this->unitOfWork->registerClean($order2);

        $order3 = new OrderEntity();
        $order3->setId(2);
        $this->unitOfWork->registerDeleted($order3);

        $this->unitOfWork->clear();

        // After clear, commit should do nothing
        $this->unitOfWork->commit();
        $this->assertEmpty($this->executedStatements);
    }

    public function testDeleteRemovesEntityFromIdentityMap(): void
    {
        $order = new OrderEntity();
        $order->setId(99);
        $order->setDescription('To Delete');

        $this->identityMap->put(OrderEntity::class, 99, $order);
        $this->unitOfWork->registerClean($order);
        $this->unitOfWork->registerDeleted($order);

        $this->unitOfWork->commit();

        $this->assertNull($this->identityMap->get(OrderEntity::class, 99));
    }

    public function testInsertRegistersEntityInIdentityMap(): void
    {
        $order = new OrderEntity();
        $order->setDescription('New');
        $order->setTotal(10.0);

        $this->unitOfWork->registerNew($order);
        $this->unitOfWork->commit();

        $generatedId = $order->getId();
        $this->assertNotNull($generatedId);
        $this->assertSame($order, $this->identityMap->get(OrderEntity::class, $generatedId));
    }

    public function testInsertOmitsIdentityColumnFromSQL(): void
    {
        $order = new OrderEntity();
        $order->setDescription('Test');
        $order->setTotal(10.0);

        $this->unitOfWork->registerNew($order);
        $this->unitOfWork->commit();

        $sql = $this->executedStatements[0]['sql'];

        // The id column should be omitted from the INSERT
        $this->assertStringNotContainsString('[id]', $sql);
        $this->assertStringContainsString('[description]', $sql);
        $this->assertStringContainsString('[total]', $sql);
    }

    // ---------------------------------------------------------------
    // Task 3.7: Composite WHERE clause tests
    // ---------------------------------------------------------------

    public function testUpdateSingleKeyWhereClauseBackwardCompat(): void
    {
        $order = new OrderEntity();
        $order->setId(42);
        $order->setDescription('Original');
        $order->setTotal(100.0);

        $this->unitOfWork->registerClean($order);
        $order->setDescription('Updated');

        $this->unitOfWork->commit();

        $this->assertCount(1, $this->executedStatements);
        $sql = $this->executedStatements[0]['sql'];

        // Single-key entity should produce WHERE [id] = ?
        $this->assertStringContainsString('WHERE [id] = ?', $sql);
        // Should NOT contain AND in the WHERE clause (single key)
        $this->assertStringNotContainsString(' AND ', $sql);

        // The last parameter should be the id value
        $params = $this->executedStatements[0]['params'];
        $this->assertSame(42, $params[array_key_last($params)]);
    }

    public function testDeleteSingleKeyWhereClauseBackwardCompat(): void
    {
        $order = new OrderEntity();
        $order->setId(99);
        $order->setDescription('To Delete');

        $this->unitOfWork->registerClean($order);
        $this->unitOfWork->registerDeleted($order);

        $this->unitOfWork->commit();

        $this->assertCount(1, $this->executedStatements);
        $sql = $this->executedStatements[0]['sql'];

        // Single-key entity should produce DELETE FROM [orders] WHERE [id] = ?
        $this->assertStringContainsString('DELETE FROM', $sql);
        $this->assertStringContainsString('WHERE [id] = ?', $sql);
        $this->assertStringNotContainsString(' AND ', $sql);

        // The parameter should be the id value
        $params = $this->executedStatements[0]['params'];
        $this->assertSame(99, $params[0]);
    }

    public function testUpdateCompositeKeyWhereClauseWithAndJoinedConditions(): void
    {
        $entity = new CompositeKeyEntity();
        $entity->setOrgId(10);
        $entity->setUserId(20);
        $entity->setRole('admin');

        $this->unitOfWork->registerClean($entity);
        $entity->setRole('editor');

        $this->unitOfWork->commit();

        $this->assertCount(1, $this->executedStatements);
        $sql = $this->executedStatements[0]['sql'];

        // Composite-key entity should produce WHERE [org_id] = ? AND [user_id] = ?
        $this->assertStringContainsString('UPDATE', $sql);
        $this->assertStringContainsString('[composite_entities]', $sql);
        $this->assertStringContainsString('[org_id] = ?', $sql);
        $this->assertStringContainsString('[user_id] = ?', $sql);
        $this->assertStringContainsString(' AND ', $sql);

        // Params: first is the SET value ('editor'), then WHERE values (10, 20)
        $params = $this->executedStatements[0]['params'];
        $this->assertSame('editor', $params[0]);
        $this->assertSame(10, $params[1]);
        $this->assertSame(20, $params[2]);
    }

    public function testDeleteCompositeKeyWhereClauseWithAndJoinedConditions(): void
    {
        $entity = new CompositeKeyEntity();
        $entity->setOrgId(10);
        $entity->setUserId(20);
        $entity->setRole('admin');

        $this->unitOfWork->registerClean($entity);
        $this->unitOfWork->registerDeleted($entity);

        $this->unitOfWork->commit();

        $this->assertCount(1, $this->executedStatements);
        $sql = $this->executedStatements[0]['sql'];

        // Composite-key entity should produce DELETE FROM [composite_entities] WHERE [org_id] = ? AND [user_id] = ?
        $this->assertStringContainsString('DELETE FROM', $sql);
        $this->assertStringContainsString('[composite_entities]', $sql);
        $this->assertStringContainsString('[org_id] = ?', $sql);
        $this->assertStringContainsString('[user_id] = ?', $sql);
        $this->assertStringContainsString(' AND ', $sql);

        // Params should be the composite key values
        $params = $this->executedStatements[0]['params'];
        $this->assertSame(10, $params[0]);
        $this->assertSame(20, $params[1]);
    }

    // ---------------------------------------------------------------
    // v1.2.8: Newly inserted entities must NOT be processed by executeUpdates
    // ---------------------------------------------------------------

    public function testNewlyInsertedEntitiesAreNotProcessedByExecuteUpdates(): void
    {
        // Register a new entity for INSERT
        $newOrder = new OrderEntity();
        $newOrder->setDescription('New Order');
        $newOrder->setTotal(50.0);

        $this->unitOfWork->registerNew($newOrder);
        $this->unitOfWork->commit();

        // Should have exactly 1 INSERT and 0 UPDATEs
        $inserts = array_filter($this->executedStatements, fn($s) => str_contains($s['sql'], 'INSERT'));
        $updates = array_filter($this->executedStatements, fn($s) => str_contains($s['sql'], 'UPDATE'));

        $this->assertCount(1, $inserts, 'Expected exactly 1 INSERT');
        $this->assertCount(0, $updates, 'Expected 0 UPDATEs — newly inserted entities must not trigger UPDATE');
    }

    public function testInsertAndUpdateInSameCommitDoNotDuplicate(): void
    {
        // Pre-existing managed entity (will be updated)
        $existingOrder = new OrderEntity();
        $existingOrder->setId(42);
        $existingOrder->setDescription('Original');
        $existingOrder->setTotal(100.0);
        $this->unitOfWork->registerClean($existingOrder);

        // Modify the existing entity
        $existingOrder->setDescription('Modified');

        // New entity (will be inserted)
        $newOrder = new OrderEntity();
        $newOrder->setDescription('Brand New');
        $newOrder->setTotal(25.0);
        $this->unitOfWork->registerNew($newOrder);

        $this->unitOfWork->commit();

        // Should have exactly 1 INSERT + 1 UPDATE, no duplicates
        $inserts = array_filter($this->executedStatements, fn($s) => str_contains($s['sql'], 'INSERT'));
        $updates = array_filter($this->executedStatements, fn($s) => str_contains($s['sql'], 'UPDATE'));

        $this->assertCount(1, $inserts, 'Expected exactly 1 INSERT');
        $this->assertCount(1, $updates, 'Expected exactly 1 UPDATE for the pre-existing entity');
    }

    public function testMultipleInsertsDoNotGenerateSpuriousUpdates(): void
    {
        // Insert 5 entities at once
        for ($i = 0; $i < 5; $i++) {
            $order = new OrderEntity();
            $order->setDescription('Order ' . $i);
            $order->setTotal(10.0 * ($i + 1));
            $this->unitOfWork->registerNew($order);
        }

        $this->unitOfWork->commit();

        // Should have exactly 5 INSERTs and 0 UPDATEs
        $inserts = array_filter($this->executedStatements, fn($s) => str_contains($s['sql'], 'INSERT'));
        $updates = array_filter($this->executedStatements, fn($s) => str_contains($s['sql'], 'UPDATE'));

        $this->assertCount(5, $inserts, 'Expected exactly 5 INSERTs');
        $this->assertCount(0, $updates, 'Expected 0 UPDATEs — no spurious updates for newly inserted entities');
    }
}
