<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a non-unique index on one or more columns.
 *
 * Used by the MigrationManager to generate CREATE INDEX DDL.
 *
 * Usage:
 *     #[Entity(table: 'orders')]
 *     #[Index(columns: ['customer_id'])]
 *     #[Index(columns: ['status', 'created_at'], name: 'idx_status_date')]
 *     class Order { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Index
{
    /**
     * @param string[] $columns Column names (database names)
     * @param string|null $name Index name (auto-generated if null)
     */
    public function __construct(
        public readonly array $columns,
        public readonly ?string $name = null,
    ) {}
}
