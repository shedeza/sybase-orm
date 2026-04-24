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
        $this->entityShortName = (new \ReflectionClass($this->entityClass))->getShortName();
    }

    // ── Persistencia ────────────────────────────────────────────────

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
        if (is_array($id)) {
            return $this->findOneBy($id);
        }
        return $this->entityManager->find($this->entityClass, $id);
    }

    /** @return object[] */
    public function findAll(): array
    {
        return $this->entityManager->query(
            sprintf('SELECT e FROM %s e', $this->entityShortName),
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return object[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        if (empty($criteria) && $orderBy === null && $limit === null) {
            return $this->findAll();
        }

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
        if (empty($criteria)) {
            return $this->entityManager->queryOne(
                sprintf('SELECT e FROM %s e', $this->entityShortName),
            );
        }

        [$conditions, $params] = $this->buildCriteriaConditions($criteria, 'p');

        $oql = sprintf(
            'SELECT e FROM %s e WHERE %s',
            $this->entityShortName,
            implode(' AND ', $conditions),
        );

        return $this->entityManager->queryOne($oql, $params);
    }

    /** @return object[] */
    public function query(string $oql, array $params = []): array
    {
        return $this->entityManager->query($oql, $params);
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

        return (int) reset($result[0]);
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
        $conditions = [];
        $params = [];
        $i = 0;

        foreach ($criteria as $property => $value) {
            $paramName = $prefix . $i;

            if ($value === null) {
                $conditions[] = sprintf('e.%s IS NULL', $property);
            } elseif (is_array($value)) {
                // Separate nulls from non-null values
                $hasNull = in_array(null, $value, true);
                $nonNullValues = array_values(array_filter($value, fn($v) => $v !== null));

                if ($hasNull && empty($nonNullValues)) {
                    $conditions[] = sprintf('e.%s IS NULL', $property);
                } elseif ($hasNull && !empty($nonNullValues)) {
                    // Mix of null and non-null → (prop IS NULL OR prop IN (:param))
                    $conditions[] = sprintf('(e.%s IS NULL OR e.%s IN (:%s))', $property, $property, $paramName);
                    $params[$paramName] = $nonNullValues;
                } else {
                    $conditions[] = sprintf('e.%s IN (:%s)', $property, $paramName);
                    $params[$paramName] = $nonNullValues;
                }
            } else {
                $conditions[] = sprintf('e.%s = :%s', $property, $paramName);
                $params[$paramName] = $value;
            }

            $i++;
        }

        return [$conditions, $params];
    }
}
