<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Enables soft delete for an entity.
 *
 * When an entity with #[SoftDelete] is removed, instead of executing a DELETE,
 * the ORM sets the specified column to the current datetime.
 *
 * Queries via findBy/findAll automatically filter out soft-deleted records
 * unless explicitly requested.
 *
 * Usage:
 *     #[Entity(table: 'usuarios')]
 *     #[SoftDelete(column: 'deleted_at')]
 *     class Usuario { ... }
 *
 *     // Soft delete:
 *     $repo->delete($usuario); // UPDATE SET deleted_at = GETDATE()
 *
 *     // Query (auto-filters deleted):
 *     $repo->findAll(); // WHERE deleted_at IS NULL
 *
 *     // Include deleted:
 *     $repo->findBy(['_withTrashed' => true]);
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SoftDelete
{
    public function __construct(
        public readonly string $column = 'deleted_at',
    ) {
    }
}
