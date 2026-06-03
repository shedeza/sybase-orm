<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a one-to-many relationship between two entities.
 *
 * This is always the inverse side; `mappedBy` references the owning
 * ManyToOne property on the target entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToMany
{
    public function __construct(
        public readonly string $targetEntity,
        public readonly string $mappedBy,
        public readonly array $cascade = [],
        public readonly string $fetch = 'LAZY',
        public readonly bool $orphanRemoval = false,
    ) {
    }
}
