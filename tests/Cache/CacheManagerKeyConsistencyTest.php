<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Cache;

use PHPUnit\Framework\TestCase;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\SecondLevelCacheInterface;
use SybaseORM\ORM\IdentityMap;
use stdClass;

/**
 * Tests that CacheManager uses typed key prefixes consistent with IdentityMap.
 */
final class CacheManagerKeyConsistencyTest extends TestCase
{
    private IdentityMap $identityMap;

    protected function setUp(): void
    {
        $this->identityMap = new IdentityMap();
    }

    public function testEntityKeyUsesTypedPrefixForIntId(): void
    {
        $entity = new stdClass();
        $entity->name = 'Alice';

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\User:i:42', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\User', 42, $entity);
    }

    public function testEntityKeyUsesTypedPrefixForStringId(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\User:s:abc-123', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\User', 'abc-123', $entity);
    }

    public function testEntityKeyUsesTypedPrefixForCompositeId(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\Membership:i:1|i:42', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\Membership', ['orgId' => 1, 'userId' => 42], $entity);
    }

    public function testEntityKeyCompositeIdIsSorted(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        // Keys should be sorted: orgId before userId
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\Membership:i:1|i:42', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        // Pass in reverse order — should still produce same key
        $cache->put('App\\Entity\\Membership', ['userId' => 42, 'orgId' => 1], $entity);
    }

    public function testEntityKeyDistinguishesIntFromString(): void
    {
        $entity1 = new stdClass();
        $entity1->type = 'int';
        $entity2 = new stdClass();
        $entity2->type = 'string';

        $capturedKeys = [];
        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->method('put')
            ->willReturnCallback(function (string $key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
            });

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\User', 1, $entity1);
        $cache->put('App\\Entity\\User', '1', $entity2);

        $this->assertCount(2, $capturedKeys);
        $this->assertSame('entity:App\\Entity\\User:i:1', $capturedKeys[0]);
        $this->assertSame('entity:App\\Entity\\User:s:1', $capturedKeys[1]);
    }

    public function testInvalidateUsesTypedKey(): void
    {
        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('delete')
            ->with('entity:App\\Entity\\User:i:5');

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->invalidate('App\\Entity\\User', 5);
    }

    public function testGetUsesTypedKey(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('get')
            ->with('entity:App\\Entity\\User:i:7')
            ->willReturn($entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $result = $cache->get('App\\Entity\\User', 7);

        $this->assertSame($entity, $result);
    }

    public function testEntityKeyHandlesNullInComposite(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\Test:i:1|n:', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\Test', ['a' => 1, 'b' => null], $entity);
    }

    public function testEntityKeyHandlesBoolId(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\Test:b:1', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\Test', true, $entity);
    }

    public function testEntityKeyHandlesFloatId(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\Test:f:3.14', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\Test', 3.14, $entity);
    }
}
