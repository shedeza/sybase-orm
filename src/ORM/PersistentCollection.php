<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * A collection wrapper that supports lazy loading for OneToMany/ManyToMany relationships.
 *
 * When accessed for the first time (iteration, count, array access), the collection
 * triggers its initializer closure to load the related entities from the database.
 *
 * This prevents N+1 queries when the collection is never accessed, and loads all
 * related entities in a single query when it is.
 *
 * @template T of object
 * @implements \IteratorAggregate<int, T>
 * @implements \Countable
 * @implements \ArrayAccess<int, T>
 */
final class PersistentCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    private bool $initialized = false;

    /** @var T[] */
    private array $elements = [];

    /** @var (\Closure(): T[])|null */
    private ?\Closure $initializer;

    /**
     * @param (\Closure(): T[])|null $initializer Closure that loads and returns the collection elements
     */
    public function __construct(?\Closure $initializer = null)
    {
        $this->initializer = $initializer;
    }

    /**
     * Creates a PersistentCollection that is already initialized with the given elements.
     *
     * @param T[] $elements
     * @return self<T>
     */
    public static function fromArray(array $elements): self
    {
        $collection = new self();
        $collection->elements = $elements;
        $collection->initialized = true;

        return $collection;
    }

    /**
     * Returns true if the collection has been loaded from the database.
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Forces initialization of the collection.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        if ($this->initializer !== null) {
            $this->elements = ($this->initializer)();
            $this->initializer = null;
        }
    }

    /**
     * Returns all elements as a plain array.
     *
     * @return T[]
     */
    public function toArray(): array
    {
        $this->initialize();

        return $this->elements;
    }

    /**
     * Adds an element to the collection.
     *
     * @param T $element
     */
    public function add(object $element): void
    {
        $this->initialize();
        $this->elements[] = $element;
    }

    /**
     * Removes an element from the collection.
     *
     * @param T $element
     * @return bool True if the element was found and removed
     */
    public function remove(object $element): bool
    {
        $this->initialize();

        foreach ($this->elements as $key => $existing) {
            if ($existing === $element) {
                unset($this->elements[$key]);
                $this->elements = array_values($this->elements);

                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the collection contains the given element.
     *
     * @param T $element
     */
    public function contains(object $element): bool
    {
        $this->initialize();

        foreach ($this->elements as $existing) {
            if ($existing === $element) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if the collection is empty.
     */
    public function isEmpty(): bool
    {
        $this->initialize();

        return $this->elements === [];
    }

    /**
     * Returns the first element or null if empty.
     *
     * @return T|null
     */
    public function first(): ?object
    {
        $this->initialize();

        return $this->elements[0] ?? null;
    }

    /**
     * Returns the last element or null if empty.
     *
     * @return T|null
     */
    public function last(): ?object
    {
        $this->initialize();

        if ($this->elements === []) {
            return null;
        }

        return $this->elements[array_key_last($this->elements)];
    }

    /**
     * Filters the collection using a callback.
     *
     * @param callable(T): bool $predicate
     * @return self<T>
     */
    public function filter(callable $predicate): self
    {
        $this->initialize();

        return self::fromArray(array_values(array_filter($this->elements, $predicate)));
    }

    /**
     * Maps the collection using a callback.
     *
     * @template U
     * @param callable(T): U $callback
     * @return U[]
     */
    public function map(callable $callback): array
    {
        $this->initialize();

        return array_map($callback, $this->elements);
    }

    // ── IteratorAggregate ───────────────────────────────────────

    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        return new \ArrayIterator($this->elements);
    }

    // ── Countable ───────────────────────────────────────────────

    public function count(): int
    {
        $this->initialize();

        return count($this->elements);
    }

    // ── ArrayAccess ─────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return isset($this->elements[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->initialize();

        return $this->elements[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->initialize();

        if ($offset === null) {
            $this->elements[] = $value;
        } else {
            $this->elements[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->initialize();

        unset($this->elements[$offset]);
        $this->elements = array_values($this->elements);
    }
}
