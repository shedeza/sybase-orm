<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Collection\ArrayCollection;

/**
 * A database-backed collection that supports lazy loading.
 *
 * When accessed for the first time (iteration, count, array access), the collection
 * triggers its initializer closure to load the related entities from the database.
 *
 * Extends ArrayCollection with lazy initialization behavior.
 *
 * @template T of object
 * @extends ArrayCollection<T>
 */
final class PersistentCollection extends ArrayCollection
{
    private bool $initialized = false;

    /** @var (\Closure(): T[])|null */
    private ?\Closure $initializer;

    /**
     * @param (\Closure(): T[])|null $initializer Closure that loads and returns the collection elements
     */
    public function __construct(?\Closure $initializer = null)
    {
        parent::__construct([]);
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
     * Forces initialization of the collection (loads from DB if not yet loaded).
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

    // ── Override all access methods to trigger initialization ────

    public function toArray(): array
    {
        $this->initialize();

        return parent::toArray();
    }

    public function add(object $element): void
    {
        $this->initialize();
        parent::add($element);
    }

    public function remove(object $element): bool
    {
        $this->initialize();

        return parent::remove($element);
    }

    public function contains(object $element): bool
    {
        $this->initialize();

        return parent::contains($element);
    }

    public function isEmpty(): bool
    {
        $this->initialize();

        return parent::isEmpty();
    }

    public function first(): ?object
    {
        $this->initialize();

        return parent::first();
    }

    public function last(): ?object
    {
        $this->initialize();

        return parent::last();
    }

    public function filter(callable $predicate): static
    {
        $this->initialize();

        return self::fromArray(array_values(array_filter($this->elements, $predicate)));
    }

    public function map(callable $callback): array
    {
        $this->initialize();

        return parent::map($callback);
    }

    public function clear(): void
    {
        $this->initialized = true;
        $this->initializer = null;
        parent::clear();
    }

    public function getIterator(): \ArrayIterator
    {
        $this->initialize();

        /** @var \ArrayIterator<int, T> */
        return parent::getIterator();
    }

    public function count(): int
    {
        $this->initialize();

        return parent::count();
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->initialize();

        return parent::offsetExists($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->initialize();

        return parent::offsetGet($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->initialize();
        parent::offsetSet($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->initialize();
        parent::offsetUnset($offset);
    }

    public function jsonSerialize(): array
    {
        $this->initialize();

        return parent::jsonSerialize();
    }
}
