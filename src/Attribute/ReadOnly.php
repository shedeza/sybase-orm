<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks an entity as read-only.
 *
 * Read-only entities are never included in UPDATE or INSERT operations
 * during flush. They can be loaded and queried but not persisted.
 *
 * Useful for views, reporting tables, or reference data.
 *
 * Usage:
 *     #[Entity(table: 'v_user_stats')]
 *     #[Immutable]
 *     class UserStats { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Immutable
{
}
