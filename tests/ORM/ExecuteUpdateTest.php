<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Type\TypeCaster;
use SybaseORM\Type\TypeCasterInterface;
use SybaseORM\Tests\Query\Fixtures\OqlUserEntity;
use SybaseORM\Tests\Query\Fixtures\OqlPostEntity;

/**
 * Unit tests for EntityManager::executeUpdate().
 * Validates: Requirements 31.1–31.4
 */
final class ExecuteUpdateTest extends TestCase
{
    private EntityManager $entityManager;
    private ConnectionManagerInterface $connectionManager;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();

        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $metadataReader = new MetadataReader();
        $dialect = new SybaseDialect();
        $typeCaster = new TypeCaster();
        $identityMap = new IdentityMap();
        $hookDispatcher = new HookDispatcher($metadataReader);

        $unitOfWork = new UnitOfWork(
            $this->connectionManager,
            $metadataReader,
            $dialect,
            $typeCaster,
            $identityMap,
            $hookDispatcher,
        );

        $hydrator = new Hydrator($metadataReader, $typeCaster, $identityMap);

        $cacheManager = $this->createMock(CacheManagerInterface::class);
        $cacheManager->method('get')->willReturn(null);

        $this->entityManager = new EntityManager(
            $this->connectionManager,
            $metadataReader,
            $dialect,
            $typeCaster,
            $hydrator,
            $unitOfWork,
            $identityMap,
            $hookDispatcher,
            $cacheManager,
        );

        $this->entityManager->setEntityClasses([OqlUserEntity::class, OqlPostEntity::class]);
    }

    public function testExecuteUpdateReturnsRowCount(): void
    {
        $this->connectionManager
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(5);

        $result = $this->entityManager->executeUpdate(
            'UPDATE OqlUserEntity u SET u.name = :name WHERE u.age > :age',
            ['name' => 'Updated', 'age' => 18],
        );

        $this->assertSame(5, $result);
    }

    public function testExecuteUpdateDelete(): void
    {
        $this->connectionManager
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(3);

        $result = $this->entityManager->executeUpdate(
            'DELETE FROM OqlUserEntity u WHERE u.id = :id',
            ['id' => 42],
        );

        $this->assertSame(3, $result);
    }

    public function testExecuteUpdateThrowsOnSelect(): void
    {
        $this->expectException(OqlParseException::class);
        $this->expectExceptionMessage('executeUpdate() does not support SELECT statements');

        $this->entityManager->executeUpdate('SELECT u FROM OqlUserEntity u');
    }

    public function testExecuteUpdateWithNoParams(): void
    {
        $this->connectionManager
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(10);

        $result = $this->entityManager->executeUpdate(
            "UPDATE OqlUserEntity u SET u.name = 'default'",
        );

        $this->assertSame(10, $result);
    }

    public function testExecuteUpdateWithNullValue(): void
    {
        $this->connectionManager
            ->expects($this->once())
            ->method('executeStatement')
            ->willReturn(1);

        $result = $this->entityManager->executeUpdate(
            'UPDATE OqlUserEntity u SET u.email = NULL WHERE u.id = :id',
            ['id' => 1],
        );

        $this->assertSame(1, $result);
    }
}
