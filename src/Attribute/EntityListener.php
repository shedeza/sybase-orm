<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Registers an external listener class for entity lifecycle events.
 *
 * Unlike inline hooks (#[PrePersist] on entity methods), entity listeners
 * are separate classes that can be shared across multiple entities and
 * implement cross-cutting concerns without modifying entity code.
 *
 * Usage:
 *     #[Entity(table: 'orders')]
 *     #[EntityListener(AuditListener::class)]
 *     #[EntityListener(NotificationListener::class)]
 *     class Order { ... }
 *
 *     class AuditListener {
 *         public function prePersist(Order $entity): void { ... }
 *         public function postUpdate(Order $entity): void { ... }
 *     }
 *
 * The listener class should define methods named after the lifecycle event
 * in camelCase: prePersist, postPersist, preUpdate, postUpdate, preRemove, postRemove.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class EntityListener
{
    /**
     * @param class-string $listenerClass Fully qualified listener class name
     */
    public function __construct(
        public readonly string $listenerClass,
    ) {}
}
