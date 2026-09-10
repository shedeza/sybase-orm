<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Embedded;
use SybaseORM\Attribute\Entity;
use SybaseORM\Metadata\MetadataReader;

/**
 * @covers \SybaseORM\Metadata\MetadataReader
 */
final class MetadataReaderTest extends TestCase
{
    private MetadataReader $reader;

    protected function setUp(): void
    {
        $this->reader = new MetadataReader();
    }

    public function testReadsEntityAttributes(): void
    {
        $meta = $this->reader->getClassMetadata(DummyEntityForMetadata::class);

        $this->assertSame('dummy_table', $meta->tableName);
        $this->assertSame('dbo', $meta->schema);
    }

    public function testThrowsExceptionOnColumnAndEmbeddedCollision(): void
    {
        $this->expectException(\SybaseORM\Exception\SybaseORMException::class);
        $this->expectExceptionMessage('cannot be mapped as both #[Column] and #[Embedded]');

        $this->reader->getClassMetadata(DummyCollisionEntity::class);
    }
}

#[Entity(table: 'dummy_table', schema: 'dbo')]
class DummyEntityForMetadata
{
    #[Column(name: 'id')]
    public int $id;
}

#[Entity(table: 'collision_table')]
class DummyCollisionEntity
{
    #[Column(name: 'address')]
    #[Embedded(class: DummyAddress::class)]
    public $address;
}

use SybaseORM\Attribute\Embeddable;

#[Embeddable]
class DummyAddress
{
    #[Column(name: 'city')]
    public string $city;
}
