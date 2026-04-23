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
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWorkInterface;
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
}
