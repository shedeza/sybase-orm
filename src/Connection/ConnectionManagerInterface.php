<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\TransactionException;

/**
 * Gestiona las conexiones PDO dblib a Sybase ASE.
 */
interface ConnectionManagerInterface
{
    /**
     * Obtiene la conexión PDO activa, creándola si es necesario.
     *
     * @throws ConnectionLostException Si la conexión se pierde.
     */
    public function getConnection(): \PDO;

    /**
     * Ejecuta una sentencia SQL y retorna el PDOStatement.
     * El caller es responsable de llamar closeCursor() cuando termine.
     *
     * @throws ConnectionLostException Si la conexión se pierde durante la operación.
     * @throws \SybaseORM\Exception\PersistenceException Si ocurre un error SQL.
     */
    public function executeQuery(string $sql, array $params = []): \PDOStatement;

    /**
     * Ejecuta una sentencia SQL de modificación y retorna el número de filas afectadas.
     * Libera el PDOStatement automáticamente (closeCursor).
     *
     * @throws ConnectionLostException Si la conexión se pierde durante la operación.
     * @throws \SybaseORM\Exception\PersistenceException Si ocurre un error SQL.
     */
    public function executeStatement(string $sql, array $params = []): int;

    /** Inicia una transacción nativa de Sybase ASE. */
    public function beginTransaction(): void;

    /**
     * Confirma la transacción activa en Sybase ASE.
     *
     * @throws TransactionException Si no hay transacción activa.
     */
    public function commit(): void;

    /**
     * Revierte la transacción activa en Sybase ASE.
     *
     * @throws TransactionException Si no hay transacción activa.
     */
    public function rollback(): void;

    /**
     * Configura el nivel de aislamiento de la transacción.
     *
     * @param string $level Nivel de aislamiento (READ UNCOMMITTED, READ COMMITTED, REPEATABLE READ, SERIALIZABLE).
     */
    public function setTransactionIsolation(string $level): void;

    /**
     * Convierte valores string de un resultado de ISO-8859-1 a UTF-8.
     * Si charset_conversion está deshabilitado, retorna la fila sin cambios.
     *
     * @param array $row Fila de resultado de la base de datos.
     * @return array Fila con strings convertidos.
     */
    public function convertResultRow(array $row): array;
}
