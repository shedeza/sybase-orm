<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * @covers \SybaseORM\Hydrator\Hydrator
 */
final class HydratorTest extends TestCase
{
    private Hydrator $hydrator;
    private MetadataReaderInterface&MockObject $metadataReader;
    private IdentityMapInterface&MockObject $identityMap;
    private TypeCasterInterface&MockObject $typeCaster;

    protected function setUp(): void
    {
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->identityMap = $this->createMock(IdentityMapInterface::class);
        $this->typeCaster = $this->createMock(TypeCasterInterface::class);

        $this->hydrator = new Hydrator(
            $this->metadataReader,
            $this->typeCaster,
            $this->identityMap
        );
    }

    public function testHydrateSingleEntity(): void
    {
        $column = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: 'string'
        );
        
        $meta = new ClassMetadata(
            entityClass: DummyHydratorEntity::class,
            tableName: 'users',
            columns: [$column]
        );
        
        $this->metadataReader->method('getClassMetadata')->willReturn($meta);
        
        // Pass the raw row through typecaster
        $this->typeCaster->method('toPhpValue')->willReturnArgument(0);
        
        $row = ['name' => 'John Doe'];
        
        $result = $this->hydrator->hydrateAll([$row], DummyHydratorEntity::class);
        
        $this->assertCount(1, $result);
        $this->assertInstanceOf(DummyHydratorEntity::class, $result[0]);
        $this->assertSame('John Doe', $result[0]->name);
    }
}

class DummyHydratorEntity
{
    public string $name;
}
