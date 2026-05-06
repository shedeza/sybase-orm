<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a class as a mapped entity.
 *
 * When no table name is specified, the MetadataReader will derive
 * the table name from the class name using snake_case convention.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public readonly ?string $table = null,
        public readonly ?string $schema = null,
        public readonly ?string $repositoryClass = null,
        public readonly string $connection = 'default',
    ) {
    }
}
