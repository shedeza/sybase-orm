<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\ORM\HydrationMode;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Type\TypeCasterInterface;

final class EntityManagerTest extends TestCase
{
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface&MockObject $dialect;
    private TypeCasterInterface&MockObject $typeCaster;
    private HydratorInterface&MockObject $hydrator;
    private UnitOfWorkInterface&MockObject $unitOfWork;
    private IdentityMapInterface&MockObject $identityMap;
    private HookDispatcher $hookDispatcher;
    private CacheManagerInterface&MockObject $cacheManager;
    private EntityManager $em;

    private ClassMetadata $metadata;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->dialect = $this->createMock(DialectInterface::class);
        $this->typeCaster = $this->createMock(TypeCasterInterface::class);
        $this->hydrator = $this->createMock(HydratorInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->identityMap = $this->createMock(IdentityMapInterface::class);
        $this->cacheManager = $this->createMock(CacheManagerInterface::class);

        // Default: convertResultRow passes through unchanged (no charset conversion)
        $this->connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $this->metadata = new ClassMetadata(
            entityClass: Fixtures\CustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
            lifecycleHooks: [],
        );

        // HookDispatcher is final, so we use a real instance.
        // With empty lifecycleHooks, dispatch() is a no-op.
        $this->metadataReader->method('getClassMetadata')
            ->willReturn($this->metadata);

        $this->hookDispatcher = new HookDispatcher($this->metadataReader);

        $this->em = new EntityManager(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->hydrator,
            $this->unitOfWork,
            $this->identityMap,
            $this->hookDispatcher,
            $this->cacheManager,
        );
    }

    // ── persist() ──────────────────────────────────────────────────

    public function testPersistDispatchesPrePersistHookAndRegistersNew(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setName('Alice');

        // HookDispatcher.dispatch('PrePersist') is called (no-op with empty hooks)
        $this->unitOfWork->expects($this->once())
            ->method('registerNew')
            ->with($entity);

        $this->em->persist($entity);
    }

    // ── remove() ──────────────────────────────────────────────────

    public function testRemoveDispatchesPreRemoveHookAndRegistersDeleted(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setId(1);

        // HookDispatcher.dispatch('PreRemove') is called (no-op with empty hooks)
        $this->unitOfWork->expects($this->once())
            ->method('registerDeleted')
            ->with($entity);

        $this->em->remove($entity);
    }

    // ── flush() ───────────────────────────────────────────────────

    public function testFlushDelegatesToUnitOfWorkCommit(): void
    {
        $this->unitOfWork->expects($this->once())
            ->method('commit');

        $this->em->flush();
    }

    // ── find() ────────────────────────────────────────────────────

    public function testFindReturnsEntityFromIdentityMap(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setId(1);
        $entity->setName('Bob');

        $this->identityMap->expects($this->once())
            ->method('get')
            ->with(Fixtures\CustomerEntity::class, 1)
            ->willReturn($entity);

        // Should NOT hit cache or DB
        $this->cacheManager->expects($this->never())->method('get');
        $this->connectionManager->expects($this->never())->method('executeQuery');

        $result = $this->em->find(Fixtures\CustomerEntity::class, 1);
        $this->assertSame($entity, $result);
    }

    public function testFindReturnsEntityFromCacheManager(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setId(2);

        $this->identityMap->method('get')->willReturn(null);

        $this->cacheManager->expects($this->once())
            ->method('get')
            ->with(Fixtures\CustomerEntity::class, 2)
            ->willReturn($entity);

        $this->unitOfWork->expects($this->once())
            ->method('registerClean')
            ->with($entity);

        $this->connectionManager->expects($this->never())->method('executeQuery');

        $result = $this->em->find(Fixtures\CustomerEntity::class, 2);
        $this->assertSame($entity, $result);
    }

    public function testFindQueriesDatabaseWhenNotCached(): void
    {
        $this->identityMap->method('get')->willReturn(null);
        $this->cacheManager->method('get')->willReturn(null);

        $this->dialect->method('generateSelect')
            ->with(['*'], 'customers')
            ->willReturn('SELECT * FROM [customers]');

        $this->dialect->method('quoteIdentifier')
            ->with('id')
            ->willReturn('[id]');

        $this->typeCaster->method('toDatabaseValue')
            ->with(5, 'integer')
            ->willReturn(5);

        $row = ['id' => 5, 'name' => 'Charlie'];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM [customers] WHERE [id] = ?', [5])
            ->willReturn($stmt);

        $entity = new Fixtures\CustomerEntity();
        $entity->setId(5);
        $entity->setName('Charlie');

        $this->hydrator->expects($this->once())
            ->method('hydrate')
            ->with($row, Fixtures\CustomerEntity::class)
            ->willReturn($entity);

        $this->unitOfWork->expects($this->once())
            ->method('registerClean')
            ->with($entity);

        $this->cacheManager->expects($this->once())
            ->method('put')
            ->with(Fixtures\CustomerEntity::class, 5, $entity);

        $result = $this->em->find(Fixtures\CustomerEntity::class, 5);
        $this->assertSame($entity, $result);
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->identityMap->method('get')->willReturn(null);
        $this->cacheManager->method('get')->willReturn(null);

        $this->dialect->method('generateSelect')->willReturn('SELECT * FROM [customers]');
        $this->dialect->method('quoteIdentifier')->willReturn('[id]');
        $this->typeCaster->method('toDatabaseValue')->willReturn(999);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->expects($this->once())->method('closeCursor');

        $this->connectionManager->method('executeQuery')->willReturn($stmt);

        $result = $this->em->find(Fixtures\CustomerEntity::class, 999);
        $this->assertNull($result);
    }

    // ── clear() ───────────────────────────────────────────────────

    public function testClearDelegatesToUnitOfWorkAndIdentityMap(): void
    {
        $this->unitOfWork->expects($this->once())->method('clear');
        $this->identityMap->expects($this->once())->method('clear');

        $this->em->clear();
    }

    // ── merge() ───────────────────────────────────────────────────

    public function testMergeCopiesStateFromDetachedToManaged(): void
    {
        $detached = new Fixtures\CustomerEntity();
        $detached->setId(10);
        $detached->setName('Updated Name');

        $managed = new Fixtures\CustomerEntity();
        $managed->setId(10);
        $managed->setName('Old Name');

        // find() will return managed entity from identity map
        $this->identityMap->method('get')
            ->with(Fixtures\CustomerEntity::class, 10)
            ->willReturn($managed);

        $result = $this->em->merge($detached);

        $this->assertSame($managed, $result);
        $this->assertSame('Updated Name', $managed->getName());
    }

    // ── beginTransaction / commit / rollback ─────────────────────

    public function testBeginTransactionDelegatesToConnectionManager(): void
    {
        $this->connectionManager->expects($this->once())->method('beginTransaction');
        $this->em->beginTransaction();
    }

    public function testCommitDelegatesToConnectionManager(): void
    {
        $this->connectionManager->expects($this->once())->method('commit');
        $this->em->commit();
    }

    public function testRollbackDelegatesToConnectionManager(): void
    {
        $this->connectionManager->expects($this->once())->method('rollback');
        $this->em->rollback();
    }

    // ── getRepository() ──────────────────────────────────────────

    public function testGetRepositoryReturnsSameInstance(): void
    {
        $repo1 = $this->em->getRepository(Fixtures\CustomerEntity::class);
        $repo2 = $this->em->getRepository(Fixtures\CustomerEntity::class);

        $this->assertInstanceOf(EntityRepository::class, $repo1);
        $this->assertSame($repo1, $repo2);
    }

    // ── createQueryBuilder() ─────────────────────────────────────

    public function testCreateQueryBuilderReturnsConfiguredBuilder(): void
    {
        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $qb = $this->em->createQueryBuilder(Fixtures\CustomerEntity::class);

        $this->assertInstanceOf(\SybaseORM\Query\QueryBuilderInterface::class, $qb);

        // The QB should have FROM pre-configured
        $sql = $qb->select('*')->getSQL();
        $this->assertStringContainsString('customers', $sql);
    }

    // ── Full lifecycle: persist → flush → find → modify → flush → remove → flush ──

    public function testFullEntityLifecycle(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setName('Lifecycle Test');

        // Step 1: persist (dispatches PrePersist, registers new)
        $this->unitOfWork->expects($this->once())
            ->method('registerNew')
            ->with($entity);

        $this->em->persist($entity);

        // Step 2: flush (delegates to UoW commit)
        $this->unitOfWork->expects($this->exactly(2))
            ->method('commit');

        $this->em->flush();

        // Step 3: find from identity map
        $entity->setId(1);
        $this->identityMap->method('get')
            ->willReturnCallback(function (string $class, mixed $id) use ($entity): ?object {
                if ($class === Fixtures\CustomerEntity::class && $id === 1) {
                    return $entity;
                }
                return null;
            });

        $found = $this->em->find(Fixtures\CustomerEntity::class, 1);
        $this->assertSame($entity, $found);

        // Step 4: modify + flush
        $entity->setName('Modified');
        $this->em->flush();

        // Step 5: remove (dispatches PreRemove, registers deleted)
        $this->unitOfWork->expects($this->once())
            ->method('registerDeleted')
            ->with($entity);

        $this->em->remove($entity);
    }

    // ── Explicit transaction test ────────────────────────────────

    public function testExplicitTransactionFlow(): void
    {
        $this->connectionManager->expects($this->once())->method('beginTransaction');
        $this->connectionManager->expects($this->once())->method('commit');

        $this->em->beginTransaction();

        $entity = new Fixtures\CustomerEntity();
        $entity->setName('Transactional');

        $this->unitOfWork->expects($this->once())->method('registerNew')->with($entity);
        $this->unitOfWork->expects($this->once())->method('commit');

        $this->em->persist($entity);
        $this->em->flush();
        $this->em->commit();
    }

    public function testExplicitTransactionRollback(): void
    {
        $this->connectionManager->expects($this->once())->method('beginTransaction');
        $this->connectionManager->expects($this->once())->method('rollback');
        $this->connectionManager->expects($this->never())->method('commit');

        $this->em->beginTransaction();

        $entity = new Fixtures\CustomerEntity();
        $entity->setName('Will Rollback');

        $this->em->persist($entity);

        $this->em->rollback();
    }

    // ── find() with composite keys ───────────────────────────────

    public function testFindWithCompositeKeyQueriesDatabase(): void
    {
        $compositeMetadata = new ClassMetadata(
            entityClass: Fixtures\CompositeKeyEntity::class,
            tableName: 'composite_entities',
            columns: [
                new ColumnMetadata('orgId', 'org_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('userId', 'user_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('role', 'role', 'string', false, 100),
            ],
            idFields: ['orgId', 'userId'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($compositeMetadata);

        $identityMap = $this->createMock(IdentityMapInterface::class);
        $identityMap->method('get')->willReturn(null);

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('get')->willReturn(null);

        $dialect = $this->createMock(DialectInterface::class);
        $dialect->method('generateSelect')
            ->with(['*'], 'composite_entities')
            ->willReturn('SELECT * FROM [composite_entities]');
        $dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $typeCaster = $this->createMock(TypeCasterInterface::class);
        $typeCaster->method('toDatabaseValue')
            ->willReturnCallback(fn(mixed $value) => $value);

        $row = ['org_id' => 1, 'user_id' => 42, 'role' => 'admin'];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT * FROM [composite_entities] WHERE [org_id] = ? AND [user_id] = ?',
                [1, 42],
            )
            ->willReturn($stmt);
        $connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $entity = new Fixtures\CompositeKeyEntity();
        $entity->setOrgId(1);
        $entity->setUserId(42);
        $entity->setRole('admin');

        $hydrator = $this->createMock(HydratorInterface::class);
        $hydrator->expects($this->once())
            ->method('hydrate')
            ->with($row, Fixtures\CompositeKeyEntity::class)
            ->willReturn($entity);

        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $unitOfWork->expects($this->once())
            ->method('registerClean')
            ->with($entity);

        $hookDispatcher = new HookDispatcher($metadataReader);

        $em = new EntityManager(
            $connectionManager,
            $metadataReader,
            $dialect,
            $typeCaster,
            $hydrator,
            $unitOfWork,
            $identityMap,
            $hookDispatcher,
            $cacheManager,
        );

        $compositeId = ['orgId' => 1, 'userId' => 42];
        $result = $em->find(Fixtures\CompositeKeyEntity::class, $compositeId);

        $this->assertSame($entity, $result);
    }

    public function testFindWithCompositeKeyReturnsNullWhenNotFound(): void
    {
        $compositeMetadata = new ClassMetadata(
            entityClass: Fixtures\CompositeKeyEntity::class,
            tableName: 'composite_entities',
            columns: [
                new ColumnMetadata('orgId', 'org_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('userId', 'user_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('role', 'role', 'string', false, 100),
            ],
            idFields: ['orgId', 'userId'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($compositeMetadata);

        $identityMap = $this->createMock(IdentityMapInterface::class);
        $identityMap->method('get')->willReturn(null);

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('get')->willReturn(null);

        $dialect = $this->createMock(DialectInterface::class);
        $dialect->method('generateSelect')->willReturn('SELECT * FROM [composite_entities]');
        $dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $typeCaster = $this->createMock(TypeCasterInterface::class);
        $typeCaster->method('toDatabaseValue')
            ->willReturnCallback(fn(mixed $value) => $value);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->expects($this->once())->method('closeCursor');

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->method('executeQuery')->willReturn($stmt);
        $connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $hydrator = $this->createMock(HydratorInterface::class);
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $hookDispatcher = new HookDispatcher($metadataReader);

        $em = new EntityManager(
            $connectionManager,
            $metadataReader,
            $dialect,
            $typeCaster,
            $hydrator,
            $unitOfWork,
            $identityMap,
            $hookDispatcher,
            $cacheManager,
        );

        $result = $em->find(Fixtures\CompositeKeyEntity::class, ['orgId' => 1, 'userId' => 999]);
        $this->assertNull($result);
    }

    public function testFindWithCompositeKeyThrowsOnKeyMismatch(): void
    {
        $compositeMetadata = new ClassMetadata(
            entityClass: Fixtures\CompositeKeyEntity::class,
            tableName: 'composite_entities',
            columns: [
                new ColumnMetadata('orgId', 'org_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('userId', 'user_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('role', 'role', 'string', false, 100),
            ],
            idFields: ['orgId', 'userId'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($compositeMetadata);

        $identityMap = $this->createMock(IdentityMapInterface::class);
        $identityMap->method('get')->willReturn(null);

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('get')->willReturn(null);

        $dialect = $this->createMock(DialectInterface::class);
        $typeCaster = $this->createMock(TypeCasterInterface::class);
        $hydrator = $this->createMock(HydratorInterface::class);
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);
        $hookDispatcher = new HookDispatcher($metadataReader);

        $em = new EntityManager(
            $connectionManager,
            $metadataReader,
            $dialect,
            $typeCaster,
            $hydrator,
            $unitOfWork,
            $identityMap,
            $hookDispatcher,
            $cacheManager,
        );

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Key mismatch');

        // Provide wrong keys: 'orgId' and 'wrongKey' instead of 'orgId' and 'userId'
        $em->find(Fixtures\CompositeKeyEntity::class, ['orgId' => 1, 'wrongKey' => 42]);
    }

    public function testFindWithCompositeKeyReturnsEntityFromIdentityMap(): void
    {
        $compositeMetadata = new ClassMetadata(
            entityClass: Fixtures\CompositeKeyEntity::class,
            tableName: 'composite_entities',
            columns: [
                new ColumnMetadata('orgId', 'org_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('userId', 'user_id', 'integer', false, null, null, null, true),
                new ColumnMetadata('role', 'role', 'string', false, 100),
            ],
            idFields: ['orgId', 'userId'],
        );

        $entity = new Fixtures\CompositeKeyEntity();
        $entity->setOrgId(1);
        $entity->setUserId(42);
        $entity->setRole('admin');

        $compositeId = ['orgId' => 1, 'userId' => 42];

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($compositeMetadata);

        $identityMap = $this->createMock(IdentityMapInterface::class);
        $identityMap->expects($this->once())
            ->method('get')
            ->with(Fixtures\CompositeKeyEntity::class, $compositeId)
            ->willReturn($entity);

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->expects($this->never())->method('get');

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->never())->method('executeQuery');
        $connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $dialect = $this->createMock(DialectInterface::class);
        $typeCaster = $this->createMock(TypeCasterInterface::class);
        $hydrator = $this->createMock(HydratorInterface::class);
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $hookDispatcher = new HookDispatcher($metadataReader);

        $em = new EntityManager(
            $connectionManager,
            $metadataReader,
            $dialect,
            $typeCaster,
            $hydrator,
            $unitOfWork,
            $identityMap,
            $hookDispatcher,
            $cacheManager,
        );

        $result = $em->find(Fixtures\CompositeKeyEntity::class, $compositeId);
        $this->assertSame($entity, $result);
    }

    public function testFindWithScalarIdStillWorksAfterCompositeKeySupport(): void
    {
        // This test verifies backward compatibility — scalar find path is unchanged
        $this->identityMap->method('get')->willReturn(null);
        $this->cacheManager->method('get')->willReturn(null);

        $this->dialect->method('generateSelect')
            ->with(['*'], 'customers')
            ->willReturn('SELECT * FROM [customers]');

        $this->dialect->method('quoteIdentifier')
            ->with('id')
            ->willReturn('[id]');

        $this->typeCaster->method('toDatabaseValue')
            ->with(7, 'integer')
            ->willReturn(7);

        $row = ['id' => 7, 'name' => 'BackwardCompat'];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT * FROM [customers] WHERE [id] = ?', [7])
            ->willReturn($stmt);

        $entity = new Fixtures\CustomerEntity();
        $entity->setId(7);
        $entity->setName('BackwardCompat');

        $this->hydrator->expects($this->once())
            ->method('hydrate')
            ->with($row, Fixtures\CustomerEntity::class)
            ->willReturn($entity);

        $result = $this->em->find(Fixtures\CustomerEntity::class, 7);
        $this->assertSame($entity, $result);
    }

    // ── query() hydration mode ───────────────────────────────────

    public function testQueryWithHydrateArrayReturnsRawRows(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->never())->method('hydrateAll');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c',
            [],
            HydrationMode::HYDRATE_ARRAY,
        );

        $this->assertSame($rows, $result);
    }

    public function testQueryWithHydrateObjectReturnsEntities(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
        ];

        $entity = new Fixtures\CustomerEntity();
        $entity->setId(1);
        $entity->setName('Alice');

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->once())
            ->method('hydrateAll')
            ->with($rows, Fixtures\CustomerEntity::class)
            ->willReturn([$entity]);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c',
            [],
            HydrationMode::HYDRATE_OBJECT,
        );

        $this->assertCount(1, $result);
        $this->assertSame($entity, $result[0]);
    }

    public function testQueryAutoDetectsArrayModeForAggregates(): void
    {
        $rows = [
            ['cnt' => 5],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        // Should NOT call hydrateAll because auto-detection triggers HYDRATE_ARRAY
        $this->hydrator->expects($this->never())->method('hydrateAll');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->query(
            'SELECT COUNT(c.id) AS cnt FROM CustomerEntity c',
        );

        $this->assertSame($rows, $result);
    }

    public function testQueryAutoDetectsArrayModeForAliases(): void
    {
        $rows = [
            ['customer_name' => 'Alice'],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->never())->method('hydrateAll');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->query(
            'SELECT c.name AS customer_name FROM CustomerEntity c',
        );

        $this->assertSame($rows, $result);
    }

    // ── query() IN parameter expansion ───────────────────────────

    public function testQueryExpandsInArrayParameter(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 3, 'name' => 'Charlie'],
        ];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        // Verify the expanded SQL has 3 positional placeholders and the flattened params
        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql): bool {
                    // The SQL should contain 3 positional placeholders for the IN clause
                    return str_contains($sql, '?, ?, ?');
                }),
                [1, 2, 3],
            )
            ->willReturn($stmt);

        $this->hydrator->expects($this->never())->method('hydrateAll');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c WHERE c.id IN (:ids)',
            ['ids' => [1, 2, 3]],
            HydrationMode::HYDRATE_ARRAY,
        );

        $this->assertSame($rows, $result);
    }

    public function testQueryExpandsInArrayParameterWithCorrectPlaceholderCount(): void
    {
        $ids = [10, 20, 30, 40, 50];

        $rows = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql) use ($ids): bool {
                    // Count the number of ? placeholders in the IN clause
                    $expectedPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
                    return str_contains($sql, $expectedPlaceholders);
                }),
                $ids,
            )
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c WHERE c.id IN (:ids)',
            ['ids' => $ids],
            HydrationMode::HYDRATE_ARRAY,
        );
    }

    public function testQueryExpandsAssociativeArrayUsingKeys(): void
    {
        $rows = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn($rows);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        // Associative array where values are sub-arrays (like Unidad constants)
        // The ORM should use the KEYS ('001', '002', '003') as IN values
        $grupo = [
            '001' => ['clave' => '001', 'nombre' => 'Iztapalapa'],
            '002' => ['clave' => '002', 'nombre' => 'Azcapotzalco'],
            '003' => ['clave' => '003', 'nombre' => 'Lerma'],
        ];

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, '?, ?, ?');
                }),
                ['001', '002', '003'],
            )
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c WHERE c.id IN (:grupo)',
            ['grupo' => $grupo],
            HydrationMode::HYDRATE_ARRAY,
        );
    }

    // ── queryOne() ───────────────────────────────────────────────

    public function testQueryOneReturnsHydratedEntity(): void
    {
        $row = ['id' => 1, 'name' => 'Alice'];

        $entity = new Fixtures\CustomerEntity();
        $entity->setId(1);
        $entity->setName('Alice');

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->expects($this->once())
            ->method('applyPagination')
            ->with($this->isType('string'), 1)
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->once())
            ->method('hydrate')
            ->with($row, Fixtures\CustomerEntity::class)
            ->willReturn($entity);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryOne(
            'SELECT c FROM CustomerEntity c WHERE c.id = :id',
            ['id' => 1],
        );

        $this->assertSame($entity, $result);
    }

    public function testQueryOneReturnsNullWhenNoResults(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->never())->method('hydrate');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryOne(
            'SELECT c FROM CustomerEntity c WHERE c.id = :id',
            ['id' => 999],
        );

        $this->assertNull($result);
    }

    public function testQueryOneWithHydrateArrayReturnsRawRow(): void
    {
        $row = ['id' => 1, 'name' => 'Alice'];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->never())->method('hydrate');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryOne(
            'SELECT c.id, c.name FROM CustomerEntity c WHERE c.id = :id',
            ['id' => 1],
            HydrationMode::HYDRATE_ARRAY,
        );

        $this->assertSame($row, $result);
    }

    public function testQueryOneAutoDetectsArrayModeForAggregates(): void
    {
        $row = ['cnt' => 5];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->hydrator->expects($this->never())->method('hydrate');

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryOne(
            'SELECT COUNT(c.id) AS cnt FROM CustomerEntity c',
        );

        $this->assertSame($row, $result);
    }

    // ── queryScalar() ────────────────────────────────────────────

    public function testQueryScalarReturnsFirstColumnValue(): void
    {
        $row = ['cnt' => 42];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryScalar(
            'SELECT COUNT(*) FROM CustomerEntity c',
        );

        $this->assertSame(42, $result);
    }

    public function testQueryScalarReturnsNullWhenNoResults(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryScalar(
            'SELECT COUNT(*) FROM CustomerEntity c WHERE c.id = :id',
            ['id' => 999],
        );

        $this->assertNull($result);
    }

    public function testQueryScalarReturnsStringValue(): void
    {
        $row = ['name' => 'Alice'];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->dialect->method('applyPagination')
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->queryScalar(
            'SELECT c.name FROM CustomerEntity c WHERE c.id = :id',
            ['id' => 1],
        );

        $this->assertSame('Alice', $result);
    }

    public function testQueryScalarAppliesPaginationLimit(): void
    {
        $row = ['cnt' => 10];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($row);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        // Verify applyPagination is called with limit=1
        $this->dialect->expects($this->once())
            ->method('applyPagination')
            ->with($this->isType('string'), 1)
            ->willReturnCallback(fn(string $sql, int $limit) => preg_replace('/^(\s*SELECT\s)/i', '$1TOP 1 ', $sql, 1));

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $this->em->queryScalar('SELECT COUNT(*) FROM CustomerEntity c');
    }

    // ── query() with empty IN array parameter ────────────────────

    public function testQueryWithEmptyArrayParameterReturnsNoResults(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->expects($this->once())->method('closeCursor');

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        // The empty array should produce IN (NULL) in the SQL, with no bound params
        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql): bool {
                    // The named param :ids should be replaced with NULL
                    return str_contains($sql, 'NULL')
                        && !str_contains($sql, ':ids');
                }),
                [],
            )
            ->willReturn($stmt);

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);

        $result = $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c WHERE c.id IN (:ids)',
            ['ids' => []],
            HydrationMode::HYDRATE_ARRAY,
        );

        $this->assertSame([], $result);
    }
}
