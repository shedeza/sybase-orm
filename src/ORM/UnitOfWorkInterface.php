<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Tracks entity changes and coordinates persistence.
 */
interface UnitOfWorkInterface
{
    /** Registers a new entity for insertion. */
    public function registerNew(object $entity): void;

    /** Registers an entity for deletion. */
    public function registerDeleted(object $entity): void;

    /** Registers an entity for restoration (SoftDelete). */
    public function registerRestored(object $entity): void;

    /** Registers an entity as clean (unmodified), taking a snapshot of its current state. */
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
