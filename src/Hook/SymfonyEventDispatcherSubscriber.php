<?php

declare(strict_types=1);

namespace SybaseORM\Hook;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Bridges ORM lifecycle hooks to Symfony's EventDispatcher.
 *
 * Register this subscriber on the HookDispatcher to automatically dispatch
 * EntityChangedEvent via Symfony's event system after each lifecycle operation.
 *
 * Usage:
 *     $hookDispatcher->addSubscriber(new SymfonyEventDispatcherSubscriber($eventDispatcher));
 */
final class SymfonyEventDispatcherSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function getSubscribedEvents(): array
    {
        return ['PostPersist', 'PostUpdate', 'PostRemove'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        $event = new EntityChangedEvent(
            entity: $entity,
            entityClass: $entity::class,
            hookType: $hookType,
        );

        $this->eventDispatcher->dispatch($event);
    }
}
