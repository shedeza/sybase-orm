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

    /** @var \SplObjectStorage<object, true> Entities that have been inserted in any commit — prevents re-insertion */
    private \SplObjectStorage $insertedEntities;

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
        $this->insertedEntities = new \SplObjectStorage();
    }

    public function registerNew(object $entity): void
    {
        // No registrar como nueva si ya está managed (tiene snapshot)
        if ($this->entitySnapshots->contains($entity)) {
            return;
        }

        // No registrar si ya fue insertada en un commit anterior
        if ($this->insertedEntities->contains($entity)) {
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
            $currentValue = $this->getEntityPropertyValue($entity, $column->propertyName);
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

            // 2b. Orphan removal: detect removed items from orphanRemoval collections
            $this->processOrphanRemoval();

            // 3. Snapshot managed entities BEFORE inserts to avoid iterating newly inserted ones
            $managedBeforeInsert = [];
            foreach ($this->entitySnapshots as $entity) {
                $managedBeforeInsert[] = $entity;
            }

            // 4. Execute INSERTs for new entities
            $this->executeInserts();

            // 5. Execute UPDATEs only for entities that were managed BEFORE this commit
            $this->executeUpdates($managedBeforeInsert);

            // 6. Execute DELETEs for removed entities
            $this->executeDeletes();

            $this->connectionManager->commit();

            // Clear tracked changes after successful commit
            $this->newEntities = new \SplObjectStorage();
            $this->deletedEntities = new \SplObjectStorage();
            $this->insertedEntities = new \SplObjectStorage();
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
        $this->insertedEntities = new \SplObjectStorage();
        $this->identityMap->clear();
    }

    public function isManaged(object $entity): bool
    {
        return $this->entitySnapshots->contains($entity);
    }

    public function detach(object $entity): void
    {
        $this->entitySnapshots->detach($entity);
        $this->newEntities->detach($entity);
        $this->deletedEntities->detach($entity);
        $this->insertedEntities->detach($entity);
    }

    /**
     * Removes all entities of a specific class from tracking.
     */
    public function clearClass(string $entityClass): void
    {
        $toDetach = [];
        foreach ($this->entitySnapshots as $entity) {
            if ($entity::class === $entityClass) {
                $toDetach[] = $entity;
            }
        }
        foreach ($toDetach as $entity) {
            $this->detach($entity);
        }
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
            $snapshot[$column->propertyName] = $this->getEntityPropertyValue($entity, $column->propertyName);
        }

        // Capture relationship values for orphan removal detection
        foreach ($metadata->relationships as $relationship) {
            if (!$relationship->orphanRemoval) {
                continue;
            }

            $refProp = $this->getReflectionProperty($entity::class, $relationship->propertyName);
            $value = $refProp->getValue($entity);

            // Deep-copy collections/arrays so snapshot is independent of current state
            if ($value instanceof \SybaseORM\Collection\Collection) {
                $snapshot[$relationship->propertyName] = [...$value->toArray()];
            } elseif (is_array($value)) {
                $snapshot[$relationship->propertyName] = [...$value];
            } else {
                $snapshot[$relationship->propertyName] = $value;
            }
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

                foreach ($this->extractRelatedEntities($relatedValue) as $relatedEntity) {
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

                foreach ($this->extractRelatedEntities($relatedValue) as $relatedEntity) {
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
     * Detects orphaned entities in collections with orphanRemoval=true.
     * Compares current collection state with snapshot to find removed items.
     */
    private function processOrphanRemoval(): void
    {
        foreach ($this->entitySnapshots as $entity) {
            $metadata = $this->metadataReader->getClassMetadata($entity::class);

            foreach ($metadata->relationships as $relationship) {
                if (!$relationship->orphanRemoval) {
                    continue;
                }

                // Only process to-many relationships (collections)
                if ($relationship->type !== 'OneToMany' && $relationship->type !== 'OneToOne') {
                    continue;
                }

                $refProp = $this->getReflectionProperty($entity::class, $relationship->propertyName);
                $currentValue = $refProp->getValue($entity);
                $snapshotValue = $this->entitySnapshots[$entity][$relationship->propertyName] ?? null;

                if ($relationship->type === 'OneToOne') {
                    // OneToOne: if old value was an object and new value is null or different, orphan the old
                    if (is_object($snapshotValue) && $snapshotValue !== $currentValue) {
                        if (!$this->deletedEntities->contains($snapshotValue)) {
                            $this->deletedEntities->attach($snapshotValue);
                        }
                    }
                    continue;
                }

                // OneToMany: compare collections/arrays to find removed items
                $currentItems = $currentValue;
                if ($currentValue instanceof \SybaseORM\Collection\Collection) {
                    $currentItems = $currentValue->toArray();
                }
                $snapshotItems = $snapshotValue;
                if ($snapshotValue instanceof \SybaseORM\Collection\Collection) {
                    $snapshotItems = $snapshotValue->toArray();
                }

                if (!is_array($currentItems) || !is_array($snapshotItems)) {
                    continue;
                }

                // Find items in snapshot that are no longer in current collection
                $currentSet = new \SplObjectStorage();
                foreach ($currentItems as $item) {
                    if (is_object($item)) {
                        $currentSet->attach($item);
                    }
                }

                foreach ($snapshotItems as $oldItem) {
                    if (is_object($oldItem) && !$currentSet->contains($oldItem)) {
                        if (!$this->deletedEntities->contains($oldItem)) {
                            $this->deletedEntities->attach($oldItem);
                        }
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

        // Guard: track inserted entities to prevent any possibility of double-insert
        $inserted = new \SplObjectStorage();

        foreach ($ordered as $entity) {
            // Skip if already inserted in this commit cycle
            if ($inserted->contains($entity)) {
                continue;
            }

            // Skip if already managed (was inserted in a previous commit)
            if ($this->entitySnapshots->contains($entity)) {
                $this->newEntities->detach($entity);
                continue;
            }

            $metadata = $this->metadataReader->getClassMetadata($entity::class);
            $idColumn = $metadata->getIdColumn();

            $columns = [];
            $placeholders = [];
            $values = [];
            $valueExpressions = [];
            $identityColumnName = null;

            foreach ($metadata->columns as $column) {
                // Determine if this is an identity column to omit
                if ($column->isId && $column->generatedValue !== null) {
                    $identityColumnName = $column->columnName;
                }

                $columns[] = $column->columnName;
                $placeholders[] = '?';

                // Get SQL-wrapping expression for this column's type
                $valueExpressions[] = $this->typeCaster->getDatabaseValueSQL('?', $column->type);

                $phpValue = $this->getEntityPropertyValue($entity, $column->propertyName);
                $values[] = $this->typeCaster->toDatabaseValue($phpValue, $column->type);
            }

            // Normalize value expressions: if expression equals '?', set to null (no wrapping needed)
            $normalizedExpressions = array_map(
                fn(string $expr) => $expr === '?' ? null : $expr,
                $valueExpressions,
            );
            $hasWrapping = array_filter($normalizedExpressions, fn($e) => $e !== null);

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
                !empty($hasWrapping) ? $normalizedExpressions : null,
            );

            $this->connectionManager->executeStatement($sql, $values);

            // Mark as inserted and remove from newEntities immediately
            $inserted->attach($entity);
            $this->insertedEntities->attach($entity);
            $this->newEntities->detach($entity);

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
     *
     * @param object[] $entities Entities to check for updates (pre-computed to avoid
     *                           iterating entitySnapshots which may have been modified
     *                           by executeInserts).
     */
    private function executeUpdates(array $entities): void
    {
        foreach ($entities as $entity) {
            if ($this->newEntities->contains($entity) || $this->deletedEntities->contains($entity)) {
                continue;
            }

            if (!$this->entitySnapshots->contains($entity)) {
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
            $updateValueExpressions = [];

            foreach ($changeset as $propertyName => $change) {
                $column = $metadata->getColumn($propertyName);
                if ($column === null || $column->isId) {
                    continue;
                }
                $updateColumns[] = $column->columnName;
                $updateValues[] = $this->typeCaster->toDatabaseValue($change['new'], $column->type);

                // Get SQL-wrapping expression for this column's type
                $expr = $this->typeCaster->getDatabaseValueSQL('?', $column->type);
                $updateValueExpressions[] = $expr === '?' ? null : $expr;
            }

            if (empty($updateColumns)) {
                continue;
            }

            // Dispatch PreUpdate hook antes del SQL
            $this->hookDispatcher?->dispatch($entity, 'PreUpdate');

            [$whereClause, $whereValues] = $this->buildCompositeWhereClause($metadata, $entity);
            $updateValues = array_merge($updateValues, $whereValues);

            $hasWrapping = array_filter($updateValueExpressions, fn($e) => $e !== null);

            $sql = $this->dialect->generateUpdate(
                $metadata->getQualifiedTableName(),
                $updateColumns,
                $whereClause,
                !empty($hasWrapping) ? $updateValueExpressions : null,
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
     * Supports SoftDelete: if enabled, executes an UPDATE instead of a physical DELETE.
     */
    private function executeDeletes(): void
    {
        foreach ($this->deletedEntities as $entity) {
            $metadata = $this->metadataReader->getClassMetadata($entity::class);
            $idColumns = $metadata->getIdColumns();

            if (empty($idColumns)) {
                continue;
            }

            // Dispatch PreRemove hook
            $this->hookDispatcher?->dispatch($entity, 'PreRemove');

            [$whereClause, $whereValues] = $this->buildCompositeWhereClause($metadata, $entity);

            if ($metadata->softDeleteColumn !== null) {
                // Perform Soft Delete: UPDATE table SET deleted_at = GETDATE() WHERE ...
                $sql = sprintf(
                    'UPDATE %s SET %s = GETDATE() WHERE %s',
                    $this->dialect->quoteIdentifier($metadata->getQualifiedTableName()),
                    $this->dialect->quoteIdentifier($metadata->softDeleteColumn),
                    $whereClause,
                );
            } else {
                // Perform physical DELETE
                $sql = $this->dialect->generateDelete($metadata->getQualifiedTableName(), $whereClause);
            }

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
                if (!empty($relationship->joinColumns)) {
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
                // Solo relaciones con joinColumns (ManyToOne, OneToOne owning side)
                if (empty($rel->joinColumns)) {
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
                // La propagación del ID automático (autoincrement) se asume para un solo ID / FK
                if (count($rel->joinColumns) === 1) {
                    $jcName = array_key_first($rel->joinColumns);
                    $fkColumn = $depMetadata->getColumn($jcName);
                    if ($fkColumn !== null) {
                        $fkProp = $this->getReflectionProperty($dependentEntity::class, $fkColumn->propertyName);
                        $fkProp->setValue($dependentEntity, $generatedId);
                    }
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
     * Extracts entities from a relationship value (handles arrays, PersistentCollection, single objects).
     *
     * @return object[]
     */
    private function extractRelatedEntities(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof \SybaseORM\Collection\Collection) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return [$value];
        }

        return [];
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
            $this->reflectionCache[$className][$propertyName] = $prop;
        }

        return $this->reflectionCache[$className][$propertyName];
    }

    /**
     * Reads a property value from an entity, supporting dot notation for embedded objects.
     * For "address.street", reads $entity->address then ->street.
     */
    private function getEntityPropertyValue(object $entity, string $propertyName): mixed
    {
        if (!str_contains($propertyName, '.')) {
            $refProp = $this->getReflectionProperty($entity::class, $propertyName);

            return $refProp->getValue($entity);
        }

        // Dot notation: embedded property
        [$embeddedProp, $innerProp] = explode('.', $propertyName, 2);
        $refProp = $this->getReflectionProperty($entity::class, $embeddedProp);
        $embeddedObject = $refProp->getValue($entity);

        if ($embeddedObject === null) {
            return null;
        }

        $innerRefProp = $this->getReflectionProperty($embeddedObject::class, $innerProp);

        return $innerRefProp->getValue($embeddedObject);
    }
}
