<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Exception\OqlParseException;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlToSqlTranslator;
use SybaseORM\Query\QueryBuilder;
use SybaseORM\Query\QueryBuilderInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Central orchestrator of the ORM. Coordinates UnitOfWork, IdentityMap,
 * MetadataReader, Hydrator, HookDispatcher, CacheManager and ConnectionManager
 * to implement the full entity lifecycle.
 */
final class EntityManager implements EntityManagerInterface
{
    /** @var array<string, EntityRepository> */
    private array $repositories = [];

    /** @var string[] Entity FQCN list for OQL translator */
    private array $entityClasses = [];

    /** @var array<string, string> Pre-computed shortName → FQCN map */
    private array $entityShortNameMap = [];

    /** @var OqlParser Instancia reutilizable del parser OQL */
    private OqlParser $oqlParser;

    /** @var OqlToSqlTranslator|null Instancia reutilizable del traductor OQL→SQL */
    private ?OqlToSqlTranslator $oqlTranslator = null;

    /** @var bool Whether entity classes have been auto-discovered */
    private bool $entitiesDiscovered = false;

    /** @var string[] Directories to scan for entity classes */
    private array $entityDirectories = [];

    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly MetadataReaderInterface $metadataReader,
        private readonly DialectInterface $dialect,
        private readonly TypeCasterInterface $typeCaster,
        private readonly HydratorInterface $hydrator,
        private readonly UnitOfWorkInterface $unitOfWork,
        private readonly IdentityMapInterface $identityMap,
        private readonly HookDispatcher $hookDispatcher,
        private readonly CacheManagerInterface $cacheManager,
    ) {
        $this->oqlParser = new OqlParser();
    }

    /**
     * Registers known entity classes for OQL resolution.
     * Pre-computes the shortName → FQCN map to avoid Reflection on each query.
     *
     * @param string[] $entityClasses
     */
    public function setEntityClasses(array $entityClasses): void
    {
        $this->entityClasses = $entityClasses;
        $this->entityShortNameMap = [];
        $this->oqlTranslator = null;
        $this->entitiesDiscovered = true;
        foreach ($entityClasses as $fqcn) {
            $shortName = (new \ReflectionClass($fqcn))->getShortName();
            $this->entityShortNameMap[$shortName] = $fqcn;
        }
    }

    /**
     * Sets directories to scan for entity classes.
     * Entity discovery happens lazily on first OQL query.
     *
     * @param string[] $directories
     */
    public function setEntityDirectories(array $directories): void
    {
        $this->entityDirectories = $directories;
        $this->entitiesDiscovered = false;
    }

    /**
     * Discovers entity classes from configured directories if not already done.
     */
    private function ensureEntitiesDiscovered(): void
    {
        if ($this->entitiesDiscovered) {
            return;
        }

        $this->entitiesDiscovered = true;

        if (empty($this->entityDirectories)) {
            return;
        }

        $discovered = [];
        foreach ($this->entityDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->resolveClassNameFromFile($file->getPathname());
                if ($className !== null && $this->metadataReader->isEntity($className)) {
                    $discovered[] = $className;
                }
            }
        }

        if (!empty($discovered)) {
            $merged = array_unique(array_merge($this->entityClasses, $discovered));
            $this->setEntityClasses($merged);
        }
    }

    /**
     * Extracts the FQCN from a PHP file by reading its namespace and class declarations.
     */
    private function resolveClassNameFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        $tokens = token_get_all($contents);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            // PHP 8.0+ uses T_NAMESPACE and T_NAME_QUALIFIED
            if ($tokens[$i][0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                        $ns .= $tokens[$j][1];
                    } elseif (is_string($tokens[$j]) && $tokens[$j] === ';') {
                        break;
                    } elseif (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
                $namespace = trim($ns);
            }

            if ($tokens[$i][0] === T_CLASS) {
                // Skip anonymous classes and ::class
                if ($i > 0 && is_array($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON) {
                    continue;
                }
                // Next non-whitespace token is the class name
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break 2;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }

        if ($class === null) {
            return null;
        }

        $fqcn = $namespace !== null ? $namespace . '\\' . $class : $class;

        return class_exists($fqcn) ? $fqcn : null;
    }

    public function persist(object $entity): void
    {
        $this->hookDispatcher->dispatch($entity, 'PrePersist');
        $this->unitOfWork->registerNew($entity);
    }

    public function remove(object $entity): void
    {
        $this->hookDispatcher->dispatch($entity, 'PreRemove');
        $this->unitOfWork->registerDeleted($entity);
    }

    public function flush(): void
    {
        $this->unitOfWork->commit();
    }

    public function find(string $entityClass, mixed $id): ?object
    {
        // 1. Check IdentityMap (first-level cache)
        $entity = $this->identityMap->get($entityClass, $id);
        if ($entity !== null) {
            return $entity;
        }

        // 2. Check CacheManager (may include second-level)
        $entity = $this->cacheManager->get($entityClass, $id);
        if ($entity !== null) {
            $this->unitOfWork->registerClean($entity);
            return $entity;
        }

        // 3. Build query
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        if (is_array($id)) {
            // Composite key: validate keys match declared idFields
            $idColumns = $metadata->getIdColumns();
            $declaredFields = array_map(fn($c) => $c->propertyName, $idColumns);
            $providedFields = array_keys($id);
            sort($declaredFields);
            sort($providedFields);
            if ($declaredFields !== $providedFields) {
                throw new PersistenceException(
                    sprintf(
                        'Key mismatch for entity "%s": expected [%s], got [%s].',
                        $entityClass,
                        implode(', ', $declaredFields),
                        implode(', ', $providedFields),
                    ),
                );
            }

            // Build multi-column WHERE
            $conditions = [];
            $dbValues = [];
            foreach ($idColumns as $idCol) {
                $conditions[] = $this->dialect->quoteIdentifier($idCol->columnName) . ' = ?';
                $dbValues[] = $this->typeCaster->toDatabaseValue($id[$idCol->propertyName], $idCol->type);
            }
            $whereClause = implode(' AND ', $conditions);
        } else {
            // Single key: existing behavior
            $idColumn = $metadata->getIdColumn();
            if ($idColumn === null) {
                return null;
            }
            $whereClause = $this->dialect->quoteIdentifier($idColumn->columnName) . ' = ?';
            $dbValues = [$this->typeCaster->toDatabaseValue($id, $idColumn->type)];
        }

        $sql = $this->dialect->generateSelect(
            ['*'],
            $metadata->getQualifiedTableName(),
        );
        $sql .= ' WHERE ' . $whereClause;

        $stmt = $this->connectionManager->executeQuery($sql, $dbValues);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row === false) {
            return null;
        }

        // 4. Hydrate and register
        $entity = $this->hydrator->hydrate($row, $entityClass);
        $this->unitOfWork->registerClean($entity);
        $this->cacheManager->put($entityClass, $id, $entity);

        return $entity;
    }

    public function createQueryBuilder(string $entityClass): QueryBuilderInterface
    {
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        $qb = new QueryBuilder($this->dialect);
        $qb->from($metadata->getQualifiedTableName(), 'e');

        return $qb;
    }

    public function query(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): array
    {
        $prepared = $this->prepareQueryExecution($oql, $params);
        $sql = $prepared['sql'];
        $orderedParams = $prepared['params'];
        $ast = $prepared['ast'];

        $stmt = $this->connectionManager->executeQuery($sql, $orderedParams);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // Auto-detect hydration mode: if AST contains FunctionCall, aliases, or multi-entity selects, default to HYDRATE_ARRAY
        $effectiveMode = $hydrationMode;
        if ($hydrationMode === HydrationMode::HYDRATE_OBJECT && $this->shouldAutoDetectArrayMode($ast)) {
            $effectiveMode = HydrationMode::HYDRATE_ARRAY;
        }

        // HYDRATE_ARRAY: return raw rows without hydration
        if ($effectiveMode === HydrationMode::HYDRATE_ARRAY) {
            return $rows;
        }

        // Determine entity class from the FROM clause
        $entityClass = $this->resolveEntityFromAst($ast);

        if ($entityClass === null) {
            return $rows;
        }

        return $this->hydrator->hydrateAll($rows, $entityClass);
    }

    public function queryOne(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): mixed
    {
        $prepared = $this->prepareQueryExecution($oql, $params);
        $sql = $prepared['sql'];
        $orderedParams = $prepared['params'];
        $ast = $prepared['ast'];

        // Limit to 1 result
        $sql = $this->dialect->applyPagination($sql, 1);

        $stmt = $this->connectionManager->executeQuery($sql, $orderedParams);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row === false) {
            return null;
        }

        // Auto-detect hydration mode
        $effectiveMode = $hydrationMode;
        if ($hydrationMode === HydrationMode::HYDRATE_OBJECT && $this->shouldAutoDetectArrayMode($ast)) {
            $effectiveMode = HydrationMode::HYDRATE_ARRAY;
        }

        if ($effectiveMode === HydrationMode::HYDRATE_ARRAY) {
            return $row;
        }

        $entityClass = $this->resolveEntityFromAst($ast);

        if ($entityClass === null) {
            return $row;
        }

        return $this->hydrator->hydrate($row, $entityClass);
    }

    public function queryScalar(string $oql, array $params = []): mixed
    {
        $prepared = $this->prepareQueryExecution($oql, $params);
        $sql = $prepared['sql'];
        $orderedParams = $prepared['params'];

        // Limit to 1 result
        $sql = $this->dialect->applyPagination($sql, 1);

        $stmt = $this->connectionManager->executeQuery($sql, $orderedParams);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($row === false) {
            return null;
        }

        return reset($row) !== false ? reset($row) : null;
    }

    public function executeUpdate(string $oql, array $params = []): int
    {
        $this->ensureEntitiesDiscovered();

        $ast = $this->oqlParser->parse($oql);

        if ($ast instanceof \SybaseORM\Query\AST\SelectStatement) {
            throw new OqlParseException('executeUpdate() does not support SELECT statements. Use query() instead.');
        }

        if ($this->oqlTranslator === null) {
            $this->oqlTranslator = new OqlToSqlTranslator(
                $this->dialect,
                $this->metadataReader,
                $this->entityClasses,
            );
        }

        $result = $this->oqlTranslator->translate($ast);
        $sql = $result['sql'];
        $parameterNames = $result['parameters'];

        // Map named parameters to ordered values, expanding array params for IN clauses
        $orderedParams = [];
        foreach ($parameterNames as $name) {
            $value = $params[$name] ?? null;
            if (is_array($value)) {
                $scalarValues = $this->normalizeArrayParam($value);
                $placeholders = implode(', ', array_fill(0, count($scalarValues), '?'));
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', $placeholders, $sql, 1);
                foreach ($scalarValues as $item) {
                    $orderedParams[] = $item;
                }
            } else {
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', '?', $sql, 1);
                $orderedParams[] = $value;
            }
        }

        // Safety: expand any remaining named params not reported by the translator
        foreach ($params as $name => $value) {
            if (in_array($name, $parameterNames, true)) {
                continue;
            }
            if (is_array($value) && str_contains($sql, ':' . $name)) {
                $scalarValues = $this->normalizeArrayParam($value);
                $placeholders = implode(', ', array_fill(0, count($scalarValues), '?'));
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', $placeholders, $sql, 1);
                foreach ($scalarValues as $item) {
                    $orderedParams[] = $item;
                }
            } elseif (str_contains($sql, ':' . $name)) {
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', '?', $sql, 1);
                $orderedParams[] = $value;
            }
        }

        return $this->connectionManager->executeStatement($sql, $orderedParams);
    }

    public function queryIterator(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): \Generator
    {
        $prepared = $this->prepareQueryExecution($oql, $params);
        $sql = $prepared['sql'];
        $orderedParams = $prepared['params'];
        $ast = $prepared['ast'];

        $stmt = $this->connectionManager->executeQuery($sql, $orderedParams);

        // Auto-detect hydration mode
        $effectiveMode = $hydrationMode;
        if ($hydrationMode === HydrationMode::HYDRATE_OBJECT && $this->shouldAutoDetectArrayMode($ast)) {
            $effectiveMode = HydrationMode::HYDRATE_ARRAY;
        }

        // Determine entity class from the FROM clause
        $entityClass = ($effectiveMode === HydrationMode::HYDRATE_OBJECT)
            ? $this->resolveEntityFromAst($ast)
            : null;

        try {
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if ($effectiveMode === HydrationMode::HYDRATE_ARRAY || $entityClass === null) {
                    yield $row;
                } else {
                    yield $this->hydrator->hydrate($row, $entityClass);
                }
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Prepares OQL for execution: parses, translates, and maps parameters.
     *
     * @return array{sql: string, params: list<mixed>, ast: object}
     */
    private function prepareQueryExecution(string $oql, array $params): array
    {
        $this->ensureEntitiesDiscovered();

        $ast = $this->oqlParser->parse($oql);

        if ($this->oqlTranslator === null) {
            $this->oqlTranslator = new OqlToSqlTranslator(
                $this->dialect,
                $this->metadataReader,
                $this->entityClasses,
            );
        }

        $result = $this->oqlTranslator->translate($ast);
        $sql = $result['sql'];
        $parameterNames = $result['parameters'];

        // Map named parameters to ordered values, expanding array params for IN clauses
        $orderedParams = [];
        foreach ($parameterNames as $name) {
            $value = $params[$name] ?? null;
            if (is_array($value)) {
                $scalarValues = $this->normalizeArrayParam($value);
                $placeholders = implode(', ', array_fill(0, count($scalarValues), '?'));
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', $placeholders, $sql, 1);
                foreach ($scalarValues as $item) {
                    $orderedParams[] = $item;
                }
            } else {
                // Replace named parameter with positional placeholder
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', '?', $sql, 1);
                $orderedParams[] = $value;
            }
        }

        // Safety: expand any remaining named params not reported by the translator
        // This handles edge cases where params exist in SQL but weren't tracked
        foreach ($params as $name => $value) {
            if (in_array($name, $parameterNames, true)) {
                continue; // Already processed above
            }
            if (is_array($value) && str_contains($sql, ':' . $name)) {
                $scalarValues = $this->normalizeArrayParam($value);
                $placeholders = implode(', ', array_fill(0, count($scalarValues), '?'));
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', $placeholders, $sql, 1);
                foreach ($scalarValues as $item) {
                    $orderedParams[] = $item;
                }
            } elseif (str_contains($sql, ':' . $name)) {
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', '?', $sql, 1);
                $orderedParams[] = $value;
            }
        }

        return ['sql' => $sql, 'params' => $orderedParams, 'ast' => $ast];
    }

    /**
     * Determines if the AST should trigger automatic HYDRATE_ARRAY mode.
     * Returns true when select expressions contain FunctionCall nodes, aliases,
     * selections from multiple entities, or when GROUP BY is present.
     */
    private function shouldAutoDetectArrayMode(object $ast): bool
    {
        // GROUP BY present → array mode
        if (isset($ast->groupBy) && $ast->groupBy !== null) {
            return true;
        }

        if (!isset($ast->selectExpressions) || !is_array($ast->selectExpressions)) {
            return false;
        }

        $aliases = [];
        foreach ($ast->selectExpressions as $expr) {
            // FunctionCall in select → array mode
            if ($expr->expression instanceof \SybaseORM\Query\AST\FunctionCall) {
                return true;
            }

            // Column alias → array mode
            if ($expr->alias !== null) {
                return true;
            }

            // Track entity aliases for multi-entity detection
            if (is_string($expr->expression) && str_contains($expr->expression, '.')) {
                $parts = explode('.', $expr->expression);
                $aliases[$parts[0]] = true;
            } elseif (is_string($expr->expression) && $expr->expression !== '*') {
                $aliases[$expr->expression] = true;
            }
        }

        // Multiple distinct entity aliases → array mode
        if (count($aliases) > 1) {
            return true;
        }

        return false;
    }

    public function clear(): void
    {
        $this->unitOfWork->clear();
        $this->identityMap->clear();
    }

    public function merge(object $entity): object
    {
        $entityClass = $entity::class;
        $metadata = $this->metadataReader->getClassMetadata($entityClass);
        $idColumns = $metadata->getIdColumns();

        if (empty($idColumns)) {
            throw new PersistenceException(
                sprintf('Cannot merge entity "%s": no ID column defined.', $entityClass),
            );
        }

        $reflectionClass = new \ReflectionClass($entityClass);

        if (count($idColumns) === 1) {
            // Single key
            $idProp = $reflectionClass->getProperty($idColumns[0]->propertyName);
            $id = $idProp->getValue($entity);
        } else {
            // Composite key
            $id = [];
            foreach ($idColumns as $idCol) {
                $prop = $reflectionClass->getProperty($idCol->propertyName);
                $val = $prop->getValue($entity);
                if ($val === null) {
                    throw new PersistenceException(
                        sprintf('Cannot merge entity "%s": composite key field "%s" is null.', $entityClass, $idCol->propertyName),
                    );
                }
                $id[$idCol->propertyName] = $val;
            }
        }

        if ($id === null || (is_array($id) && empty($id))) {
            throw new PersistenceException(
                sprintf('Cannot merge entity "%s": ID value is null.', $entityClass),
            );
        }

        // Find or load the managed entity
        $managed = $this->find($entityClass, $id);

        if ($managed === null) {
            $this->persist($entity);
            return $entity;
        }

        // Copy property values from detached to managed
        foreach ($metadata->columns as $column) {
            if ($column->isId) {
                continue;
            }

            $prop = $reflectionClass->getProperty($column->propertyName);
            $value = $prop->getValue($entity);
            $prop->setValue($managed, $value);
        }

        return $managed;
    }

    public function beginTransaction(): void
    {
        $this->connectionManager->beginTransaction();
    }

    public function commit(): void
    {
        $this->connectionManager->commit();
    }

    public function rollback(): void
    {
        $this->connectionManager->rollback();
    }

    public function getRepository(string $entityClass): EntityRepository
    {
        if (!isset($this->repositories[$entityClass])) {
            $this->repositories[$entityClass] = new EntityRepository($this, $entityClass);
        }

        return $this->repositories[$entityClass];
    }

    public function getDialect(): DialectInterface
    {
        return $this->dialect;
    }

    public function getConnection(): ConnectionManagerInterface
    {
        return $this->connectionManager;
    }

    public function isManaged(object $entity): bool
    {
        return $this->unitOfWork->isManaged($entity);
    }

    public function detach(object $entity): void
    {
        $metadata = $this->metadataReader->getClassMetadata($entity::class);
        $idColumns = $metadata->getIdColumns();

        if (!empty($idColumns)) {
            $reflectionClass = new \ReflectionClass($entity::class);
            if (count($idColumns) === 1) {
                $idProp = $reflectionClass->getProperty($idColumns[0]->propertyName);
                $id = $idProp->getValue($entity);
                if ($id !== null) {
                    $this->identityMap->remove($entity::class, $id);
                }
            } else {
                $compositeId = [];
                foreach ($idColumns as $idCol) {
                    $prop = $reflectionClass->getProperty($idCol->propertyName);
                    $compositeId[$idCol->propertyName] = $prop->getValue($entity);
                }
                $this->identityMap->remove($entity::class, $compositeId);
            }
        }
    }

    public function getMetadataReader(): MetadataReaderInterface
    {
        return $this->metadataReader;
    }

    /**
     * Normalizes an array parameter for IN clause expansion.
     *
     * Handles three cases:
     * 1. Flat array of scalars: ['a', 'b', 'c'] → used as-is
     * 2. Associative array with non-scalar values: ['001' => [...], '002' => [...]]
     *    → uses array_keys() as the IN values (Doctrine DQL compatibility)
     * 3. Mixed: ensures all values are scalar, throws on nested arrays
     *
     * @param array $value The array parameter to normalize.
     * @return list<scalar|null> Flat list of scalar values for binding.
     * @throws \InvalidArgumentException If values cannot be normalized to scalars.
     */
    private function normalizeArrayParam(array $value): array
    {
        if (empty($value)) {
            return [];
        }

        // Check if any value is a non-scalar (array or object)
        $hasNonScalar = false;
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                $hasNonScalar = true;
                break;
            }
        }

        if (!$hasNonScalar) {
            // All values are scalar — use array_values to ensure sequential keys
            return array_values($value);
        }

        // Values contain arrays/objects — use keys as the IN values
        // This matches Doctrine DQL behavior for associative arrays
        $keys = array_keys($value);

        // Validate that keys are scalar
        foreach ($keys as $key) {
            if (!is_scalar($key)) {
                throw new \InvalidArgumentException(
                    'IN clause parameter contains non-scalar values that cannot be normalized. '
                    . 'Pass a flat array of scalar values, or an associative array where keys are the desired IN values.',
                );
            }
        }

        return $keys;
    }

    /**
     * Resolves the entity FQCN from the OQL AST's FROM clause.
     */
    private function resolveEntityFromAst(object $ast): ?string
    {
        if (!isset($ast->from->entityName)) {
            return null;
        }

        $shortName = $ast->from->entityName;

        // Check pre-computed shortName map (no Reflection needed)
        if (isset($this->entityShortNameMap[$shortName])) {
            return $this->entityShortNameMap[$shortName];
        }

        // If it looks like a FQCN, use directly
        if (str_contains($shortName, '\\')) {
            return $shortName;
        }

        return null;
    }
}
