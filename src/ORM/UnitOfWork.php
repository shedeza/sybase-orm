<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Tracks entity changes and coordinates persistence within a transaction.
 *
 * Implements dirty checking via property snapshots taken at registerClean() time,
 * and executes INSERTs, UPDATEs, DELETEs in order during commit().
 *
 * Cachea ReflectionProperty para evitar recrearlos en cada operación.
 */
final class UnitOfWork implements UnitOfWorkInterface
{
    /** @var \SplObjectStorage<object, true> */
    private \SplObjectStorage $newEntities;

    /** @var \SplObjectStorage<object, true> */
    private \SplObjectStorage $deletedEntities;

    /** @var \SplObjectStorage<object, array<string, mixed>> */
    private \SplObjectStorage $entitySnapshots;

    /** @var array<string, array<string, \ReflectionProperty>> Caché de ReflectionProperty por clase y propiedad */
    private array $reflectionCache = [];

    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly MetadataReaderInterface $metadataReader,
        private readonly DialectInterface $dialect,
        private readonly TypeCasterInterface $typeCaster,
        private readonly IdentityMapInterface $identityMap,
        private readonly ?HookDispatcher $hookDispatcher = null,
    ) {
        $this->newEntities = new \SplObjectStorage();
        $this->deletedEntities = new \SplObjectStorage();
        $this->entitySnapshots = new \SplObjectStorage();
    }

    public function registerNew(object $entity): void
    {
        // No registrar como nueva si ya está managed (tiene snapshot)
        if ($this->entitySnapshots->contains($entity)) {
            return;
        }

        $this->newEntities->attach($entity);
    }

    public function registerDeleted(object $entity): void
    {
        $this->deletedEntities->attach($entity);
    }

    public function registerClean(object $entity): void
    {
        $metadata = $this->metadataReader->getClassMetadata($entity::class);
        $snapshot = $this->takeSnapshot($entity, $metadata);
        $this->entitySnapshots->attach($entity, $snapshot);
    }

    public function computeChangeset(object $entity): array
    {
        if (!$this->entitySnapshots->contains($entity)) {
            return [];
        }

        $metadata = $this->metadataReader->getClassMetadata($entity::class);
        $snapshot = $this->entitySnapshots[$entity];
        $changeset = [];

        foreach ($metadata->columns as $column) {
            $refProp = $this->getReflectionProperty($entity::class, $column->propertyName);
            $currentValue = $refProp->getValue($entity);
            $oldValue = $snapshot[$column->propertyName] ?? null;

            if ($currentValue !== $oldValue) {
                $changeset[$column->propertyName] = [
                    'old' => $oldValue,
                    'new' => $currentValue,
                ];
            }
        }

        return $changeset;
    }

    public function commit(): void
    {
        try {
            $this->connectionManager->beginTransaction();

            // 1. Cascade: discover related entities marked with cascade=['persist']
            $this->processCascadePersist();

            // 2. Cascade: discover related entities marked with cascade=['remove']
            $this->processCascadeRemove();

            // 3. Execute INSERTs for new entities
            $this->executeInserts();

            // 4. Execute UPDATEs for dirty (managed) entities
            $this->executeUpdates();

            // 5. Execute DELETEs for removed entities
            $this->executeDeletes();

            $this->connectionManager->commit();

            // Clear tracked changes after successful commit
            $this->newEntities = new \SplObjectStorage();
            $this->deletedEntities = new \SplObjectStorage();
        } catch (PersistenceException $e) {
            $this->safeRollback();
            throw $e;
        } catch (\Throwable $e) {
            $this->safeRollback();
            throw new PersistenceException(
                'Flush failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
            );
        }
    }

    public function clear(): void
    {
        $this->newEntities = new \SplObjectStorage();
        $this->deletedEntities = new \SplObjectStorage();
        $this->entitySnapshots = new \SplObjectStorage();
        $this->identityMap->clear();
    }

    public function isManaged(object $entity): bool
    {
        return $this->entitySnapshots->contains($entity);
    }

    /**
     * Takes a snapshot of all mapped property values via Reflection.
     *
     * @return array<string, mixed>
     */
    private function takeSnapshot(object $entity, ClassMetadata $metadata): array
    {
        $snapshot = [];

        foreach ($metadata->columns as $column) {
            $refProp = $this->getReflectionProperty($entity::class, $column->propertyName);
            $snapshot[$column->propertyName] = $refProp->getValue($entity);
        }

        return $snapshot;
    }

    /**
     * Discovers related entities marked with cascade=['persist'] and registers them as new
     * if they are not already tracked.
     */
    private function processCascadePersist(): void
    {
        $toProcess = [];
        foreach ($this->newEntities as $entity) {
            $toProcess[] = $entity;
        }

        $processed = new \SplObjectStorage();

        while (!empty($toProcess)) {
            $entity = array_shift($toProcess);

            if ($processed->contains($entity)) {
                continue;
            }
            $processed->attach($entity);

            $metadata = $this->metadataReader->getClassMetadata($entity::class);

            foreach ($metadata->relationships as $relationship) {
                if (!in_array('persist', $relationship->cascade, true)) {
                    continue;
                }

                $refProp = $this->getReflectionProperty($entity::class, $relationship->propertyName);
                $relatedValue = $refProp->getValue($entity);

                if ($relatedValue === null) {
                    continue;
                }

                $relatedEntities = is_array($relatedValue) ? $relatedValue : [$relatedValue];

                foreach ($relatedEntities as $relatedEntity) {
                    if (!is_object($relatedEntity)) {
                        continue;
                    }

                    if (!$this->newEntities->contains($relatedEntity)
                        && !$this->entitySnapshots->contains($relatedEntity)
                    ) {
                        $this->newEntities->attach($relatedEntity);
                        $toProcess[] = $relatedEntity;
                    }
                }
            }
        }
    }

    /**
     * Discovers related entities marked with cascade=['remove'] and registers them
     * for deletion if they are managed (have a snapshot).
     */
    private function processCascadeRemove(): void
    {
        $toProcess = [];
        foreach ($this->deletedEntities as $entity) {
            $toProcess[] = $entity;
        }

        $processed = new \SplObjectStorage();

        while (!empty($toProcess)) {
            $entity = array_shift($toProcess);

            if ($processed->contains($entity)) {
                continue;
            }
            $processed->attach($entity);

            $metadata = $this->metadataReader->getClassMetadata($entity::class);

            foreach ($metadata->relationships as $relationship) {
                if (!in_array('remove', $relationship->cascade, true)) {
                    continue;
                }

                $refProp = $this->getReflectionProperty($entity::class, $relationship->propertyName);
                $relatedValue = $refProp->getValue($entity);

                if ($relatedValue === null) {
                    continue;
                }

                $relatedEntities = is_array($relatedValue) ? $relatedValue : [$relatedValue];

                foreach ($relatedEntities as $relatedEntity) {
                    if (!is_object($relatedEntity)) {
                        continue;
                    }

                    if (!$this->deletedEntities->contains($relatedEntity)
                        && $this->entitySnapshots->contains($relatedEntity)
                    ) {
                        $this->deletedEntities->attach($relatedEntity);
                        $toProcess[] = $relatedEntity;
                    }
                }
            }
        }
    }

    /**
     * Executes INSERT statements for all new entities.
     * Retrieves @@identity after each INSERT and sets it on the entity.
     */
    private function executeInserts(): void
    {
        // Order new entities respecting foreign key dependencies
        $ordered = $this->orderEntitiesForInsert();

        foreach ($ordered as $entity) {
            $metadata = $this->metadataReader->getClassMetadata($entity::class);
            $idColumn = $metadata->getIdColumn();

            $columns = [];
            $placeholders = [];
            $values = [];
            $identityColumnName = null;

            foreach ($metadata->columns as $column) {
                // Determine if this is an identity column to omit
                if ($column->isId && $column->generatedValue !== null) {
                    $identityColumnName = $column->columnName;
                }

                $columns[] = $column->columnName;
                $placeholders[] = '?';

                $refProp = $this->getReflectionProperty($entity::class, $column->propertyName);
                $phpValue = $refProp->getValue($entity);
                $values[] = $this->typeCaster->toDatabaseValue($phpValue, $column->type);
            }

            // Filter out identity column values from the params array
            if ($identityColumnName !== null) {
                $filteredValues = [];
                foreach ($columns as $i => $col) {
                    if ($col !== $identityColumnName) {
                        $filteredValues[] = $values[$i];
                    }
                }
                $values = $filteredValues;
            }

            $sql = $this->dialect->generateInsert(
                $metadata->getQualifiedTableName(),
                $columns,
                $placeholders,
                $identityColumnName,
            );

            $this->connectionManager->executeStatement($sql, $values);

            // Retrieve @@identity and set on entity
            if ($identityColumnName !== null && $idColumn !== null) {
                $identitySql = $this->dialect->getLastInsertIdSQL();
                $stmt = $this->connectionManager->executeQuery($identitySql);
                $row = $stmt->fetch(\PDO::FETCH_NUM);
                $stmt->closeCursor();

                if ($row !== false && isset($row[0])) {
                    $generatedId = $this->typeCaster->toPhpValue($row[0], $idColumn->type);
                    $refProp = $this->getReflectionProperty($entity::class, $idColumn->propertyName);
                    $refProp->setValue($entity, $generatedId);

                    // Register in identity map
                    $this->identityMap->put($entity::class, $generatedId, $entity);

                    // Propagar el ID generado a entidades dependientes (FK)
                    $this->propagateGeneratedId($entity, $generatedId);
                }
            }

            // Take snapshot after insert so entity is now "clean"
            $this->registerClean($entity);

            // Dispatch PostPersist hook
            $this->hookDispatcher?->dispatch($entity, 'PostPersist');
        }
    }

    /**
     * Executes UPDATE statements for dirty managed entities (only changed columns).
     */
    private function executeUpdates(): void
    {
        foreach ($this->entitySnapshots as $entity) {
            if ($this->newEntities->contains($entity) || $this->deletedEntities->contains($entity)) {
                continue;
            }

            $changeset = $this->computeChangeset($entity);
            if (empty($changeset)) {
                continue;
            }

            $metadata = $this->metadataReader->getClassMetadata($entity::class);
            $idColumns = $metadata->getIdColumns();

            if (empty($idColumns)) {
                continue;
            }

            $updateColumns = [];
            $updateValues = [];

            foreach ($changeset as $propertyName => $change) {
                $column = $metadata->getColumn($propertyName);
                if ($column === null || $column->isId) {
                    continue;
                }
                $updateColumns[] = $column->columnName;
                $updateValues[] = $this->typeCaster->toDatabaseValue($change['new'], $column->type);
            }

            if (empty($updateColumns)) {
                continue;
            }

            // Dispatch PreUpdate hook antes del SQL
            $this->hookDispatcher?->dispatch($entity, 'PreUpdate');

            [$whereClause, $whereValues] = $this->buildCompositeWhereClause($metadata, $entity);
            $updateValues = array_merge($updateValues, $whereValues);

            $sql = $this->dialect->generateUpdate(
                $metadata->getQualifiedTableName(),
                $updateColumns,
                $whereClause,
            );

            $this->connectionManager->executeStatement($sql, $updateValues);

            // Update snapshot after successful update
            $this->registerClean($entity);

            // Dispatch PostUpdate hook
            $this->hookDispatcher?->dispatch($entity, 'PostUpdate');
        }
    }

    /**
     * Executes DELETE statements for all deleted entities.
     */
    private function executeDeletes(): void
    {
        foreach ($this->deletedEntities as $entity) {
            $metadata = $this->metadataReader->getClassMetadata($entity::class);
            $idColumns = $metadata->getIdColumns();

            if (empty($idColumns)) {
                continue;
            }

            [$whereClause, $whereValues] = $this->buildCompositeWhereClause($metadata, $entity);

            $sql = $this->dialect->generateDelete($metadata->getQualifiedTableName(), $whereClause);

            $this->connectionManager->executeStatement($sql, $whereValues);

            // Remove from identity map
            if (count($idColumns) === 1) {
                $refIdProp = $this->getReflectionProperty($entity::class, $idColumns[0]->propertyName);
                $idValue = $refIdProp->getValue($entity);
                $this->identityMap->remove($entity::class, $idValue);
            } else {
                $compositeId = [];
                foreach ($idColumns as $idCol) {
                    $refProp = $this->getReflectionProperty($entity::class, $idCol->propertyName);
                    $compositeId[$idCol->propertyName] = $refProp->getValue($entity);
                }
                $this->identityMap->remove($entity::class, $compositeId);
            }

            // Remove snapshot
            if ($this->entitySnapshots->contains($entity)) {
                $this->entitySnapshots->detach($entity);
            }

            // Dispatch PostRemove hook
            $this->hookDispatcher?->dispatch($entity, 'PostRemove');
        }
    }

    /**
     * Builds a composite WHERE clause for UPDATE/DELETE operations using all primary key columns.
     *
     * For single-key entities, produces a single `column = ?` condition.
     * For composite-key entities, produces `col1 = ? AND col2 = ? AND ...`.
     *
     * @return array{0: string, 1: list<mixed>} [$whereString, $values]
     */
    private function buildCompositeWhereClause(ClassMetadata $metadata, object $entity): array
    {
        $idColumns = $metadata->getIdColumns();
        $conditions = [];
        $values = [];

        foreach ($idColumns as $idCol) {
            $refProp = $this->getReflectionProperty($entity::class, $idCol->propertyName);
            $idValue = $refProp->getValue($entity);
            $conditions[] = $this->dialect->quoteIdentifier($idCol->columnName) . ' = ?';
            $values[] = $this->typeCaster->toDatabaseValue($idValue, $idCol->type);
        }

        return [implode(' AND ', $conditions), $values];
    }

    /**
     * Orders new entities for INSERT respecting foreign key dependencies.
     * Entities that are depended upon (targets of ManyToOne/OneToOne with joinColumn)
     * are inserted first.
     *
     * @return object[]
     */
    private function orderEntitiesForInsert(): array
    {
        $entities = [];
        foreach ($this->newEntities as $entity) {
            $entities[] = $entity;
        }

        if (count($entities) <= 1) {
            return $entities;
        }

        // Build a dependency graph: entity -> depends on [other entities]
        $dependsOn = new \SplObjectStorage();

        foreach ($entities as $entity) {
            $deps = [];
            $metadata = $this->metadataReader->getClassMetadata($entity::class);

            foreach ($metadata->relationships as $relationship) {
                // ManyToOne and owning OneToOne have a joinColumn = FK dependency
                if ($relationship->joinColumn !== null) {
                    $refProp = $this->getReflectionProperty($entity::class, $relationship->propertyName);
                    $related = $refProp->getValue($entity);

                    if ($related !== null && is_object($related) && $this->newEntities->contains($related)) {
                        $deps[] = $related;
                    }
                }
            }

            $dependsOn->attach($entity, $deps);
        }

        // Topological sort con detección de ciclos
        $sorted = [];
        $visited = new \SplObjectStorage();
        $inStack = new \SplObjectStorage();

        $visit = function (object $entity) use (&$visit, &$sorted, $visited, $inStack, $dependsOn): void {
            if ($visited->contains($entity)) {
                return;
            }

            // Detectar dependencia circular
            if ($inStack->contains($entity)) {
                throw new PersistenceException(sprintf(
                    'Dependencia circular detectada al ordenar entidades para INSERT. Clase: %s',
                    $entity::class,
                ));
            }

            $inStack->attach($entity);

            if ($dependsOn->contains($entity)) {
                foreach ($dependsOn[$entity] as $dep) {
                    $visit($dep);
                }
            }

            $inStack->detach($entity);
            $visited->attach($entity);
            $sorted[] = $entity;
        };

        foreach ($entities as $entity) {
            $visit($entity);
        }

        return $sorted;
    }

    /**
     * Propaga el ID generado de una entidad padre a las entidades dependientes
     * que tienen una FK (joinColumn) apuntando a ella.
     *
     * Ejemplo: si Customer obtiene id=42, y Order tiene customer_id como FK,
     * este método busca todas las entidades nuevas que referencian a Customer
     * y les asigna customer_id=42 via Reflection.
     */
    private function propagateGeneratedId(object $parentEntity, mixed $generatedId): void
    {
        foreach ($this->newEntities as $dependentEntity) {
            if ($dependentEntity === $parentEntity) {
                continue;
            }

            $depMetadata = $this->metadataReader->getClassMetadata($dependentEntity::class);

            foreach ($depMetadata->relationships as $rel) {
                // Solo relaciones con joinColumn (ManyToOne, OneToOne owning side)
                if ($rel->joinColumn === null) {
                    continue;
                }

                // Verificar que la relación apunta a la clase del padre
                if ($rel->targetEntity !== $parentEntity::class) {
                    continue;
                }

                // Verificar que la propiedad de relación contiene la instancia del padre
                $relProp = $this->getReflectionProperty($dependentEntity::class, $rel->propertyName);
                $relatedValue = $relProp->getValue($dependentEntity);

                if ($relatedValue !== $parentEntity) {
                    continue;
                }

                // Buscar la columna FK en la entidad dependiente y asignar el ID generado
                // La columna FK corresponde al joinColumn de la relación
                $fkColumn = $depMetadata->getColumn($rel->joinColumn);
                if ($fkColumn !== null) {
                    // Si hay una propiedad mapeada para la FK, asignar directamente
                    $fkProp = $this->getReflectionProperty($dependentEntity::class, $fkColumn->propertyName);
                    $fkProp->setValue($dependentEntity, $generatedId);
                }
            }
        }
    }

    /**
     * Safely attempts rollback, suppressing any secondary exceptions.
     */
    private function safeRollback(): void
    {
        try {
            $this->connectionManager->rollback();
        } catch (\Throwable) {
            // Suppress rollback errors — the original exception is more important
        }
    }

    /**
     * Obtiene un ReflectionProperty cacheado para evitar recrearlo en cada operación.
     *
     * @param class-string $className    Nombre completo de la clase
     * @param string       $propertyName Nombre de la propiedad
     */
    private function getReflectionProperty(string $className, string $propertyName): \ReflectionProperty
    {
        if (!isset($this->reflectionCache[$className][$propertyName])) {
            $prop = new \ReflectionProperty($className, $propertyName);
            $prop->setAccessible(true);
            $this->reflectionCache[$className][$propertyName] = $prop;
        }

        return $this->reflectionCache[$className][$propertyName];
    }
}
