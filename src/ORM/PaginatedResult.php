<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Represents a paginated result set with metadata.
 *
 * @template T
 */
final class PaginatedResult implements \JsonSerializable
{
    /**
     * @param T[] $data The items for the current page
     * @param int $total Total number of items matching the query
     * @param int $page Current page number (1-based)
     * @param int $perPage Items per page
     */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    /**
     * Returns the last page number.
     */
    public function getLastPage(): int
    {
        if ($this->perPage <= 0) {
            return 1;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    /**
     * Returns true if there are more pages after the current one.
     */
    public function hasNextPage(): bool
    {
        return $this->page < $this->getLastPage();
    }

    /**
     * Returns true if there are pages before the current one.
     */
    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    /**
     * Returns the offset used for the current page.
     */
    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Returns true if the result set is empty.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Returns the number of items on the current page.
     */
    public function count(): int
    {
        return count($this->data);
    }

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->data,
            'meta' => [
                'total' => $this->total,
                'page' => $this->page,
                'per_page' => $this->perPage,
                'last_page' => $this->getLastPage(),
                'has_next_page' => $this->hasNextPage(),
                'has_previous_page' => $this->hasPreviousPage(),
            ],
        ];
    }
}
