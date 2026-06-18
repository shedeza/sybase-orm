<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Hydrator\HydratorInterface;

/**
 * Executes raw SQL and maps results to entities.
 *
 * Usage:
 *     $nq = $em->createNativeQuery(
 *         'SELECT * FROM users WHERE email LIKE ?',
 *         [User::class]
 *     );
 *     $users = $nq->execute(['%@gmail.com']);
 *
 *     // Scalar results:
 *     $nq = $em->createNativeQuery('SELECT COUNT(*) FROM users');
 *     $count = $nq->executeScalar([]);
 */
final class NativeQuery
{
    /**
     * @param string $sql Raw SQL query
     * @param class-string|null $entityClass Entity class to hydrate results into (null for raw arrays)
     */
    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager,
        private readonly ?HydratorInterface $hydrator,
        private readonly string $sql,
        private readonly ?string $entityClass = null,
    ) {}

    /**
     * Executes the query and returns hydrated entities or raw rows.
     *
     * @param array $params Positional parameters
     * @return array Hydrated entities or associative arrays
     */
    public function execute(array $params = []): array
    {
        $stmt = $this->connectionManager->executeQuery($this->sql, $params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $rows = array_map(fn(array $row) => $this->connectionManager->convertResultRow($row), $rows);

        if ($this->entityClass !== null && $this->hydrator !== null) {
            return $this->hydrator->hydrateAll($rows, $this->entityClass);
        }

        return $rows;
    }

    /**
     * Executes the query and returns the first result or null.
     *
     * @param array $params Positional parameters
     */
    public function executeOne(array $params = []): mixed
    {
        $results = $this->execute($params);

        return $results[0] ?? null;
    }

    /**
     * Executes the query and returns a single scalar value (first column, first row).
     *
     * @param array $params Positional parameters
     */
    public function executeScalar(array $params = []): mixed
    {
        $stmt = $this->connectionManager->executeQuery($this->sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        $stmt->closeCursor();

        if ($row === false) {
            return null;
        }

        return $row[0] ?? null;
    }

    /**
     * Executes a modification statement (INSERT/UPDATE/DELETE) and returns affected rows.
     *
     * @param array $params Positional parameters
     */
    public function executeStatement(array $params = []): int
    {
        return $this->connectionManager->executeStatement($this->sql, $params);
    }
}
