<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a SET assignment in an UPDATE statement: alias.property = value
 */
final class SetClause
{
    public function __construct(
        public readonly PropertyAccess $property,
        public readonly Parameter|Literal|CustomFunctionCall $value,
    ) {
    }
}
