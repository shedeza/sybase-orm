<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type\Fixtures;

/**
 * A class that does NOT implement CustomTypeInterface, used for testing validation.
 */
class InvalidCustomType
{
    public function toDatabaseValue(mixed $value): mixed
    {
        return $value;
    }
}
