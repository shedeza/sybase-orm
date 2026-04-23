<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\ORM\IdentityMap;
use stdClass;

/**
 * @covers \SybaseORM\ORM\IdentityMap
 */
final class IdentityMapTest extends TestCase
{
    private IdentityMap $map;

    protected function setUp(): void
    {
        $this->map = new IdentityMap();
    }

    public function testPutAndGetReturnsSameInstance(): void
    {
        $entity = new stdClass();
        $entity->name = 'Alice';

        $this->map->put('App\Entity\User', 1, $entity);

        $this->assertSame($entity, $this->map->get('App\Entity\User', 1));
    }

    public function testGetReturnsNullForMissingEntity(): void
    {
        $this->assertNull($this->map->get('App\Entity\User', 999));
    }

    public function testGetReturnsNullForMissingClass(): void
    {
        $this->assertNull($this->map->get('App\Entity\NonExistent', 1));
    }

    public function testContainsReturnsTrueWhenPresent(): void
    {
        $this->map->put('App\Entity\User', 5, new stdClass());

        $this->assertTrue($this->map->contains('App\Entity\User', 5));
    }

    public function testContainsReturnsFalseWhenAbsent(): void
    {
        $this->assertFalse($this->map->contains('App\Entity\User', 5));
    }

    public function testRemoveDeletesEntity(): void
    {
        $this->map->put('App\Entity\User', 1, new stdClass());
        $this->map->remove('App\Entity\User', 1);

        $this->assertFalse($this->map->contains('App\Entity\User', 1));
        $this->assertNull($this->map->get('App\Entity\User', 1));
    }

    public function testRemoveOnMissingEntityDoesNotError(): void
    {
        // Should not throw
        $this->map->remove('App\Entity\User', 999);
        $this->assertFalse($this->map->contains('App\Entity\User', 999));
    }

    public function testClearRemovesAllEntities(): void
    {
        $this->map->put('App\Entity\User', 1, new stdClass());
        $this->map->put('App\Entity\Post', 2, new stdClass());

        $this->map->clear();

        $this->assertNull($this->map->get('App\Entity\User', 1));
        $this->assertNull($this->map->get('App\Entity\Post', 2));
    }

    public function testPutOverwritesPreviousInstance(): void
    {
        $first = new stdClass();
        $first->version = 1;
        $second = new stdClass();
        $second->version = 2;

        $this->map->put('App\Entity\User', 1, $first);
        $this->map->put('App\Entity\User', 1, $second);

        $this->assertSame($second, $this->map->get('App\Entity\User', 1));
    }

    public function testDifferentClassesSameIdAreIndependent(): void
    {
        $user = new stdClass();
        $user->type = 'user';
        $post = new stdClass();
        $post->type = 'post';

        $this->map->put('App\Entity\User', 1, $user);
        $this->map->put('App\Entity\Post', 1, $post);

        $this->assertSame($user, $this->map->get('App\Entity\User', 1));
        $this->assertSame($post, $this->map->get('App\Entity\Post', 1));
    }

    public function testStringIdIsSupported(): void
    {
        $entity = new stdClass();
        $this->map->put('App\Entity\User', 'uuid-abc-123', $entity);

        $this->assertTrue($this->map->contains('App\Entity\User', 'uuid-abc-123'));
        $this->assertSame($entity, $this->map->get('App\Entity\User', 'uuid-abc-123'));
    }

    public function testUniquenessGuaranteedAcrossGets(): void
    {
        $entity = new stdClass();
        $this->map->put('App\Entity\User', 42, $entity);

        $a = $this->map->get('App\Entity\User', 42);
        $b = $this->map->get('App\Entity\User', 42);

        $this->assertSame($a, $b, 'Multiple get() calls must return the exact same instance');
    }
}
