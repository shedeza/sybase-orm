<?php

declare(strict_types=1);

namespace SybaseORM\Query;

/**
 * Construye consultas SQL de forma programática y segura.
 */
interface QueryBuilderInterface
{
    /** Define las columnas o expresiones a seleccionar. */
    public function select(string ...$columns): static;

    /** Define la tabla o entidad origen de la consulta. */
    public function from(string $from, ?string $alias = null): static;

    /** Agrega una condición WHERE con parametrización automática. */
    public function where(string $condition, array $params = []): static;

    /** Agrega una condición AND WHERE. */
    public function andWhere(string $condition, array $params = []): static;

    /** Agrega una condición OR WHERE. */
    public function orWhere(string $condition, array $params = []): static;

    /** Agrega un JOIN a la consulta. */
    public function join(string $join, string $alias, string $condition): static;

    /** Agrega un LEFT JOIN a la consulta. */
    public function leftJoin(string $join, string $alias, string $condition): static;

    /** Define el ordenamiento de los resultados. */
    public function orderBy(string $column, string $direction = 'ASC'): static;

    /** Define la agrupación de los resultados. */
    public function groupBy(string ...$columns): static;

    /** Define el límite de resultados (delegado al Dialect para TOP/ROW_NUMBER). */
    public function limit(int $limit): static;

    /** Define el offset de resultados (delegado al Dialect para subconsultas). */
    public function offset(int $offset): static;

    /** Especifica relaciones para Eager Loading mediante JOINs o WHERE IN. */
    public function with(string ...$relations): static;

    /** Agrega una condición HAVING a la consulta. */
    public function having(string $condition, array $params = []): static;

    /** Sets a single named parameter value. */
    public function setParameter(string $name, mixed $value): static;

    /** Merges multiple named parameter values. */
    public function setParameters(array $params): static;

    /** Resets all query state for reuse. */
    public function reset(): static;

    /** Genera la consulta SQL parametrizada. */
    public function getSQL(): string;

    /** Retorna los parámetros de la consulta. */
    public function getParameters(): array;
}
