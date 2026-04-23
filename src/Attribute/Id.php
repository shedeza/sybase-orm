<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a property as the primary key identifier.
 *
 * The default strategy is 'identity', which maps to Sybase ASE's
 * @@identity mechanism for auto-generated IDs.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Id
{
    public function __construct(
        public readonly string $strategy = 'identity',
    ) {
    }
}
