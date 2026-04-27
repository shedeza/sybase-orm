<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Value object holding the complete mapping metadata for an entity class.
 */
final class ClassMetadata
{
    /** @var array<string, ColumnMetadata> Mapa indexado propertyName → ColumnMetadata */
    private array $columnsByProperty = [];

    /** @var array<string, ColumnMetadata> Mapa indexado columnName → ColumnMetadata */
    private array $columnsByName = [];

    /** @var string[] Property names of all primary key fields */
    public readonly array $idFields;

    public readonly ?string $idField;

    /**
     * @param string                $entityClass        Fully qualified class name
     * @param string                $tableName          Database table name
     * @param string|null           $schema             Database schema name (e.g. 'dbo')
     * @param ColumnMetadata[]      $columns            Mapped columns
     * @param string|null           $idField            Property name of the primary key (backward compat)
     * @param RelationshipMetadata[] $relationships     Mapped relationships
     * @param string|null           $inheritanceType    TPH, TPT, TPC or null
     * @param string|null           $discriminatorColumn Discriminator column name
     * @param array<string, string> $discriminatorMap   Discriminator value => class map
     * @param array<string, string[]> $lifecycleHooks   Hook type => method names
     * @param string[]              $idFields           Property names of all primary key fields
     * @param string|null           $repositoryClass    Custom repository class FQCN
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly string $tableName,
        public readonly ?string $schema = null,
        public readonly array $columns = [],
        ?string $idField = null,
        public readonly array $relationships = [],
        public readonly ?string $inheritanceType = null,
        public readonly ?string $discriminatorColumn = null,
        public readonly array $discriminatorMap = [],
        public readonly array $lifecycleHooks = [],
        array $idFields = [],
        public readonly ?string $repositoryClass = null,
    ) {
        // Compute idFields and idField for backward compatibility
        if ($idFields !== []) {
            $this->idFields = $idFields;
            $this->idField = $idFields[0];
        } elseif ($idField !== null) {
            $this->idFields = [$idField];
            $this->idField = $idField;
        } else {
            $this->idFields = [];
            $this->idField = null;
        }

        // Pre-computar mapas indexados para búsqueda O(1)
        foreach ($this->columns as $column) {
            $this->columnsByProperty[$column->propertyName] = $column;
            $this->columnsByName[$column->columnName] = $column;
        }
    }

    /**
     * Returns the fully qualified table name including schema if present.
     * E.g. "dbo.users" or just "users" if no schema.
     */
    public function getQualifiedTableName(): string
    {
        if ($this->schema !== null) {
            return $this->schema . '.' . $this->tableName;
        }

        return $this->tableName;
    }

    /**
     * Returns the ColumnMetadata for a given property name, or null if not found. O(1).
     */
    public function getColumn(string $propertyName): ?ColumnMetadata
    {
        return $this->columnsByProperty[$propertyName] ?? null;
    }

    /**
     * Returns the ColumnMetadata for a given column name, or null if not found. O(1).
     */
    public function getColumnByName(string $columnName): ?ColumnMetadata
    {
        return $this->columnsByName[$columnName] ?? null;
    }

    /**
     * Returns ColumnMetadata[] for all primary key fields.
     * @return ColumnMetadata[]
     */
    public function getIdColumns(): array
    {
        $idColumns = [];
        foreach ($this->idFields as $fieldName) {
            $column = $this->getColumn($fieldName);
            if ($column !== null) {
                $idColumns[] = $column;
            }
        }

        return $idColumns;
    }

    /**
     * Returns the ColumnMetadata for the primary key, or null if not set.
     */
    public function getIdColumn(): ?ColumnMetadata
    {
        $idColumns = $this->getIdColumns();

        return $idColumns[0] ?? null;
    }

    /**
     * Returns the RelationshipMetadata for a given property name, or null if not found.
     */
    public function getRelationship(string $propertyName): ?RelationshipMetadata
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->propertyName === $propertyName) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Returns true if this entity has any lifecycle hooks configured.
     */
    public function hasLifecycleHooks(): bool
    {
        return $this->lifecycleHooks !== [];
    }

    /**
     * Returns true if this entity uses an inheritance strategy.
     */
    public function hasInheritance(): bool
    {
        return $this->inheritanceType !== null;
    }

    /**
     * Returns true if this entity has a composite primary key (more than one ID field).
     */
    public function hasCompositeId(): bool
    {
        return count($this->idFields) > 1;
    }

    /**
     * Returns all relationships of a given type.
     *
     * @param string $type One of: 'OneToOne', 'OneToMany', 'ManyToOne', 'ManyToMany'
     * @return RelationshipMetadata[]
     */
    public function getRelationshipsByType(string $type): array
    {
        return array_values(array_filter(
            $this->relationships,
            fn(RelationshipMetadata $r) => $r->type === $type,
        ));
    }

    /**
     * Returns the RelationshipMetadata that uses the given join column name, or null.
     */
    public function getRelationshipByJoinColumn(string $joinColumnName): ?RelationshipMetadata
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship->joinColumn === $joinColumnName) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * Returns all column property names.
     *
     * @return string[]
     */
    public function getColumnPropertyNames(): array
    {
        return array_keys($this->columnsByProperty);
    }

    /**
     * Returns all column database names.
     *
     * @return string[]
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columnsByName);
    }

    /**
     * Returns all nullable columns.
     *
     * @return ColumnMetadata[]
     */
    public function getNullableColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn(ColumnMetadata $c) => $c->nullable,
        ));
    }

    /**
     * Returns all non-ID columns (useful for UPDATE SET clauses).
     *
     * @return ColumnMetadata[]
     */
    public function getNonIdColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn(ColumnMetadata $c) => !$c->isId,
        ));
    }

    /**
     * Returns all columns with generated values (e.g. IDENTITY).
     *
     * @return ColumnMetadata[]
     */
    public function getGeneratedColumns(): array
    {
        return array_values(array_filter(
            $this->columns,
            fn(ColumnMetadata $c) => $c->isGenerated(),
        ));
    }

    public function __toString(): string
    {
        return sprintf(
            'ClassMetadata(%s → %s, %d columns, %d relationships)',
            $this->entityClass,
            $this->getQualifiedTableName(),
            count($this->columns),
            count($this->relationships),
        );
    }
}
