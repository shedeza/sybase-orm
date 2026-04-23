<?php

declare(strict_types=1);

namespace SybaseORM\Hydrator;

use ReflectionClass;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Converts database result rows into entity instances using the Reflection API.
 *
 * Integrates with TypeCaster for type conversion and IdentityMap for
 * ensuring object identity within a session.
 */
final class Hydrator implements HydratorInterface
{
    /** @var array<string, ReflectionClass<object>> Caché de ReflectionClass por nombre de clase */
    private array $reflectionClassCache = [];

    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
        private readonly TypeCasterInterface $typeCaster,
        private readonly ?IdentityMapInterface $identityMap = null,
    ) {
    }

    public function hydrate(array $row, string $entityClass): object
    {
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        // Check Identity Map first if available
        if ($this->identityMap !== null) {
            $existingEntity = $this->resolveFromIdentityMap($row, $metadata);
            if ($existingEntity !== null) {
                return $existingEntity;
            }
        }

        // Create entity instance without calling constructor
        $reflectionClass = $this->getReflectionClass($entityClass);
        $entity = $reflectionClass->newInstanceWithoutConstructor();

        // Hydrate mapped columns
        $this->hydrateColumns($entity, $row, $metadata, $reflectionClass);

        // Hydrate eager-loaded relationships
        $this->hydrateEagerRelationships($entity, $row, $metadata, $reflectionClass);

        // Store in Identity Map if available
        if ($this->identityMap !== null) {
            $this->storeInIdentityMap($entity, $metadata);
        }

        return $entity;
    }

    public function hydrateAll(array $rows, string $entityClass): array
    {
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrate($row, $entityClass);
        }

        return $entities;
    }

    /**
     * Checks the Identity Map for an existing entity matching the row's ID.
     */
    private function resolveFromIdentityMap(array $row, ClassMetadata $metadata): ?object
    {
        $idColumn = $metadata->getIdColumn();
        if ($idColumn === null) {
            return null;
        }

        $idValue = $row[$idColumn->columnName] ?? null;
        if ($idValue === null) {
            return null;
        }

        // Convert the ID value to the proper PHP type
        $idValue = $this->typeCaster->toPhpValue($idValue, $idColumn->type);

        return $this->identityMap->get($metadata->entityClass, $idValue);
    }

    /**
     * Hydrates the entity's mapped columns from the row data.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    private function hydrateColumns(
        object $entity,
        array $row,
        ClassMetadata $metadata,
        ReflectionClass $reflectionClass,
    ): void {
        foreach ($row as $columnName => $rawValue) {
            $column = $metadata->getColumnByName($columnName);
            if ($column === null) {
                // Columna no mapeada — ignorar silenciosamente
                continue;
            }

            $phpValue = ($rawValue !== null)
                ? $this->typeCaster->toPhpValue($rawValue, $column->type)
                : null;

            $this->setPropertyValue($entity, $column->propertyName, $phpValue, $reflectionClass);
        }
    }

    /**
     * Hydrates eager-loaded relationships from prefixed columns in the row.
     *
     * Eager-loaded relationship data is expected in the row with keys prefixed
     * by the relationship property name followed by a dot, e.g. "profile.id", "profile.name".
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    private function hydrateEagerRelationships(
        object $entity,
        array $row,
        ClassMetadata $metadata,
        ReflectionClass $reflectionClass,
    ): void {
        foreach ($metadata->relationships as $relationship) {
            if ($relationship->fetch !== 'EAGER') {
                continue;
            }

            $prefix = $relationship->propertyName . '.';
            $relatedRow = [];

            foreach ($row as $key => $value) {
                if (str_starts_with($key, $prefix)) {
                    $relatedRow[substr($key, strlen($prefix))] = $value;
                }
            }

            if (empty($relatedRow)) {
                continue;
            }

            // Check if all values are null (LEFT JOIN with no match)
            $allNull = true;
            foreach ($relatedRow as $value) {
                if ($value !== null) {
                    $allNull = false;
                    break;
                }
            }

            if ($allNull) {
                continue;
            }

            // Hydrate the related entity (recursively uses Identity Map)
            $relatedEntity = $this->hydrate($relatedRow, $relationship->targetEntity);
            $this->setPropertyValue($entity, $relationship->propertyName, $relatedEntity, $this->getReflectionClass($entity::class));
        }
    }

    /**
     * Stores the hydrated entity in the Identity Map.
     */
    private function storeInIdentityMap(
        object $entity,
        ClassMetadata $metadata,
    ): void {
        $idColumn = $metadata->getIdColumn();
        if ($idColumn === null) {
            return;
        }

        $reflectionClass = $this->getReflectionClass($entity::class);
        $idValue = $this->getPropertyValue($entity, $idColumn->propertyName, $reflectionClass);
        if ($idValue === null) {
            return;
        }

        $this->identityMap->put($metadata->entityClass, $idValue, $entity);
    }

    /**
     * Sets a property value on an entity using Reflection, even if private.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    private function setPropertyValue(
        object $entity,
        string $propertyName,
        mixed $value,
        ReflectionClass $reflectionClass,
    ): void {
        if (!$reflectionClass->hasProperty($propertyName)) {
            return;
        }

        $property = $reflectionClass->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($entity, $value);
    }

    /**
     * Gets a property value from an entity using Reflection, even if private.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    private function getPropertyValue(
        object $entity,
        string $propertyName,
        ReflectionClass $reflectionClass,
    ): mixed {
        if (!$reflectionClass->hasProperty($propertyName)) {
            return null;
        }

        $property = $reflectionClass->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($entity);
    }

    /**
     * Obtiene un ReflectionClass cacheado para evitar recrearlo en cada hidratación.
     *
     * @param class-string $entityClass
     * @return ReflectionClass<object>
     */
    private function getReflectionClass(string $entityClass): ReflectionClass
    {
        if (!isset($this->reflectionClassCache[$entityClass])) {
            $this->reflectionClassCache[$entityClass] = new ReflectionClass($entityClass);
        }

        return $this->reflectionClassCache[$entityClass];
    }
}
