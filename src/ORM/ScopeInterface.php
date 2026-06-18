<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Query\QueryBuilderInterface;

/**
 * Interface for global query scopes.
 *
 * Global scopes are applied automatically to all queries made through
 * the repository. They can be used for multi-tenancy, soft-delete, or
 * any cross-cutting filter.
 *
 * Usage:
 *     class ActiveScope implements ScopeInterface {
 *         public function apply(QueryBuilderInterface $qb): void {
 *             $qb->andWhere('e.active = :_scope_active', ['_scope_active' => 1]);
 *         }
 *     }
 *
 * Register on entity:
 *     #[Entity(table: 'users')]
 *     #[GlobalScope(ActiveScope::class)]
 *     class User { ... }
 */
interface ScopeInterface
{
    /**
     * Apply the scope to the given query builder.
     */
    public function apply(QueryBuilderInterface $qb): void;
}
