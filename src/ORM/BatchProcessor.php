<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Processes large datasets in chunks with automatic memory management.
 *
 * Clears the EntityManager identity map periodically to prevent memory
 * exhaustion during batch operations.
 *
 * Usage:
 *     $batch = new BatchProcessor($em, chunkSize: 100);
 *
 *     // Process all users in chunks of 100
 *     $batch->iterate('SELECT u FROM User u', [], function (object $entity) use ($em) {
 *         $entity->status = 'processed';
 *     });
 *
 *     // Chunk processing with explicit flush control
 *     $batch->chunk(User::class, [], 200, function (array $chunk) {
 *         foreach ($chunk as $user) { ... }
 *     });
 */
final class BatchProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly int $chunkSize = 100,
    ) {}

    /**
     * Iterates over all results of an OQL query in chunks, calling $callback
     * for each entity. Flushes and clears every $chunkSize entities.
     *
     * @param string $oql OQL query
     * @param array<string, mixed> $params Query parameters
     * @param callable(object): void $callback Called for each entity
     * @param bool $autoFlush Whether to flush after each chunk (default: true)
     * @return int Number of entities processed
     */
    public function iterate(string $oql, array $params, callable $callback, bool $autoFlush = true): int
    {
        $processed = 0;
        $iterator = $this->entityManager->queryIterator($oql, $params);

        foreach ($iterator as $entity) {
            $callback($entity);
            $processed++;

            if ($processed % $this->chunkSize === 0) {
                if ($autoFlush) {
                    $this->entityManager->flush();
                }
                $this->entityManager->clear();
            }
        }

        // Final flush for remaining entities
        if ($autoFlush && $processed % $this->chunkSize !== 0) {
            $this->entityManager->flush();
        }

        return $processed;
    }

    /**
     * Loads entities in chunks using offset pagination.
     * Calls $callback with each chunk array.
     *
     * @param string $entityClass Entity FQCN
     * @param array<string, mixed> $criteria findBy criteria
     * @param int $chunkSize Override default chunk size
     * @param callable(object[]): void $callback Called for each chunk
     * @param array<string, string>|null $orderBy Optional ordering
     * @return int Total entities processed
     */
    public function chunk(
        string $entityClass,
        array $criteria,
        int $chunkSize,
        callable $callback,
        ?array $orderBy = null,
    ): int {
        $offset = 0;
        $total = 0;
        $repo = $this->entityManager->getRepository($entityClass);

        do {
            $results = $repo->findBy($criteria, $orderBy, $chunkSize, $offset);
            $count = count($results);

            if ($count > 0) {
                $callback($results);
                $total += $count;
            }

            $this->entityManager->clear();
            $offset += $chunkSize;
        } while ($count === $chunkSize);

        return $total;
    }
}
