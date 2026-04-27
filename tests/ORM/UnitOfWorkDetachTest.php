<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Tests for UnitOfWork::detach().
 */
final class UnitOfWorkDetachTest extends TestCase
{
    public function testDetachRemovesEntityFromTracking(): void
    {
        $metadata = new ClassMetadata(
            entityClass: DetachTestEntity::class,
            tableName: 'test',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
            ],
            idFields: ['id'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($metadata);

        $uow = new UnitOfWork(
            $this->createMock(ConnectionManagerInterface::class),
            $metadataReader,
            $this->createMock(DialectInterface::class),
            $this->createMock(TypeCasterInterface::class),
            new IdentityMap(),
        );

        $entity = new DetachTestEntity();
        $entity->id = 1;

        $uow->registerClean($entity);
        $this->assertTrue($uow->isManaged($entity));

        $uow->detach($entity);
        $this->assertFalse($uow->isManaged($entity));
    }

    public function testDetachAlsoRemovesFromNewEntities(): void
    {
        $metadata = new ClassMetadata(
            entityClass: DetachTestEntity::class,
            tableName: 'test',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
            ],
            idFields: ['id'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')->willReturn($metadata);

        $uow = new UnitOfWork(
            $this->createMock(ConnectionManagerInterface::class),
            $metadataReader,
            $this->createMock(DialectInterface::class),
            $this->createMock(TypeCasterInterface::class),
            new IdentityMap(),
        );

        $entity = new DetachTestEntity();
        $entity->id = null;

        $uow->registerNew($entity);
        $uow->detach($entity);

        // After detach, entity should not be managed
        $this->assertFalse($uow->isManaged($entity));
    }
}

class DetachTestEntity
{
    public ?int $id = null;
}
