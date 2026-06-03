<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a literal value: string, integer, or float.
 */
final class Literal
{
    public function __construct(
        public readonly string|int|float $value,
        public readonly string $type,
    ) {}
}
