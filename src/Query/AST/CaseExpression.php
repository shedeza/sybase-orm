<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a CASE WHEN ... THEN ... ELSE ... END expression.
 *
 * @property array<int, array{condition: mixed, result: mixed}> $whenClauses
 */
final class CaseExpression
{
    /**
     * @param array<int, array{condition: mixed, result: mixed}> $whenClauses
     * @param mixed $elseResult The ELSE value (Parameter, Literal, PropertyAccess, or null)
     */
    public function __construct(
        public readonly array $whenClauses,
        public readonly mixed $elseResult = null,
    ) {}
}
