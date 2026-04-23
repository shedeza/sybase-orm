<?php

declare(strict_types=1);

namespace SybaseORM\Type;

/**
 * Interfaz para tipos personalizados que envuelven el placeholder SQL con una expresión.
 * Por ejemplo, un tipo que necesita CONVERT(REAL, ?) en lugar de solo ?.
 */
interface SqlWrappingTypeInterface extends CustomTypeInterface
{
    /**
     * Envuelve una expresión SQL con la conversión necesaria.
     * Ejemplo: convertToDatabaseValueSQL('?') → 'CONVERT(REAL, ?)'
     */
    public function convertToDatabaseValueSQL(string $sqlExpr): string;
}
