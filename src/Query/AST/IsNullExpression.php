<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents an IS NULL or IS NOT NULL condition.
 * e.g. u.name IS NULL, u.name IS NOT NULL
 */
final class IsNullExpression
{
    public function __construct(
        public readonly PropertyAccess $property,
        public readonly bool $negated = false,
    ) {
    }
}
