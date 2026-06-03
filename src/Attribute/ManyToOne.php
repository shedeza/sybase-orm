<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines a many-to-one relationship between two entities.
 *
 * This is always the owning side; `inversedBy` references the inverse
 * OneToMany property on the target entity.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToOne
{
    public function __construct(
        public readonly string $targetEntity,
        public readonly ?string $inversedBy = null,
        public readonly array $cascade = [],
        public readonly string $fetch = 'LAZY',
    ) {
    }
}
