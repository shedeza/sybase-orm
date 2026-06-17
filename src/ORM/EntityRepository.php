<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Query\QueryBuilderInterface;

/**
 * Repositorio base para operaciones de persistencia y consulta sobre una entidad.
 *
 * Cada entidad trabaja a través de su propio repositorio, que delega
 * internamente al EntityManager. Los desarrolladores no necesitan
 * interactuar con el EntityManager directamente.
 *
 * Uso típico:
 *
 *     $repo = $this->em->getRepository(Producto::class);
 *     $repo->save($producto);
 *     $repo->delete($producto);
 *     $producto = $repo->find(1);
 *     $todos = $repo->findAll();
 */
class EntityRepository
{
    /** @var string Cached short class name for OQL queries */
    private readonly string $entityShortName;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $entityClass,
    ) {
        $this->entityShortName = ($pos = strrpos($this->entityClass, '\\')) !== false
            ? substr($this->entityClass, $pos + 1)
            : $this->entityClass;
    }

    // ── Persistencia ────────────────────────────────────────────────

    /**
     * Registers an entity for insertion/update without flushing.
     * Call flush() manually when ready to commit all pending changes.
     */
    public function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
    }

    /**
     * Flushes all pending changes (INSERTs, UPDATEs, DELETEs) to the database.
     */
    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function save(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /** @param object[] $entities */
    public function saveMany(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
    }

    public function delete(object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    /** @param object[] $entities */
    public function deleteMany(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->remove($entity);
        }
        $this->entityManager->flush();
    }

    public function merge(object $entity): object
    {
        return $this->entityManager->merge($entity);
    }

    // ── Consultas ───────────────────────────────────────────────────

    public function find(mixed $id): ?object
    {
        // Arrays are treated as composite primary keys and delegated to EntityManager::find()
        return $this->entityManager->find($this->entityClass, $id);
    }

    /**
     * Finds an entity by its primary key or throws PersistenceException if not found.
     *
     * @throws \SybaseORM\Exception\PersistenceException If the entity is not found.
     */
    public function findOrFail(mixed $id): object
    {
        $entity = $this->find($id);

        if ($entity === null) {
            throw \SybaseORM\Exception\PersistenceException::forEntity(
                $this->entityClass,
                sprintf('find (id: %s)', is_array($id) ? json_encode($id) : (string) $id),
            );
        }

        return $entity;
    }

    /** @return object[] */
    public function findAll(): array
    {
        return $this->findBy([]);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return object[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        [$conditions, $params] = $this->buildCriteriaConditions($criteria, 'p');

        $oql = sprintf('SELECT e FROM %s e', $this->entityShortName);

        if (!empty($conditions)) {
            $oql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if ($orderBy !== null && !empty($orderBy)) {
            $orderParts = [];
            foreach ($orderBy as $property => $direction) {
                $orderParts[] = sprintf('e.%s %s', $property, strtoupper($direction));
            }
            $oql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        $results = $this->entityManager->query($oql, $params, HydrationMode::HYDRATE_OBJECT, $limit, $offset);

        return $results;
    }

    /** @param array<string, mixed> $criteria */
    public function findOneBy(array $criteria): ?object
    {
        [$conditions, $params] = $this->buildCriteriaConditions($criteria, 'p');

        $oql = sprintf('SELECT e FROM %s e', $this->entityShortName);

        if (!empty($conditions)) {
            $oql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return $this->entityManager->queryOne($oql, $params);
    }

    /**
     * Finds a single entity by criteria or throws PersistenceException if not found.
     *
     * @param array<string, mixed> $criteria
     * @throws \SybaseORM\Exception\PersistenceException If no entity matches the criteria.
     */
    public function findOneByOrFail(array $criteria): object
    {
        $entity = $this->findOneBy($criteria);

        if ($entity === null) {
            throw \SybaseORM\Exception\PersistenceException::forEntity(
                $this->entityClass,
                sprintf('findOneBy (criteria: %s)', json_encode($criteria)),
            );
        }

        return $entity;
    }

    /** @return object[] */
    public function query(string $oql, array $params = []): array
    {
        return $this->entityManager->query($oql, $params);
    }

    /**
     * Executes an OQL query and returns a Generator that yields results one by one.
     * Useful for large result sets that don't fit in memory.
     */
    public function queryIterator(string $oql, array $params = []): \Generator
    {
        return $this->entityManager->queryIterator($oql, $params);
    }

    /**
     * Executes an OQL query with second-level cache support.
     *
     * @param int $ttl Cache TTL in seconds (default: 3600)
     */
    public function queryCached(string $oql, array $params = [], int $ttl = 3600): array
    {
        return $this->entityManager->queryCached($oql, $params, $ttl);
    }

    // ── Conteo y existencia ────────────────────────────────────────

    /** @param array<string, mixed> $criteria */
    public function count(array $criteria = []): int
    {
        [$conditions, $params] = $this->buildCriteriaConditions($criteria, 'c');

        $oql = sprintf('SELECT COUNT(*) FROM %s e', $this->entityShortName);

        if (!empty($conditions)) {
            $oql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $result = $this->entityManager->query($oql, $params, HydrationMode::HYDRATE_ARRAY);

        if (empty($result)) {
            return 0;
        }

        $row = $result[0];

        return (int) (is_array($row) ? reset($row) : $row);
    }

    /** @param array<string, mixed> $criteria */
    public function exists(array $criteria): bool
    {
        [$conditions, $params] = $this->buildCriteriaConditions($criteria, 'e');

        $oql = sprintf('SELECT COUNT(*) FROM %s e', $this->entityShortName);

        if (!empty($conditions)) {
            $oql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $count = $this->entityManager->queryScalar($oql, $params);

        return ((int) ($count ?? 0)) > 0;
    }

    // ── OQL convenience ────────────────────────────────────────────

    /**
     * Executes an OQL UPDATE or DELETE statement and returns the number of affected rows.
     */
    public function executeUpdate(string $oql, array $params = []): int
    {
        return $this->entityManager->executeUpdate($oql, $params);
    }

    /**
     * Executes an OQL query and returns a single scalar value.
     */
    public function queryScalar(string $oql, array $params = []): mixed
    {
        return $this->entityManager->queryScalar($oql, $params);
    }

    /**
     * Executes an OQL query and returns the first column of each row as a scalar array.
     *
     * @return array<int, mixed>
     */
    public function queryScalarAll(string $oql, array $params = [], ?int $limit = null, ?int $offset = null): array
    {
        return $this->entityManager->queryScalarAll($oql, $params, $limit, $offset);
    }

    /**
     * Executes an OQL query and returns a single result or throws if not found.
     *
     * @throws \SybaseORM\Exception\PersistenceException If no result is found.
     */
    public function queryOneOrFail(string $oql, array $params = []): mixed
    {
        return $this->entityManager->queryOneOrFail($oql, $params);
    }

    // ── QueryBuilder ────────────────────────────────────────────────

    public function createQueryBuilder(): QueryBuilderInterface
    {
        return $this->entityManager->createQueryBuilder($this->entityClass);
    }

    // ── Transacciones ───────────────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->entityManager->beginTransaction();
    }

    public function commit(): void
    {
        $this->entityManager->commit();
    }

    public function rollback(): void
    {
        $this->entityManager->rollback();
    }

    /**
     * Executes a callable within a transaction. Commits on success, rolls back on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed
    {
        return $this->entityManager->transactional($callback);
    }

    // ── Utilidades ──────────────────────────────────────────────────

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getTableName(): string
    {
        $metadata = $this->entityManager->getMetadataReader()->getClassMetadata($this->entityClass);

        return $metadata->getQualifiedTableName();
    }

    public function getEntityShortName(): string
    {
        return $this->entityShortName;
    }

    /**
     * Reloads an entity from the database, discarding any in-memory changes.
     */
    public function refresh(object $entity): void
    {
        $this->entityManager->refresh($entity);
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    // ── Helpers privados ────────────────────────────────────────────

    /**
     * Builds OQL WHERE conditions and parameter array from criteria.
     *
     * Handles three value types:
     * - null → IS NULL (no parameter)
     * - array → IN (:param) with automatic expansion
     * - scalar → = :param
     *
     * @param array<string, mixed> $criteria
     * @param string $prefix Parameter name prefix to avoid collisions
     * @return array{0: string[], 1: array<string, mixed>}
     */
    private function buildCriteriaConditions(array $criteria, string $prefix): array
    {
        $metadata = $this->entityManager->getMetadataReader()->getClassMetadata($this->entityClass);
        $withTrashed = $criteria['_withTrashed'] ?? false;
        unset($criteria['_withTrashed']);

        $conditions = [];
        $params = [];
        $i = 0;

        $typeCaster = $this->entityManager->getTypeCaster();

        // Apply SoftDelete filter by default
        if ($metadata->softDeleteColumn !== null && !$withTrashed) {
            // Find property name for the soft delete column if possible
            $softDeleteProp = $metadata->softDeleteColumn;
            $softDeleteCol = $metadata->getColumnByName($metadata->softDeleteColumn);
            if ($softDeleteCol !== null) {
                $softDeleteProp = $softDeleteCol->propertyName;
            }
            $conditions[] = sprintf('e.%s IS NULL', $softDeleteProp);
        }

        foreach ($criteria as $property => $value) {
            $paramName = $prefix . $i;
            $column = $metadata->getColumn($property);

            if ($value === null) {
                $conditions[] = sprintf('e.%s IS NULL', $property);
            } elseif (is_array($value)) {
                // Separate nulls from non-null values
                $hasNull = in_array(null, $value, true);
                $nonNullValues = array_values(array_filter($value, fn($v) => $v !== null));

                if ($column !== null) {
                    $nonNullValues = array_map(
                        fn($v) => $typeCaster->toDatabaseValue($v, $column->type),
                        $nonNullValues
                    );
                }

                if ($hasNull && $nonNullValues === []) {
                    $conditions[] = sprintf('e.%s IS NULL', $property);
                } elseif ($hasNull && $nonNullValues !== []) {
                    // Mix of null and non-null → (prop IS NULL OR prop IN (:param))
                    $conditions[] = sprintf('(e.%s IS NULL OR e.%s IN (:%s))', $property, $property, $paramName);
                    $params[$paramName] = $nonNullValues;
                } else {
                    $conditions[] = sprintf('e.%s IN (:%s)', $property, $paramName);
                    $params[$paramName] = $nonNullValues;
                }
            } else {
                $dbValue = ($column !== null)
                    ? $typeCaster->toDatabaseValue($value, $column->type)
                    : $value;

                $conditions[] = sprintf('e.%s = :%s', $property, $paramName);
                $params[$paramName] = $dbValue;
            }

            $i++;
        }

        return [$conditions, $params];
    }
}
