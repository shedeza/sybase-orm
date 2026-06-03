<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Rastrea cambios en entidades y coordina la persistencia.
 */
interface UnitOfWorkInterface
{
    /** Registra una entidad como nueva (pendiente de INSERT). */
    public function registerNew(object $entity): void;

    /** Marca una entidad para eliminación (pendiente de DELETE). */
    public function registerDeleted(object $entity): void;

    /** Toma un snapshot del estado actual de la entidad para Dirty Checking. */
    public function registerClean(object $entity): void;

    /** Ejecuta todos los cambios pendientes dentro de una transacción. */
    public function commit(): void;

    /**
     * Detecta propiedades modificadas comparando estado actual vs snapshot.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function computeChangeset(object $entity): array;

    /** Limpia todos los registros de cambios y snapshots. */
    public function clear(): void;

    /** Verifica si una entidad está siendo gestionada (tiene snapshot). */
    public function isManaged(object $entity): bool;

    /** Removes an entity from tracking (snapshots and pending operations). */
    public function detach(object $entity): void;

    /** Removes all entities of a specific class from tracking. */
    public function clearClass(string $entityClass): void;
}
