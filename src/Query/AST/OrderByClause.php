<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents the ORDER BY clause containing one or more order items.
 */
final class OrderByClause
{
    /**
     * @param OrderByItem[] $items
     */
    public function __construct(
        public readonly array $items,
    ) {
    }
}
