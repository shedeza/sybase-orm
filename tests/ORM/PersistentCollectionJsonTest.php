<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\PersistentCollection;

/**
 * Tests for PersistentCollection JsonSerializable support.
 */
final class PersistentCollectionJsonTest extends TestCase
{
    public function testJsonSerializeReturnsElements(): void
    {
        $a = new \stdClass();
        $a->name = 'Alice';
        $b = new \stdClass();
        $b->name = 'Bob';

        $collection = PersistentCollection::fromArray([$a, $b]);

        $json = json_encode($collection);
        $decoded = json_decode($json, true);

        $this->assertCount(2, $decoded);
        $this->assertSame('Alice', $decoded[0]['name']);
        $this->assertSame('Bob', $decoded[1]['name']);
    }

    public function testJsonSerializeEmptyCollection(): void
    {
        $collection = PersistentCollection::fromArray([]);

        $this->assertSame('[]', json_encode($collection));
    }

    public function testJsonSerializeTriggersInitialization(): void
    {
        $loaded = false;
        $collection = new PersistentCollection(function () use (&$loaded) {
            $loaded = true;
            return [new \stdClass()];
        });

        $this->assertFalse($loaded);

        json_encode($collection);

        $this->assertTrue($loaded);
    }
}
