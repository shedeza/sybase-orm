<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

use Psr\Log\LoggerInterface;
use SybaseORM\Exception\ConnectionLostException;

/**
 * Decorator that adds automatic retry logic to a ConnectionManager.
 *
 * On ConnectionLostException, reconnects and retries the operation up to $maxRetries times.
 * Only retries read operations (executeQuery) by default. Write operations (executeStatement)
 * are only retried if they are NOT inside a transaction (to avoid partial commit issues).
 *
 * Usage:
 *     $inner = new ConnectionManager($config, $logger);
 *     $connection = new RetryConnectionManager($inner, maxRetries: 3, delayMs: 200);
 */
final class RetryConnectionManager implements ConnectionManagerInterface
{
    public function __construct(
        private readonly ConnectionManager $inner,
        private readonly int $maxRetries = 3,
        private readonly int $delayMs = 100,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getConnection(): \PDO
    {
        return $this->inner->getConnection();
    }

    public function executeQuery(string $sql, array $params = []): \PDOStatement
    {
        return $this->retryOnConnectionLost(
            fn() => $this->inner->executeQuery($sql, $params),
            'executeQuery',
        );
    }

    public function executeStatement(string $sql, array $params = []): int
    {
        // Don't retry writes inside a transaction — partial state is dangerous
        if ($this->inner->isInTransaction()) {
            return $this->inner->executeStatement($sql, $params);
        }

        return $this->retryOnConnectionLost(
            fn() => $this->inner->executeStatement($sql, $params),
            'executeStatement',
        );
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function setTransactionIsolation(string $level): void
    {
        $this->inner->setTransactionIsolation($level);
    }

    public function convertResultRow(array $row): array
    {
        return $this->inner->convertResultRow($row);
    }

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function isInTransaction(): bool
    {
        return $this->inner->isInTransaction();
    }

    /**
     * Delegates to the inner ConnectionManager for savepoint creation.
     */
    public function createSavepoint(): string
    {
        return $this->inner->createSavepoint();
    }

    /**
     * Delegates to the inner ConnectionManager for savepoint rollback.
     */
    public function rollbackToSavepoint(string $name): void
    {
        $this->inner->rollbackToSavepoint($name);
    }

    /**
     * Delegates to the inner ConnectionManager for savepoint release.
     */
    public function releaseSavepoint(string $name): void
    {
        $this->inner->releaseSavepoint($name);
    }

    /**
     * Forces a reconnection on the inner ConnectionManager.
     */
    public function reconnect(): void
    {
        $this->inner->reconnect();
    }

    /**
     * Returns true if the inner connection is read-only.
     */
    public function isReadOnly(): bool
    {
        return $this->inner->isReadOnly();
    }

    /**
     * Returns the connection config (password masked).
     *
     * @return array<string, mixed>
     */
    public function getConfigSafe(): array
    {
        return $this->inner->getConfigSafe();
    }

    /**
     * Returns the database name from the inner connection.
     */
    public function getDatabaseName(): string
    {
        return $this->inner->getDatabaseName();
    }

    /**
     * Returns the host from the inner connection.
     */
    public function getHost(): string
    {
        return $this->inner->getHost();
    }

    /**
     * Returns the port from the inner connection.
     */
    public function getPort(): int
    {
        return $this->inner->getPort();
    }

    /**
     * Retries an operation on ConnectionLostException, reconnecting between attempts.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws ConnectionLostException If all retries are exhausted.
     */
    private function retryOnConnectionLost(callable $operation, string $operationName): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            try {
                return $operation();
            } catch (ConnectionLostException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt > $this->maxRetries) {
                    break;
                }

                $this->logger?->warning(sprintf(
                    'Connection lost during %s (attempt %d/%d). Reconnecting in %dms...',
                    $operationName,
                    $attempt,
                    $this->maxRetries,
                    $this->delayMs,
                ));

                // Wait before retry
                if ($this->delayMs > 0) {
                    usleep($this->delayMs * 1000);
                }

                // Force reconnection
                $this->inner->reconnect();
            }
        }

        throw $lastException;
    }
}
