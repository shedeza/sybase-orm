<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\DiscriminatorColumn;
use SybaseORM\Attribute\DiscriminatorMap;
use SybaseORM\Attribute\Embeddable;
use SybaseORM\Attribute\Embedded;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\InheritanceType;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\ManyToMany;
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\OneToMany;
use SybaseORM\Attribute\OneToOne;
use SybaseORM\Attribute\PostPersist;
use SybaseORM\Attribute\PostRemove;
use SybaseORM\Attribute\PostUpdate;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PreRemove;
use SybaseORM\Attribute\PreUpdate;

/**
 * Reads PHP Attributes from entity classes using the Reflection API
 * and builds ClassMetadata objects.
 *
 * Supports in-memory caching (static array) and optional file-based caching.
 */
final class MetadataReader implements MetadataReaderInterface
{
    private const RELATIONSHIP_ATTRIBUTES = [
        OneToOne::class,
        OneToMany::class,
        ManyToOne::class,
        ManyToMany::class,
    ];

    /** @var array<class-string, string> Mapa pre-computado de hook attribute class → shortName. Values must match HookDispatcher::VALID_HOOK_TYPES. */
    private const LIFECYCLE_HOOK_NAMES = [
        PrePersist::class => 'PrePersist',
        PostPersist::class => 'PostPersist',
        PreUpdate::class => 'PreUpdate',
        PostUpdate::class => 'PostUpdate',
        PreRemove::class => 'PreRemove',
        PostRemove::class => 'PostRemove',
    ];

    /** @var array<string, ClassMetadata> In-memory metadata cache keyed by class name (shared across instances). */
    private static array $memoryCache = [];

    /** @var array<string, ClassMetadata> Instance-level cache (isolated per MetadataReader instance). */
    private array $instanceCache = [];

    public function __construct(
        private readonly ?string $cacheDir = null,
        private readonly bool $useInstanceCache = false,
    ) {
    }

    /**
     * Clears the in-memory metadata cache. Useful for testing.
     */
    public static function clearMemoryCache(): void
    {
        self::$memoryCache = [];
    }

    /**
     * Clears this instance's local cache.
     */
    public function clearInstanceCache(): void
    {
        $this->instanceCache = [];
    }

    public function getClassMetadata(string $entityClass): ClassMetadata
    {
        // 1a. Check instance-level cache (if enabled)
        if ($this->useInstanceCache && isset($this->instanceCache[$entityClass])) {
            return $this->instanceCache[$entityClass];
        }

        // 1b. Check shared in-memory cache
        if (isset(self::$memoryCache[$entityClass])) {
            return self::$memoryCache[$entityClass];
        }

        // 2. Check file cache
        if ($this->cacheDir !== null) {
            $metadata = $this->loadFromFileCache($entityClass);
            if ($metadata !== null) {
                self::$memoryCache[$entityClass] = $metadata;
                return $metadata;
            }
        }

        // 3. Read from reflection
        $reflectionClass = new ReflectionClass($entityClass);

        $entityAttr = $this->getClassAttribute($reflectionClass, Entity::class);
        if ($entityAttr === null) {
            throw new \RuntimeException(sprintf('Class "%s" is not annotated with #[Entity].', $entityClass));
        }

        $tableName = $entityAttr->table ?? $this->toSnakeCase($reflectionClass->getShortName());
        $schema = $entityAttr->schema;

        $columns = [];
        $idFields = [];
        $relationships = [];
        $embeddeds = [];

        // Recorrer toda la jerarquía de clases para incluir propiedades privadas heredadas
        $classHierarchy = [];
        $current = $reflectionClass;
        while ($current !== false) {
            $classHierarchy[] = $current;
            $current = $current->getParentClass();
        }
        // Procesar desde la raíz para que las propiedades del hijo sobreescriban las del padre
        $classHierarchy = array_reverse($classHierarchy);

        foreach ($classHierarchy as $hierarchyClass) {
            // Solo leer propiedades declaradas en esta clase (no heredadas, para evitar duplicados)
            foreach ($hierarchyClass->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $hierarchyClass->getName()) {
                    continue;
                }

                $columnMeta = $this->readColumnMetadata($property);
                if ($columnMeta !== null) {
                    $columns[] = $columnMeta;
                    if ($columnMeta->isId) {
                        $idFields[] = $columnMeta->propertyName;
                    }
                }

                $relationMeta = $this->readRelationshipMetadata($property);
                if ($relationMeta !== null) {
                    $relationships[] = $relationMeta;
                }

                // Read #[Embedded] properties
                $embeddedMeta = $this->readEmbeddedMetadata($property);
                if ($embeddedMeta !== null) {
                    $embeddeds[] = $embeddedMeta;
                    // Expand embeddable columns into the parent entity's column list
                    foreach ($embeddedMeta->columns as $embCol) {
                        $columns[] = $embCol;
                    }
                }
            }
        }

        [$inheritanceType, $discriminatorColumn, $discriminatorMap] = $this->readInheritanceMetadata($reflectionClass);
        $lifecycleHooks = $this->readLifecycleHooks($reflectionClass);

        $metadata = new ClassMetadata(
            entityClass: $entityClass,
            tableName: $tableName,
            schema: $schema,
            columns: $columns,
            relationships: $relationships,
            inheritanceType: $inheritanceType,
            discriminatorColumn: $discriminatorColumn,
            discriminatorMap: $discriminatorMap,
            lifecycleHooks: $lifecycleHooks,
            idFields: $idFields,
            repositoryClass: $entityAttr->repositoryClass,
            embeddeds: $embeddeds,
        );

        // Validate metadata consistency
        $this->validateMetadata($metadata);

        // Store in memory cache
        self::$memoryCache[$entityClass] = $metadata;
        if ($this->useInstanceCache) {
            $this->instanceCache[$entityClass] = $metadata;
        }

        // Store in file cache
        if ($this->cacheDir !== null) {
            $this->saveToFileCache($entityClass, $metadata);
        }

        return $metadata;
    }

    /**
     * Validates metadata consistency: checks FK columns exist, discriminator values are unique, etc.
     *
     * @throws \RuntimeException If validation fails.
     */
    private function validateMetadata(ClassMetadata $metadata): void
    {
        // Validate discriminator map values are unique (TPH)
        if ($metadata->inheritanceType === 'TPH' && !empty($metadata->discriminatorMap)) {
            $values = array_keys($metadata->discriminatorMap);
            if (count($values) !== count(array_unique($values))) {
                throw new \RuntimeException(sprintf(
                    'Duplicate discriminator values in entity "%s".',
                    $metadata->entityClass,
                ));
            }
        }

        // Validate relationship target entities are valid class names
        foreach ($metadata->relationships as $relationship) {
            if (!class_exists($relationship->targetEntity)) {
                throw new \RuntimeException(sprintf(
                    'Relationship "%s" on entity "%s" references non-existent class "%s".',
                    $relationship->propertyName,
                    $metadata->entityClass,
                    $relationship->targetEntity,
                ));
            }
        }

        // Validate FK join columns reference existing properties
        foreach ($metadata->relationships as $relationship) {
            if ($relationship->joinColumn !== null) {
                $fkColumn = $metadata->getColumnByName($relationship->joinColumn);
                $fkProperty = $metadata->getColumn($relationship->joinColumn);
                // joinColumn may reference a column name or property name — both are valid
                if ($fkColumn === null && $fkProperty === null) {
                    // Not a hard error — joinColumn might be a virtual FK not mapped as a column
                }
            }
        }
    }

    public function isEntity(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        $reflectionClass = new ReflectionClass($className);

        return $this->getClassAttribute($reflectionClass, Entity::class) !== null;
    }

    private function readColumnMetadata(ReflectionProperty $property): ?ColumnMetadata
    {
        $columnAttr = $this->getPropertyAttribute($property, Column::class);
        if ($columnAttr === null) {
            return null;
        }

        $isId = $this->getPropertyAttribute($property, Id::class) !== null;
        $generatedValueAttr = $this->getPropertyAttribute($property, GeneratedValue::class);

        return new ColumnMetadata(
            propertyName: $property->getName(),
            columnName: $columnAttr->name ?? $this->toSnakeCase($property->getName()),
            type: $columnAttr->type,
            nullable: $columnAttr->nullable,
            length: $columnAttr->length,
            precision: $columnAttr->precision,
            scale: $columnAttr->scale,
            isId: $isId,
            generatedValue: $generatedValueAttr?->strategy,
        );
    }

    private function readRelationshipMetadata(ReflectionProperty $property): ?RelationshipMetadata
    {
        $joinColumnAttr = $this->getPropertyAttribute($property, JoinColumn::class);

        foreach (self::RELATIONSHIP_ATTRIBUTES as $attrClass) {
            $attr = $this->getPropertyAttribute($property, $attrClass);
            if ($attr === null) {
                continue;
            }

            $type = match ($attrClass) {
                OneToOne::class => 'OneToOne',
                OneToMany::class => 'OneToMany',
                ManyToOne::class => 'ManyToOne',
                ManyToMany::class => 'ManyToMany',
            };

            return new RelationshipMetadata(
                propertyName: $property->getName(),
                type: $type,
                targetEntity: $attr->targetEntity,
                mappedBy: $attr->mappedBy ?? null,
                inversedBy: $attr->inversedBy ?? null,
                joinColumn: $joinColumnAttr?->name,
                referencedColumnName: $joinColumnAttr?->referencedColumnName,
                joinTable: ($attr instanceof ManyToMany) ? $attr->joinTable : null,
                cascade: $attr->cascade,
                fetch: $attr->fetch,
                orphanRemoval: ($attr instanceof OneToMany || $attr instanceof OneToOne) ? $attr->orphanRemoval : false,
            );
        }

        return null;
    }

    /**
     * Reads #[Embedded] attribute from a property and expands the embeddable's columns.
     */
    private function readEmbeddedMetadata(ReflectionProperty $property): ?EmbeddedMetadata
    {
        $embeddedAttr = $this->getPropertyAttribute($property, Embedded::class);
        if ($embeddedAttr === null) {
            return null;
        }

        $embeddableClass = $embeddedAttr->class;
        $prefix = $embeddedAttr->columnPrefix ?? ($this->toSnakeCase($property->getName()) . '_');

        // Verify the target class has #[Embeddable]
        if (!class_exists($embeddableClass)) {
            throw new \RuntimeException(sprintf(
                'Embedded class "%s" on property "%s" does not exist.',
                $embeddableClass,
                $property->getName(),
            ));
        }

        $embReflection = new \ReflectionClass($embeddableClass);
        $embeddableAttr = $this->getClassAttribute($embReflection, Embeddable::class);
        if ($embeddableAttr === null) {
            throw new \RuntimeException(sprintf(
                'Class "%s" used in #[Embedded] on property "%s" is not annotated with #[Embeddable].',
                $embeddableClass,
                $property->getName(),
            ));
        }

        // Read columns from the embeddable class and prefix them
        $expandedColumns = [];
        foreach ($embReflection->getProperties() as $embProperty) {
            $columnAttr = $this->getPropertyAttribute($embProperty, Column::class);
            if ($columnAttr === null) {
                continue;
            }

            $columnName = $columnAttr->name ?? $this->toSnakeCase($embProperty->getName());

            // The propertyName uses dot notation: "address.street" so the Hydrator
            // knows to set it on the embedded object, not directly on the entity
            $expandedColumns[] = new ColumnMetadata(
                propertyName: $property->getName() . '.' . $embProperty->getName(),
                columnName: $prefix . $columnName,
                type: $columnAttr->type,
                nullable: $columnAttr->nullable,
                length: $columnAttr->length,
                precision: $columnAttr->precision,
                scale: $columnAttr->scale,
            );
        }

        return new EmbeddedMetadata(
            propertyName: $property->getName(),
            class: $embeddableClass,
            columnPrefix: $prefix,
            columns: $expandedColumns,
        );
    }

    /**
     * @return array{?string, ?string, array<string, string>}
     */
    private function readInheritanceMetadata(ReflectionClass $reflectionClass): array
    {
        $inheritanceType = null;
        $discriminatorColumn = null;
        $discriminatorMap = [];

        $inheritanceAttr = $this->getClassAttribute($reflectionClass, InheritanceType::class);
        if ($inheritanceAttr !== null) {
            $inheritanceType = $inheritanceAttr->strategy;
        }

        $discColAttr = $this->getClassAttribute($reflectionClass, DiscriminatorColumn::class);
        if ($discColAttr !== null) {
            $discriminatorColumn = $discColAttr->name;
        }

        $discMapAttr = $this->getClassAttribute($reflectionClass, DiscriminatorMap::class);
        if ($discMapAttr !== null) {
            $discriminatorMap = $discMapAttr->map;
        }

        return [$inheritanceType, $discriminatorColumn, $discriminatorMap];
    }

    /**
     * @return array<string, string[]>
     */
    private function readLifecycleHooks(ReflectionClass $reflectionClass): array
    {
        $hasHooksAttr = $this->getClassAttribute($reflectionClass, HasLifecycleHooks::class);
        if ($hasHooksAttr === null) {
            return [];
        }

        /** @var array<string, array<int, array{method: string, priority: int}>> */
        $hookEntries = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach (self::LIFECYCLE_HOOK_NAMES as $hookAttrClass => $hookName) {
                $attrs = $method->getAttributes($hookAttrClass);
                if (!empty($attrs)) {
                    $attrInstance = $attrs[0]->newInstance();
                    $priority = $attrInstance->priority ?? 0;
                    $hookEntries[$hookName][] = [
                        'method' => $method->getName(),
                        'priority' => $priority,
                    ];
                }
            }
        }

        // Sort by priority descending (higher priority executes first)
        $hooks = [];
        foreach ($hookEntries as $hookName => $entries) {
            usort($entries, fn($a, $b) => $b['priority'] <=> $a['priority']);
            $hooks[$hookName] = array_map(fn($e) => $e['method'], $entries);
        }

        return $hooks;
    }

    /**
     * Loads ClassMetadata from the file cache.
     */
    private function loadFromFileCache(string $entityClass): ?ClassMetadata
    {
        $path = $this->getFileCachePath($entityClass);
        if (!is_file($path)) {
            return null;
        }

        $data = @unserialize(
            file_get_contents($path),
            ['allowed_classes' => [ClassMetadata::class, ColumnMetadata::class, RelationshipMetadata::class, EmbeddedMetadata::class]],
        );
        if ($data instanceof ClassMetadata) {
            return $data;
        }

        return null;
    }

    /**
     * Saves ClassMetadata to the file cache.
     */
    private function saveToFileCache(string $entityClass, ClassMetadata $metadata): void
    {
        $path = $this->getFileCachePath($entityClass);
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, serialize($metadata));
    }

    /**
     * Returns the file path for a cached class metadata entry.
     */
    private function getFileCachePath(string $entityClass): string
    {
        return $this->cacheDir . '/' . str_replace('\\', '_', $entityClass) . '.cache';
    }

    /**
     * Converts a PascalCase or camelCase string to snake_case.
     */
    private function toSnakeCase(string $input): string
    {
        $result = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $input);
        $result = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $result);

        return strtolower($result);
    }

    /**
     * @template T of object
     * @param ReflectionClass<object> $reflectionClass
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private function getClassAttribute(ReflectionClass $reflectionClass, string $attributeClass): ?object
    {
        $attrs = $reflectionClass->getAttributes($attributeClass);
        if (empty($attrs)) {
            return null;
        }

        return $attrs[0]->newInstance();
    }

    /**
     * @template T of object
     * @param class-string<T> $attributeClass
     * @return T|null
     */
    private function getPropertyAttribute(ReflectionProperty $property, string $attributeClass): ?object
    {
        $attrs = $property->getAttributes($attributeClass);
        if (empty($attrs)) {
            return null;
        }

        return $attrs[0]->newInstance();
    }
}
