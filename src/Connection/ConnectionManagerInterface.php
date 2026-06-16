<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\TransactionException;

/**
 * Manages PDO dblib connections to Sybase ASE.
 */
interface ConnectionManagerInterface
{
    /**
     * Returns the active PDO connection, creating it if necessary.
     *
     * @throws ConnectionLostException If the connection cannot be established.
     */
    public function getConnection(): \PDO;

    /**
     * Executes a SQL query and returns the PDOStatement.
     * The caller is responsible for calling closeCursor() when done.
     *
     * @throws ConnectionLostException If the connection is lost during the operation.
     * @throws \SybaseORM\Exception\PersistenceException If a SQL error occurs.
     */
    public function executeQuery(string $sql, array $params = []): \PDOStatement;

    /**
     * Executes a SQL modification statement and returns the number of affected rows.
     * Releases the PDOStatement automatically (closeCursor).
     *
     * @throws ConnectionLostException If the connection is lost during the operation.
     * @throws \SybaseORM\Exception\PersistenceException If a SQL error occurs.
     */
    public function executeStatement(string $sql, array $params = []): int;

    /** Begins a native Sybase ASE transaction. */
    public function beginTransaction(): void;

    /**
     * Commits the active transaction.
     *
     * @throws TransactionException If no transaction is active.
     */
    public function commit(): void;

    /**
     * Rolls back the active transaction.
     *
     * @throws TransactionException If no transaction is active.
     */
    public function rollback(): void;

    /**
     * Sets the transaction isolation level.
     *
     * @param string $level Isolation level (READ UNCOMMITTED, READ COMMITTED, REPEATABLE READ, SERIALIZABLE).
     */
    public function setTransactionIsolation(string $level): void;

    /**
     * Converts string values in a result row from ISO-8859-1 to UTF-8.
     * If charset_conversion is disabled, returns the row unchanged.
     *
     * @param array $row Database result row.
     * @return array Row with converted strings.
     */
    public function convertResultRow(array $row): array;

    /**
     * Checks if the database connection is still alive.
     *
     * @return bool True if the connection responds, false otherwise.
     */
    public function ping(): bool;

    /**
     * Returns the Sybase ASE server version string.
     */
    public function getServerVersion(): string;

    /**
     * Returns true if a transaction is currently active.
     */
    public function isInTransaction(): bool;
}
