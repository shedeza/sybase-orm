<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a BETWEEN expression: property BETWEEN low AND high
 * or: property NOT BETWEEN low AND high
 */
final class BetweenExpression
{
    public function __construct(
        public readonly PropertyAccess $property,
        public readonly Parameter|Literal $low,
        public readonly Parameter|Literal $high,
        public readonly bool $negated = false,
    ) {}
}
