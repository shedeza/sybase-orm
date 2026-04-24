<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Query\QueryBuilderInterface;

/**
 * Punto de entrada principal del ORM. Coordina todos los componentes internos.
 */
interface EntityManagerInterface
{
    /** Registra una entidad nueva para inserción en el próximo flush. */
    public function persist(object $entity): void;

    /** Marca una entidad para eliminación en el próximo flush. */
    public function remove(object $entity): void;

    /** Sincroniza todos los cambios pendientes con la base de datos. */
    public function flush(): void;

    /** Busca una entidad por su identificador primario. */
    public function find(string $entityClass, mixed $id): ?object;

    /** Crea un Query_Builder para la entidad especificada. */
    public function createQueryBuilder(string $entityClass): QueryBuilderInterface;

    /** Ejecuta una consulta OQL y retorna los resultados hidratados. */
    public function query(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT, ?int $limit = null, ?int $offset = null): array;

    /**
     * Ejecuta una consulta OQL y retorna un Generator que produce resultados uno a uno.
     * Útil para conjuntos de datos grandes que no caben en memoria.
     */
    public function queryIterator(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): \Generator;

    /** Executes an OQL query and returns a single result or null. */
    public function queryOne(string $oql, array $params = [], int $hydrationMode = HydrationMode::HYDRATE_OBJECT): mixed;

    /** Executes an OQL query and returns a single scalar value or null. */
    public function queryScalar(string $oql, array $params = []): mixed;

    /** Ejecuta una sentencia OQL UPDATE o DELETE y retorna el número de filas afectadas. */
    public function executeUpdate(string $oql, array $params = []): int;

    /** Limpia el Identity_Map y desasocia todas las entidades. */
    public function clear(?string $entityClass = null): void;

    /** Re-asocia una entidad detached al Entity_Manager. */
    public function merge(object $entity): object;

    /** Inicia una transacción explícita. */
    public function beginTransaction(): void;

    /** Confirma la transacción activa. */
    public function commit(): void;

    /** Revierte la transacción activa. */
    public function rollback(): void;

    /** Obtiene la referencia al repositorio de una entidad. */
    public function getRepository(string $entityClass): EntityRepository;

    /** Returns the SQL dialect used by this EntityManager. */
    public function getDialect(): \SybaseORM\Dialect\DialectInterface;

    /** Returns the connection manager for raw SQL access. */
    public function getConnection(): \SybaseORM\Connection\ConnectionManagerInterface;

    /** Checks if an entity is managed by the UnitOfWork. */
    public function isManaged(object $entity): bool;

    /** Detaches an entity from the persistence context. */
    public function detach(object $entity): void;

    /** Returns the metadata reader. */
    public function getMetadataReader(): \SybaseORM\Metadata\MetadataReaderInterface;

    /**
     * Registers a custom OQL function with its SQL template.
     *
     * @param string $name        Function name as used in OQL (e.g. 'RAND2')
     * @param string $sqlTemplate Raw SQL output (e.g. 'RAND2()')
     */
    public function registerOqlFunction(string $name, string $sqlTemplate): void;

    /**
     * Sets directories to scan for entity classes.
     * Entity discovery happens lazily on first OQL query.
     *
     * @param string[] $directories
     */
    public function setEntityDirectories(array $directories): void;
}
