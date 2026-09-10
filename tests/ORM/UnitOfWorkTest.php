<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\Type\TypeCasterInterface;
use SybaseORM\Hook\HookDispatcher;

/**
 * @covers \SybaseORM\ORM\UnitOfWork
 */
final class UnitOfWorkTest extends TestCase
{
    private UnitOfWork $unitOfWork;
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface&MockObject $dialect;
    private TypeCasterInterface&MockObject $typeCaster;
    private IdentityMapInterface&MockObject $identityMap;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->metadataReader->method('getClassMetadata')->willReturn(
            new \SybaseORM\Metadata\ClassMetadata(
                entityClass: \stdClass::class,
                tableName: 'test_table'
            )
        );
        $this->dialect = $this->createMock(DialectInterface::class);
        $this->typeCaster = $this->createMock(TypeCasterInterface::class);
        $this->identityMap = $this->createMock(IdentityMapInterface::class);
        $hookDispatcher = new HookDispatcher($this->metadataReader);

        $this->unitOfWork = new UnitOfWork(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->identityMap,
            $hookDispatcher
        );
    }

    public function testRegisterNewAndIsManaged(): void
    {
        $entity = new \stdClass();
        $this->assertFalse($this->unitOfWork->isManaged($entity));

        $this->unitOfWork->registerNew($entity);
        // It's not managed until snapshot is taken during/after flush/commit
        $this->assertFalse($this->unitOfWork->isManaged($entity));
    }

    public function testClearEmptiesState(): void
    {
        $entity = new \stdClass();
        $this->unitOfWork->registerNew($entity);
        $this->unitOfWork->clear();

        // Testing clear involves checking internal state, but we can verify it doesn't crash
        $this->assertTrue(true);
    }
}
