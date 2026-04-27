<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\PersistentCollection;

/**
 * Tests for PersistentCollection lazy-loading collection.
 */
final class PersistentCollectionTest extends TestCase
{
    public function testLazyInitialization(): void
    {
        $loadCount = 0;
        $collection = new PersistentCollection(function () use (&$loadCount) {
            $loadCount++;
            return [new \stdClass(), new \stdClass()];
        });

        $this->assertFalse($collection->isInitialized());
        $this->assertSame(0, $loadCount);

        $this->assertSame(2, $collection->count());
        $this->assertTrue($collection->isInitialized());
        $this->assertSame(1, $loadCount);

        // Second access doesn't re-load
        $collection->count();
        $this->assertSame(1, $loadCount);
    }

    public function testFromArray(): void
    {
        $items = [new \stdClass(), new \stdClass()];
        $collection = PersistentCollection::fromArray($items);

        $this->assertTrue($collection->isInitialized());
        $this->assertSame(2, $collection->count());
    }

    public function testAddAndRemove(): void
    {
        $collection = PersistentCollection::fromArray([]);
        $item = new \stdClass();

        $collection->add($item);
        $this->assertSame(1, $collection->count());
        $this->assertTrue($collection->contains($item));

        $removed = $collection->remove($item);
        $this->assertTrue($removed);
        $this->assertSame(0, $collection->count());
    }

    public function testRemoveNonExistentReturnsFalse(): void
    {
        $collection = PersistentCollection::fromArray([]);

        $this->assertFalse($collection->remove(new \stdClass()));
    }

    public function testIterator(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $collection = PersistentCollection::fromArray([$a, $b]);

        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        $this->assertSame([$a, $b], $items);
    }

    public function testArrayAccess(): void
    {
        $a = new \stdClass();
        $collection = PersistentCollection::fromArray([$a]);

        $this->assertTrue(isset($collection[0]));
        $this->assertSame($a, $collection[0]);
        $this->assertFalse(isset($collection[1]));
    }

    public function testFirstAndLast(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $collection = PersistentCollection::fromArray([$a, $b]);

        $this->assertSame($a, $collection->first());
        $this->assertSame($b, $collection->last());
    }

    public function testFirstAndLastOnEmpty(): void
    {
        $collection = PersistentCollection::fromArray([]);

        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue(PersistentCollection::fromArray([])->isEmpty());
        $this->assertFalse(PersistentCollection::fromArray([new \stdClass()])->isEmpty());
    }

    public function testFilter(): void
    {
        $a = new \stdClass();
        $a->active = true;
        $b = new \stdClass();
        $b->active = false;

        $collection = PersistentCollection::fromArray([$a, $b]);
        $filtered = $collection->filter(fn($item) => $item->active);

        $this->assertSame(1, $filtered->count());
        $this->assertSame($a, $filtered->first());
    }

    public function testMap(): void
    {
        $a = new \stdClass();
        $a->name = 'Alice';
        $b = new \stdClass();
        $b->name = 'Bob';

        $collection = PersistentCollection::fromArray([$a, $b]);
        $names = $collection->map(fn($item) => $item->name);

        $this->assertSame(['Alice', 'Bob'], $names);
    }

    public function testToArray(): void
    {
        $items = [new \stdClass(), new \stdClass()];
        $collection = PersistentCollection::fromArray($items);

        $this->assertSame($items, $collection->toArray());
    }
}
