<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\IdentityMap;

/**
 * Tests for IdentityMap count() and countClass() methods.
 */
final class IdentityMapCountTest extends TestCase
{
    private IdentityMap $map;

    protected function setUp(): void
    {
        $this->map = new IdentityMap();
    }

    public function testCountReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->map->count());
    }

    public function testCountReturnsTotalEntities(): void
    {
        $this->map->put('App\\Entity\\User', 1, new \stdClass());
        $this->map->put('App\\Entity\\User', 2, new \stdClass());
        $this->map->put('App\\Entity\\Post', 1, new \stdClass());

        $this->assertSame(3, $this->map->count());
    }

    public function testCountClassReturnsZeroForUnknownClass(): void
    {
        $this->assertSame(0, $this->map->countClass('App\\Entity\\Unknown'));
    }

    public function testCountClassReturnsCorrectCount(): void
    {
        $this->map->put('App\\Entity\\User', 1, new \stdClass());
        $this->map->put('App\\Entity\\User', 2, new \stdClass());
        $this->map->put('App\\Entity\\Post', 1, new \stdClass());

        $this->assertSame(2, $this->map->countClass('App\\Entity\\User'));
        $this->assertSame(1, $this->map->countClass('App\\Entity\\Post'));
    }

    public function testCountDecreasesAfterRemove(): void
    {
        $this->map->put('App\\Entity\\User', 1, new \stdClass());
        $this->map->put('App\\Entity\\User', 2, new \stdClass());

        $this->assertSame(2, $this->map->count());

        $this->map->remove('App\\Entity\\User', 1);

        $this->assertSame(1, $this->map->count());
        $this->assertSame(1, $this->map->countClass('App\\Entity\\User'));
    }

    public function testCountReturnsZeroAfterClear(): void
    {
        $this->map->put('App\\Entity\\User', 1, new \stdClass());
        $this->map->put('App\\Entity\\Post', 1, new \stdClass());

        $this->map->clear();

        $this->assertSame(0, $this->map->count());
    }

    public function testCountClassReturnsZeroAfterClearClass(): void
    {
        $this->map->put('App\\Entity\\User', 1, new \stdClass());
        $this->map->put('App\\Entity\\Post', 1, new \stdClass());

        $this->map->clearClass('App\\Entity\\User');

        $this->assertSame(0, $this->map->countClass('App\\Entity\\User'));
        $this->assertSame(1, $this->map->count());
    }
}
