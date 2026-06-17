<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Query\QueryBuilderInterface;

/**
 * Main entry point of the ORM. Coordinates all internal components.
 */
interface EntityManagerInterface
{
    /** Registers a new entity for insertion on the next flush. */
    public function persist(object $entity): void;

    /** Marks an entity for deletion on the next flush. */
    public function remove(object $entity): void;

    /** Synchronizes all pending changes with the database. */
    public function flush(): void;

    /** Finds an entity by its primary key identifier. */
    public function find(string $entityClass, mixed $id): ?object;

    /** Creates a QueryBuilder for the specified entity. */
    public function createQueryBuilder(string $entityClass): QueryBuilderInterface;

    /** Executes an OQL query and returns hydrated results. */
    public function query(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT, ?int $limit = null, ?int $offset = null): array;

    /**
     * Executes an OQL query and returns a Generator that yields results one by one.
     * Useful for large result sets that don't fit in memory.
     */
    public function queryIterator(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): \Generator;

    /**
     * Executes an OQL query with second-level cache support.
     * Returns cached results if available, otherwise executes and caches.
     *
     * @param int $ttl Cache TTL in seconds
     */
    public function queryCached(string $oql, array $params = [], int $ttl = 3600, int $hydrationMode = HydrationMode::HYDRATE_OBJECT): array;

    /** Executes an OQL query and returns a single result or null. */
    public function queryOne(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): mixed;

    /** Executes an OQL query and returns a single scalar value or null. */
    public function queryScalar(string $oql, array $params = []): mixed;

    /**
     * Executes an OQL query and returns the first column of each row as a flat array of scalars.
     *
     * @return array<int, mixed>
     */
    public function queryScalarAll(string $oql, array $params = [], ?int $limit = null, ?int $offset = null): array;

    /**
     * Executes an OQL query and returns a single result or throws if not found.
     *
     * @throws \SybaseORM\Exception\PersistenceException If no result is found.
     */
    public function queryOneOrFail(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): mixed;

    /** Executes an OQL UPDATE or DELETE statement and returns the number of affected rows. */
    public function executeUpdate(string $oql, array $params = []): int;

    /** Clears the IdentityMap and detaches all entities. */
    public function clear(?string $entityClass = null): void;

    /** Re-attaches a detached entity to the EntityManager. */
    public function merge(object $entity): object;

    /** Begins an explicit transaction. */
    public function beginTransaction(): void;

    /** Commits the active transaction. */
    public function commit(): void;

    /** Rolls back the active transaction. */
    public function rollback(): void;

    /**
     * Executes a callable within a database transaction.
     * Commits on success, rolls back on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T The return value of the callback
     */
    public function transactional(callable $callback): mixed;

    /** Returns the repository for an entity class. */
    public function getRepository(string $entityClass): EntityRepository;

    /** Returns the SQL dialect used by this EntityManager. */
    public function getDialect(): \SybaseORM\Dialect\DialectInterface;

    /** Returns the connection manager for raw SQL access. */
    public function getConnection(): \SybaseORM\Connection\ConnectionManagerInterface;

    /** Checks if an entity is managed by the UnitOfWork. */
    public function isManaged(object $entity): bool;

    /** Alias for isManaged() — Doctrine API compatibility. */
    public function contains(object $entity): bool;

    /** Detaches an entity from the persistence context. */
    public function detach(object $entity): void;

    /** Returns the metadata reader. */
    public function getMetadataReader(): \SybaseORM\Metadata\MetadataReaderInterface;

    /** Returns the type caster. */
    public function getTypeCaster(): \SybaseORM\Type\TypeCasterInterface;

    /**
     * Registers a custom OQL function with its SQL template.
     *
     * @param string $name        Function name as used in OQL (e.g. 'RAND2')
     * @param string $sqlTemplate Raw SQL output (e.g. 'RAND2()')
     */
    public function registerOqlFunction(string $name, string $sqlTemplate): void;

    /**
     * Reloads an entity from the database, discarding any in-memory changes.
     *
     * @throws \SybaseORM\Exception\PersistenceException If the entity has no ID or is not found.
     */
    public function refresh(object $entity): void;

    /**
     * Sets directories to scan for entity classes.
     * Entity discovery happens lazily on first OQL query.
     *
     * @param string[] $directories
     */
    public function setEntityDirectories(array $directories): void;

    /**
     * Explicitly sets the list of entity classes (bypasses directory scanning).
     *
     * @param string[] $entityClasses Fully qualified class names
     */
    public function setEntityClasses(array $entityClasses): void;
}
