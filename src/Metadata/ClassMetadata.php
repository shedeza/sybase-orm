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

    /**
     * @param string                $entityClass        Fully qualified class name
     * @param string                $tableName          Database table name
     * @param string|null           $schema             Database schema name (e.g. 'dbo')
     * @param ColumnMetadata[]      $columns            Mapped columns
     * @param string|null           $idField            Property name of the primary key
     * @param RelationshipMetadata[] $relationships     Mapped relationships
     * @param string|null           $inheritanceType    TPH, TPT, TPC or null
     * @param string|null           $discriminatorColumn Discriminator column name
     * @param array<string, string> $discriminatorMap   Discriminator value => class map
     * @param array<string, string[]> $lifecycleHooks   Hook type => method names
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly string $tableName,
        public readonly ?string $schema = null,
        public readonly array $columns = [],
        public readonly ?string $idField = null,
        public readonly array $relationships = [],
        public readonly ?string $inheritanceType = null,
        public readonly ?string $discriminatorColumn = null,
        public readonly array $discriminatorMap = [],
        public readonly array $lifecycleHooks = [],
    ) {
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
     * Returns the ColumnMetadata for the primary key, or null if not set.
     */
    public function getIdColumn(): ?ColumnMetadata
    {
        if ($this->idField === null) {
            return null;
        }

        return $this->getColumn($this->idField);
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
}
