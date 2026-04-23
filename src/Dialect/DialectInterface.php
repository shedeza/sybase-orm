<?php

declare(strict_types=1);

namespace SybaseORM\Dialect;

/**
 * Adapta la generación de SQL a la sintaxis y limitaciones específicas de un motor de base de datos.
 * Permite reemplazar el dialecto Sybase ASE por otro (PostgreSQL, SQL Server) sin modificar el código de negocio.
 */
interface DialectInterface
{
    /** Genera SQL de paginación usando TOP o ROW_NUMBER() según corresponda. */
    public function applyPagination(string $sql, int $limit, ?int $offset = null): string;

    /** Genera la sentencia INSERT omitiendo la columna identity si corresponde. */
    public function generateInsert(string $table, array $columns, array $values): string;

    /** Genera SQL para obtener el último identificador generado (@@identity). */
    public function getLastInsertIdSQL(): string;

    /** Formatea un identificador (tabla o columna) compatible con el dialecto. */
    public function quoteIdentifier(string $identifier): string;

    /** Genera SQL para valores NULL respetando la configuración ANSINULL. */
    public function generateNullComparison(string $column, bool $isNull): string;

    /** Genera la sentencia UPDATE con las columnas especificadas. */
    public function generateUpdate(string $table, array $columns, string $whereClause): string;

    /** Genera la sentencia DELETE para la tabla especificada. */
    public function generateDelete(string $table, string $whereClause): string;

    /** Genera la sentencia SELECT base. */
    public function generateSelect(array $columns, string $from, ?string $alias = null): string;
}
