<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Guarantees object identity: one entity instance per class + id within a session.
 */
interface IdentityMapInterface
{
    /** Stores an entity in the map. */
    public function put(string $entityClass, mixed $id, object $entity): void;

    /** Retrieves an entity from the map. Returns null if not found. */
    public function get(string $entityClass, mixed $id): ?object;

    /** Checks if an entity exists in the map. */
    public function contains(string $entityClass, mixed $id): bool;

    /** Removes an entity from the map. */
    public function remove(string $entityClass, mixed $id): void;

    /** Clears the entire map. */
    public function clear(): void;

    /** Clears only entities of a specific class from the map. */
    public function clearClass(string $entityClass): void;

    /** Returns the total number of entities stored across all classes. */
    public function count(): int;

    /** Returns the number of entities stored for a specific class. */
    public function countClass(string $entityClass): int;
}
