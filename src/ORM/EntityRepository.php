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

    /**
     * Persiste una entidad y sincroniza con la base de datos.
     *
     * Si la entidad es nueva (sin ID), la registra para INSERT.
     * Si la entidad ya existe (con ID y managed), solo ejecuta flush
     * para que el dirty checking genere el UPDATE.
     */
    public function save(object $entity): void
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    /**
     * Persiste múltiples entidades en una sola transacción.
     *
     * @param object[] $entities
     */
    public function saveMany(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->entityManager->flush();
    }

    /**
     * Elimina una entidad y sincroniza con la base de datos.
     *
     * Equivale a llamar remove() + flush() en el EntityManager.
     */
    public function delete(object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    /**
     * Elimina múltiples entidades en una sola transacción.
     *
     * @param object[] $entities
     */
    public function deleteMany(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
    }

    /**
     * Re-asocia una entidad detached al contexto de persistencia.
     * Retorna la instancia managed con los valores copiados.
     */
    public function merge(object $entity): object
    {
        return $this->entityManager->merge($entity);
    }

    // ── Consultas ───────────────────────────────────────────────────

    /**
     * Busca una entidad por su identificador primario.
     *
     * Para llaves compuestas, pasar un array asociativo con los campos
     * que componen la llave: find(['campo1' => valor1, 'campo2' => valor2]).
     * Internamente delega a findOneBy() en ese caso.
     *
     * @param mixed $id Valor escalar para llave simple, o array asociativo para llave compuesta
     */
    public function find(mixed $id): ?object
    {
        if (is_array($id)) {
            return $this->findOneBy($id);
        }

        return $this->entityManager->find($this->entityClass, $id);
    }

    /**
     * Busca todas las entidades de este tipo.
     *
     * @return object[]
     */
    public function findAll(): array
    {
        $oql = sprintf('SELECT e FROM %s e', $this->entityShortName);

        return $this->entityManager->query($oql);
    }

    /**
     * Busca entidades que coincidan con los criterios dados.
     *
     * @param array<string, mixed> $criteria Nombre de propiedad => valor
     * @param array<string, string>|null $orderBy Nombre de propiedad => 'ASC'|'DESC'
     * @param int|null $limit Número máximo de resultados
     * @param int|null $offset Desplazamiento para paginación
     * @return object[]
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
    {
        if (empty($criteria) && $orderBy === null && $limit === null) {
            return $this->findAll();
        }

        $conditions = [];
        $params = [];
        $i = 0;

        foreach ($criteria as $property => $value) {
            $paramName = 'p' . $i;
            $conditions[] = sprintf('e.%s = :%s', $property, $paramName);
            $params[$paramName] = $value;
            $i++;
        }

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

        $results = $this->entityManager->query($oql, $params);

        if ($limit !== null) {
            $results = array_slice($results, $offset ?? 0, $limit);
        } elseif ($offset !== null) {
            $results = array_slice($results, $offset);
        }

        return $results;
    }

    /**
     * Busca una sola entidad que coincida con los criterios, o null si no existe.
     *
     * @param array<string, mixed> $criteria Nombre de propiedad => valor
     */
    public function findOneBy(array $criteria): ?object
    {
        if (empty($criteria)) {
            $oql = sprintf('SELECT e FROM %s e', $this->entityShortName);
            return $this->entityManager->queryOne($oql);
        }

        $conditions = [];
        $params = [];
        $i = 0;

        foreach ($criteria as $property => $value) {
            $paramName = 'p' . $i;
            $conditions[] = sprintf('e.%s = :%s', $property, $paramName);
            $params[$paramName] = $value;
            $i++;
        }

        $oql = sprintf(
            'SELECT e FROM %s e WHERE %s',
            $this->entityShortName,
            implode(' AND ', $conditions),
        );

        return $this->entityManager->queryOne($oql, $params);
    }

    /**
     * Ejecuta una consulta OQL personalizada.
     *
     * @param array<string, mixed> $params Parámetros de la consulta
     * @return object[]
     */
    public function query(string $oql, array $params = []): array
    {
        return $this->entityManager->query($oql, $params);
    }

    // ── Conteo y existencia ────────────────────────────────────────

    /**
     * Cuenta las entidades que coincidan con los criterios dados.
     *
     * @param array<string, mixed> $criteria Nombre de propiedad => valor
     */
    public function count(array $criteria = []): int
    {
        $conditions = [];
        $params = [];
        $i = 0;

        foreach ($criteria as $property => $value) {
            $paramName = 'c' . $i;
            $conditions[] = sprintf('e.%s = :%s', $property, $paramName);
            $params[$paramName] = $value;
            $i++;
        }

        $oql = sprintf('SELECT COUNT(*) FROM %s e', $this->entityShortName);

        if (!empty($conditions)) {
            $oql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $result = $this->entityManager->query($oql, $params, HydrationMode::HYDRATE_ARRAY);

        if (empty($result)) {
            return 0;
        }

        $row = $result[0];

        return (int) reset($row);
    }

    /**
     * Verifica si existe al menos una entidad que coincida con los criterios.
     *
     * @param array<string, mixed> $criteria Nombre de propiedad => valor
     */
    public function exists(array $criteria): bool
    {
        $conditions = [];
        $params = [];
        $i = 0;

        foreach ($criteria as $property => $value) {
            $paramName = 'e' . $i;
            $conditions[] = sprintf('e.%s = :%s', $property, $paramName);
            $params[$paramName] = $value;
            $i++;
        }

        $oql = sprintf('SELECT COUNT(*) FROM %s e', $this->entityShortName);
        if (!empty($conditions)) {
            $oql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $count = $this->entityManager->queryScalar($oql, $params);

        return ((int) ($count ?? 0)) > 0;
    }

    // ── QueryBuilder ────────────────────────────────────────────────

    /**
     * Crea un QueryBuilder pre-configurado con FROM para esta entidad.
     *
     * El alias por defecto es 'e'.
     */
    public function createQueryBuilder(): QueryBuilderInterface
    {
        return $this->entityManager->createQueryBuilder($this->entityClass);
    }

    // ── Transacciones ───────────────────────────────────────────────

    /**
     * Inicia una transacción explícita.
     */
    public function beginTransaction(): void
    {
        $this->entityManager->beginTransaction();
    }

    /**
     * Confirma la transacción activa.
     */
    public function commit(): void
    {
        $this->entityManager->commit();
    }

    /**
     * Revierte la transacción activa.
     */
    public function rollback(): void
    {
        $this->entityManager->rollback();
    }

    // ── Utilidades ──────────────────────────────────────────────────

    /**
     * Retorna la clase de entidad gestionada por este repositorio.
     */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /**
     * Returns the database table name for this entity.
     */
    public function getTableName(): string
    {
        $metadata = $this->entityManager->getMetadataReader()->getClassMetadata($this->entityClass);

        return $metadata->getQualifiedTableName();
    }

    /**
     * Returns the short class name used in OQL queries.
     */
    public function getEntityShortName(): string
    {
        return $this->entityShortName;
    }

    /**
     * Acceso protegido al EntityManager para repositorios personalizados.
     */
    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
