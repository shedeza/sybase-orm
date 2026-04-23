<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Garantiza unicidad de instancias por identificador dentro de una sesión.
 */
interface IdentityMapInterface
{
    /** Almacena una entidad en el mapa. */
    public function put(string $entityClass, mixed $id, object $entity): void;

    /** Busca una entidad en el mapa. Retorna null si no existe. */
    public function get(string $entityClass, mixed $id): ?object;

    /** Verifica si una entidad existe en el mapa. */
    public function contains(string $entityClass, mixed $id): bool;

    /** Elimina una entidad del mapa. */
    public function remove(string $entityClass, mixed $id): void;

    /** Limpia todo el mapa. */
    public function clear(): void;
}
