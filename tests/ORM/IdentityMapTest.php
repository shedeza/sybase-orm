<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\IdentityMap;

/**
 * @covers \SybaseORM\ORM\IdentityMap
 */
final class IdentityMapTest extends TestCase
{
    private IdentityMap $identityMap;

    protected function setUp(): void
    {
        $this->identityMap = new IdentityMap();
    }

    public function testDeriveKeyScalarFastPath(): void
    {
        $this->assertSame('i:42', IdentityMap::deriveKey(42));
        $this->assertSame('s:test', IdentityMap::deriveKey('test'));
        $this->assertSame('f:3.14', IdentityMap::deriveKey(3.14));
        $this->assertSame('b:1', IdentityMap::deriveKey(true));
        $this->assertSame('b:0', IdentityMap::deriveKey(false));
        $this->assertSame('n:', IdentityMap::deriveKey(null));
    }

    public function testDeriveKeyComposite(): void
    {
        $this->assertSame('i:1|s:foo', IdentityMap::deriveKey(['b' => 'foo', 'a' => 1]));
    }

    public function testPutGetContainsRemove(): void
    {
        $entity = new \stdClass();
        $entity->name = 'test';

        $this->assertFalse($this->identityMap->contains(\stdClass::class, 10));
        $this->assertNull($this->identityMap->get(\stdClass::class, 10));

        $this->identityMap->put(\stdClass::class, 10, $entity);

        $this->assertTrue($this->identityMap->contains(\stdClass::class, 10));
        $this->assertSame($entity, $this->identityMap->get(\stdClass::class, 10));
        $this->assertSame(1, $this->identityMap->count());
        $this->assertSame(1, $this->identityMap->countClass(\stdClass::class));

        $this->identityMap->remove(\stdClass::class, 10);
        $this->assertFalse($this->identityMap->contains(\stdClass::class, 10));
        $this->assertSame(0, $this->identityMap->count());
    }

    public function testClearAndClearClass(): void
    {
        $entity1 = new \stdClass();
        $entity2 = new \stdClass();

        $this->identityMap->put(\stdClass::class, 1, $entity1);
        $this->identityMap->put(\stdClass::class, 2, $entity2);
        $this->assertSame(2, $this->identityMap->count());

        $this->identityMap->clearClass(\stdClass::class);
        $this->assertFalse($this->identityMap->contains(\stdClass::class, 1));
        $this->assertFalse($this->identityMap->contains(\stdClass::class, 2));

        $this->identityMap->put(\stdClass::class, 1, $entity1);
        $this->identityMap->clear();
        $this->assertSame(0, $this->identityMap->count());
    }
}
