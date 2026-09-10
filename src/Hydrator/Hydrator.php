<?php

declare(strict_types=1);

namespace SybaseORM\Hydrator;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\InheritanceHandler;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Proxy\LazyLoadingProxy;
use SybaseORM\Proxy\ProxyGenerator;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Converts database result rows into entity instances using the Reflection API.
 *
 * Employs a pre-computed HydrationPlan to eliminate reflection and metadata lookups
 * during iteration loops, and performs single-pass charset conversion.
 */
final class Hydrator implements HydratorInterface
{
    /** @var array<string, ReflectionClass<object>> Caché de ReflectionClass por nombre de clase */
    private array $reflectionClassCache = [];

    /** @var array<string, array<string, ReflectionProperty>> Caché de ReflectionProperty por clase y propiedad */
    private array $reflectionPropertyCache = [];

    /** @var array<string, HydrationPlan> Caché de planes de hidratación por clase de entidad */
    private array $hydrationPlanCache = [];

    /** Maximum cached ReflectionClass instances */
    private const REFLECTION_CLASS_CACHE_MAX = 256;

    /** Maximum cached classes in property cache */
    private const REFLECTION_PROPERTY_CACHE_MAX = 256;

    /** Maximum cached hydration plans */
    private const HYDRATION_PLAN_CACHE_MAX = 256;

    /** @var (callable(string $entityClass, string $propertyName, object $owner): array)|null */
    private $collectionLoader = null;

    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
        private readonly TypeCasterInterface $typeCaster,
        private readonly ?IdentityMapInterface $identityMap = null,
        private readonly ?UnitOfWorkInterface $unitOfWork = null,
        private readonly ?ProxyGenerator $proxyGenerator = null,
        private ?EntityManagerInterface $entityManager = null,
        private ?ConnectionManagerInterface $connectionManager = null,
        private readonly ?InheritanceHandler $inheritanceHandler = null,
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

    public function setConnectionManager(ConnectionManagerInterface $connectionManager): void
    {
        $this->connectionManager = $connectionManager;
    }

    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
        if ($this->connectionManager === null) {
            $this->connectionManager = $entityManager->getConnection();
        }
    }

    public function hydrate(array $row, string $entityClass): object
    {
        $plan = $this->getHydrationPlan($entityClass);
        if ($plan->metadata->inheritanceType === 'TPH' && !empty($plan->metadata->discriminatorMap)) {
            $targetClass = $this->resolveConcreteClassWithMetadata($row, $plan->metadata);
            if ($targetClass !== $entityClass) {
                $plan = $this->getHydrationPlan($targetClass);
            }
        }

        return $this->hydrateWithPlan($row, $plan, true);
    }

    public function hydrateAll(array $rows, string $entityClass): array
    {
        if (empty($rows)) {
            return [];
        }

        $plan = $this->getHydrationPlan($entityClass);
        $isTph = $plan->metadata->inheritanceType === 'TPH' && !empty($plan->metadata->discriminatorMap);

        if (!$isTph) {
            $entities = [];
            foreach ($rows as $row) {
                $entities[] = $this->hydrateWithPlan($row, $plan, true);
            }

            return $entities;
        }

        $entities = [];
        foreach ($rows as $row) {
            $targetClass = $this->resolveConcreteClassWithMetadata($row, $plan->metadata);
            $rowPlan = $targetClass === $entityClass ? $plan : $this->getHydrationPlan($targetClass);
            $entities[] = $this->hydrateWithPlan($row, $rowPlan, true);
        }

        return $entities;
    }

    /**
     * Resolves the concrete entity class using pre-resolved metadata.
     */
    private function resolveConcreteClassWithMetadata(array $row, ClassMetadata $metadata): string
    {
        if ($metadata->inheritanceType === 'TPH' && $metadata->discriminatorColumn !== null) {
            if ($this->inheritanceHandler !== null) {
                return $this->inheritanceHandler->resolveTPHClass($row, $metadata);
            }

            $discVal = $row[$metadata->discriminatorColumn] ?? null;
            if ($discVal !== null && isset($metadata->discriminatorMap[$discVal])) {
                return $metadata->discriminatorMap[$discVal];
            }
        }

        return $metadata->entityClass;
    }

    /**
     * Hydrates a single row using a pre-computed HydrationPlan.
     */
    private function hydrateWithPlan(array $row, HydrationPlan $plan, bool $convertCharset): object
    {
        if ($convertCharset && $this->connectionManager !== null) {
            $row = $this->connectionManager->convertResultRow($row);
        }

        $entity = null;

        // Check Identity Map first if available
        if ($this->identityMap !== null && !empty($plan->idColumns)) {
            $existingEntity = $this->resolveFromIdentityMapWithPlan($row, $plan);
            if ($existingEntity !== null) {
                if ($existingEntity instanceof LazyLoadingProxy && !$existingEntity->__isInitialized()) {
                    $entity = $existingEntity;
                    $entity->__setInitializer(null);
                } else {
                    return $existingEntity;
                }
            }
        }

        if ($entity === null) {
            $entity = $plan->reflectionClass->newInstanceWithoutConstructor();
        }

        // Hydrate mapped columns using pre-resolved properties in plan
        $this->hydrateColumnsWithPlan($entity, $row, $plan);

        // Store in Identity Map IMMEDIATELY after columns are hydrated
        if ($this->identityMap !== null && !empty($plan->idColumns)) {
            $this->storeInIdentityMapWithPlan($entity, $plan);
        }

        // Hydrate eager-loaded relationships
        if (!empty($plan->eagerRelationships)) {
            $this->hydrateEagerRelationshipsWithPlan($entity, $row, $plan);
        }

        // Hydrate lazy-loaded ManyToOne/OneToOne as Proxies
        if (!empty($plan->lazyToOneRelationships)) {
            $this->hydrateLazyToOneRelationshipsWithPlan($entity, $row, $plan);
        }

        // Wrap to-many relationship arrays in PersistentCollection
        if (!empty($plan->collectionRelationships)) {
            $this->wrapCollectionRelationshipsWithPlan($entity, $plan);
        }

        // Register as clean in UnitOfWork so dirty checking works on subsequent save()
        if ($this->unitOfWork !== null) {
            $this->unitOfWork->registerClean($entity);
        }

        return $entity;
    }

    /**
     * Resolves an entity from the identity map using pre-resolved PK metadata in the plan.
     */
    private function resolveFromIdentityMapWithPlan(array $row, HydrationPlan $plan): ?object
    {
        if (count($plan->idColumns) === 1) {
            $idCol = $plan->idColumns[0];
            $idValue = $row[$idCol['columnName']] ?? null;
            if ($idValue === null) {
                return null;
            }
            $idValue = $this->typeCaster->toPhpValue($idValue, $idCol['type']);

            return $this->identityMap?->get($plan->metadata->entityClass, $idValue);
        }

        $compositeId = [];
        foreach ($plan->idColumns as $idCol) {
            $val = $row[$idCol['columnName']] ?? null;
            if ($val === null) {
                return null;
            }
            $compositeId[$idCol['propertyName']] = $this->typeCaster->toPhpValue($val, $idCol['type']);
        }

        return $this->identityMap?->get($plan->metadata->entityClass, $compositeId);
    }

    /**
     * Stores an entity in the identity map using pre-resolved PK reflection properties in the plan.
     */
    private function storeInIdentityMapWithPlan(object $entity, HydrationPlan $plan): void
    {
        if (count($plan->idColumns) === 1) {
            $idCol = $plan->idColumns[0];
            if ($idCol['property'] !== null) {
                $idValue = $idCol['property']->getValue($entity);
                if ($idValue !== null) {
                    $this->identityMap?->put($plan->metadata->entityClass, $idValue, $entity);
                }
            }

            return;
        }

        $compositeId = [];
        foreach ($plan->idColumns as $idCol) {
            if ($idCol['property'] === null) {
                return;
            }
            $val = $idCol['property']->getValue($entity);
            if ($val === null) {
                return;
            }
            $compositeId[$idCol['propertyName']] = $val;
        }

        $this->identityMap?->put($plan->metadata->entityClass, $compositeId, $entity);
    }

    /**
     * Hydrates the entity's mapped columns from row data using the pre-computed plan.
     */
    private function hydrateColumnsWithPlan(
        object $entity,
        array $row,
        HydrationPlan $plan,
    ): void {
        $embeddedValues = [];

        foreach ($row as $columnName => $rawValue) {
            $col = $plan->columnMap[$columnName] ?? null;
            if ($col === null) {
                continue;
            }

            $phpValue = ($rawValue !== null)
                ? $this->typeCaster->toPhpValue($rawValue, $col['type'])
                : null;

            if ($col['isDate'] && $phpValue instanceof DateTimeInterface) {
                if ($col['dateTargetType'] === DateTimeImmutable::class && $phpValue instanceof DateTime) {
                    $phpValue = DateTimeImmutable::createFromInterface($phpValue);
                } elseif ($col['dateTargetType'] === DateTime::class && $phpValue instanceof DateTimeImmutable) {
                    $phpValue = DateTime::createFromInterface($phpValue);
                }
            }

            if ($col['isEmbedded']) {
                $embeddedValues[$col['embeddedProp']][$col['innerProp']] = $phpValue;
            } elseif ($col['property'] !== null) {
                $col['property']->setValue($entity, $phpValue);
            }
        }

        // Hydrate embedded objects if configured
        if (!empty($plan->embeddedMap) && !empty($embeddedValues)) {
            foreach ($plan->embeddedMap as $embPropName => $embMeta) {
                $values = $embeddedValues[$embPropName] ?? [];
                if (empty($values)) {
                    continue;
                }

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

                $embObject = $embMeta['reflection']->newInstanceWithoutConstructor();
                foreach ($values as $innerProp => $value) {
                    $prop = $embMeta['innerProperties'][$innerProp] ?? null;
                    if ($prop !== null) {
                        $prop->setValue($embObject, $value);
                    }
                }

                if ($embMeta['property'] !== null) {
                    $embMeta['property']->setValue($entity, $embObject);
                }
            }
        }
    }

    /**
     * Hydrates eager-loaded relationships from prefixed columns in the row.
     */
    private function hydrateEagerRelationshipsWithPlan(
        object $entity,
        array $row,
        HydrationPlan $plan,
    ): void {
        foreach ($plan->eagerRelationships as $relationship) {
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

            // Hydrate related entity without re-converting charset (parent row already converted)
            $relatedPlan = $this->getHydrationPlan($relationship->targetEntity);
            $relatedEntity = $this->hydrateWithPlan($relatedRow, $relatedPlan, false);

            $this->setPropertyValue($entity, $relationship->propertyName, $relatedEntity, $plan->reflectionClass);
        }
    }

    /**
     * Hydrates lazy to-one relationships (ManyToOne/OneToOne) using Proxy Generator.
     */
    private function hydrateLazyToOneRelationshipsWithPlan(
        object $entity,
        array $row,
        HydrationPlan $plan,
    ): void {
        foreach ($plan->lazyToOneRelationships as $relationship) {
            // Interceptar el lado inverso del OneToOne (no tiene joinColumns en esta tabla)
            if ($relationship->type === 'OneToOne' && $relationship->isInverseSide()) {
                if ($this->entityManager === null) {
                    continue;
                }
                $inverseEntity = $this->entityManager->getRepository($relationship->targetEntity)->findOneBy([
                    $relationship->mappedBy => $entity,
                ]);
                $this->setPropertyValue($entity, $relationship->propertyName, $inverseEntity, $plan->reflectionClass);
                continue;
            }

            // Construir la "identidad" o IDs de la base de datos a partir del diccionario de JoinColumn
            $targetIdValues = [];
            $hasValue = false;

            $targetPlan = $this->getHydrationPlan($relationship->targetEntity);

            foreach ($relationship->joinColumns as $columnName => $referencedColumnName) {
                $rawValue = $row[$columnName] ?? null;

                if ($rawValue === null) {
                    $targetIdValues = [];
                    $hasValue = false;
                    break;
                }

                $targetColumn = $targetPlan->metadata->getColumnByName($referencedColumnName);
                $targetPropertyName = $targetColumn !== null ? $targetColumn->propertyName : $referencedColumnName;

                $phpValue = ($targetColumn !== null)
                    ? $this->typeCaster->toPhpValue($rawValue, $targetColumn->type)
                    : $rawValue;

                $targetIdValues[$targetPropertyName] = $phpValue;
                $hasValue = true;
            }

            if (!$hasValue) {
                continue;
            }

            $proxyId = count($targetIdValues) === 1 ? reset($targetIdValues) : $targetIdValues;

            if ($this->identityMap !== null) {
                $existing = $this->identityMap->get($relationship->targetEntity, $proxyId);
                if ($existing !== null) {
                    $this->setPropertyValue($entity, $relationship->propertyName, $existing, $plan->reflectionClass);
                    continue;
                }
            }

            if ($this->proxyGenerator === null || $this->entityManager === null) {
                continue;
            }

            $em = $this->entityManager;
            $metaReader = $this->metadataReader;
            $hydrator = $this;
            $identityMap = $this->identityMap;
            $initializer = function (object $proxy) use ($em, $relationship, $proxyId, $metaReader, $hydrator, $identityMap): void {
                if ($identityMap !== null) {
                    $identityMap->remove($relationship->targetEntity, $proxyId);
                }

                $real = $em->find($relationship->targetEntity, $proxyId);

                if ($identityMap !== null) {
                    $identityMap->put($relationship->targetEntity, $proxyId, $proxy);
                }

                if ($real === null || $real === $proxy) {
                    return;
                }

                $targetMeta = $metaReader->getClassMetadata($relationship->targetEntity);
                $targetReflection = $hydrator->getReflectionClass($relationship->targetEntity);

                foreach ($targetMeta->columns as $column) {
                    if (str_contains($column->propertyName, '.')) {
                        continue;
                    }
                    $prop = $targetReflection->getProperty($column->propertyName);
                    $prop->setValue($proxy, $prop->getValue($real));
                }

                foreach ($targetMeta->embeddeds as $embedded) {
                    $prop = $targetReflection->getProperty($embedded->propertyName);
                    $prop->setValue($proxy, $prop->getValue($real));
                }
            };

            $proxyInstance = $this->proxyGenerator->createProxy(
                $relationship->targetEntity,
                $initializer
            );

            $targetReflection = $this->getReflectionClass($relationship->targetEntity);
            if (is_array($proxyId)) {
                foreach ($proxyId as $propName => $propValue) {
                    $this->setPropertyValue($proxyInstance, $propName, $propValue, $targetReflection);
                }
            } else {
                $idCol = $targetPlan->metadata->getIdColumn();
                if ($idCol !== null) {
                    $this->setPropertyValue($proxyInstance, $idCol->propertyName, $proxyId, $targetReflection);
                }
            }

            if ($this->identityMap !== null) {
                $this->identityMap->put($relationship->targetEntity, $proxyId, $proxyInstance);
            }

            $this->setPropertyValue($entity, $relationship->propertyName, $proxyInstance, $plan->reflectionClass);
        }
    }

    /**
     * Wraps to-many relationship properties in PersistentCollection.
     */
    private function wrapCollectionRelationshipsWithPlan(
        object $entity,
        HydrationPlan $plan,
    ): void {
        foreach ($plan->collectionRelationships as $relationship) {
            $property = $this->getReflectionProperty($plan->reflectionClass->getName(), $relationship->propertyName);
            if ($property === null) {
                continue;
            }

            $propertyType = $property->getType();
            if ($propertyType instanceof ReflectionNamedType && $propertyType->getName() === 'array') {
                continue;
            }

            $currentValue = $property->getValue($entity);

            if ($currentValue instanceof \SybaseORM\Collection\Collection) {
                continue;
            }

            if (is_array($currentValue) && !empty($currentValue)) {
                $collection = \SybaseORM\ORM\PersistentCollection::fromArray($currentValue);
            } elseif ($this->collectionLoader !== null) {
                $loader = $this->collectionLoader;
                $ownerEntity = $entity;
                $relPropName = $relationship->propertyName;
                $ownerClass = $plan->metadata->entityClass;

                $collection = new \SybaseORM\ORM\PersistentCollection(
                    function () use ($loader, $ownerClass, $relPropName, $ownerEntity): array {
                        return ($loader)($ownerClass, $relPropName, $ownerEntity);
                    }
                );
            } else {
                $collection = \SybaseORM\ORM\PersistentCollection::fromArray([]);
            }

            $property->setValue($entity, $collection);
        }
    }

    /**
     * Pre-computes or retrieves a cached HydrationPlan for the entity class.
     */
    public function getHydrationPlan(string $entityClass): HydrationPlan
    {
        if (isset($this->hydrationPlanCache[$entityClass])) {
            return $this->hydrationPlanCache[$entityClass];
        }

        $metadata = $this->metadataReader->getClassMetadata($entityClass);
        $reflectionClass = $this->getReflectionClass($entityClass);

        $columnMap = [];
        foreach ($metadata->columns as $column) {
            $isEmbedded = str_contains($column->propertyName, '.');
            $embeddedProp = null;
            $innerProp = null;
            $property = null;
            $isDate = false;
            $dateTargetType = null;

            if ($isEmbedded) {
                [$embeddedProp, $innerProp] = explode('.', $column->propertyName, 2);
            } else {
                $property = $this->getReflectionProperty($entityClass, $column->propertyName);
                if ($property !== null) {
                    $type = $property->getType();
                    if ($type instanceof ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === DateTimeImmutable::class || $typeName === DateTime::class || is_subclass_of($typeName, DateTimeInterface::class)) {
                            $isDate = true;
                            $dateTargetType = $typeName;
                        }
                    }
                }
            }

            $columnMap[$column->columnName] = [
                'propertyName' => $column->propertyName,
                'type' => $column->type,
                'property' => $property,
                'isDate' => $isDate,
                'dateTargetType' => $dateTargetType,
                'isEmbedded' => $isEmbedded,
                'embeddedProp' => $embeddedProp,
                'innerProp' => $innerProp,
            ];
        }

        $embeddedMap = [];
        foreach ($metadata->embeddeds as $embedded) {
            $embReflection = $this->getReflectionClass($embedded->class);
            $embProp = $this->getReflectionProperty($entityClass, $embedded->propertyName);
            $innerProps = [];
            foreach ($columnMap as $col) {
                if ($col['isEmbedded'] && $col['embeddedProp'] === $embedded->propertyName && $col['innerProp'] !== null) {
                    $innerProps[$col['innerProp']] = $this->getReflectionProperty($embedded->class, $col['innerProp']);
                }
            }

            $embeddedMap[$embedded->propertyName] = [
                'class' => $embedded->class,
                'reflection' => $embReflection,
                'property' => $embProp,
                'innerProperties' => $innerProps,
            ];
        }

        $idColumns = [];
        foreach ($metadata->getIdColumns() as $idCol) {
            $idColumns[] = [
                'columnName' => $idCol->columnName,
                'propertyName' => $idCol->propertyName,
                'type' => $idCol->type,
                'property' => $this->getReflectionProperty($entityClass, $idCol->propertyName),
            ];
        }

        $eagerRelationships = [];
        $lazyToOneRelationships = [];
        $collectionRelationships = [];

        foreach ($metadata->relationships as $relationship) {
            if ($relationship->fetch === 'EAGER') {
                $eagerRelationships[] = $relationship;
            }
            if ($relationship->fetch === 'LAZY' && ($relationship->type === 'ManyToOne' || $relationship->type === 'OneToOne')) {
                $lazyToOneRelationships[] = $relationship;
            }
            if ($relationship->type === 'OneToMany' || $relationship->type === 'ManyToMany') {
                $collectionRelationships[] = $relationship;
            }
        }

        $plan = new HydrationPlan(
            metadata: $metadata,
            reflectionClass: $reflectionClass,
            columnMap: $columnMap,
            embeddedMap: $embeddedMap,
            idColumns: $idColumns,
            eagerRelationships: $eagerRelationships,
            lazyToOneRelationships: $lazyToOneRelationships,
            collectionRelationships: $collectionRelationships,
        );

        if (count($this->hydrationPlanCache) >= self::HYDRATION_PLAN_CACHE_MAX) {
            $oldestKey = array_key_first($this->hydrationPlanCache);
            if ($oldestKey !== null) {
                unset($this->hydrationPlanCache[$oldestKey]);
            }
        }

        $this->hydrationPlanCache[$entityClass] = $plan;

        return $plan;
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

        if ($value instanceof DateTimeInterface) {
            $type = $property->getType();
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
                if ($typeName === DateTimeImmutable::class && $value instanceof DateTime) {
                    $value = DateTimeImmutable::createFromInterface($value);
                } elseif ($typeName === DateTime::class && $value instanceof DateTimeImmutable) {
                    $value = DateTime::createFromInterface($value);
                }
            }
        }

        $property->setValue($entity, $value);
    }


    /**
     * Obtiene un ReflectionProperty cacheado para evitar recrearlo en cada hidratación.
     */
    private function getReflectionProperty(string $className, string $propertyName): ?ReflectionProperty
    {
        if (!isset($this->reflectionPropertyCache[$className][$propertyName])) {
            if (count($this->reflectionPropertyCache) >= self::REFLECTION_PROPERTY_CACHE_MAX && !isset($this->reflectionPropertyCache[$className])) {
                $oldestKey = array_key_first($this->reflectionPropertyCache);
                if ($oldestKey !== null) {
                    unset($this->reflectionPropertyCache[$oldestKey]);
                }
            }

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
     * Returns a cached ReflectionClass instance with LRU eviction.
     *
     * @param class-string $entityClass
     * @return ReflectionClass<object>
     */
    public function getReflectionClass(string $entityClass): ReflectionClass
    {
        if (!isset($this->reflectionClassCache[$entityClass])) {
            if (count($this->reflectionClassCache) >= self::REFLECTION_CLASS_CACHE_MAX) {
                $oldestKey = array_key_first($this->reflectionClassCache);
                if ($oldestKey !== null) {
                    unset($this->reflectionClassCache[$oldestKey]);
                }
            }

            $this->reflectionClassCache[$entityClass] = new ReflectionClass($entityClass);
        }

        return $this->reflectionClassCache[$entityClass];
    }
}
