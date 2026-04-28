<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Collection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Collection\ArrayCollection;
use SybaseORM\Collection\Collection;

/**
 * Tests for ArrayCollection.
 */
final class ArrayCollectionTest extends TestCase
{
    public function testImplementsCollectionInterface(): void
    {
        $collection = new ArrayCollection();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertInstanceOf(\IteratorAggregate::class, $collection);
        $this->assertInstanceOf(\Countable::class, $collection);
        $this->assertInstanceOf(\ArrayAccess::class, $collection);
        $this->assertInstanceOf(\JsonSerializable::class, $collection);
    }

    public function testConstructWithElements(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $collection = new ArrayCollection([$a, $b]);

        $this->assertSame(2, $collection->count());
        $this->assertSame([$a, $b], $collection->toArray());
    }

    public function testAddAndRemove(): void
    {
        $collection = new ArrayCollection();
        $item = new \stdClass();

        $collection->add($item);
        $this->assertTrue($collection->contains($item));
        $this->assertSame(1, $collection->count());

        $this->assertTrue($collection->remove($item));
        $this->assertFalse($collection->contains($item));
        $this->assertSame(0, $collection->count());
    }

    public function testRemoveNonExistent(): void
    {
        $collection = new ArrayCollection();

        $this->assertFalse($collection->remove(new \stdClass()));
    }

    public function testFirstAndLast(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $collection = new ArrayCollection([$a, $b]);

        $this->assertSame($a, $collection->first());
        $this->assertSame($b, $collection->last());
    }

    public function testFirstAndLastEmpty(): void
    {
        $collection = new ArrayCollection();

        $this->assertNull($collection->first());
        $this->assertNull($collection->last());
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue((new ArrayCollection())->isEmpty());
        $this->assertFalse((new ArrayCollection([new \stdClass()]))->isEmpty());
    }

    public function testFilter(): void
    {
        $a = new \stdClass();
        $a->active = true;
        $b = new \stdClass();
        $b->active = false;

        $collection = new ArrayCollection([$a, $b]);
        $filtered = $collection->filter(fn($item) => $item->active);

        $this->assertInstanceOf(ArrayCollection::class, $filtered);
        $this->assertSame(1, $filtered->count());
        $this->assertSame($a, $filtered->first());
    }

    public function testMap(): void
    {
        $a = new \stdClass();
        $a->name = 'Alice';
        $b = new \stdClass();
        $b->name = 'Bob';

        $collection = new ArrayCollection([$a, $b]);
        $names = $collection->map(fn($item) => $item->name);

        $this->assertSame(['Alice', 'Bob'], $names);
    }

    public function testClear(): void
    {
        $collection = new ArrayCollection([new \stdClass(), new \stdClass()]);
        $collection->clear();

        $this->assertTrue($collection->isEmpty());
        $this->assertSame(0, $collection->count());
    }

    public function testIterator(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $collection = new ArrayCollection([$a, $b]);

        $items = [];
        foreach ($collection as $item) {
            $items[] = $item;
        }

        $this->assertSame([$a, $b], $items);
    }

    public function testArrayAccess(): void
    {
        $a = new \stdClass();
        $collection = new ArrayCollection([$a]);

        $this->assertTrue(isset($collection[0]));
        $this->assertSame($a, $collection[0]);
        $this->assertFalse(isset($collection[1]));

        $b = new \stdClass();
        $collection[] = $b;
        $this->assertSame($b, $collection[1]);

        unset($collection[0]);
        $this->assertSame($b, $collection[0]); // re-indexed
    }

    public function testJsonSerialize(): void
    {
        $a = new \stdClass();
        $a->x = 1;
        $collection = new ArrayCollection([$a]);

        $json = json_encode($collection);
        $this->assertSame('[{"x":1}]', $json);
    }
}
