<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents a named parameter: :paramName
 */
final class Parameter
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
