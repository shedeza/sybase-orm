<?php

declare(strict_types=1);

namespace SybaseORM\Scaffold;

use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Exception\SybaseORMException;

/**
 * Introspects the database schema to read table structures for scaffolding.
 */
class SchemaIntrospector
{
    public function __construct(
        private readonly ConnectionManagerInterface $connection
    ) {}

    /**
     * Gets all user tables in the database.
     *
     * @return string[]
     */
    public function getTables(): array
    {
        $stmt = $this->connection->executeQuery(
            "SELECT name FROM sysobjects WHERE type = 'U' AND name NOT LIKE 'sys%' ORDER BY name"
        );
        $tables = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $tables[] = $row['name'];
        }
        return $tables;
    }

    /**
     * Retrieves schema definition for a specific table.
     *
     * @return array{
     *     name: string,
     *     columns: array<string, array{
     *         name: string,
     *         type: string,
     *         length: int,
     *         precision: int|null,
     *         scale: int|null,
     *         nullable: bool,
     *         isPrimaryKey: bool
     *     }>
     * }
     */
    public function getTableDefinition(string $tableName): array
    {
        $columns = [];
        $stmt = $this->connection->executeQuery(
            "SELECT 
                c.name AS column_name,
                t.name AS data_type,
                c.length,
                c.prec AS precision_val,
                c.scale,
                c.status
            FROM syscolumns c
            JOIN sysobjects o ON c.id = o.id
            JOIN systypes t ON c.usertype = t.usertype
            WHERE o.name = ? AND o.type = 'U'
            ORDER BY c.colid",
            [$tableName]
        );

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $columns[$row['column_name']] = [
                'name' => $row['column_name'],
                'type' => $row['data_type'],
                'length' => (int) $row['length'],
                'precision' => $row['precision_val'] !== null ? (int) $row['precision_val'] : null,
                'scale' => $row['scale'] !== null ? (int) $row['scale'] : null,
                'nullable' => ($row['status'] & 8) !== 0,
                'isPrimaryKey' => false,
            ];
        }

        if (empty($columns)) {
            throw new SybaseORMException("Table '$tableName' does not exist or has no columns.");
        }

        // Fetch primary keys
        $pkStmt = $this->connection->executeQuery(
            "SELECT c.name
            FROM sysindexes i
            JOIN sysindexkeys ik ON i.id = ik.id AND i.indid = ik.indid
            JOIN syscolumns c ON ik.id = c.id AND ik.colid = c.colid
            JOIN sysobjects o ON i.id = o.id
            WHERE o.name = ? AND (i.status & 2048) != 0",
            [$tableName]
        );

        while ($pkRow = $pkStmt->fetch(\PDO::FETCH_ASSOC)) {
            $colName = $pkRow['name'];
            if (isset($columns[$colName])) {
                $columns[$colName]['isPrimaryKey'] = true;
            }
        }

        return [
            'name' => $tableName,
            'columns' => $columns,
        ];
    }
}
