<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines the inheritance mapping strategy for an entity hierarchy.
 *
 * Supported strategies:
 * - TPH: Table Per Hierarchy (single table with discriminator column)
 * - TPT: Table Per Type (base table + one table per subclass, joined by PK)
 * - TPC: Table Per Concrete Class (independent table per concrete class)
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InheritanceType
{
    public function __construct(
        public readonly string $strategy,
    ) {}
}
