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
        $key = $this->deriveKey($id);

        // Evitar sobrescribir una entidad real con un Proxy.
        // La entidad real siempre tiene precedencia para mantener la integridad de los datos en memoria.
        if (isset($this->map[$entityClass][$key])) {
            $existing = $this->map[$entityClass][$key];
            if ($entity instanceof \SybaseORM\Proxy\LazyLoadingProxy && !($existing instanceof \SybaseORM\Proxy\LazyLoadingProxy)) {
                return;
            }
        }

        $this->map[$entityClass][$key] = $entity;
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
    public static function deriveKey(mixed $id): string
    {
        if (is_array($id)) {
            ksort($id);

            return implode('|', array_map(fn($v) => self::typedValue($v), $id));
        }

        return self::typedValue($id);
    }

    /**
     * Returns a type-prefixed string representation of a value.
     */
    public static function typedValue(mixed $value): string
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

    /**
     * Returns the number of entities stored across all classes.
     */
    public function count(): int
    {
        $total = 0;
        foreach ($this->map as $entities) {
            $total += count($entities);
        }

        return $total;
    }

    /**
     * Returns the number of entities stored for a specific class.
     */
    public function countClass(string $entityClass): int
    {
        return count($this->map[$entityClass] ?? []);
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
