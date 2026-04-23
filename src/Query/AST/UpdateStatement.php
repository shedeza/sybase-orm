<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Root AST node representing a complete OQL UPDATE statement.
 * e.g. UPDATE EntityName alias SET alias.prop = value [WHERE condition]
 */
final class UpdateStatement
{
    /**
     * @param SetClause[] $setClauses
     */
    public function __construct(
        public readonly string $entityName,
        public readonly string $alias,
        public readonly array $setClauses,
        public readonly ?WhereClause $where = null,
    ) {
    }
}
