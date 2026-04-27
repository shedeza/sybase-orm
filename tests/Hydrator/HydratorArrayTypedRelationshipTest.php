<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\OneToMany;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\PersistentCollection;
use SybaseORM\Type\TypeCaster;

/**
 * Tests that Hydrator doesn't wrap array-typed relationship properties in PersistentCollection.
 */
final class HydratorArrayTypedRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
    }

    public function testArrayTypedPropertyRemainsArray(): void
    {
        $reader = new MetadataReader();
        $hydrator = new Hydrator($reader, new TypeCaster());

        $row = ['id' => 1, 'name' => 'Test'];

        $entity = $hydrator->hydrate($row, ArrayTypedRelEntity::class);

        // The property is typed as 'array', so it should NOT be wrapped in PersistentCollection
        $this->assertIsArray($entity->items);
        $this->assertNotInstanceOf(PersistentCollection::class, $entity->items);
    }

    public function testUntypedPropertyGetsWrapped(): void
    {
        $reader = new MetadataReader();
        $hydrator = new Hydrator($reader, new TypeCaster());

        $row = ['id' => 1];

        $entity = $hydrator->hydrate($row, UntypedRelEntity::class);

        // Untyped property CAN be wrapped in PersistentCollection
        $this->assertInstanceOf(PersistentCollection::class, $entity->items);
    }
}

#[Entity(table: 'array_typed_rel')]
class ArrayTypedRelEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public string $name = '';

    #[OneToMany(targetEntity: \stdClass::class, mappedBy: 'parent')]
    public array $items = [];
}

#[Entity(table: 'untyped_rel')]
class UntypedRelEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    /** @var object[] */
    #[OneToMany(targetEntity: \stdClass::class, mappedBy: 'parent')]
    public $items = [];
}
