<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

use Psr\Log\LoggerInterface;
use SybaseORM\Exception\ConnectionLostException;
use SybaseORM\Exception\PersistenceException;
use SybaseORM\Exception\TransactionException;

/**
 * Gestiona las conexiones PDO dblib a Sybase ASE.
 *
 * Construye el DSN como dblib:host={host}:{port};dbname={dbname};charset={charset},
 * ejecuta SET ANSINULL ON y SET QUOTED_IDENTIFIER ON al establecer conexión,
 * soporta conexiones persistentes, y gestiona transacciones con niveles de aislamiento.
 */
class ConnectionManager implements ConnectionManagerInterface
{
    private const VALID_ISOLATION_LEVELS = [
        'READ UNCOMMITTED',
        'READ COMMITTED',
        'REPEATABLE READ',
        'SERIALIZABLE',
    ];

    private const CONNECTION_LOST_CODES = [
        '08S01', // Communication link failure
        '08001', // Unable to connect
        'HY000', // General error (often connection-related with dblib)
    ];

    private ?\PDO $connection = null;
    private bool $inTransaction = false;
    private bool $charsetConversion;
    private int $savepointCounter = 0;
    private bool $readOnly;

    /** @var string[] Stack of active savepoint names */
    private array $savepointStack = [];

    /** @var array<string, \PDOStatement> Cache de sentencias preparadas por SQL */
    private array $stmtCache = [];

    /** Maximum number of cached prepared statements (LRU eviction) */
    private const STMT_CACHE_MAX_SIZE = 256;

    /** @var array{host: string, port: int, dbname: string, username: string, password: string, charset: string, persistent: bool} */
    private array $config;

    public function __construct(array $config, private readonly ?LoggerInterface $logger = null)
    {
        $this->charsetConversion = (bool) ($config['charset_conversion'] ?? false);
        $this->readOnly = (bool) ($config['read_only'] ?? false);

        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 5000,
            'dbname' => '',
            'username' => '',
            'password' => '',
            'charset' => 'UTF-8',
            'persistent' => false,
        ], $config);

        if ($this->config['dbname'] === '') {
            throw new \InvalidArgumentException('Connection config requires a non-empty "dbname".');
        }
    }

    public function getConnection(): \PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        // Clear statement cache — old statements are invalid for new connections
        $this->stmtCache = [];

        $port = (int) $this->config['port'];
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid port "%d". Must be between 1 and 65535.',
                $port,
            ));
        }

        $dsn = sprintf(
            'dblib:host=%s:%d;dbname=%s;charset=%s',
            $this->config['host'],
            $port,
            $this->config['dbname'],
            $this->config['charset'],
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ];

        if ($this->config['persistent']) {
            $options[\PDO::ATTR_PERSISTENT] = true;
        }

        try {
            $this->connection = $this->createPdo($dsn, $this->config['username'], $this->config['password'], $options);
            $this->connection->exec('SET ANSINULL ON');
            $this->connection->exec('SET QUOTED_IDENTIFIER ON');
        } catch (\PDOException $e) {
            $this->connection = null;
            throw new ConnectionLostException(
                sprintf('Failed to connect to Sybase ASE at %s:%d: %s', $this->config['host'], (int) $this->config['port'], $e->getMessage()),
                0,
                $e,
                is_string($e->getCode()) ? (string) $e->getCode() : null,
            );
        }

        return $this->connection;
    }

    public function executeQuery(string $sql, array $params = []): \PDOStatement
    {
        try {
            $pdo = $this->getConnection();
            [$sql, $params] = $this->expandArrayParams($sql, $params);

            // Don't use statement cache for queries — the returned statement
            // has an open cursor that the caller will read from. Reusing it
            // would cause "cursor already open" errors in Sybase ASE.
            $stmt = $pdo->prepare($sql);

            $this->bindParams($stmt, $this->convertParams($params));
            $stmt->execute();

            return $stmt;
        } catch (\PDOException $e) {
            $this->handlePdoException($e);
        }
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        if ($this->readOnly) {
            throw new PersistenceException(
                sprintf('Cannot execute write operation on read-only connection "%s": %s', $this->config['dbname'], mb_substr($sql, 0, 80)),
            );
        }

        try {
            $pdo = $this->getConnection();
            [$sql, $params] = $this->expandArrayParams($sql, $params);

            $stmt = $this->getCachedStatement($pdo, $sql);

            $this->bindParams($stmt, $this->convertParams($params));
            $stmt->execute();
            $rowCount = $stmt->rowCount();
            $stmt->closeCursor();

            return $rowCount;
        } catch (\PDOException $e) {
            $this->handlePdoException($e);
        }
    }

    public function beginTransaction(): void
    {
        if ($this->readOnly) {
            throw new PersistenceException(
                sprintf('Cannot begin transaction on read-only connection "%s".', $this->config['dbname']),
            );
        }

        $pdo = $this->getConnection();

        try {
            $pdo->beginTransaction();
            $this->inTransaction = true;
        } catch (\PDOException $e) {
            $this->handlePdoException($e);
        }
    }

    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new TransactionException('Cannot commit: no active transaction.');
        }

        try {
            $this->getConnection()->commit();
            $this->inTransaction = false;
            $this->savepointStack = [];
        } catch (\PDOException $e) {
            $this->handlePdoException($e);
        }
    }

    public function rollback(): void
    {
        if (!$this->inTransaction) {
            throw new TransactionException('Cannot rollback: no active transaction.');
        }

        try {
            $this->getConnection()->rollBack();
            $this->inTransaction = false;
            $this->savepointStack = [];
        } catch (\PDOException $e) {
            $this->handlePdoException($e);
        }
    }

    public function setTransactionIsolation(string $level): void
    {
        $normalized = strtoupper(trim($level));

        if (!in_array($normalized, self::VALID_ISOLATION_LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid isolation level "%s". Valid levels: %s',
                $level,
                implode(', ', self::VALID_ISOLATION_LEVELS),
            ));
        }

        try {
            $this->getConnection()->exec('SET TRANSACTION ISOLATION LEVEL ' . $normalized);
        } catch (\PDOException $e) {
            $this->handlePdoException($e);
        }
    }

    /**
     * Returns true if a transaction is currently active.
     */
    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Creates a savepoint within the current transaction.
     * Sybase ASE uses SAVE TRANSACTION name.
     *
     * @return string The savepoint name (auto-generated)
     * @throws TransactionException If no transaction is active.
     */
    public function createSavepoint(): string
    {
        if (!$this->inTransaction) {
            throw new TransactionException('Cannot create savepoint: no active transaction.');
        }

        $name = 'sp_' . (++$this->savepointCounter);
        $this->getConnection()->exec('SAVE TRANSACTION ' . $name);
        $this->savepointStack[] = $name;

        return $name;
    }

    /**
     * Rolls back to a savepoint within the current transaction.
     * Sybase ASE uses ROLLBACK TRANSACTION name.
     *
     * @throws TransactionException If no transaction is active.
     */
    public function rollbackToSavepoint(string $name): void
    {
        if (!$this->inTransaction) {
            throw new TransactionException('Cannot rollback to savepoint: no active transaction.');
        }

        $this->getConnection()->exec('ROLLBACK TRANSACTION ' . $name);

        // Remove this savepoint and any created after it from the stack
        $pos = array_search($name, $this->savepointStack, true);
        if ($pos !== false) {
            $this->savepointStack = array_slice($this->savepointStack, 0, $pos);
        }
    }

    /**
     * Releases a savepoint (no-op in Sybase ASE, but cleans up internal state).
     */
    public function releaseSavepoint(string $name): void
    {
        $pos = array_search($name, $this->savepointStack, true);
        if ($pos !== false) {
            array_splice($this->savepointStack, $pos, 1);
        }
    }

    /**
     * Binds parameters to a PDOStatement with proper PDO types.
     * Integers use PDO::PARAM_INT, nulls use PDO::PARAM_NULL, booleans use PDO::PARAM_BOOL,
     * everything else uses PDO::PARAM_STR. This prevents Sybase implicit conversion errors.
     *
     * Array values are expanded inline as individual binds. This handles cases where
     * upstream code (e.g., direct ConnectionManager usage) passes unexpanded array
     * parameters for IN clauses.
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        $position = 1; // PDO positional params are 1-based

        foreach ($params as $value) {
            if (is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Cannot bind non-scalar value at position %d. '
                    . 'Array/object parameters must be expanded before reaching ConnectionManager. '
                    . 'Got: %s',
                    $position,
                    get_debug_type($value),
                ));
            }

            $this->bindSingleValue($stmt, $position, $value);
            $position++;
        }
    }

    /**
     * Binds a single scalar value to a PDOStatement at the given position.
     */
    private function bindSingleValue(\PDOStatement $stmt, int $position, mixed $value): void
    {
        if ($value === null) {
            $stmt->bindValue($position, null, \PDO::PARAM_NULL);
        } elseif (is_int($value)) {
            $stmt->bindValue($position, $value, \PDO::PARAM_INT);
        } elseif (is_bool($value)) {
            $stmt->bindValue($position, $value ? 1 : 0, \PDO::PARAM_INT);
        } elseif (is_float($value)) {
            // Use sprintf with %.17g to preserve full float precision.
            // Sybase CONVERT(REAL, ?) in the SQL handles the type conversion.
            $stmt->bindValue($position, sprintf('%.17g', $value), \PDO::PARAM_STR);
        } else {
            $stmt->bindValue($position, (string) $value, \PDO::PARAM_STR);
        }
    }

    /**
     * Expands array parameters into individual positional placeholders.
     *
     * When a positional parameter (?) corresponds to an array value, replaces
     * the single ? with multiple ?, ?, ... placeholders and flattens the
     * array values into the params list. This ensures the SQL placeholder
     * count always matches the bind count.
     *
     * @param string $sql SQL with ? placeholders.
     * @param array $params Positional parameters (may contain arrays for IN clauses).
     * @return array{0: string, 1: array} Tuple of [expanded SQL, flat params].
     */
    private function expandArrayParams(string $sql, array $params): array
    {
        $hasArray = false;
        foreach ($params as $value) {
            if (is_array($value)) {
                $hasArray = true;
                break;
            }
        }

        if (!$hasArray) {
            return [$sql, $params];
        }

        // Rebuild SQL and params, expanding arrays
        $flatParams = [];
        $paramIndex = 0;
        $result = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            if ($sql[$i] === '?') {
                $value = $params[$paramIndex] ?? null;

                if (is_array($value)) {
                    // Normalize: if values are non-scalar, use keys instead
                    $scalarValues = $this->normalizeExpandValues($value);
                    $count = count($scalarValues);
                    if ($count === 0) {
                        // Empty array: use impossible condition (1 = 0) to match nothing
                        $result .= '1 = 0';
                    } else {
                        $result .= implode(', ', array_fill(0, $count, '?'));
                        foreach ($scalarValues as $item) {
                            $flatParams[] = $item;
                        }
                    }
                } else {
                    $result .= '?';
                    $flatParams[] = $value;
                }

                $paramIndex++;
            } elseif ($sql[$i] === "'") {
                // Skip string literals to avoid replacing ? inside them
                $result .= "'";
                $i++;
                while ($i < $length && $sql[$i] !== "'") {
                    $result .= $sql[$i];
                    $i++;
                }
                if ($i < $length) {
                    $result .= "'";
                }
            } else {
                $result .= $sql[$i];
            }
        }

        return [$result, $flatParams];
    }

    /**
     * Normalizes array values for SQL expansion.
     * If all values are scalar/null, returns them as-is.
     * If values contain arrays/objects, uses array_keys() instead (Doctrine compatibility).
     *
     * @param array $value The array to normalize.
     * @return list<scalar|null> Flat list of scalar values.
     */
    private function normalizeExpandValues(array $value): array
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                // Values are non-scalar — use keys as the actual values
                return array_values(array_map(
                    fn($k) => is_int($k) || is_string($k) || is_float($k) ? $k : (string) $k,
                    array_keys($value),
                ));
            }
        }

        return array_values($value);
    }

    /**
     * Convierte parámetros string de UTF-8 a ISO-8859-1 antes de enviar al servidor.
     *
     * @param array $params Parámetros de la consulta.
     * @return array Parámetros con strings convertidos si charset_conversion está habilitado.
     */
    private function convertParams(array $params): array
    {
        if (!$this->charsetConversion) {
            return $params;
        }

        return array_map(fn($v) => is_string($v) ? $this->convertToDatabase($v) : $v, $params);
    }

    /**
     * Convierte valores string de un resultado de ISO-8859-1 a UTF-8.
     *
     * @param array $row Fila de resultado de la base de datos.
     * @return array Fila con strings convertidos si charset_conversion está habilitado.
     */
    public function convertResultRow(array $row): array
    {
        if (!$this->charsetConversion) {
            return $row;
        }

        return array_map(fn($v) => is_string($v) ? $this->convertFromDatabase($v) : $v, $row);
    }

    /**
     * Convierte un string UTF-8 a ISO-8859-1 para enviar a la base de datos.
     * Si la conversión falla, preserva el string original (sin excepción).
     */
    private function convertToDatabase(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        if ($converted === false) {
            $this->logger?->warning('Charset conversion failed (UTF-8 → ISO-8859-1) for value: ' . mb_substr($value, 0, 100));

            return $value;
        }

        return $converted;
    }

    /**
     * Convierte un string ISO-8859-1 de la base de datos a UTF-8.
     * Si la conversión falla, preserva el string original (sin excepción).
     */
    private function convertFromDatabase(string $value): string
    {
        $converted = @iconv('ISO-8859-1', 'UTF-8', $value);

        if ($converted === false) {
            $this->logger?->warning('Charset conversion failed (ISO-8859-1 → UTF-8) for value: ' . mb_substr($value, 0, 100));

            return $value;
        }

        return $converted;
    }

    /**
     * Wraps PDOExceptions into appropriate ORM exceptions.
     *
     * Connection-related errors become ConnectionLostException.
     * Other errors become PersistenceException to avoid masking SQL errors
     * as connection issues.
     *
     * @throws ConnectionLostException When the connection is lost.
     * @throws \SybaseORM\Exception\PersistenceException For other database errors.
     * @return never
     */
    private function handlePdoException(\PDOException $e): never
    {
        $sqlState = $e->getCode();

        if (in_array((string) $sqlState, self::CONNECTION_LOST_CODES, true) || $this->isConnectionLostMessage($e->getMessage())) {
            $this->connection = null;
            $this->inTransaction = false;
            $this->stmtCache = [];

            throw new ConnectionLostException(
                'Connection to Sybase ASE was lost: ' . $e->getMessage(),
                0,
                $e,
                (string) $sqlState,
            );
        }

        // Para errores no relacionados con la conexión (syntax error, constraint violation, etc.)
        throw new PersistenceException(
            'Database operation failed: ' . $e->getMessage(),
            0,
            $e,
        );
    }

    private function isConnectionLostMessage(string $message): bool
    {
        $patterns = [
            'server has gone away',
            'lost connection',
            'connection reset',
            'broken pipe',
            'connection timed out',
            'no connection to the server',
        ];

        $lower = strtolower($message);
        foreach ($patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a PDOException indicates a deadlock.
     * Sybase ASE deadlock messages typically contain "deadlock" or error 1205.
     */
    public static function isDeadlock(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'error 1205')
            || str_contains($message, 'chosen as the deadlock victim');
    }

    /**
     * Factory method for PDO creation — allows overriding in tests.
     */
    protected function createPdo(string $dsn, string $username, string $password, array $options): \PDO
    {
        return new \PDO($dsn, $username, $password, $options);
    }

    public function ping(): bool
    {
        try {
            $this->getConnection()->exec('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Forces a reconnection by closing the current connection.
     * The next call to getConnection() will establish a new connection.
     */
    public function reconnect(): void
    {
        $this->connection = null;
        $this->stmtCache = [];
        $this->inTransaction = false;
        $this->savepointStack = [];
        $this->savepointCounter = 0;
    }

    /**
     * Gets or creates a cached prepared statement with LRU eviction.
     */
    private function getCachedStatement(\PDO $pdo, string $sql): \PDOStatement
    {
        if (isset($this->stmtCache[$sql])) {
            // Move to end (most recently used) by re-inserting
            $stmt = $this->stmtCache[$sql];
            unset($this->stmtCache[$sql]);
            $this->stmtCache[$sql] = $stmt;

            return $stmt;
        }

        $stmt = $pdo->prepare($sql);

        // Evict oldest entry if cache is full
        if (count($this->stmtCache) >= self::STMT_CACHE_MAX_SIZE) {
            $oldestKey = array_key_first($this->stmtCache);
            if ($oldestKey !== null) {
                unset($this->stmtCache[$oldestKey]);
            }
        }

        $this->stmtCache[$sql] = $stmt;

        return $stmt;
    }

    public function getServerVersion(): string
    {
        $pdo = $this->getConnection();
        $stmt = $this->getCachedStatement($pdo, 'SELECT @@version');
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        $stmt->closeCursor();

        return is_array($row) ? ($row[0] ?? 'unknown') : 'unknown';
    }

    /**
     * Returns the current database name from the configuration.
     */
    public function getDatabaseName(): string
    {
        return $this->config['dbname'] ?? '';
    }

    /**
     * Returns the configured host.
     */
    public function getHost(): string
    {
        return $this->config['host'] ?? 'localhost';
    }

    /**
     * Returns the configured port.
     */
    public function getPort(): int
    {
        return (int) ($this->config['port'] ?? 5000);
    }

    /**
     * Returns true if this connection is configured as read-only.
     * Read-only connections reject executeStatement() and beginTransaction().
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Returns the connection configuration for inspection (password is masked).
     *
     * @return array<string, mixed>
     */
    public function getConfigSafe(): array
    {
        $safe = $this->config;
        if (isset($safe['password'])) {
            $safe['password'] = '***';
        }
        $safe['read_only'] = $this->readOnly;

        return $safe;
    }
}
