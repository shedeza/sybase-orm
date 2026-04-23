<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
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
        foreach ($entityClasses as $fqcn) {
            $shortName = (new \ReflectionClass($fqcn))->getShortName();
            $this->entityShortNameMap[$shortName] = $fqcn;
        }
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
        $ast = $this->oqlParser->parse($oql);

        $translator = new OqlToSqlTranslator(
            $this->dialect,
            $this->metadataReader,
            $this->entityClasses,
        );

        $result = $translator->translate($ast);
        $sql = $result['sql'];
        $parameterNames = $result['parameters'];

        // Map named parameters to ordered values, expanding array params for IN clauses
        $orderedParams = [];
        foreach ($parameterNames as $name) {
            $value = $params[$name] ?? null;
            if (is_array($value)) {
                // IN parameter expansion: replace single named placeholder with N positional placeholders
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', $placeholders, $sql, 1);
                foreach ($value as $item) {
                    $orderedParams[] = $item;
                }
            } else {
                $orderedParams[] = $value;
            }
        }

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

    public function queryIterator(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): \Generator
    {
        $ast = $this->oqlParser->parse($oql);

        $translator = new OqlToSqlTranslator(
            $this->dialect,
            $this->metadataReader,
            $this->entityClasses,
        );

        $result = $translator->translate($ast);
        $sql = $result['sql'];
        $parameterNames = $result['parameters'];

        // Map named parameters to ordered values, expanding array params for IN clauses
        $orderedParams = [];
        foreach ($parameterNames as $name) {
            $value = $params[$name] ?? null;
            if (is_array($value)) {
                $placeholders = implode(', ', array_fill(0, count($value), '?'));
                $sql = preg_replace('/\:' . preg_quote($name, '/') . '\b/', $placeholders, $sql, 1);
                foreach ($value as $item) {
                    $orderedParams[] = $item;
                }
            } else {
                $orderedParams[] = $value;
            }
        }

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
        $idColumn = $metadata->getIdColumn();

        if ($idColumn === null) {
            throw new PersistenceException(
                sprintf('Cannot merge entity "%s": no ID column defined.', $entityClass),
            );
        }

        $reflectionClass = new \ReflectionClass($entityClass);
        $idProp = $reflectionClass->getProperty($idColumn->propertyName);
        $id = $idProp->getValue($entity);

        if ($id === null) {
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
