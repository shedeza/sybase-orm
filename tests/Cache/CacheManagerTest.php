<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SybaseORM\Cache\CacheManager;
use SybaseORM\Cache\SecondLevelCacheInterface;
use SybaseORM\ORM\IdentityMap;
use stdClass;

/**
 * @covers \SybaseORM\Cache\CacheManager
 */
final class CacheManagerTest extends TestCase
{
    private IdentityMap $identityMap;

    protected function setUp(): void
    {
        $this->identityMap = new IdentityMap();
    }

    // ── First-level cache (Identity Map) hit/miss ──────────────────

    public function testGetReturnsEntityFromFirstLevelCache(): void
    {
        $entity = new stdClass();
        $entity->name = 'Alice';

        $this->identityMap->put('App\\Entity\\User', 1, $entity);

        $cache = new CacheManager($this->identityMap);

        $this->assertSame($entity, $cache->get('App\\Entity\\User', 1));
    }

    public function testGetReturnsNullWhenEntityNotInAnyCache(): void
    {
        $cache = new CacheManager($this->identityMap);

        $this->assertNull($cache->get('App\\Entity\\User', 999));
    }

    public function testPutStoresEntityInFirstLevelCache(): void
    {
        $cache = new CacheManager($this->identityMap);
        $entity = new stdClass();

        $cache->put('App\\Entity\\User', 1, $entity);

        $this->assertSame($entity, $this->identityMap->get('App\\Entity\\User', 1));
    }

    public function testGetChecksFirstLevelBeforeSecondLevel(): void
    {
        $entity = new stdClass();
        $entity->source = 'identity_map';

        $this->identityMap->put('App\\Entity\\User', 1, $entity);

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        // Second level should never be called when first level has the entity
        $secondLevel->expects($this->never())->method('get');

        $cache = new CacheManager($this->identityMap, $secondLevel);

        $this->assertSame($entity, $cache->get('App\\Entity\\User', 1));
    }

    // ── Second-level cache integration ─────────────────────────────

    public function testGetFallsToSecondLevelOnFirstLevelMiss(): void
    {
        $entity = new stdClass();
        $entity->source = 'second_level';

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->method('get')
            ->with('entity:App\\Entity\\User:1')
            ->willReturn($entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);

        $result = $cache->get('App\\Entity\\User', 1);

        $this->assertSame($entity, $result);
        // Should also be promoted to first level
        $this->assertSame($entity, $this->identityMap->get('App\\Entity\\User', 1));
    }

    public function testPutStoresInBothLevels(): void
    {
        $entity = new stdClass();

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('entity:App\\Entity\\User:1', $entity);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->put('App\\Entity\\User', 1, $entity);

        $this->assertSame($entity, $this->identityMap->get('App\\Entity\\User', 1));
    }

    // ── Invalidation ───────────────────────────────────────────────

    public function testInvalidateRemovesFromBothLevels(): void
    {
        $entity = new stdClass();
        $this->identityMap->put('App\\Entity\\User', 1, $entity);

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('delete')
            ->with('entity:App\\Entity\\User:1');

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->invalidate('App\\Entity\\User', 1);

        $this->assertNull($this->identityMap->get('App\\Entity\\User', 1));
    }

    public function testInvalidateWorksWithoutSecondLevel(): void
    {
        $entity = new stdClass();
        $this->identityMap->put('App\\Entity\\User', 1, $entity);

        $cache = new CacheManager($this->identityMap);
        $cache->invalidate('App\\Entity\\User', 1);

        $this->assertNull($this->identityMap->get('App\\Entity\\User', 1));
    }

    // ── Query result caching (second level only) ───────────────────

    public function testPutQueryResultStoresInSecondLevel(): void
    {
        $result = [['id' => 1, 'name' => 'Alice']];

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())
            ->method('put')
            ->with('query:select_users', $result, 300);

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->putQueryResult('select_users', $result, 300);
    }

    public function testGetQueryResultReturnsFromSecondLevel(): void
    {
        $result = [['id' => 1, 'name' => 'Alice']];

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->method('get')
            ->with('query:select_users')
            ->willReturn($result);

        $cache = new CacheManager($this->identityMap, $secondLevel);

        $this->assertSame($result, $cache->getQueryResult('select_users'));
    }

    public function testGetQueryResultReturnsNullWithoutSecondLevel(): void
    {
        $cache = new CacheManager($this->identityMap);

        $this->assertNull($cache->getQueryResult('select_users'));
    }

    public function testPutQueryResultIsNoOpWithoutSecondLevel(): void
    {
        $cache = new CacheManager($this->identityMap);
        // Should not throw
        $cache->putQueryResult('select_users', [['id' => 1]], 60);

        $this->assertNull($cache->getQueryResult('select_users'));
    }

    // ── Fallback on second-level failure ───────────────────────────

    public function testFallsBackToFirstLevelOnSecondLevelGetFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Second-level cache unavailable'));

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->method('get')
            ->willThrowException(new \RuntimeException('Redis connection refused'));

        $cache = new CacheManager($this->identityMap, $secondLevel, $logger);

        // Should not throw, returns null (miss on both levels)
        $this->assertNull($cache->get('App\\Entity\\User', 1));
    }

    public function testFallsBackToFirstLevelOnSecondLevelPutFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->method('put')
            ->willThrowException(new \RuntimeException('Redis connection refused'));

        $cache = new CacheManager($this->identityMap, $secondLevel, $logger);
        $entity = new stdClass();

        // Should not throw, entity still stored in first level
        $cache->put('App\\Entity\\User', 1, $entity);

        $this->assertSame($entity, $this->identityMap->get('App\\Entity\\User', 1));
    }

    public function testSecondLevelDisabledAfterFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $callCount = 0;
        $secondLevel->method('get')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                throw new \RuntimeException('Redis down');
            });

        $cache = new CacheManager($this->identityMap, $secondLevel, $logger);

        // First call triggers failure and disables second level
        $cache->get('App\\Entity\\User', 1);
        // Second call should NOT hit second level at all
        $cache->get('App\\Entity\\User', 2);

        $this->assertSame(1, $callCount, 'Second level should only be called once before being disabled');
    }

    // ── Clear ──────────────────────────────────────────────────────

    public function testClearBothLevels(): void
    {
        $entity = new stdClass();
        $this->identityMap->put('App\\Entity\\User', 1, $entity);

        $secondLevel = $this->createMock(SecondLevelCacheInterface::class);
        $secondLevel->expects($this->once())->method('clear');

        $cache = new CacheManager($this->identityMap, $secondLevel);
        $cache->clear();

        $this->assertNull($this->identityMap->get('App\\Entity\\User', 1));
    }

    public function testClearWorksWithoutSecondLevel(): void
    {
        $entity = new stdClass();
        $this->identityMap->put('App\\Entity\\User', 1, $entity);

        $cache = new CacheManager($this->identityMap);
        $cache->clear();

        $this->assertNull($this->identityMap->get('App\\Entity\\User', 1));
    }
}
