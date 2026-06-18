<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Registers a global scope on an entity class.
 *
 * Global scopes are applied automatically to QueryBuilder queries.
 * Multiple scopes can be stacked on the same entity.
 *
 * Usage:
 *     #[Entity(table: 'users')]
 *     #[GlobalScope(ActiveScope::class)]
 *     #[GlobalScope(TenantScope::class)]
 *     class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class GlobalScope
{
    /**
     * @param class-string<\SybaseORM\ORM\ScopeInterface> $scopeClass
     */
    public function __construct(
        public readonly string $scopeClass,
    ) {}
}
