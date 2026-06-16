<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Tracks entity changes and coordinates persistence.
 */
interface UnitOfWorkInterface
{
    /** Registers an entity as new (pending INSERT). */
    public function registerNew(object $entity): void;

    /** Marks an entity for deletion (pending DELETE). */
    public function registerDeleted(object $entity): void;

    /** Takes a snapshot of the entity's current state for dirty checking. */
    public function registerClean(object $entity): void;

    /** Executes all pending changes within a transaction. */
    public function commit(): void;

    /**
     * Detects modified properties by comparing current state vs snapshot.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function computeChangeset(object $entity): array;

    /** Clears all tracked changes and snapshots. */
    public function clear(): void;

    /** Checks if an entity is managed (has a snapshot). */
    public function isManaged(object $entity): bool;

    /** Removes an entity from tracking (snapshots and pending operations). */
    public function detach(object $entity): void;

    /** Removes all entities of a specific class from tracking. */
    public function clearClass(string $entityClass): void;
}
