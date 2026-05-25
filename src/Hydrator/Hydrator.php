<?php

declare(strict_types=1);

namespace SybaseORM\Hydrator;

use ReflectionClass;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Type\TypeCasterInterface;
use SybaseORM\Proxy\ProxyGenerator;
use SybaseORM\ORM\EntityManagerInterface;

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

    /** @var array<string, array<string, \ReflectionProperty>> Caché de ReflectionProperty por clase y propiedad */
    private array $reflectionPropertyCache = [];

    /** @var (callable(string $entityClass, string $propertyName, object $owner): array)|null */
    private $collectionLoader = null;

    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
        private readonly TypeCasterInterface $typeCaster,
        private readonly ?IdentityMapInterface $identityMap = null,
        private readonly ?UnitOfWorkInterface $unitOfWork = null,
        private readonly ?ProxyGenerator $proxyGenerator = null,
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    /**
     * Sets a callable that loads related entities for a collection relationship.
     * Signature: fn(string $entityClass, string $propertyName, object $owner): array
     *
     * When set, PersistentCollections will use this loader as their initializer,
     * enabling lazy loading of to-many relationships.
     */
    public function setCollectionLoader(callable $loader): void
    {
        $this->collectionLoader = $loader;
    }

    public function hydrate(array $row, string $entityClass): object
    {
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        $entity = null;

        // Check Identity Map first if available
        if ($this->identityMap !== null) {
            $existingEntity = $this->resolveFromIdentityMap($row, $metadata);
            if ($existingEntity !== null) {
                if ($existingEntity instanceof \SybaseORM\Proxy\LazyLoadingProxy && !$existingEntity->__isInitialized()) {
                    $entity = $existingEntity;
                    // Prevent recursion or double fetching during hydration
                    $entity->__setInitializer(null);
                } else {
                    return $existingEntity;
                }
            }
        }

        if ($entity === null) {
            // Create entity instance without calling constructor
            $reflectionClass = $this->getReflectionClass($entityClass);
            $entity = $reflectionClass->newInstanceWithoutConstructor();
        } else {
            $reflectionClass = $this->getReflectionClass($entityClass);
        }

        // Hydrate mapped columns
        $this->hydrateColumns($entity, $row, $metadata, $reflectionClass);

        // Hydrate eager-loaded relationships
        $this->hydrateEagerRelationships($entity, $row, $metadata, $reflectionClass);

        // Hydrate lazy-loaded ManyToOne/OneToOne as Proxies
        $this->hydrateLazyToOneRelationships($entity, $row, $metadata, $reflectionClass);

        // Wrap to-many relationship arrays in PersistentCollection
        $this->wrapCollectionRelationships($entity, $metadata, $reflectionClass);

        // Store in Identity Map if available
        if ($this->identityMap !== null) {
            $this->storeInIdentityMap($entity, $metadata);
        }

        // Register as clean in UnitOfWork so dirty checking works on subsequent save()
        if ($this->unitOfWork !== null) {
            $this->unitOfWork->registerClean($entity);
        }

        return $entity;
    }

    /**
     * Hydrates lazy to-one relationships (ManyToOne/OneToOne) using Proxy Generator.
     */
    private function hydrateLazyToOneRelationships(
        object $entity,
        array $row,
        ClassMetadata $metadata,
        ReflectionClass $reflectionClass,
    ): void {
        if ($this->proxyGenerator === null || $this->entityManager === null) {
            return; // Sin herramientas para proxies, abortar
        }

        foreach ($metadata->relationships as $relationship) {
            if ($relationship->fetch !== 'LAZY') {
                continue;
            }

            if ($relationship->type !== 'ManyToOne' && $relationship->type !== 'OneToOne') {
                continue; // Collection loader procesa los OneToMany
            }

            // Interceptar el lado inverso del OneToOne (no tiene joinColumns en esta tabla)
            if ($relationship->type === 'OneToOne' && $relationship->isInverseSide()) {
                // Al no tener FK local, debemos consultar la BD para saber si existe o debe ser null.
                $inverseEntity = $this->entityManager->getRepository($relationship->targetEntity)->findOneBy([
                    $relationship->mappedBy => $entity
                ]);
                $this->setPropertyValue($entity, $relationship->propertyName, $inverseEntity, $reflectionClass);
                continue;
            }

            // Construir la "identidad" o IDs de la base de datos a partir del diccionario de JoyColumn
            $targetIdValues = [];
            $hasValue = false;

            $targetMeta = $this->metadataReader->getClassMetadata($relationship->targetEntity);

            foreach ($relationship->joinColumns as $columnName => $referencedColumnName) {
                $rawValue = $row[$columnName] ?? null;

                // Si la DB devuelve NULL para una columna FK, la relación ManyToOne es asume no existente
                if ($rawValue === null) {
                    $targetIdValues = []; // Invalidar
                    $hasValue = false;
                    break;
                }

                $targetColumn = $targetMeta->getColumnByName($referencedColumnName);
                $targetPropertyName = $targetColumn !== null ? $targetColumn->propertyName : $referencedColumnName;

                $targetIdValues[$targetPropertyName] = $rawValue;
                $hasValue = true;
            }

            if (!$hasValue) {
                continue; // Todo es null, la relación no existe en BD
            }

            // Si es PK simple, extraemos el valor, sino dejamos el array asociativo
            $proxyId = count($targetIdValues) === 1 ? reset($targetIdValues) : $targetIdValues;

            // Emplear el Factory para crear el proxy. 
            // Closure (Initializer) pedirá al EntityManager que obtenga el objeto real.
            $em = $this->entityManager;
            $initializer = function (object $proxy) use ($em, $relationship, $proxyId): void {
                // Encontrar a través del EntityManager invocará nuevamente el Hydrator.
                // Como el proxy ya está en el IdentityMap, el Hydrator inyectará
                // los datos directamente en esta instancia del proxy en lugar de
                // crear una nueva clónica, preservando la identidad de memoria.
                $em->find($relationship->targetEntity, $proxyId);
            };

            // Crear el objeto Proxy real que actúa de señuelo en la propiedad
            $proxyInstance = $this->proxyGenerator->createProxy(
                $relationship->targetEntity,
                $initializer
            );

            // Pre-poblar los identificadores primarios en el Proxy para que si el usuario
            // usa $proxy->getId() no se vea forzado a conectarse a la Base de Datos.
            $proxyReflection = new \ReflectionClass($proxyInstance);
            if (is_array($proxyId)) {
                foreach ($proxyId as $propName => $propValue) {
                    $this->setPropertyValue($proxyInstance, $propName, $propValue, $proxyReflection);
                }
            } else {
                $idCol = $targetMeta->getIdColumn();
                if ($idCol !== null) {
                    $this->setPropertyValue($proxyInstance, $idCol->propertyName, $proxyId, $proxyReflection);
                }
            }

            // Registrar el Proxy en el IdentityMap desde el principio para que si el usuario
            // pide la entidad directamente $em->find(), reciba la MISMA instancia (Preserva Identidad)
            if ($this->identityMap !== null) {
                $this->identityMap->put($relationship->targetEntity, $proxyId, $proxyInstance);
            }

            // Finalmente, asignar el proxy a la propiedad del objeto principal

            $this->setPropertyValue($entity, $relationship->propertyName, $proxyInstance, $reflectionClass);
        }
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
        $idColumns = $metadata->getIdColumns();
        if (empty($idColumns)) {
            return null;
        }

        if (count($idColumns) === 1) {
            // Single key: existing fast path
            $idCol = $idColumns[0];
            $idValue = $row[$idCol->columnName] ?? null;
            if ($idValue === null) {
                return null;
            }
            $idValue = $this->typeCaster->toPhpValue($idValue, $idCol->type);

            return $this->identityMap->get($metadata->entityClass, $idValue);
        }

        // Composite key: build associative array
        $compositeId = [];
        foreach ($idColumns as $idCol) {
            $val = $row[$idCol->columnName] ?? null;
            if ($val === null) {
                return null;
            }
            $compositeId[$idCol->propertyName] = $this->typeCaster->toPhpValue($val, $idCol->type);
        }

        return $this->identityMap->get($metadata->entityClass, $compositeId);
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
        // Collect embedded property values grouped by embedded name
        /** @var array<string, array<string, mixed>> */
        $embeddedValues = [];

        foreach ($row as $columnName => $rawValue) {
            $column = $metadata->getColumnByName($columnName);
            if ($column === null) {
                continue;
            }

            $phpValue = ($rawValue !== null)
                ? $this->typeCaster->toPhpValue($rawValue, $column->type)
                : null;

            // Check if this is an embedded property (dot notation: "address.street")
            if (str_contains($column->propertyName, '.')) {
                [$embeddedProp, $innerProp] = explode('.', $column->propertyName, 2);
                $embeddedValues[$embeddedProp][$innerProp] = $phpValue;
            } else {
                $this->setPropertyValue($entity, $column->propertyName, $phpValue, $reflectionClass);
            }
        }

        // Hydrate embedded objects
        foreach ($metadata->embeddeds as $embedded) {
            $values = $embeddedValues[$embedded->propertyName] ?? [];
            if (empty($values)) {
                continue;
            }

            // If all values are null, don't create the embedded object
            $allNull = true;
            foreach ($values as $v) {
                if ($v !== null) {
                    $allNull = false;
                    break;
                }
            }

            if ($allNull) {
                continue;
            }

            $embReflection = $this->getReflectionClass($embedded->class);
            $embObject = $embReflection->newInstanceWithoutConstructor();

            foreach ($values as $innerProp => $value) {
                $this->setPropertyValue($embObject, $innerProp, $value, $embReflection);
            }

            $this->setPropertyValue($entity, $embedded->propertyName, $embObject, $reflectionClass);
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
     * Wraps to-many relationship properties in PersistentCollection.
     *
     * For properties that are currently arrays, wraps them in PersistentCollection::fromArray().
     * For properties that are null (lazy relationships), sets an empty PersistentCollection
     * that could be initialized later with a loader.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    private function wrapCollectionRelationships(
        object $entity,
        ClassMetadata $metadata,
        ReflectionClass $reflectionClass,
    ): void {
        foreach ($metadata->relationships as $relationship) {
            // Only wrap to-many relationships
            if ($relationship->type !== 'OneToMany' && $relationship->type !== 'ManyToMany') {
                continue;
            }

            $property = $this->getReflectionProperty($reflectionClass->getName(), $relationship->propertyName);
            if ($property === null) {
                continue;
            }

            // Don't wrap if the property is typed as 'array' — Collection
            // is not assignable to array-typed properties. Only wrap untyped or
            // Collection-typed properties.
            $propertyType = $property->getType();
            if ($propertyType instanceof \ReflectionNamedType && $propertyType->getName() === 'array') {
                continue;
            }

            $currentValue = $property->getValue($entity);

            // If already a Collection, skip
            if ($currentValue instanceof \SybaseORM\Collection\Collection) {
                continue;
            }

            if (is_array($currentValue) && !empty($currentValue)) {
                $collection = \SybaseORM\ORM\PersistentCollection::fromArray($currentValue);
            } elseif ($this->collectionLoader !== null) {
                // Set up lazy loading — the collection will load on first access
                $loader = $this->collectionLoader;
                $ownerEntity = $entity;
                $relPropName = $relationship->propertyName;
                $ownerClass = $metadata->entityClass;

                $collection = new \SybaseORM\ORM\PersistentCollection(
                    function () use ($loader, $ownerClass, $relPropName, $ownerEntity): array {
                        return ($loader)($ownerClass, $relPropName, $ownerEntity);
                    }
                );
            } else {
                // No loader available — set empty initialized collection
                $collection = \SybaseORM\ORM\PersistentCollection::fromArray([]);
            }

            $property->setValue($entity, $collection);
        }
    }

    /**
     * Stores the hydrated entity in the Identity Map.
     */
    private function storeInIdentityMap(
        object $entity,
        ClassMetadata $metadata,
    ): void {
        $idColumns = $metadata->getIdColumns();
        if (empty($idColumns)) {
            return;
        }

        $reflectionClass = $this->getReflectionClass($entity::class);

        if (count($idColumns) === 1) {
            // Single key: existing fast path
            $idCol = $idColumns[0];
            $idValue = $this->getPropertyValue($entity, $idCol->propertyName, $reflectionClass);
            if ($idValue === null) {
                return;
            }
            $this->identityMap->put($metadata->entityClass, $idValue, $entity);

            return;
        }

        // Composite key: build associative array
        $compositeId = [];
        foreach ($idColumns as $idCol) {
            $val = $this->getPropertyValue($entity, $idCol->propertyName, $reflectionClass);
            if ($val === null) {
                return;
            }
            $compositeId[$idCol->propertyName] = $val;
        }
        $this->identityMap->put($metadata->entityClass, $compositeId, $entity);
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
        $property = $this->getReflectionProperty($reflectionClass->getName(), $propertyName);
        if ($property === null) {
            return;
        }

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
        $property = $this->getReflectionProperty($reflectionClass->getName(), $propertyName);
        if ($property === null) {
            return null;
        }

        return $property->getValue($entity);
    }

    /**
     * Obtiene un ReflectionProperty cacheado para evitar recrearlo en cada hidratación.
     */
    private function getReflectionProperty(string $className, string $propertyName): ?\ReflectionProperty
    {
        if (!isset($this->reflectionPropertyCache[$className][$propertyName])) {
            $reflectionClass = $this->getReflectionClass($className);
            if (!$reflectionClass->hasProperty($propertyName)) {
                return null;
            }
            $prop = $reflectionClass->getProperty($propertyName);
            $this->reflectionPropertyCache[$className][$propertyName] = $prop;
        }

        return $this->reflectionPropertyCache[$className][$propertyName];
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

    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }
}
