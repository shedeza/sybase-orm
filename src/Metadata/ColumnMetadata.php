<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Value object representing the metadata of a single mapped column.
 */
final class ColumnMetadata
{
    public function __construct(
        public readonly string $propertyName,
        public readonly string $columnName,
        public readonly string $type = 'string',
        public readonly bool $nullable = false,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $isId = false,
        public readonly ?string $generatedValue = null,
    ) {
    }
}
