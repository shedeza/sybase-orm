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

        // 3. Query the database
        $metadata = $this->metadataReader->getClassMetadata($entityClass);
        $idColumn = $metadata->getIdColumn();

        if ($idColumn === null) {
            return null;
        }

        $sql = $this->dialect->generateSelect(
            ['*'],
            $metadata->getQualifiedTableName(),
        );
        $sql .= ' WHERE ' . $this->dialect->quoteIdentifier($idColumn->columnName) . ' = ?';

        $dbValue = $this->typeCaster->toDatabaseValue($id, $idColumn->type);
        $stmt = $this->connectionManager->executeQuery($sql, [$dbValue]);
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

    public function query(string $oql, array $params = []): array
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

        // Map named parameters to ordered values
        $orderedParams = [];
        foreach ($parameterNames as $name) {
            $orderedParams[] = $params[$name] ?? null;
        }

        $stmt = $this->connectionManager->executeQuery($sql, $orderedParams);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        // Determine entity class from the FROM clause
        $entityClass = $this->resolveEntityFromAst($ast);

        if ($entityClass === null) {
            return $rows;
        }

        return $this->hydrator->hydrateAll($rows, $entityClass);
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
