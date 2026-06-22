<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Enables automatic audit trail for an entity.
 *
 * When an auditable entity is inserted, updated, or deleted, the ORM
 * records the change in an audit log (via AuditSubscriber).
 *
 * Usage:
 *     #[Entity(table: 'orders')]
 *     #[Auditable]
 *     class Order { ... }
 *
 * The audit log captures: entity class, entity ID, operation (INSERT/UPDATE/DELETE),
 * changed fields with old/new values, timestamp, and optionally the user who made the change.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Auditable {}
