<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Root AST node representing a complete OQL SELECT statement.
 */
final class SelectStatement
{
    /**
     * @param SelectExpression[] $selectExpressions
     * @param JoinClause[]       $joins
     */
    public function __construct(
        public readonly array $selectExpressions,
        public readonly FromClause $from,
        public readonly ?WhereClause $where = null,
        public readonly array $joins = [],
        public readonly ?OrderByClause $orderBy = null,
        public readonly ?GroupByClause $groupBy = null,
        public readonly ?HavingClause $havingClause = null,
        public readonly bool $distinct = false,
    ) {}
}
