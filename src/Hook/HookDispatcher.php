<?php

declare(strict_types=1);

namespace SybaseORM\Hook;

use SybaseORM\Metadata\MetadataReaderInterface;

/**
 * Dispatches lifecycle hook methods on entities.
 *
 * Reads the lifecycleHooks map from ClassMetadata and invokes
 * the annotated methods at the appropriate moment. If a hook
 * method throws an exception it propagates unmodified, which
 * allows the caller (e.g. UnitOfWork) to cancel the operation.
 */
final class HookDispatcher
{
    /** @var EventSubscriberInterface[] */
    private array $subscribers = [];

    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
    ) {}

    /**
     * Registers an external event subscriber.
     */
    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    /**
     * Returns all registered subscribers.
     *
     * @return EventSubscriberInterface[]
     */
    public function getSubscribers(): array
    {
        return $this->subscribers;
    }

    /** @var string[] Valid lifecycle hook type names */
    private const VALID_HOOK_TYPES = [
        'PrePersist',
        'PostPersist',
        'PreUpdate',
        'PostUpdate',
        'PreRemove',
        'PostRemove',
    ];

    /**
     * Returns the list of supported lifecycle hook type names.
     *
     * @return string[]
     */
    public static function getSupportedHookTypes(): array
    {
        return self::VALID_HOOK_TYPES;
    }

    /**
     * Dispatches a lifecycle hook on the given entity.
     *
     * @param object $entity   The entity instance whose hook methods will be called.
     * @param string $hookType The hook type name (e.g. "PrePersist", "PostPersist", etc.).
     *
     * @throws \InvalidArgumentException If the hook type is not valid.
     * @throws \RuntimeException If a hook method does not exist on the entity.
     * @throws \Throwable Re-throws any exception raised by a hook method.
     */
    public function dispatch(object $entity, string $hookType): void
    {
        $metadata = $this->metadataReader->getClassMetadata($entity::class);

        // 0. Handle automatic timestamps
        $this->handleTimestamps($entity, $hookType);

        // 1. Dispatch entity-level hooks (attribute-based)
        $methods = $metadata->lifecycleHooks[$hookType] ?? [];

        foreach ($methods as $method) {
            if (!method_exists($entity, $method)) {
                throw new \RuntimeException(sprintf(
                    'Lifecycle hook method "%s::%s" for hook "%s" does not exist.',
                    $entity::class,
                    $method,
                    $hookType,
                ));
            }
            $entity->$method();
        }

        // 2. Dispatch entity listeners (external classes via #[EntityListener])
        $this->dispatchEntityListeners($entity, $hookType);

        // 3. Notify external subscribers
        foreach ($this->subscribers as $subscriber) {
            if (in_array($hookType, $subscriber->getSubscribedEvents(), true)) {
                $subscriber->onEvent($entity, $hookType);
            }
        }
    }

    /**
     * Handles automatic timestamp management via #[Timestamps] attribute.
     */
    private function handleTimestamps(object $entity, string $hookType): void
    {
        $reflection = new \ReflectionClass($entity);
        $attrs = $reflection->getAttributes(\SybaseORM\Attribute\Timestamps::class);

        if (empty($attrs)) {
            return;
        }

        $timestamps = $attrs[0]->newInstance();
        $now = new \DateTimeImmutable();

        if ($hookType === 'PrePersist') {
            if ($reflection->hasProperty($timestamps->createdAt)) {
                $prop = $reflection->getProperty($timestamps->createdAt);
                $prop->setAccessible(true);
                if ($prop->getValue($entity) === null) {
                    $prop->setValue($entity, $now);
                }
            }
            if ($reflection->hasProperty($timestamps->updatedAt)) {
                $prop = $reflection->getProperty($timestamps->updatedAt);
                $prop->setAccessible(true);
                $prop->setValue($entity, $now);
            }
        } elseif ($hookType === 'PreUpdate') {
            if ($reflection->hasProperty($timestamps->updatedAt)) {
                $prop = $reflection->getProperty($timestamps->updatedAt);
                $prop->setAccessible(true);
                $prop->setValue($entity, $now);
            }
        }
    }

    /**
     * Dispatches entity listeners registered via #[EntityListener] attribute.
     */
    private function dispatchEntityListeners(object $entity, string $hookType): void
    {
        $reflection = new \ReflectionClass($entity);
        $attrs = $reflection->getAttributes(\SybaseORM\Attribute\EntityListener::class);

        if (empty($attrs)) {
            return;
        }

        // Convert hook type to camelCase method name: PrePersist → prePersist
        $methodName = lcfirst($hookType);

        foreach ($attrs as $attr) {
            $listenerAttr = $attr->newInstance();
            $listenerClass = $listenerAttr->listenerClass;

            if (!class_exists($listenerClass)) {
                continue;
            }

            $listener = new $listenerClass();

            if (method_exists($listener, $methodName)) {
                $listener->$methodName($entity);
            }
        }
    }

    /**
     * Dispatches multiple lifecycle hooks on the given entity in order.
     *
     * @param object   $entity    The entity instance.
     * @param string[] $hookTypes The hook type names to dispatch in order.
     */
    public function dispatchAll(object $entity, array $hookTypes): void
    {
        foreach ($hookTypes as $hookType) {
            $this->dispatch($entity, $hookType);
        }
    }
}
