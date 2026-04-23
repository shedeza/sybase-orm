<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a one-to-one relationship between two entities.
 *
 * The owning side uses `inversedBy` and the inverse side uses `mappedBy`.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToOne
{
    public function __construct(
        public readonly string $targetEntity,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        public readonly array $cascade = [],
        public readonly string $fetch = 'LAZY',
    ) {
    }
}
