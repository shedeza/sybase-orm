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

/**
 * Tests for repositoryClass support in #[Entity] attribute.
 */
final class RepositoryClassTest extends TestCase
{
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->connectionManager->method('convertResultRow')
            ->willReturnCallback(fn(array $row) => $row);

        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);

        $dialect = $this->createMock(DialectInterface::class);
        $typeCaster = $this->createMock(TypeCasterInterface::class);
        $hydrator = $this->createMock(HydratorInterface::class);
        $unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $identityMap = $this->createMock(IdentityMapInterface::class);
        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $hookDispatcher = new HookDispatcher($this->metadataReader);

        $this->em = new EntityManager(
            $this->connectionManager,
            $this->metadataReader,
            $dialect,
            $typeCaster,
            $hydrator,
            $unitOfWork,
            $identityMap,
            $hookDispatcher,
            $cacheManager,
        );
    }

    public function testGetRepositoryReturnsCustomRepositoryWhenConfigured(): void
    {
        $metadata = new ClassMetadata(
            entityClass: Fixtures\RepoCustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
            repositoryClass: Fixtures\CustomCustomerRepository::class,
        );

        $this->metadataReader->method('getClassMetadata')
            ->with(Fixtures\RepoCustomerEntity::class)
            ->willReturn($metadata);

        $repo = $this->em->getRepository(Fixtures\RepoCustomerEntity::class);

        $this->assertInstanceOf(Fixtures\CustomCustomerRepository::class, $repo);
    }

    public function testGetRepositoryReturnsDefaultWhenNoRepositoryClass(): void
    {
        $metadata = new ClassMetadata(
            entityClass: Fixtures\CustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
        );

        $this->metadataReader->method('getClassMetadata')
            ->with(Fixtures\CustomerEntity::class)
            ->willReturn($metadata);

        $repo = $this->em->getRepository(Fixtures\CustomerEntity::class);

        $this->assertInstanceOf(EntityRepository::class, $repo);
        $this->assertNotInstanceOf(Fixtures\CustomCustomerRepository::class, $repo);
    }

    public function testGetRepositoryCachesCustomRepositoryInstance(): void
    {
        $metadata = new ClassMetadata(
            entityClass: Fixtures\RepoCustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
            repositoryClass: Fixtures\CustomCustomerRepository::class,
        );

        $this->metadataReader->method('getClassMetadata')
            ->with(Fixtures\RepoCustomerEntity::class)
            ->willReturn($metadata);

        $repo1 = $this->em->getRepository(Fixtures\RepoCustomerEntity::class);
        $repo2 = $this->em->getRepository(Fixtures\RepoCustomerEntity::class);

        $this->assertSame($repo1, $repo2);
    }

    public function testCustomRepositoryReceivesCorrectEntityClass(): void
    {
        $metadata = new ClassMetadata(
            entityClass: Fixtures\RepoCustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
            repositoryClass: Fixtures\CustomCustomerRepository::class,
        );

        $this->metadataReader->method('getClassMetadata')
            ->with(Fixtures\RepoCustomerEntity::class)
            ->willReturn($metadata);

        $repo = $this->em->getRepository(Fixtures\RepoCustomerEntity::class);

        $this->assertSame(Fixtures\RepoCustomerEntity::class, $repo->getEntityClass());
    }
}
