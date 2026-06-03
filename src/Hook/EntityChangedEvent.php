<?php

declare(strict_types=1);

namespace SybaseORM\Hook;

/**
 * Domain event dispatched after entity lifecycle operations.
 *
 * Can be listened to via Symfony's EventDispatcher for cross-cutting concerns
 * like recalculating aggregates, sending notifications, or auditing.
 *
 * Usage with Symfony EventDispatcher:
 *     #[AsEventListener(event: EntityChangedEvent::class)]
 *     class RecalcularConsecutivosListener {
 *         public function __invoke(EntityChangedEvent $event): void {
 *             if ($event->entityClass === Actividad::class && $event->hookType === 'PostPersist') {
 *                 // Recalcular...
 *             }
 *         }
 *     }
 */
final class EntityChangedEvent
{
    public function __construct(
        public readonly object $entity,
        public readonly string $entityClass,
        public readonly string $hookType,
    ) {}
}
