<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a JOIN clause: JOIN alias.property newAlias
 */
final class JoinClause
{
    public function __construct(
        public readonly string $joinType,
        public readonly PropertyAccess $property,
        public readonly string $alias,
    ) {
    }
}
