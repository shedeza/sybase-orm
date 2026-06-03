<?php

declare(strict_types=1);

namespace SybaseORM\Collection;

/**
 * Base interface for all collection types in the ORM.
 *
 * Provides a consistent API for working with collections of entities,
 * whether they come from the database (PersistentCollection) or are
 * created in application code (ArrayCollection).
 *
 * @template T of object
 * @extends \IteratorAggregate<int, T>
 * @extends \ArrayAccess<int, T>
 */
interface Collection extends \IteratorAggregate, \Countable, \ArrayAccess, \JsonSerializable
{
    /**
     * Returns all elements as a plain array.
     *
     * @return T[]
     */
    public function toArray(): array;

    /**
     * Adds an element to the collection.
     *
     * @param T $element
     */
    public function add(object $element): void;

    /**
     * Removes an element from the collection (by identity).
     *
     * @param T $element
     * @return bool True if the element was found and removed
     */
    public function remove(object $element): bool;

    /**
     * Checks if the collection contains the given element (by identity).
     *
     * @param T $element
     */
    public function contains(object $element): bool;

    /**
     * Returns true if the collection is empty.
     */
    public function isEmpty(): bool;

    /**
     * Returns the first element or null if empty.
     *
     * @return T|null
     */
    public function first(): ?object;

    /**
     * Returns the last element or null if empty.
     *
     * @return T|null
     */
    public function last(): ?object;

    /**
     * Filters the collection using a callback, returning a new collection.
     *
     * @param callable(T): bool $predicate
     * @return static
     */
    public function filter(callable $predicate): static;

    /**
     * Maps the collection using a callback.
     *
     * @template U
     * @param callable(T): U $callback
     * @return U[]
     */
    public function map(callable $callback): array;

    /**
     * Clears all elements from the collection.
     */
    public function clear(): void;
}
