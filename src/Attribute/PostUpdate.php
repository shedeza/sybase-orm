<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a method to be executed after updating the entity in the database.
 * Higher priority values execute first (default: 0).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PostUpdate
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
