<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Enables automatic timestamp management on an entity.
 *
 * When an entity with #[Timestamps] is persisted, the `createdAt` property
 * is set to the current datetime. On update, the `updatedAt` property is updated.
 *
 * Usage:
 *     #[Entity(table: 'users')]
 *     #[Timestamps]
 *     class User {
 *         #[Column(type: 'datetime', nullable: true)]
 *         public ?\DateTimeInterface $createdAt = null;
 *
 *         #[Column(type: 'datetime', nullable: true)]
 *         public ?\DateTimeInterface $updatedAt = null;
 *     }
 *
 * Custom column names:
 *     #[Timestamps(createdAt: 'fecha_creacion', updatedAt: 'fecha_modificacion')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Timestamps
{
    public function __construct(
        public readonly string $createdAt = 'createdAt',
        public readonly string $updatedAt = 'updatedAt',
    ) {}
}
