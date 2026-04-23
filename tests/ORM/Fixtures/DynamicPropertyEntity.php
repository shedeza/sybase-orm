<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

/**
 * Test fixture entity with 5 potential key properties and one data property.
 *
 * Used by UnitOfWorkPropertyTest to test composite WHERE clause generation
 * with varying numbers of id columns (1–5).
 */
class DynamicPropertyEntity
{
    public mixed $key0 = null;
    public mixed $key1 = null;
    public mixed $key2 = null;
    public mixed $key3 = null;
    public mixed $key4 = null;
    public mixed $data = null;
}
