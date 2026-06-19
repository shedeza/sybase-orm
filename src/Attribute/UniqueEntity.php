<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Validates uniqueness of one or more fields BEFORE persisting to the database.
 *
 * The ORM will query the database during flush to verify that no other record
 * exists with the same values for the specified fields. If a duplicate is found,
 * a UniqueConstraintViolationException is thrown BEFORE the INSERT/UPDATE is sent.
 *
 * This prevents cryptic database errors and gives meaningful error messages.
 *
 * Usage (single field):
 *     #[Entity(table: 'users')]
 *     #[UniqueEntity(fields: ['email'], message: 'El email ya está registrado.')]
 *     class User { ... }
 *
 * Usage (composite unique):
 *     #[UniqueEntity(fields: ['tenantId', 'username'], message: 'El usuario ya existe en este tenant.')]
 *
 * Usage (multiple constraints):
 *     #[UniqueEntity(fields: ['email'])]
 *     #[UniqueEntity(fields: ['document'])]
 *     class User { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class UniqueEntity
{
    /**
     * @param string[] $fields Property names (PHP names, not column names) to check for uniqueness
     * @param string $message Error message when uniqueness is violated
     */
    public function __construct(
        public readonly array $fields,
        public readonly string $message = 'A record with these values already exists.',
    ) {}
}
