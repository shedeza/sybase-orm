<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Type\TypeCasterInterface;

final class EntityManagerRefreshTest extends TestCase
{
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface&MockObject $dialect;
    private TypeCasterInterface&MockObject $typeCaster;
    private HydratorInterface&MockObject $hydrator;
    private UnitOfWorkInterface&MockObject $unitOfWork;
    private IdentityMapInterface&MockObject $identityMap;
    private CacheManagerInterface&MockObject $cacheManager;
    private EntityManager $em;

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

        $this->connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $metadata = new ClassMetadata(
            entityClass: Fixtures\CustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
            lifecycleHooks: [],
        );

        $this->metadataReader->method('getClassMetadata')
            ->willReturn($metadata);

        $hookDispatcher = new HookDispatcher($this->metadataReader);

        $this->em = new EntityManager(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->hydrator,
            $this->unitOfWork,
            $this->identityMap,
            $hookDispatcher,
            $this->cacheManager,
        );
    }

    public function testRefreshReloadsEntityFromDatabase(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setId(1);
        $entity->setName('Dirty Name');

        // refresh() removes from identity map first
        $this->identityMap->expects($this->once())
            ->method('remove')
            ->with(Fixtures\CustomerEntity::class, 1);

        // Then find() is called — identity map returns null (just removed)
        $this->identityMap->method('get')
            ->willReturn(null);

        $this->cacheManager->method('get')
            ->willReturn(null);

        $this->dialect->method('generateSelect')
            ->willReturn('SELECT * FROM [customers]');
        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->typeCaster->method('toDatabaseValue')
            ->willReturnCallback(fn(mixed $value) => $value);

        $freshRow = ['id' => 1, 'name' => 'Fresh Name'];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($freshRow);
        $stmt->method('closeCursor')->willReturn(true);

        $this->connectionManager->method('executeQuery')
            ->willReturn($stmt);

        $freshEntity = new Fixtures\CustomerEntity();
        $freshEntity->setId(1);
        $freshEntity->setName('Fresh Name');

        $this->hydrator->method('hydrate')
            ->with($freshRow, Fixtures\CustomerEntity::class)
            ->willReturn($freshEntity);

        // registerClean called for the fresh entity from find(), then for the original entity from refresh()
        $this->unitOfWork->expects($this->atLeast(1))
            ->method('registerClean');

        // identityMap->put called for the original entity
        $this->identityMap->expects($this->atLeast(1))
            ->method('put');

        $this->em->refresh($entity);

        // The original entity should have the fresh values
        $this->assertSame('Fresh Name', $entity->getName());
        $this->assertSame(1, $entity->getId());
    }

    public function testRefreshThrowsWhenEntityNotFoundInDatabase(): void
    {
        $entity = new Fixtures\CustomerEntity();
        $entity->setId(999);
        $entity->setName('Ghost');

        $this->identityMap->method('get')->willReturn(null);
        $this->cacheManager->method('get')->willReturn(null);

        $this->dialect->method('generateSelect')
            ->willReturn('SELECT * FROM [customers]');
        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $this->typeCaster->method('toDatabaseValue')
            ->willReturnCallback(fn(mixed $value) => $value);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('closeCursor')->willReturn(true);

        $this->connectionManager->method('executeQuery')
            ->willReturn($stmt);

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Entity not found in database during refresh.');

        $this->em->refresh($entity);
    }

    public function testRefreshThrowsWhenEntityHasNoId(): void
    {
        $noIdMetadata = new ClassMetadata(
            entityClass: Fixtures\CustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            lifecycleHooks: [],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($noIdMetadata);

        $hookDispatcher = new HookDispatcher($metadataReader);

        $em = new EntityManager(
            $this->connectionManager,
            $metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->hydrator,
            $this->unitOfWork,
            $this->identityMap,
            $hookDispatcher,
            $this->cacheManager,
        );

        $entity = new Fixtures\CustomerEntity();
        $entity->setName('No ID');

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Cannot refresh entity without ID.');

        $em->refresh($entity);
    }

    public function testRefreshWithCompositeKey(): void
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

        $freshRow = ['org_id' => 1, 'user_id' => 42, 'role' => 'superadmin'];
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($freshRow);
        $stmt->method('closeCursor')->willReturn(true);

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->method('executeQuery')->willReturn($stmt);
        $connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $freshEntity = new Fixtures\CompositeKeyEntity();
        $freshEntity->setOrgId(1);
        $freshEntity->setUserId(42);
        $freshEntity->setRole('superadmin');

        $hydrator = $this->createMock(HydratorInterface::class);
        $hydrator->method('hydrate')
            ->with($freshRow, Fixtures\CompositeKeyEntity::class)
            ->willReturn($freshEntity);

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

        $entity = new Fixtures\CompositeKeyEntity();
        $entity->setOrgId(1);
        $entity->setUserId(42);
        $entity->setRole('old-role');

        $em->refresh($entity);

        $this->assertSame('superadmin', $entity->getRole());
    }
}
