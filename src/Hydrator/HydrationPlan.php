<?php

declare(strict_types=1);

namespace SybaseORM\Hydrator;

use ReflectionClass;
use ReflectionProperty;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\RelationshipMetadata;

/**
 * Pre-computed execution plan for hydrating entity instances.
 * Eliminates repeated metadata lookups, reflection property lookups, and type checks during hydration loops.
 */
final class HydrationPlan
{
    /**
     * @param ClassMetadata $metadata
     * @param ReflectionClass<object> $reflectionClass
     * @param array<string, array{
     *     propertyName: string,
     *     type: string,
     *     property: ?ReflectionProperty,
     *     isDate: bool,
     *     dateTargetType: ?string,
     *     isEmbedded: bool,
     *     embeddedProp: ?string,
     *     innerProp: ?string
     * }> $columnMap Map of columnName => column plan details
     * @param array<string, array{
     *     class: string,
     *     reflection: ReflectionClass<object>,
     *     property: ?ReflectionProperty,
     *     innerProperties: array<string, ?ReflectionProperty>
     * }> $embeddedMap Map of embedded property name => embedded plan details
     * @param array<int, array{
     *     columnName: string,
     *     propertyName: string,
     *     type: string,
     *     property: ?ReflectionProperty
     * }> $idColumns Pre-resolved identity columns
     * @param RelationshipMetadata[] $eagerRelationships Eager relationships to hydrate
     * @param RelationshipMetadata[] $lazyToOneRelationships Lazy ManyToOne/OneToOne relationships
     * @param RelationshipMetadata[] $collectionRelationships To-many collection relationships
     */
    public function __construct(
        public readonly ClassMetadata $metadata,
        public readonly ReflectionClass $reflectionClass,
        public readonly array $columnMap,
        public readonly array $embeddedMap,
        public readonly array $idColumns,
        public readonly array $eagerRelationships,
        public readonly array $lazyToOneRelationships,
        public readonly array $collectionRelationships,
    ) {}
}
