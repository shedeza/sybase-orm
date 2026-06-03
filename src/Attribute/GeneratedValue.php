<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Indicates that the value of the annotated property is generated
 * by the database.
 *
 * The default (and primary) strategy is IDENTITY, which uses
 * Sybase ASE's @@identity to retrieve the generated value
 * immediately after INSERT.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class GeneratedValue
{
    public function __construct(
        public readonly string $strategy = 'IDENTITY',
    ) {}
}
