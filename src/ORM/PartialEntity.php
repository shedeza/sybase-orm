<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Marker interface for partially loaded entities.
 *
 * When an entity is loaded with only a subset of columns (via select()),
 * accessing non-loaded properties may return unexpected defaults.
 * This interface allows code to detect partial entities.
 *
 * Usage in repository:
 *     $users = $repo->createQueryBuilder()
 *         ->select('e.id', 'e.name')  // Only load id and name
 *         ->getArrayResult();         // Returns arrays, not full entities
 *
 * For type-safe partial loading, use DTOs or getArrayResult().
 */
interface PartialEntity
{
    /**
     * Returns the list of properties that were loaded.
     *
     * @return string[]
     */
    public function getLoadedProperties(): array;
}
