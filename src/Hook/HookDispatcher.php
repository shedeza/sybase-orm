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

    /**
     * Dispatches a lifecycle hook on the given entity.
     *
     * @param object $entity   The entity instance whose hook methods will be called.
     * @param string $hookType The hook type name (e.g. "PrePersist", "PostPersist", etc.).
     *
     * @throws \Throwable Re-throws any exception raised by a hook method.
     */
    public function dispatch(object $entity, string $hookType): void
    {
        $metadata = $this->metadataReader->getClassMetadata($entity::class);

        $methods = $metadata->lifecycleHooks[$hookType] ?? [];

        foreach ($methods as $method) {
            $entity->$method();
        }
    }
}
