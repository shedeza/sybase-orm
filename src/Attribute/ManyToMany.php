<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a many-to-many relationship between two entities.
 *
 * The owning side specifies `joinTable` and optionally `inversedBy`.
 * The inverse side uses `mappedBy`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToMany
{
    public function __construct(
        public readonly string $targetEntity,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        public readonly ?string $joinTable = null,
        public readonly array $cascade = [],
        public readonly string $fetch = 'LAZY',
    ) {}
}
