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
 * Tests for Hydrator collection loader (lazy loading support).
 */
final class HydratorCollectionLoaderTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
    }

    public function testCollectionLoaderIsCalledOnFirstAccess(): void
    {
        $reader = new MetadataReader();
        $hydrator = new Hydrator($reader, new TypeCaster());

        $loadCount = 0;
        $hydrator->setCollectionLoader(function (string $class, string $prop, object $owner) use (&$loadCount) {
            $loadCount++;
            $item = new \stdClass();
            $item->name = 'loaded';
            return [$item];
        });

        $row = ['id' => 1];
        $entity = $hydrator->hydrate($row, CollectionLoaderTestEntity::class);

        // Collection should not be loaded yet
        $this->assertSame(0, $loadCount);

        // Access the collection — triggers loader
        $items = $entity->items;
        $this->assertInstanceOf(PersistentCollection::class, $items);

        // First access triggers initialization
        $this->assertSame(1, $items->count());
        $this->assertSame(1, $loadCount);
        $this->assertSame('loaded', $items->first()->name);

        // Second access doesn't re-load
        $items->count();
        $this->assertSame(1, $loadCount);
    }

    public function testWithoutLoaderCollectionIsEmptyInitialized(): void
    {
        $reader = new MetadataReader();
        $hydrator = new Hydrator($reader, new TypeCaster());
        // No loader set

        $row = ['id' => 1];
        $entity = $hydrator->hydrate($row, CollectionLoaderTestEntity::class);

        $this->assertInstanceOf(PersistentCollection::class, $entity->items);
        $this->assertSame(0, $entity->items->count());
    }
}

#[Entity(table: 'collection_loader_test')]
class CollectionLoaderTestEntity
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    /** @var PersistentCollection<\stdClass> */
    #[OneToMany(targetEntity: \stdClass::class, mappedBy: 'parent')]
    public $items = null;
}
