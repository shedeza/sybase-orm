<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a property as the optimistic locking version field.
 *
 * On each UPDATE, the version value is incremented automatically.
 * If the version in the database doesn't match the expected value,
 * an OptimisticLockException is thrown, indicating a concurrent modification.
 *
 * Supported types: integer (incremented by 1) or datetime (set to current time).
 *
 * Usage:
 *     #[Version]
 *     #[Column(type: 'integer')]
 *     public int $version = 0;
 *
 *     // Or with timestamp:
 *     #[Version]
 *     #[Column(type: 'datetime')]
 *     public ?\DateTimeInterface $updatedAt = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Version {}
