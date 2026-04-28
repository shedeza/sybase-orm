<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Collection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Collection\ArrayCollection;
use SybaseORM\Collection\Collection;
use SybaseORM\ORM\PersistentCollection;

/**
 * Tests that both ArrayCollection and PersistentCollection implement Collection.
 */
final class CollectionInterfaceTest extends TestCase
{
    public function testArrayCollectionImplementsCollection(): void
    {
        $this->assertInstanceOf(Collection::class, new ArrayCollection());
    }

    public function testPersistentCollectionImplementsCollection(): void
    {
        $this->assertInstanceOf(Collection::class, new PersistentCollection());
    }

    public function testPersistentCollectionExtentsArrayCollection(): void
    {
        $this->assertInstanceOf(ArrayCollection::class, new PersistentCollection());
    }

    public function testBothCanBeUsedPolymorphically(): void
    {
        $item = new \stdClass();

        $array = new ArrayCollection([$item]);
        $persistent = PersistentCollection::fromArray([$item]);

        $this->assertSame(1, $this->countCollection($array));
        $this->assertSame(1, $this->countCollection($persistent));
    }

    private function countCollection(Collection $collection): int
    {
        return $collection->count();
    }
}
