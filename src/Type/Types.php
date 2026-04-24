<?php

declare(strict_types=1);

namespace SybaseORM\Type;

/**
 * Diccionario de tipos para el mapeo entidad-relación.
 *
 * Uso:
 *     #[Column(name: 'unidad_xx', type: Types::STRING)]
 *     #[Column(name: 'empleado_cl', type: Types::INTEGER)]
 *     #[Column(name: 'aleatorio_nu', type: Types::REAL)]
 *
 * Cada constante corresponde a un tipo soportado por el TypeCaster.
 * Los alias (e.g. INT y INTEGER) producen el mismo comportamiento.
 */
final class Types
{
    // ── String types ────────────────────────────────────────────────

    /** VARCHAR — cadena de longitud variable */
    public const STRING = 'string';

    /** VARCHAR — alias de STRING */
    public const VARCHAR = 'varchar';

    /** TEXT — cadena de longitud ilimitada */
    public const TEXT = 'text';

    // ── Integer types ───────────────────────────────────────────────

    /** INT — entero de 32 bits */
    public const INTEGER = 'integer';

    /** INT — alias corto */
    public const INT = 'int';

    /** TINYINT — entero de 8 bits (0–255) */
    public const TINYINT = 'tinyint';

    /** SMALLINT — entero de 16 bits */
    public const SMALLINT = 'smallint';

    /** BIGINT — entero de 64 bits */
    public const BIGINT = 'bigint';

    // ── Float types ─────────────────────────────────────────────────

    /** FLOAT — punto flotante de doble precisión */
    public const FLOAT = 'float';

    /** DOUBLE — alias de FLOAT */
    public const DOUBLE = 'double';

    /** DECIMAL — número de precisión fija (requiere precision y scale en Column) */
    public const DECIMAL = 'decimal';

    /** REAL — punto flotante de precisión simple (Sybase ASE nativo) */
    public const REAL = 'real';

    // ── Boolean type ────────────────────────────────────────────────

    /** BIT — booleano (0/1) */
    public const BOOLEAN = 'boolean';

    /** BIT — alias corto */
    public const BOOL = 'bool';

    // ── Date/Time types ─────────────────────────────────────────────

    /** DATETIME — fecha y hora con milisegundos */
    public const DATETIME = 'datetime';

    // ── Prevent instantiation ───────────────────────────────────────

    private function __construct()
    {
    }
}
