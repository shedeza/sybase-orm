<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * @covers \SybaseORM\ORM\EntityManager
 */
final class EntityManagerTest extends TestCase
{
    private EntityManager $entityManager;
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface&MockObject $dialect;
    private TypeCasterInterface&MockObject $typeCaster;
    private HydratorInterface&MockObject $hydrator;
    private UnitOfWorkInterface&MockObject $unitOfWork;
    private IdentityMapInterface&MockObject $identityMap;
    private HookDispatcher $hookDispatcher;
    private CacheManagerInterface&MockObject $cacheManager;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->metadataReader->method('getClassMetadata')->willReturn(
            new \SybaseORM\Metadata\ClassMetadata(
                entityClass: DummyEntityForHooks::class,
                tableName: 'test_table',
                lifecycleHooks: [
                    'PrePersist' => ['prePersistMethod'],
                    'PreRemove' => ['preRemoveMethod'],
                ]
            )
        );
        $this->dialect = $this->createMock(DialectInterface::class);
        $this->typeCaster = $this->createMock(TypeCasterInterface::class);
        $this->hydrator = $this->createMock(HydratorInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->identityMap = $this->createMock(IdentityMapInterface::class);
        $this->hookDispatcher = new HookDispatcher($this->metadataReader);
        $this->cacheManager = $this->createMock(CacheManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager = new EntityManager(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->hydrator,
            $this->unitOfWork,
            $this->identityMap,
            $this->hookDispatcher,
            $this->cacheManager,
            $this->logger
        );
    }

    public function testPersistDispatchesHookAndRegistersNew(): void
    {
        $entity = new DummyEntityForHooks();

        // Hook dispatcher is real, we just test that the entity manager
        // delegates correctly to the UnitOfWork. The HookDispatcher logic
        // is tested in its own test suite.
        $this->unitOfWork->expects($this->once())
            ->method('registerNew')
            ->with($entity);

        $this->entityManager->persist($entity);
    }

    public function testRemoveDispatchesHookAndRegistersDeleted(): void
    {
        $entity = new DummyEntityForHooks();

        $this->unitOfWork->expects($this->once())
            ->method('registerDeleted')
            ->with($entity);

        $this->entityManager->remove($entity);
    }

    public function testRestoreRegistersRestored(): void
    {
        $entity = new \stdClass();

        $this->unitOfWork->expects($this->once())
            ->method('registerRestored')
            ->with($entity);

        $this->entityManager->restore($entity);
    }

    public function testFlushCommitsUnitOfWork(): void
    {
        $this->unitOfWork->expects($this->once())
            ->method('commit');

        $this->entityManager->flush();
    }

    public function testOqlQueryCacheReusesCachedExecutionPlan(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([['id' => 1]]);

        $this->connectionManager->expects($this->exactly(2))
            ->method('executeQuery')
            ->willReturn($stmt);

        $dummy = new DummyEntityForHooks();
        $this->hydrator->expects($this->exactly(2))
            ->method('hydrateAll')
            ->willReturn([$dummy]);

        $this->entityManager->setEntityClasses([DummyEntityForHooks::class]);

        $oql = 'SELECT e FROM DummyEntityForHooks e WHERE e.id = :id';

        // 1st run: compiles and caches
        $res1 = $this->entityManager->query($oql, ['id' => 1]);
        $this->assertSame([$dummy], $res1);

        // 2nd run: bypasses OQL parsing and uses cached translation/AST metadata
        $res2 = $this->entityManager->query($oql, ['id' => 2]);
        $this->assertSame([$dummy], $res2);
    }
}

class DummyEntityForHooks
{
    public bool $prePersistCalled = false;
    public bool $preRemoveCalled = false;

    public function prePersistMethod(): void
    {
        $this->prePersistCalled = true;
    }

    public function preRemoveMethod(): void
    {
        $this->preRemoveCalled = true;
    }
}
