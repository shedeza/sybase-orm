<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\IdentityMap;

/**
 * Tests for IdentityMap static deriveKey() and typedValue() methods.
 */
final class IdentityMapDeriveKeyStaticTest extends TestCase
{
    public function testDeriveKeyForInt(): void
    {
        $this->assertSame('i:42', IdentityMap::deriveKey(42));
    }

    public function testDeriveKeyForString(): void
    {
        $this->assertSame('s:abc', IdentityMap::deriveKey('abc'));
    }

    public function testDeriveKeyForCompositeArray(): void
    {
        $key = IdentityMap::deriveKey(['b' => 2, 'a' => 1]);

        // Keys are sorted, so 'a' comes first
        $this->assertSame('i:1|i:2', $key);
    }

    public function testDeriveKeyForNull(): void
    {
        $this->assertSame('n:', IdentityMap::deriveKey(null));
    }

    public function testDeriveKeyForBool(): void
    {
        $this->assertSame('b:1', IdentityMap::deriveKey(true));
        $this->assertSame('b:0', IdentityMap::deriveKey(false));
    }

    public function testTypedValueForFloat(): void
    {
        $this->assertSame('f:3.14', IdentityMap::typedValue(3.14));
    }

    public function testDeriveKeyConsistentWithInstanceUsage(): void
    {
        $map = new IdentityMap();
        $entity = new \stdClass();

        $map->put('Test', ['a' => 1, 'b' => 2], $entity);

        // The static method should produce the same key
        $this->assertSame($entity, $map->get('Test', ['a' => 1, 'b' => 2]));
        $this->assertSame($entity, $map->get('Test', ['b' => 2, 'a' => 1]));
    }
}
