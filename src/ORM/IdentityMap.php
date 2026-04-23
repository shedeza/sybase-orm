<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Guarantees object identity: one entity instance per class + id within a session.
 *
 * Uses a nested array structure: $map[className][id] = entity.
 */
final class IdentityMap implements IdentityMapInterface
{
    /** @var array<string, array<string, object>> */
    private array $map = [];

    public function put(string $entityClass, mixed $id, object $entity): void
    {
        $this->map[$entityClass][$this->deriveKey($id)] = $entity;
    }

    public function get(string $entityClass, mixed $id): ?object
    {
        return $this->map[$entityClass][$this->deriveKey($id)] ?? null;
    }

    public function contains(string $entityClass, mixed $id): bool
    {
        return isset($this->map[$entityClass][$this->deriveKey($id)]);
    }

    public function remove(string $entityClass, mixed $id): void
    {
        unset($this->map[$entityClass][$this->deriveKey($id)]);
    }

    /**
     * Derives a deterministic string key from a scalar or composite id.
     * Scalar: (string) $id
     * Array:  ksort then implode sorted values with pipe separator.
     */
    private function deriveKey(mixed $id): string
    {
        if (is_array($id)) {
            ksort($id);
            return implode('|', array_map('strval', $id));
        }

        return (string) $id;
    }

    public function clear(): void
    {
        $this->map = [];
    }
}
