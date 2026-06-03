<?php

declare(strict_types=1);

namespace SybaseORM\Hydrator;

/**
 * Convierte los arrays resultantes de PDO fetch en instancias de clases de entidad.
 */
interface HydratorInterface
{
    /**
     * Hidrata una fila de base de datos en una instancia de entidad.
     * Usa Reflection API para asignar valores a propiedades privadas.
     * Consulta el Identity Map antes de crear una nueva instancia.
     * Ignora columnas no mapeadas sin lanzar error.
     */
    public function hydrate(array $row, string $entityClass): object;

    /**
     * Hidrata múltiples filas de base de datos en un array de instancias de entidad.
     *
     * @return object[]
     */
    public function hydrateAll(array $rows, string $entityClass): array;
}
