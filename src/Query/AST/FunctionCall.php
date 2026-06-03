<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents an aggregate function call.
 * e.g. COUNT(u.id), SUM(DISTINCT o.amount), COUNT(*)
 */
final class FunctionCall
{
    public function __construct(
        public readonly string $functionName,
        public readonly PropertyAccess|string $argument,
        public readonly bool $distinct = false,
    ) {}
}
