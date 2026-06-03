<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Maps discriminator column values to entity class names.
 *
 * Used together with InheritanceType and DiscriminatorColumn to define
 * which discriminator value corresponds to which concrete entity class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DiscriminatorMap
{
    public function __construct(
        public readonly array $map,
    ) {}
}
