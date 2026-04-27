<?php

declare(strict_types=1);

namespace SybaseORM\Cache;

/**
 * Gestiona el caché de primer y segundo nivel.
 */
interface CacheManagerInterface
{
    /**
     * Obtiene una entidad del caché (primer o segundo nivel).
     */
    public function get(string $entityClass, mixed $id): ?object;

    /**
     * Almacena una entidad en el caché.
     */
    public function put(string $entityClass, mixed $id, object $entity): void;

    /**
     * Invalida las entradas de caché correspondientes a una entidad en ambos niveles.
     */
    public function invalidate(string $entityClass, mixed $id): void;

    /**
     * Almacena el resultado de una consulta en el caché de segundo nivel.
     *
     * @param string $queryKey Clave única de la consulta.
     * @param array  $result   Resultado de la consulta.
     * @param int|null $ttl    Tiempo de expiración en segundos (null = sin expiración).
     */
    public function putQueryResult(string $queryKey, array $result, ?int $ttl = null): void;

    /**
     * Obtiene el resultado de una consulta del caché de segundo nivel.
     */
    public function getQueryResult(string $queryKey): ?array;

    /**
     * Limpia todo el caché (primer y segundo nivel).
     */
    public function clear(): void;

    /**
     * Returns true if the second-level cache is currently available.
     */
    public function isSecondLevelAvailable(): bool;
}
