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
    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
    ) {
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
