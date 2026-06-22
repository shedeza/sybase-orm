<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

use Psr\Log\LoggerInterface;

/**
 * Simple connection pool for long-running processes (workers, daemons).
 *
 * Manages a pool of ConnectionManager instances, cycling through them
 * to distribute load and handle connection timeouts gracefully.
 *
 * Usage:
 *     $pool = new ConnectionPool($config, maxConnections: 5);
 *     $conn = $pool->acquire();    // Get a connection from the pool
 *     // ... use $conn ...
 *     $pool->release($conn);       // Return to pool
 *
 * For most web requests (single connection per request), use ConnectionManager directly.
 * ConnectionPool is for batch processing, queue workers, and long-running CLI commands.
 */
final class ConnectionPool
{
    /** @var ConnectionManager[] Available (idle) connections */
    private array $idle = [];

    /** @var \SplObjectStorage<ConnectionManager, true> Active (in-use) connections */
    private \SplObjectStorage $active;

    private int $created = 0;

    public function __construct(
        private readonly array $config,
        private readonly int $maxConnections = 5,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->active = new \SplObjectStorage();
    }

    /**
     * Acquires a connection from the pool.
     * Creates a new connection if the pool is empty and max is not reached.
     *
     * @throws \RuntimeException If the pool is exhausted.
     */
    public function acquire(): ConnectionManager
    {
        // Try to reuse an idle connection
        if (!empty($this->idle)) {
            $conn = array_pop($this->idle);

            // Verify it's still alive
            if ($conn->ping()) {
                $this->active->attach($conn);

                return $conn;
            }

            // Dead connection — discard and try again
            $this->created--;
            $this->logger?->warning('Discarded dead pooled connection.');

            return $this->acquire();
        }

        // Create new connection if under limit
        if ($this->created < $this->maxConnections) {
            $conn = new ConnectionManager($this->config, $this->logger);
            $this->created++;
            $this->active->attach($conn);

            return $conn;
        }

        throw new \RuntimeException(sprintf(
            'Connection pool exhausted (%d/%d active). Release connections or increase maxConnections.',
            $this->active->count(),
            $this->maxConnections,
        ));
    }

    /**
     * Releases a connection back to the pool.
     */
    public function release(ConnectionManager $conn): void
    {
        if (!$this->active->contains($conn)) {
            return;
        }

        $this->active->detach($conn);

        // Don't return connections with active transactions
        if ($conn->isInTransaction()) {
            $this->logger?->warning('Released connection with active transaction — discarding.');
            $this->created--;

            return;
        }

        $this->idle[] = $conn;
    }

    /**
     * Returns pool statistics.
     *
     * @return array{total: int, active: int, idle: int, max: int}
     */
    public function getStats(): array
    {
        return [
            'total' => $this->created,
            'active' => $this->active->count(),
            'idle' => count($this->idle),
            'max' => $this->maxConnections,
        ];
    }

    /**
     * Closes all connections and resets the pool.
     */
    public function drain(): void
    {
        foreach ($this->idle as $conn) {
            $conn->reconnect(); // forces close
        }

        $this->idle = [];
        $this->active = new \SplObjectStorage();
        $this->created = 0;
    }
}
