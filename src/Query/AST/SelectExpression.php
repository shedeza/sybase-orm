<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a single expression in the SELECT clause.
 * e.g. "u" or "u.name"
 */
final class SelectExpression
{
    public function __construct(
        public readonly string|FunctionCall $expression,
        public readonly ?string $alias = null,
    ) {}
}
