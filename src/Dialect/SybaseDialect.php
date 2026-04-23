<?php

declare(strict_types=1);

namespace SybaseORM\Dialect;

/**
 * SQL dialect for Sybase ASE.
 *
 * Generates SQL compatible with Sybase ASE syntax, including:
 * - TOP / ROW_NUMBER() pagination (no LIMIT/OFFSET)
 * - Identity column omission in INSERT
 * - @@identity for last generated ID
 * - Bracket-quoted identifiers [table].[column]
 * - ANSINULL-compliant NULL comparisons (IS NULL / IS NOT NULL)
 */
final class SybaseDialect implements DialectInterface
{
    /**
     * {@inheritdoc}
     *
     * When offset is null or 0, injects TOP {limit} after the first SELECT keyword.
     * When offset > 0, wraps the query with a ROW_NUMBER() OVER (ORDER BY ...) subquery.
     */
    public function applyPagination(string $sql, int $limit, ?int $offset = null): string
    {
        if ($offset === null || $offset === 0) {
            // Simple TOP injection after the first SELECT
            return preg_replace(
                '/^(\s*SELECT\s)/i',
                '$1TOP ' . $limit . ' ',
                $sql,
                1
            );
        }

        // Extract ORDER BY clause from the original SQL (required for ROW_NUMBER)
        $orderBy = 'ORDER BY (SELECT 1)';
        if (preg_match('/\bORDER\s+BY\s+(.+?)$/is', $sql, $matches)) {
            $orderBy = 'ORDER BY ' . trim($matches[1]);
            // Remove ORDER BY from original SQL — it moves into ROW_NUMBER()
            $sql = preg_replace('/\s*\bORDER\s+BY\s+.+?$/is', '', $sql);
        }

        $start = $offset + 1;
        $end = $offset + $limit;

        return sprintf(
            'SELECT * FROM (SELECT ROW_NUMBER() OVER (%s) AS [__row_number], __inner.* FROM (%s) AS __inner) AS __paged WHERE [__row_number] BETWEEN %d AND %d',
            $orderBy,
            $sql,
            $start,
            $end
        );
    }

    /**
     * {@inheritdoc}
     *
     * Generates an INSERT statement, omitting the identity column if provided.
     *
     * @param string      $table          Table name (will be quoted)
     * @param string[]    $columns        Column names
     * @param string[]    $values         Value placeholders (e.g. '?' or ':param')
     * @param string|null $identityColumn Column name to omit (identity)
     */
    public function generateInsert(string $table, array $columns, array $values, ?string $identityColumn = null): string
    {
        if ($identityColumn !== null) {
            $filtered = [];
            $filteredValues = [];
            foreach ($columns as $i => $col) {
                if ($col !== $identityColumn) {
                    $filtered[] = $col;
                    $filteredValues[] = $values[$i];
                }
            }
            $columns = $filtered;
            $values = $filteredValues;
        }

        $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', $quotedColumns),
            implode(', ', $values)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getLastInsertIdSQL(): string
    {
        return 'SELECT @@identity';
    }

    /**
     * {@inheritdoc}
     *
     * Wraps identifier with square brackets, compatible with Sybase ASE
     * when SET QUOTED_IDENTIFIER ON is active.
     *
     * Handles schema-qualified names: "schema.table" becomes "[schema].[table]".
     */
    public function quoteIdentifier(string $identifier): string
    {
        // Don't double-quote already quoted identifiers
        if (str_starts_with($identifier, '[') && str_ends_with($identifier, ']')) {
            return $identifier;
        }

        // Handle schema-qualified names (e.g. "dbo.users" → "[dbo].[users]")
        if (str_contains($identifier, '.')) {
            $parts = explode('.', $identifier, 2);
            return $this->quoteSingleIdentifier($parts[0]) . '.' . $this->quoteSingleIdentifier($parts[1]);
        }

        return $this->quoteSingleIdentifier($identifier);
    }

    /**
     * Quotes a single identifier segment (no dots).
     */
    private function quoteSingleIdentifier(string $identifier): string
    {
        if (str_starts_with($identifier, '[') && str_ends_with($identifier, ']')) {
            return $identifier;
        }

        $escaped = str_replace(']', ']]', $identifier);

        return '[' . $escaped . ']';
    }

    /**
     * {@inheritdoc}
     *
     * Generates ANSINULL-compliant NULL comparisons.
     */
    public function generateNullComparison(string $column, bool $isNull): string
    {
        $quoted = $this->quoteIdentifier($column);

        return $isNull
            ? $quoted . ' IS NULL'
            : $quoted . ' IS NOT NULL';
    }

    /**
     * {@inheritdoc}
     */
    public function generateUpdate(string $table, array $columns, string $whereClause): string
    {
        $setClauses = array_map(
            fn(string $col) => $this->quoteIdentifier($col) . ' = ?',
            $columns
        );

        return sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
            $whereClause
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateDelete(string $table, string $whereClause): string
    {
        return sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            $whereClause
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generateSelect(array $columns, string $from, ?string $alias = null): string
    {
        $quotedColumns = array_map(
            fn(string $col) => $col === '*' ? '*' : $this->quoteIdentifier($col),
            $columns,
        );
        $quotedFrom = $this->quoteIdentifier($from);

        $sql = 'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $quotedFrom;

        if ($alias !== null) {
            $sql .= ' AS ' . $this->quoteIdentifier($alias);
        }

        return $sql;
    }
}
