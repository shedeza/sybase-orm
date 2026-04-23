<?php

declare(strict_types=1);

namespace SybaseORM\Type;

use SybaseORM\Exception\TypeConversionException;

/**
 * Convierte tipos de datos entre PHP y Sybase ASE.
 */
interface TypeCasterInterface
{
    /**
     * Convierte un valor PHP al formato de base de datos Sybase ASE.
     *
     * @throws TypeConversionException Si la conversión falla.
     */
    public function toDatabaseValue(mixed $value, string $type): mixed;

    /**
     * Convierte un valor de base de datos Sybase ASE al tipo PHP correspondiente.
     *
     * @throws TypeConversionException Si la conversión falla.
     */
    public function toPhpValue(mixed $value, string $type): mixed;

    /**
     * Registra un tipo personalizado (Value Object) con métodos de conversión bidireccional.
     */
    public function registerType(string $typeName, string $typeClass): void;

    /**
     * Retorna la expresión SQL para un valor de base de datos, aplicando envolvimiento
     * si el tipo implementa SqlWrappingTypeInterface.
     */
    public function getDatabaseValueSQL(string $sqlExpr, string $type): string;
}
