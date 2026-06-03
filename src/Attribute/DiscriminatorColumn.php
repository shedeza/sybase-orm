<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines the discriminator column used in Table Per Hierarchy (TPH)
 * inheritance to distinguish between entity types.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DiscriminatorColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
    ) {}
}
