<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Maps a property to a database column.
 *
 * When no column name is specified, the MetadataReader will derive
 * the column name from the property name using snake_case convention.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly string $type = 'string',
        public readonly bool $nullable = false,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
    ) {
    }
}
