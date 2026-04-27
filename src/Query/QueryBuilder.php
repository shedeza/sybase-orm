<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Dialect\DialectInterface;

/**
 * Fluent query builder that generates parameterized SQL via a DialectInterface.
 *
 * All mutator methods return $this for chaining.
 */
final class QueryBuilder implements QueryBuilderInterface
{
    /** @var string[] */
    private array $selectColumns = [];

    private bool $distinct = false;

    private ?string $fromTable = null;
    private ?string $fromAlias = null;

    /** @var array<int, array{condition: string, type: string}> */
    private array $whereClauses = [];

    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @var array<int, array{type: string, table: string, alias: string, condition: string}> */
    private array $joins = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orderByClauses = [];

    /** @var string[] */
    private array $groupByColumns = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    /** @var string[] */
    private array $eagerRelations = [];

    private ?string $havingCondition = null;

    /** @var array<string, mixed> */
    private array $havingParameters = [];

    public function __construct(
        private readonly DialectInterface $dialect,
    ) {
    }

    public function reset(): static
    {
        $this->selectColumns = [];
        $this->distinct = false;
        $this->fromTable = null;
        $this->fromAlias = null;
        $this->whereClauses = [];
        $this->parameters = [];
        $this->joins = [];
        $this->orderByClauses = [];
        $this->groupByColumns = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        $this->eagerRelations = [];
        $this->havingCondition = null;
        $this->havingParameters = [];

        return $this;
    }

    public function select(string ...$columns): static
    {
        $this->selectColumns = $columns;

        return $this;
    }

    /**
     * Enables DISTINCT on the SELECT clause.
     */
    public function distinct(bool $distinct = true): static
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function from(string $from, ?string $alias = null): static
    {
        $this->fromTable = $from;
        $this->fromAlias = $alias;

        return $this;
    }

    public function where(string $condition, array $params = []): static
    {
        $this->whereClauses = [['condition' => $condition, 'type' => 'AND']];
        $this->parameters = $params;

        return $this;
    }

    public function andWhere(string $condition, array $params = []): static
    {
        $this->whereClauses[] = ['condition' => $condition, 'type' => 'AND'];
        $this->parameters = array_merge($this->parameters, $params);

        return $this;
    }

    public function orWhere(string $condition, array $params = []): static
    {
        $this->whereClauses[] = ['condition' => $condition, 'type' => 'OR'];
        $this->parameters = array_merge($this->parameters, $params);

        return $this;
    }

    public function join(string $join, string $alias, string $condition): static
    {
        $this->joins[] = [
            'type' => 'JOIN',
            'table' => $join,
            'alias' => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function leftJoin(string $join, string $alias, string $condition): static
    {
        $this->joins[] = [
            'type' => 'LEFT JOIN',
            'table' => $join,
            'alias' => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function rightJoin(string $join, string $alias, string $condition): static
    {
        $this->joins[] = [
            'type' => 'RIGHT JOIN',
            'table' => $join,
            'alias' => $alias,
            'condition' => $condition,
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderByClauses[] = [
            'column' => $column,
            'direction' => strtoupper($direction),
        ];

        return $this;
    }

    public function addOrderBy(string $column, string $direction = 'ASC'): static
    {
        return $this->orderBy($column, $direction);
    }

    public function groupBy(string ...$columns): static
    {
        $this->groupByColumns = $columns;

        return $this;
    }

    /**
     * Adds additional GROUP BY columns without replacing existing ones.
     */
    public function addGroupBy(string ...$columns): static
    {
        $this->groupByColumns = array_merge($this->groupByColumns, $columns);

        return $this;
    }

    public function having(string $condition, array $params = []): static
    {
        $this->havingCondition = $condition;
        $this->havingParameters = $params;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offsetValue = $offset;

        return $this;
    }

    public function with(string ...$relations): static
    {
        $this->eagerRelations = array_merge($this->eagerRelations, $relations);

        return $this;
    }

    public function setParameter(string $name, mixed $value): static
    {
        $this->parameters[$name] = $value;

        return $this;
    }

    public function setParameters(array $params): static
    {
        $this->parameters = array_merge($this->parameters, $params);

        return $this;
    }

    public function getSQL(): string
    {
        if ($this->fromTable === null) {
            throw new \LogicException('Cannot generate SQL: from() has not been called.');
        }

        $sql = $this->buildSelectClause();
        $sql .= $this->buildFromClause();
        $sql .= $this->buildJoinClauses();
        $sql .= $this->buildEagerLoadingJoins();
        $sql .= $this->buildWhereClause();
        $sql .= $this->buildGroupByClause();
        $sql .= $this->buildHavingClause();
        $sql .= $this->buildOrderByClause();

        if ($this->limitValue !== null) {
            $sql = $this->dialect->applyPagination($sql, $this->limitValue, $this->offsetValue);
        }

        return $sql;
    }

    public function getParameters(): array
    {
        return array_merge($this->parameters, $this->havingParameters);
    }

    /**
     * Returns the current FROM table name, or null if not set.
     */
    public function getFrom(): ?string
    {
        return $this->fromTable;
    }

    /**
     * Returns the current FROM alias, or null if not set.
     */
    public function getFromAlias(): ?string
    {
        return $this->fromAlias;
    }

    /**
     * Returns the current SELECT columns.
     *
     * @return string[]
     */
    public function getSelectColumns(): array
    {
        return $this->selectColumns;
    }

    /**
     * Returns true if DISTINCT is enabled.
     */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    // ── Private SQL-building helpers ────────────────────────────────

    private function buildSelectClause(): string
    {
        $columns = $this->selectColumns ?: ['*'];
        $distinctStr = $this->distinct ? 'DISTINCT ' : '';

        return 'SELECT ' . $distinctStr . implode(', ', $columns);
    }

    private function buildFromClause(): string
    {
        if ($this->fromTable === null) {
            return '';
        }

        $sql = ' FROM ' . $this->fromTable;

        if ($this->fromAlias !== null) {
            $sql .= ' ' . $this->fromAlias;
        }

        return $sql;
    }

    private function buildJoinClauses(): string
    {
        if ($this->joins === []) {
            return '';
        }

        $parts = [];
        foreach ($this->joins as $join) {
            $parts[] = sprintf(
                ' %s %s %s ON %s',
                $join['type'],
                $join['table'],
                $join['alias'],
                $join['condition'],
            );
        }

        return implode('', $parts);
    }

    private function buildEagerLoadingJoins(): string
    {
        if ($this->eagerRelations === []) {
            return '';
        }

        $parts = [];
        foreach ($this->eagerRelations as $relation) {
            $alias = $relation;
            $parts[] = sprintf(
                ' LEFT JOIN %s %s ON %s.id = %s.%s_id',
                $relation,
                $alias,
                $this->fromAlias ?? $this->fromTable,
                $alias,
                $this->fromAlias ?? $this->fromTable,
            );
        }

        return implode('', $parts);
    }

    private function buildWhereClause(): string
    {
        if ($this->whereClauses === []) {
            return '';
        }

        $sql = ' WHERE ';
        foreach ($this->whereClauses as $i => $clause) {
            if ($i > 0) {
                $sql .= ' ' . $clause['type'] . ' ';
            }
            $sql .= $clause['condition'];
        }

        return $sql;
    }

    private function buildGroupByClause(): string
    {
        if ($this->groupByColumns === []) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', $this->groupByColumns);
    }

    private function buildHavingClause(): string
    {
        if ($this->havingCondition === null) {
            return '';
        }

        return ' HAVING ' . $this->havingCondition;
    }

    private function buildOrderByClause(): string
    {
        if ($this->orderByClauses === []) {
            return '';
        }

        $parts = array_map(
            fn(array $o) => $o['column'] . ' ' . $o['direction'],
            $this->orderByClauses,
        );

        return ' ORDER BY ' . implode(', ', $parts);
    }
}
