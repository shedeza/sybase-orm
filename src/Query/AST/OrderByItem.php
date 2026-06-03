<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a single ORDER BY item: property ASC|DESC
 */
final class OrderByItem
{
    public function __construct(
        public readonly PropertyAccess $property,
        public readonly string $direction = 'ASC',
    ) {}
}
