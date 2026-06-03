<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use ReflectionClass;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;

/**
 * Handles inheritance mapping strategies: TPH, TPT, and TPC.
 *
 * - TPH (Table Per Hierarchy): single table with discriminator column
 * - TPT (Table Per Type): base table + derived tables joined by PK
 * - TPC (Table Per Concrete Class): independent table per concrete class
 */
final class InheritanceHandler
{
    /** @var array<string, \ReflectionClass<object>> */
    private array $reflectionCache = [];

    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
    ) {}

    private function getReflectionClass(string $className): \ReflectionClass
    {
        if (!isset($this->reflectionCache[$className])) {
            $this->reflectionCache[$className] = new \ReflectionClass($className);
        }

        return $this->reflectionCache[$className];
    }

    /**
     * Resolves the concrete entity class for a TPH row using the discriminator value.
     *
     * @param array<string, mixed> $row         Database row
     * @param ClassMetadata        $baseMetadata Metadata of the base (root) entity
     * @return string Fully qualified class name of the concrete subclass
     */
    public function resolveTPHClass(array $row, ClassMetadata $baseMetadata): string
    {
        $discriminatorColumn = $baseMetadata->discriminatorColumn;
        if ($discriminatorColumn === null) {
            return $baseMetadata->entityClass;
        }

        $discriminatorValue = $row[$discriminatorColumn] ?? null;
        if ($discriminatorValue === null) {
            return $baseMetadata->entityClass;
        }

        $map = $baseMetadata->discriminatorMap;
        if (isset($map[$discriminatorValue])) {
            return $map[$discriminatorValue];
        }

        return $baseMetadata->entityClass;
    }

    /**
     * Returns the discriminator value for a given entity class in a TPH hierarchy.
     *
     * @param string        $entityClass  The concrete entity class
     * @param ClassMetadata $baseMetadata Metadata of the base (root) entity
     * @return string|null The discriminator value, or null if not found
     */
    public function getTPHDiscriminatorValue(string $entityClass, ClassMetadata $baseMetadata): ?string
    {
        foreach ($baseMetadata->discriminatorMap as $value => $class) {
            if ($class === $entityClass) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Builds the INSERT column data for a TPH entity, including the discriminator column.
     *
     * @param array<string, mixed> $columnData    Column name => value pairs
     * @param string               $entityClass   The concrete entity class being inserted
     * @param ClassMetadata        $baseMetadata  Metadata of the base (root) entity
     * @return array<string, mixed> Column data with discriminator value added
     */
    public function buildTPHInsertData(array $columnData, string $entityClass, ClassMetadata $baseMetadata): array
    {
        $discriminatorValue = $this->getTPHDiscriminatorValue($entityClass, $baseMetadata);
        if ($discriminatorValue !== null && $baseMetadata->discriminatorColumn !== null) {
            $columnData[$baseMetadata->discriminatorColumn] = $discriminatorValue;
        }

        return $columnData;
    }

    /**
     * Generates JOIN clauses for a TPT query on the base class.
     *
     * Returns an array of join definitions, each containing:
     * - table: the derived table name
     * - alias: alias for the derived table
     * - condition: the ON condition (base PK = derived PK)
     *
     * @param ClassMetadata $baseMetadata Metadata of the base entity
     * @return array<int, array{table: string, alias: string, condition: string}>
     */
    public function buildTPTJoins(ClassMetadata $baseMetadata): array
    {
        if ($baseMetadata->inheritanceType !== 'TPT') {
            return [];
        }

        $baseIdColumn = $baseMetadata->getIdColumn();
        if ($baseIdColumn === null) {
            return [];
        }

        $joins = [];
        $baseAlias = $baseMetadata->tableName;

        foreach ($baseMetadata->discriminatorMap as $value => $childClass) {
            if ($childClass === $baseMetadata->entityClass) {
                continue;
            }

            $childMetadata = $this->metadataReader->getClassMetadata($childClass);
            $childIdColumn = $childMetadata->getIdColumn();
            if ($childIdColumn === null) {
                continue;
            }

            $childTable = $childMetadata->tableName;
            $joins[] = [
                'table' => $childTable,
                'alias' => $childTable,
                'condition' => sprintf(
                    '%s.%s = %s.%s',
                    $baseAlias,
                    $baseIdColumn->columnName,
                    $childTable,
                    $childIdColumn->columnName,
                ),
            ];
        }

        return $joins;
    }

    /**
     * Splits column data for a TPT INSERT across base and derived tables.
     *
     * @param array<string, mixed> $columnData   All column name => value pairs
     * @param string               $entityClass  The concrete entity class being inserted
     * @param ClassMetadata        $baseMetadata Metadata of the base entity
     * @return array{base: array<string, mixed>, derived: array<string, mixed>}
     */
    public function splitTPTInsertData(array $columnData, string $entityClass, ClassMetadata $baseMetadata): array
    {
        $baseColumnNames = [];
        foreach ($baseMetadata->columns as $col) {
            $baseColumnNames[$col->columnName] = true;
        }

        $baseData = [];
        $derivedData = [];

        foreach ($columnData as $colName => $value) {
            if (isset($baseColumnNames[$colName])) {
                $baseData[$colName] = $value;
            } else {
                $derivedData[$colName] = $value;
            }
        }

        return ['base' => $baseData, 'derived' => $derivedData];
    }

    /**
     * Returns the full list of columns for a TPC entity (including inherited columns).
     *
     * In TPC, each concrete class has its own independent table with ALL columns,
     * including those inherited from parent classes.
     *
     * @param string $entityClass The concrete entity class
     * @return array<string, string> Column name => property name mapping
     */
    public function getTPCColumns(string $entityClass): array
    {
        $columns = [];
        $reflectionClass = $this->getReflectionClass($entityClass);

        // Collect columns from the class and all parent classes
        $classHierarchy = [];
        $current = $reflectionClass;
        while ($current !== false) {
            $classHierarchy[] = $current->getName();
            $current = $current->getParentClass();
        }

        // Reverse to process from root to leaf
        $classHierarchy = array_reverse($classHierarchy);

        foreach ($classHierarchy as $className) {
            if (!$this->metadataReader->isEntity($className)) {
                continue;
            }

            $metadata = $this->metadataReader->getClassMetadata($className);
            foreach ($metadata->columns as $col) {
                $columns[$col->columnName] = $col->propertyName;
            }
        }

        return $columns;
    }

    /**
     * Returns the table name for a TPC entity.
     * Each concrete class maps to its own independent table.
     *
     * @param string $entityClass The concrete entity class
     * @return string The table name
     */
    public function getTPCTableName(string $entityClass): string
    {
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        return $metadata->tableName;
    }

    /**
     * Determines the inheritance strategy for a given entity class.
     * Checks the class itself and its parent hierarchy.
     *
     * @return string|null 'TPH', 'TPT', 'TPC', or null
     */
    public function getInheritanceStrategy(string $entityClass): ?string
    {
        $metadata = $this->metadataReader->getClassMetadata($entityClass);
        if ($metadata->inheritanceType !== null) {
            return $metadata->inheritanceType;
        }

        // Check parent classes for inheritance type
        $reflectionClass = $this->getReflectionClass($entityClass);
        $parent = $reflectionClass->getParentClass();
        while ($parent !== false) {
            if ($this->metadataReader->isEntity($parent->getName())) {
                $parentMetadata = $this->metadataReader->getClassMetadata($parent->getName());
                if ($parentMetadata->inheritanceType !== null) {
                    return $parentMetadata->inheritanceType;
                }
            }
            $parent = $parent->getParentClass();
        }

        return null;
    }

    /**
     * Finds the root entity metadata in an inheritance hierarchy.
     *
     * @return ClassMetadata The root entity's metadata
     */
    public function getRootMetadata(string $entityClass): ClassMetadata
    {
        $reflectionClass = $this->getReflectionClass($entityClass);
        $rootClass = $entityClass;

        $parent = $reflectionClass->getParentClass();
        while ($parent !== false) {
            if ($this->metadataReader->isEntity($parent->getName())) {
                $rootClass = $parent->getName();
            }
            $parent = $parent->getParentClass();
        }

        return $this->metadataReader->getClassMetadata($rootClass);
    }
}
