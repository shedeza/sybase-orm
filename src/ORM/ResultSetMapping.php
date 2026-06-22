<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Maps columns from a native SQL result to entity properties or scalar values.
 *
 * Used with NativeQuery to map complex SQL results (JOINs across multiple
 * entities, computed columns, etc.) to structured output.
 *
 * Usage:
 *     $rsm = new ResultSetMapping();
 *     $rsm->addEntityMapping('u', User::class, [
 *         'user_id' => 'id',
 *         'user_name' => 'name',
 *         'user_email' => 'email',
 *     ]);
 *     $rsm->addEntityMapping('o', Order::class, [
 *         'order_id' => 'id',
 *         'order_total' => 'total',
 *     ]);
 *     $rsm->addScalarMapping('total_orders', 'totalOrders');
 *
 *     $nq = $em->createNativeQuery($sql, null);
 *     $results = $nq->executeWithMapping([], $rsm);
 */
final class ResultSetMapping
{
    /** @var array<string, array{class: class-string, columns: array<string, string>}> alias → entity mapping */
    private array $entityMappings = [];

    /** @var array<string, string> SQL column name → scalar result key */
    private array $scalarMappings = [];

    /**
     * Maps a set of SQL columns to an entity class.
     *
     * @param string $alias Short alias for this entity (e.g. 'u')
     * @param class-string $entityClass Entity FQCN
     * @param array<string, string> $columnToProperty Map of SQL column name → entity property name
     */
    public function addEntityMapping(string $alias, string $entityClass, array $columnToProperty): self
    {
        $this->entityMappings[$alias] = [
            'class' => $entityClass,
            'columns' => $columnToProperty,
        ];

        return $this;
    }

    /**
     * Maps a SQL column to a scalar value in the result.
     *
     * @param string $columnName SQL column name or alias
     * @param string $resultKey Key in the result array
     */
    public function addScalarMapping(string $columnName, string $resultKey): self
    {
        $this->scalarMappings[$columnName] = $resultKey;

        return $this;
    }

    /**
     * Applies the mapping to a raw database row.
     *
     * @param array<string, mixed> $row Raw database row
     * @return array<string, mixed> Mapped result with entity aliases and scalars
     */
    public function mapRow(array $row): array
    {
        $result = [];

        // Map entities
        foreach ($this->entityMappings as $alias => $mapping) {
            $entityData = [];
            foreach ($mapping['columns'] as $sqlColumn => $property) {
                if (array_key_exists($sqlColumn, $row)) {
                    $entityData[$property] = $row[$sqlColumn];
                }
            }
            $result[$alias] = [
                '_class' => $mapping['class'],
                '_data' => $entityData,
            ];
        }

        // Map scalars
        foreach ($this->scalarMappings as $sqlColumn => $resultKey) {
            $result[$resultKey] = $row[$sqlColumn] ?? null;
        }

        return $result;
    }

    /**
     * Returns all entity mappings.
     *
     * @return array<string, array{class: class-string, columns: array<string, string>}>
     */
    public function getEntityMappings(): array
    {
        return $this->entityMappings;
    }

    /**
     * Returns all scalar mappings.
     *
     * @return array<string, string>
     */
    public function getScalarMappings(): array
    {
        return $this->scalarMappings;
    }
}
