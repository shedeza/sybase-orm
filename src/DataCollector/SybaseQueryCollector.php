<?php

declare(strict_types=1);

namespace SybaseORM\DataCollector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

/**
 * Symfony Web Profiler DataCollector for SybaseORM queries.
 *
 * Collects executed queries, timing, and connection info for display
 * in the Symfony debug toolbar and profiler panel.
 */
final class SybaseQueryCollector extends DataCollector
{
    /** @var array<int, array{sql: string, params: int, time: float, connection: string}> */
    private array $queries = [];

    private float $totalTime = 0.0;

    public function getName(): string
    {
        return 'sybase_orm';
    }

    /**
     * Records a query execution.
     */
    public function addQuery(string $sql, int $paramCount, float $timeMs, string $connection = 'default'): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'params' => $paramCount,
            'time' => $timeMs,
            'connection' => $connection,
        ];
        $this->totalTime += $timeMs;
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'queries' => $this->queries,
            'query_count' => count($this->queries),
            'total_time' => $this->totalTime,
        ];
    }

    public function reset(): void
    {
        $this->data = [];
        $this->queries = [];
        $this->totalTime = 0.0;
    }

    public function getQueryCount(): int
    {
        return $this->data['query_count'] ?? 0;
    }

    public function getTotalTime(): float
    {
        return $this->data['total_time'] ?? 0.0;
    }

    /**
     * @return array<int, array{sql: string, params: int, time: float, connection: string}>
     */
    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }
}
