<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Metadata\MetadataReaderInterface;

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

    /** @var (callable(string $sql, array $params, string $mode=): (array|int))|null Executor for getResult()/getSingleResult()/execute() */
    private $executor = null;

    /** @var string|null Entity class for hydration context */
    private ?string $entityClass = null;

    /** @var MetadataReaderInterface|null Optional — required only for fromEntity() */
    private ?MetadataReaderInterface $metadataReader = null;

    public function __construct(
        private readonly DialectInterface $dialect,
    ) {}

    /**
     * Injects the MetadataReader so that fromEntity() can resolve table names.
     * EntityManager sets this automatically on QueryBuilders it creates.
     */
    public function setMetadataReader(MetadataReaderInterface $metadataReader): static
    {
        $this->metadataReader = $metadataReader;

        return $this;
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

    /**
     * MISS-06: Convenience method that sets the FROM clause from an entity FQCN.
     *
     * Resolves the entity to its qualified database table name via the MetadataReader,
     * stores the entity class for hydration context, and sets a default alias of 'e'.
     *
     * Requires a MetadataReader to be injected first. When using EntityManager::createQueryBuilder(),
     * this is done automatically. If using QueryBuilder standalone, call setMetadataReader() first.
     *
     * Example:
     *   $qb->fromEntity(Producto::class)          // SELECT * FROM mydb..producto e
     *   $qb->fromEntity(Producto::class, 'p')     // SELECT * FROM mydb..producto p
     *
     * @param class-string $entityClass Fully qualified entity class name
     * @param string       $alias       Table alias (default: 'e')
     * @throws \LogicException If no MetadataReader has been injected
     */
    public function fromEntity(string $entityClass, string $alias = 'e'): static
    {
        if ($this->metadataReader === null) {
            throw new \LogicException(
                'Cannot use fromEntity() without a MetadataReader. '
                . 'Either use EntityManager::createQueryBuilder() which sets it automatically, '
                . 'or call setMetadataReader() before fromEntity().',
            );
        }

        $metadata = $this->metadataReader->getClassMetadata($entityClass);
        $this->entityClass = $entityClass;

        return $this->from($metadata->getQualifiedTableName(), $alias);
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

    /**
     * Adds a WHERE column IN (subquery) condition.
     *
     * @param string $column The column to check (e.g. 'e.id')
     * @param QueryBuilderInterface $subquery A QueryBuilder generating the subquery
     */
    public function whereIn(string $column, QueryBuilderInterface $subquery): static
    {
        $subSql = $subquery->getSQL();
        $this->whereClauses[] = ['condition' => $column . ' IN (' . $subSql . ')', 'type' => 'AND'];
        $this->parameters = array_merge($this->parameters, $subquery->getParameters());

        return $this;
    }

    /**
     * Adds a WHERE column NOT IN (subquery) condition.
     *
     * @param string $column The column to check (e.g. 'e.id')
     * @param QueryBuilderInterface $subquery A QueryBuilder generating the subquery
     */
    public function whereNotIn(string $column, QueryBuilderInterface $subquery): static
    {
        $subSql = $subquery->getSQL();
        $this->whereClauses[] = ['condition' => $column . ' NOT IN (' . $subSql . ')', 'type' => 'AND'];
        $this->parameters = array_merge($this->parameters, $subquery->getParameters());

        return $this;
    }

    /**
     * Adds a WHERE EXISTS (subquery) condition.
     *
     * @param QueryBuilderInterface $subquery A QueryBuilder generating the subquery
     */
    public function whereExists(QueryBuilderInterface $subquery): static
    {
        $subSql = $subquery->getSQL();
        $this->whereClauses[] = ['condition' => 'EXISTS (' . $subSql . ')', 'type' => 'AND'];
        $this->parameters = array_merge($this->parameters, $subquery->getParameters());

        return $this;
    }

    /**
     * Adds a WHERE NOT EXISTS (subquery) condition.
     *
     * @param QueryBuilderInterface $subquery A QueryBuilder generating the subquery
     */
    public function whereNotExists(QueryBuilderInterface $subquery): static
    {
        $subSql = $subquery->getSQL();
        $this->whereClauses[] = ['condition' => 'NOT EXISTS (' . $subSql . ')', 'type' => 'AND'];
        $this->parameters = array_merge($this->parameters, $subquery->getParameters());

        return $this;
    }

    /**
     * Adds a WHERE column BETWEEN min AND max condition.
     */
    public function whereBetween(string $column, string $minParam, string $maxParam): static
    {
        $this->whereClauses[] = [
            'condition' => $column . ' BETWEEN :' . $minParam . ' AND :' . $maxParam,
            'type' => 'AND',
        ];

        return $this;
    }

    /**
     * Adds a WHERE column NOT BETWEEN min AND max condition.
     */
    public function whereNotBetween(string $column, string $minParam, string $maxParam): static
    {
        $this->whereClauses[] = [
            'condition' => $column . ' NOT BETWEEN :' . $minParam . ' AND :' . $maxParam,
            'type' => 'AND',
        ];

        return $this;
    }

    /**
     * Adds a WHERE column IS NULL condition.
     */
    public function whereNull(string $column): static
    {
        $this->whereClauses[] = ['condition' => $column . ' IS NULL', 'type' => 'AND'];

        return $this;
    }

    /**
     * Adds a WHERE column IS NOT NULL condition.
     */
    public function whereNotNull(string $column): static
    {
        $this->whereClauses[] = ['condition' => $column . ' IS NOT NULL', 'type' => 'AND'];

        return $this;
    }

    /**
     * Adds a WHERE column LIKE pattern condition.
     */
    public function whereLike(string $column, string $paramName): static
    {
        $this->whereClauses[] = ['condition' => $column . ' LIKE :' . $paramName, 'type' => 'AND'];

        return $this;
    }

    /**
     * Adds a WHERE column NOT LIKE pattern condition.
     */
    public function whereNotLike(string $column, string $paramName): static
    {
        $this->whereClauses[] = ['condition' => $column . ' NOT LIKE :' . $paramName, 'type' => 'AND'];

        return $this;
    }

    /**
     * Adds a raw WHERE condition (escape hatch for complex SQL).
     *
     * @param string $sql Raw SQL condition
     * @param array<string, mixed> $params Named parameters for the condition
     */
    public function whereRaw(string $sql, array $params = []): static
    {
        $this->whereClauses[] = ['condition' => $sql, 'type' => 'AND'];
        $this->parameters = array_merge($this->parameters, $params);

        return $this;
    }

    /**
     * Combines this QueryBuilder with another via UNION.
     *
     * @param QueryBuilderInterface $other The other query to union
     * @param bool $all If true, uses UNION ALL (keeps duplicates)
     */
    public function union(QueryBuilderInterface $other, bool $all = false): static
    {
        $unionType = $all ? 'UNION ALL' : 'UNION';
        $thisSql = $this->getSQL();
        $otherSql = $other->getSQL();

        // Reset and build as a raw combined query
        $this->selectColumns = [$thisSql . ' ' . $unionType . ' ' . $otherSql];
        $this->fromTable = null;
        $this->parameters = array_merge($this->parameters, $other->getParameters());

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

    public function crossJoin(string $join, string $alias): static
    {
        $this->joins[] = [
            'type' => 'CROSS JOIN',
            'table' => $join,
            'alias' => $alias,
            'condition' => '',
        ];

        return $this;
    }

    public function fullJoin(string $join, string $alias, string $condition): static
    {
        $this->joins[] = [
            'type' => 'FULL JOIN',
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

    /**
     * Returns the relations requested for eager loading via with().
     *
     * @return string[]
     */
    public function getEagerRelations(): array
    {
        return $this->eagerRelations;
    }

    /**
     * Returns the current limit value, or null if not set.
     */
    public function getLimit(): ?int
    {
        return $this->limitValue;
    }

    /**
     * Returns the current offset value, or null if not set.
     */
    public function getOffset(): ?int
    {
        return $this->offsetValue;
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
            if ($join['type'] === 'CROSS JOIN' || $join['condition'] === '') {
                // CROSS JOIN has no ON clause
                $parts[] = sprintf(
                    ' %s %s %s',
                    $join['type'],
                    $join['table'],
                    $join['alias'],
                );
            } else {
                $parts[] = sprintf(
                    ' %s %s %s ON %s',
                    $join['type'],
                    $join['table'],
                    $join['alias'],
                    $join['condition'],
                );
            }
        }

        return implode('', $parts);
    }

    private function buildEagerLoadingJoins(): string
    {
        // Eager loading JOINs are resolved at execution time by the EntityManager,
        // which has access to metadata. The QueryBuilder only stores the relation names.
        // If no external resolution has been applied, this is a no-op.
        return '';
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

    // ── Execution methods ───────────────────────────────────────────

    /**
     * Sets the executor callback used by getResult()/getSingleResult()/execute().
     * The callback receives (string $sql, array $params, string $mode) and returns results.
     *
     * @param callable(string, array, string=): (array|int) $executor
     */
    public function setExecutor(callable $executor, ?string $entityClass = null): void
    {
        $this->executor = $executor;
        $this->entityClass = $entityClass;
    }

    /**
     * Executes the query and returns all results.
     *
     * @return array Hydrated entities or raw rows
     * @throws \LogicException If no executor is configured.
     */
    public function getResult(): array
    {
        if ($this->executor === null) {
            throw new \LogicException(
                'Cannot call getResult() on a QueryBuilder without an executor. '
                . 'Use EntityManager::createQueryBuilder() or EntityRepository::createQueryBuilder() to get an executable QueryBuilder.',
            );
        }

        /** @var array $result */
        $result = ($this->executor)($this->getSQL(), $this->getParameters(), 'hydrate');

        return $result;
    }

    /**
     * Executes the query and returns the first result, or null if empty.
     *
     * @return mixed Single entity/row or null
     * @throws \LogicException If no executor is configured.
     */
    public function getSingleResult(): mixed
    {
        $prevLimit = $this->limitValue;
        $this->limitValue = 1;

        try {
            $results = $this->getResult();

            return $results[0] ?? null;
        } finally {
            $this->limitValue = $prevLimit;
        }
    }

    /**
     * Executes the query and returns all results as scalar values (first column of each row).
     *
     * @return array<int, mixed> List of scalar values
     * @throws \LogicException If no executor is configured.
     */
    public function getScalarResult(): array
    {
        if ($this->executor === null) {
            throw new \LogicException(
                'Cannot call getScalarResult() on a QueryBuilder without an executor. '
                . 'Use EntityManager::createQueryBuilder() or EntityRepository::createQueryBuilder().',
            );
        }

        /** @var array $result */
        $result = ($this->executor)($this->getSQL(), $this->getParameters(), 'scalar');

        return $result;
    }

    /**
     * Executes the query and returns a single scalar value (first column of first row).
     *
     * @return mixed The scalar value or null if no results
     * @throws \LogicException If no executor is configured.
     */
    public function getSingleScalarResult(): mixed
    {
        $prevLimit = $this->limitValue;
        $this->limitValue = 1;

        try {
            $results = $this->getScalarResult();

            return $results[0] ?? null;
        } finally {
            $this->limitValue = $prevLimit;
        }
    }

    /**
     * Executes the query and returns all results as associative arrays (no hydration).
     *
     * @return array<int, array<string, mixed>>
     * @throws \LogicException If no executor is configured.
     */
    public function getArrayResult(): array
    {
        if ($this->executor === null) {
            throw new \LogicException(
                'Cannot call getArrayResult() on a QueryBuilder without an executor. '
                . 'Use EntityManager::createQueryBuilder() or EntityRepository::createQueryBuilder().',
            );
        }

        /** @var array $result */
        $result = ($this->executor)($this->getSQL(), $this->getParameters(), 'array');

        return $result;
    }

    /**
     * Executes the query and returns the first result, or null if empty.
     * Throws if more than one result is returned.
     *
     * @return mixed Single entity/row or null
     * @throws \LogicException If no executor is configured.
     * @throws \OverflowException If more than one result is found.
     */
    public function getOneOrNullResult(): mixed
    {
        $prevLimit = $this->limitValue;
        $this->limitValue = 2;

        try {
            $results = $this->getResult();

            if (count($results) > 1) {
                throw new \OverflowException(
                    'getOneOrNullResult() expected 0 or 1 results, got more than 1.',
                );
            }

            return $results[0] ?? null;
        } finally {
            $this->limitValue = $prevLimit;
        }
    }

    /**
     * Executes an UPDATE or DELETE query and returns the number of affected rows.
     *
     * @return int Number of affected rows
     * @throws \LogicException If no executor is configured.
     */
    public function execute(): int
    {
        if ($this->executor === null) {
            throw new \LogicException(
                'Cannot call execute() on a QueryBuilder without an executor. '
                . 'Use EntityManager::createQueryBuilder() or EntityRepository::createQueryBuilder().',
            );
        }

        /** @var array|int $result */
        $result = ($this->executor)($this->getSQL(), $this->getParameters(), 'execute');

        return is_int($result) ? $result : 0;
    }

    /**
     * Returns the total count of rows matching the current WHERE/JOIN conditions,
     * without modifying the QueryBuilder's select or limit state.
     *
     * @return int Total row count
     * @throws \LogicException If no executor is configured or no FROM is set.
     */
    public function getCount(): int
    {
        if ($this->executor === null) {
            throw new \LogicException(
                'Cannot call getCount() on a QueryBuilder without an executor.',
            );
        }

        // Build a COUNT query reusing the current state without mutating it
        $countQb = clone $this;
        $countQb->selectColumns = ['COUNT(*)'];
        $countQb->orderByClauses = [];
        $countQb->limitValue = null;
        $countQb->offsetValue = null;

        /** @var array $results */
        $results = ($this->executor)($countQb->getSQL(), $countQb->getParameters(), 'scalar');

        return (int) ($results[0] ?? 0);
    }

    /**
     * Alias for limit(). Doctrine-compatible naming.
     */
    public function setMaxResults(int $maxResults): static
    {
        return $this->limit($maxResults);
    }

    /**
     * Alias for offset(). Doctrine-compatible naming.
     */
    public function setFirstResult(int $firstResult): static
    {
        return $this->offset($firstResult);
    }

    /**
     * Returns the entity class this QueryBuilder was created for, or null.
     */
    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    /**
     * Returns $this for Doctrine API compatibility.
     * In this ORM, execution methods live directly on the QueryBuilder,
     * so no intermediate Query object is needed.
     *
     * Allows: $qb->where(...)->getQuery()->getResult()
     */
    public function getQuery(): static
    {
        return $this;
    }
}
