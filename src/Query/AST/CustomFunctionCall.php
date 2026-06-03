<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a custom function call: CONVERT(expr AS type), RAND()
 * Distinct from FunctionCall which handles aggregate functions (COUNT, SUM, etc.)
 */
final class CustomFunctionCall
{
    /**
     * @param array<PropertyAccess|Literal|Parameter|CustomFunctionCall> $arguments
     */
    public function __construct(
        public readonly string $functionName,
        public readonly array $arguments = [],
        public readonly ?string $castType = null,
    ) {}
}
