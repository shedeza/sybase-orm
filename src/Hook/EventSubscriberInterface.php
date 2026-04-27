<?php

declare(strict_types=1);

namespace SybaseORM\Hook;

/**
 * Interface for external event subscribers that listen to entity lifecycle events.
 *
 * Unlike entity-level hooks (#[PrePersist], etc.), subscribers are registered
 * externally and can implement cross-cutting concerns like auditing, logging,
 * or notifications without modifying entity classes.
 *
 * Usage:
 *     $hookDispatcher->addSubscriber(new AuditSubscriber());
 */
interface EventSubscriberInterface
{
    /**
     * Returns the list of hook types this subscriber listens to.
     *
     * @return string[] e.g. ['PrePersist', 'PostUpdate', 'PostRemove']
     */
    public function getSubscribedEvents(): array;

    /**
     * Called when a subscribed event is dispatched.
     *
     * @param object $entity   The entity instance
     * @param string $hookType The hook type name (e.g. 'PrePersist')
     */
    public function onEvent(object $entity, string $hookType): void;
}
