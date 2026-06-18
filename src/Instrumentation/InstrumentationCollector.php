<?php

declare(strict_types=1);

namespace SybaseORM\Instrumentation;

/**
 * Collects and stores instrumentation data for profiling/debugging.
 *
 * Records queries, hydration events, cache hits/misses, and transactions.
 * Useful for development profilers, Symfony Web Profiler bundles, or
 * custom monitoring dashboards.
 *
 * Usage:
 *     $collector = new InstrumentationCollector();
 *     $em = OrmFactory::create(['instrumentation' => $collector, ...]);
 *
 *     // After request:
 *     $collector->getQueries();      // All executed queries with timing
 *     $collector->getTotalQueryTime(); // Total DB time in ms
 *     $collector->getStats();         // Summary stats
 */
final class InstrumentationCollector implements OrmInstrumentationInterface
{
    /** @var array<int, array{sql: string, params: array, connection: string, time_ms: float|null}> */
    private array $queries = [];

    /** @var array{hydrations: int, collections: int, identity_hits: int, identity_misses: int, cache_hits: int, cache_misses: int, cache_writes: int, lazy_loads: int, flushes: int, transactions: int, rollbacks: int} */
    private array $counters = [
        'hydrations' => 0,
        'collections' => 0,
        'identity_hits' => 0,
        'identity_misses' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'cache_writes' => 0,
        'lazy_loads' => 0,
        'flushes' => 0,
        'transactions' => 0,
        'rollbacks' => 0,
    ];

    private float $totalQueryTime = 0.0;
    private float $totalFlushTime = 0.0;
    private int $currentQueryIndex = -1;

    // ── Queries ─────────────────────────────────────────────────────

    public function onQueryStart(string $sql, array $params, string $connection): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'params' => $params,
            'connection' => $connection,
            'time_ms' => null,
        ];
        $this->currentQueryIndex = count($this->queries) - 1;
    }

    public function onQueryEnd(string $sql, array $params, string $connection, float $timeMs): void
    {
        if ($this->currentQueryIndex >= 0 && isset($this->queries[$this->currentQueryIndex])) {
            $this->queries[$this->currentQueryIndex]['time_ms'] = $timeMs;
        }
        $this->totalQueryTime += $timeMs;
    }

    // ── Hydration ───────────────────────────────────────────────────

    public function onEntityHydrated(string $entityClass, mixed $id): void
    {
        $this->counters['hydrations']++;
    }

    public function onCollectionHydrated(string $entityClass, int $count): void
    {
        $this->counters['collections']++;
    }

    // ── Identity Map ────────────────────────────────────────────────

    public function onIdentityMapHit(string $entityClass, mixed $id): void
    {
        $this->counters['identity_hits']++;
    }

    public function onIdentityMapMiss(string $entityClass, mixed $id): void
    {
        $this->counters['identity_misses']++;
    }

    public function onIdentityMapPut(string $entityClass, mixed $id): void {}

    // ── Unit of Work ────────────────────────────────────────────────

    public function onEntityScheduled(string $operation, string $entityClass, mixed $id): void {}

    public function onFlushStart(int $inserts, int $updates, int $deletes): void
    {
        $this->counters['flushes']++;
    }

    public function onFlushEnd(float $timeMs): void
    {
        $this->totalFlushTime += $timeMs;
    }

    // ── Lazy Loading ────────────────────────────────────────────────

    public function onLazyLoad(string $entityClass, mixed $id): void
    {
        $this->counters['lazy_loads']++;
    }

    // ── Cache ───────────────────────────────────────────────────────

    public function onCacheHit(string $key): void
    {
        $this->counters['cache_hits']++;
    }

    public function onCacheMiss(string $key): void
    {
        $this->counters['cache_misses']++;
    }

    public function onCacheWrite(string $key): void
    {
        $this->counters['cache_writes']++;
    }

    // ── Transactions ────────────────────────────────────────────────

    public function onTransactionBegin(): void
    {
        $this->counters['transactions']++;
    }

    public function onTransactionCommit(float $durationMs): void {}

    public function onTransactionRollback(string $reason): void
    {
        $this->counters['rollbacks']++;
    }

    // ── Accessors ───────────────────────────────────────────────────

    /**
     * Returns all recorded queries with timing information.
     *
     * @return array<int, array{sql: string, params: array, connection: string, time_ms: float|null}>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * Returns the number of queries executed.
     */
    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    /**
     * Returns total query execution time in milliseconds.
     */
    public function getTotalQueryTime(): float
    {
        return $this->totalQueryTime;
    }

    /**
     * Returns total flush time in milliseconds.
     */
    public function getTotalFlushTime(): float
    {
        return $this->totalFlushTime;
    }

    /**
     * Returns all counter statistics.
     *
     * @return array<string, int|float>
     */
    public function getStats(): array
    {
        return array_merge($this->counters, [
            'query_count' => $this->getQueryCount(),
            'total_query_time_ms' => $this->totalQueryTime,
            'total_flush_time_ms' => $this->totalFlushTime,
        ]);
    }

    /**
     * Returns queries that exceeded a time threshold (slow queries).
     *
     * @param float $thresholdMs Minimum time in ms to be considered slow
     * @return array<int, array{sql: string, params: array, connection: string, time_ms: float}>
     */
    public function getSlowQueries(float $thresholdMs = 100.0): array
    {
        return array_values(array_filter(
            $this->queries,
            fn(array $q) => ($q['time_ms'] ?? 0) >= $thresholdMs,
        ));
    }

    /**
     * Resets all collected data.
     */
    public function reset(): void
    {
        $this->queries = [];
        $this->counters = array_map(fn() => 0, $this->counters);
        $this->totalQueryTime = 0.0;
        $this->totalFlushTime = 0.0;
        $this->currentQueryIndex = -1;
    }
}
