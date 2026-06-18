<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

/**
 * Interface for connections that can provide query execution plans.
 *
 * Sybase ASE uses SET SHOWPLAN ON / SET NOEXEC ON to display the
 * query plan without executing the query.
 */
interface ExplainableConnectionInterface
{
    /**
     * Returns the execution plan for a SQL query.
     *
     * Uses Sybase ASE's SHOWPLAN to retrieve the query optimizer's plan
     * without actually executing the query.
     *
     * @param string $sql The SQL query to explain
     * @param array $params Query parameters (for context, not executed)
     * @return array<int, array{step: int, description: string}> Plan steps
     */
    public function explain(string $sql, array $params = []): array;
}
