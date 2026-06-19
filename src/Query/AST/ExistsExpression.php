<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents an EXISTS (subquery) or NOT EXISTS (subquery) condition.
 * The subquery is stored as raw OQL string for separate parsing/translation.
 */
final class ExistsExpression
{
    public function __construct(
        public readonly string $subquery,
        public readonly bool $negated = false,
    ) {}
}
