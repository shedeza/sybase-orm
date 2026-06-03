<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Root AST node representing a complete OQL DELETE statement.
 * e.g. DELETE FROM EntityName alias [WHERE condition]
 */
final class DeleteStatement
{
    public function __construct(
        public readonly string $entityName,
        public readonly string $alias,
        public readonly ?WhereClause $where = null,
    ) {}
}
