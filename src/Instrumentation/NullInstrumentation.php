<?php

declare(strict_types=1);

namespace SybaseORM\Instrumentation;

/**
 * No-op instrumentation implementation.
 *
 * All methods are empty — zero overhead in production.
 * The PHP JIT/optimizer will inline and eliminate these calls.
 */
final class NullInstrumentation implements OrmInstrumentationInterface
{
    public function onQueryStart(string $sql, array $params, string $connection): void {}

    public function onQueryEnd(string $sql, array $params, string $connection, float $timeMs): void {}

    public function onEntityHydrated(string $entityClass, mixed $id): void {}

    public function onCollectionHydrated(string $entityClass, int $count): void {}

    public function onIdentityMapHit(string $entityClass, mixed $id): void {}

    public function onIdentityMapMiss(string $entityClass, mixed $id): void {}

    public function onIdentityMapPut(string $entityClass, mixed $id): void {}

    public function onEntityScheduled(string $operation, string $entityClass, mixed $id): void {}

    public function onFlushStart(int $inserts, int $updates, int $deletes): void {}

    public function onFlushEnd(float $timeMs): void {}

    public function onLazyLoad(string $entityClass, mixed $id): void {}

    public function onCacheHit(string $key): void {}

    public function onCacheMiss(string $key): void {}

    public function onCacheWrite(string $key): void {}

    public function onTransactionBegin(): void {}

    public function onTransactionCommit(float $durationMs): void {}

    public function onTransactionRollback(string $reason): void {}
}
