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
     * Scalar: type-prefixed string (e.g., "i:1" for int, "s:abc" for string)
     * Array:  ksort then implode type-prefixed values with pipe separator.
     *
     * Type prefixes prevent collisions between int 1 and string '1'.
     */
    private function deriveKey(mixed $id): string
    {
        if (is_array($id)) {
            ksort($id);
            return implode('|', array_map(fn($v) => $this->typedValue($v), $id));
        }

        return $this->typedValue($id);
    }

    /**
     * Returns a type-prefixed string representation of a value.
     */
    private function typedValue(mixed $value): string
    {
        if ($value === null) {
            return 'n:';
        }
        if (is_int($value)) {
            return 'i:' . $value;
        }
        if (is_float($value)) {
            return 'f:' . $value;
        }
        if (is_bool($value)) {
            return 'b:' . ($value ? '1' : '0');
        }

        return 's:' . $value;
    }

    public function clear(): void
    {
        $this->map = [];
    }

    public function clearClass(string $entityClass): void
    {
        unset($this->map[$entityClass]);
    }
}
