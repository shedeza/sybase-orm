<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Value object representing the metadata of an embedded value object property.
 *
 * Stores the mapping between an entity property and an embeddable class,
 * including the column prefix used to map the embeddable's columns.
 */
final class EmbeddedMetadata
{
    /**
     * @param string $propertyName   The property name on the parent entity
     * @param string $class          Fully qualified class name of the embeddable
     * @param string $columnPrefix   Column name prefix (e.g. 'address_')
     * @param ColumnMetadata[] $columns The expanded columns from the embeddable
     */
    public function __construct(
        public readonly string $propertyName,
        public readonly string $class,
        public readonly string $columnPrefix,
        public readonly array $columns = [],
    ) {}
}
