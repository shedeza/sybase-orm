<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents the FROM clause: FROM EntityName alias
 */
final class FromClause
{
    public function __construct(
        public readonly string $entityName,
        public readonly string $alias,
    ) {
    }
}
