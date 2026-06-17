<?php

declare(strict_types=1);

namespace SybaseORM\Query;

/**
 * Builds SQL queries programmatically and safely.
 */
interface QueryBuilderInterface
{
    /** Defines the columns or expressions to select. */
    public function select(string ...$columns): static;

    /** Enables DISTINCT on the SELECT clause. */
    public function distinct(bool $distinct = true): static;

    /** Defines the source table or entity for the query. */
    public function from(string $from, ?string $alias = null): static;

    /** Sets a WHERE condition with automatic parameterization. Replaces any previous WHERE conditions. Use andWhere()/orWhere() to append. */
    public function where(string $condition, array $params = []): static;

    /** Appends an AND WHERE condition. */
    public function andWhere(string $condition, array $params = []): static;

    /** Appends an OR WHERE condition. */
    public function orWhere(string $condition, array $params = []): static;

    /** Adds a JOIN to the query. */
    public function join(string $join, string $alias, string $condition): static;

    /** Adds a LEFT JOIN to the query. */
    public function leftJoin(string $join, string $alias, string $condition): static;

    /** Adds a RIGHT JOIN to the query. */
    public function rightJoin(string $join, string $alias, string $condition): static;

    /** Defines the result ordering. */
    public function orderBy(string $column, string $direction = 'ASC'): static;

    /** Adds an additional ORDER BY clause without replacing existing ones. */
    public function addOrderBy(string $column, string $direction = 'ASC'): static;

    /** Defines the result grouping. */
    public function groupBy(string ...$columns): static;

    /** Adds additional GROUP BY columns without replacing existing ones. */
    public function addGroupBy(string ...$columns): static;

    /** Defines the result limit (delegated to Dialect for TOP/ROW_NUMBER). */
    public function limit(int $limit): static;

    /** Defines the result offset (delegated to Dialect for subqueries). */
    public function offset(int $offset): static;

    /** Specifies relations for Eager Loading via JOINs or WHERE IN. */
    public function with(string ...$relations): static;

    /** Adds a HAVING condition to the query. */
    public function having(string $condition, array $params = []): static;

    /** Sets a single named parameter value. */
    public function setParameter(string $name, mixed $value): static;

    /** Merges multiple named parameter values. */
    public function setParameters(array $params): static;

    /** Resets all query state for reuse. */
    public function reset(): static;

    /** Generates the parameterized SQL query. */
    public function getSQL(): string;

    /** Returns the query parameters. */
    public function getParameters(): array;

    /**
     * Executes the query and returns all results.
     *
     * @return array Hydrated entities or raw rows
     */
    public function getResult(): array;

    /**
     * Executes the query and returns the first result, or null if empty.
     */
    public function getSingleResult(): mixed;

    /**
     * Executes the query and returns all results as scalar values (first column of each row).
     *
     * @return array<int, mixed>
     */
    public function getScalarResult(): array;

    /**
     * Executes the query and returns a single scalar value (first column of first row).
     */
    public function getSingleScalarResult(): mixed;

    /** Sets the maximum number of results (alias for limit). */
    public function setMaxResults(int $maxResults): static;

    /** Sets the first result offset (alias for offset). */
    public function setFirstResult(int $firstResult): static;
}
