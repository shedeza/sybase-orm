<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a property access: alias.propertyName
 */
final class PropertyAccess
{
    public function __construct(
        public readonly string $alias,
        public readonly string $property,
    ) {}
}
