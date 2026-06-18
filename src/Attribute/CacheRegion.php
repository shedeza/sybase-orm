<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Configures second-level cache behavior for an entity.
 *
 * Allows per-entity TTL and region configuration for the L2 cache.
 *
 * Usage:
 *     #[Entity(table: 'products')]
 *     #[CacheRegion(ttl: 3600, region: 'products')]
 *     class Product { ... }
 *
 *     #[Entity(table: 'config')]
 *     #[CacheRegion(ttl: 86400)] // Cache for 24 hours
 *     class AppConfig { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CacheRegion
{
    public function __construct(
        /** TTL in seconds for this entity's cache entries */
        public readonly int $ttl = 3600,
        /** Named region (used as key prefix in the cache adapter) */
        public readonly string $region = 'default',
    ) {}
}
