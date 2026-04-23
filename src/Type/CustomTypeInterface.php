<?php

declare(strict_types=1);

namespace SybaseORM\Type;

/**
 * Interfaz para tipos personalizados (Value Objects) que definen
 * conversión bidireccional entre PHP y Sybase ASE.
 */
interface CustomTypeInterface
{
    /**
     * Convierte un valor PHP al formato de base de datos.
     */
    public function toDatabaseValue(mixed $value): mixed;

    /**
     * Convierte un valor de base de datos al tipo PHP correspondiente.
     */
    public function toPhpValue(mixed $value): mixed;
}
