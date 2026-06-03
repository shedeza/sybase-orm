<?php

declare(strict_types=1);

namespace SybaseORM\Query\AST;

/**
 * Represents the GROUP BY clause containing one or more property accesses.
 */
final class GroupByClause
{
    /**
     * @param PropertyAccess[] $properties
     */
    public function __construct(
        public readonly array $properties,
    ) {}
}
