<?php

declare(strict_types=1);

namespace SybaseORM\Collection;

/**
 * A simple in-memory collection that does not interact with the database.
 *
 * Use this for collections that are created in application code, not loaded
 * from the database. For database-backed lazy-loading collections, use
 * PersistentCollection instead.
 *
 * @template T of object
 * @implements Collection<T>
 */
class ArrayCollection implements Collection
{
    /** @var T[] */
    protected array $elements;

    /**
     * @param T[] $elements Initial elements
     */
    public function __construct(array $elements = [])
    {
        $this->elements = array_values($elements);
    }

    public function toArray(): array
    {
        return $this->elements;
    }

    public function add(object $element): void
    {
        $this->elements[] = $element;
    }

    public function remove(object $element): bool
    {
        foreach ($this->elements as $key => $existing) {
            if ($existing === $element) {
                unset($this->elements[$key]);
                $this->elements = array_values($this->elements);

                return true;
            }
        }

        return false;
    }

    public function contains(object $element): bool
    {
        foreach ($this->elements as $existing) {
            if ($existing === $element) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->elements === [];
    }

    public function first(): ?object
    {
        return $this->elements[0] ?? null;
    }

    public function last(): ?object
    {
        if ($this->elements === []) {
            return null;
        }

        return $this->elements[array_key_last($this->elements)];
    }

    public function filter(callable $predicate): static
    {
        /** @phpstan-ignore-next-line new.static */
        return new static(array_values(array_filter($this->elements, $predicate)));
    }

    public function map(callable $callback): array
    {
        return array_map($callback, $this->elements);
    }

    public function clear(): void
    {
        $this->elements = [];
    }

    // ── IteratorAggregate ───────────────────────────────────────

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->elements);
    }

    // ── Countable ───────────────────────────────────────────────

    public function count(): int
    {
        return count($this->elements);
    }

    // ── ArrayAccess ─────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->elements[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->elements[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->elements[] = $value;
        } else {
            $this->elements[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->elements[$offset]);
        $this->elements = array_values($this->elements);
    }

    // ── JsonSerializable ────────────────────────────────────────

    public function jsonSerialize(): array
    {
        return $this->elements;
    }
}
