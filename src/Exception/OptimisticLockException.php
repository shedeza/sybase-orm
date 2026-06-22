<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Thrown when an optimistic lock conflict is detected.
 *
 * This means the entity was modified by another process between
 * the time it was loaded and the time flush() was called.
 */
final class OptimisticLockException extends SybaseORMException
{
    private string $entityClass;
    private mixed $entityId;

    public function __construct(string $entityClass, mixed $entityId, mixed $expectedVersion, mixed $actualVersion)
    {
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;

        parent::__construct(sprintf(
            'Optimistic lock conflict on "%s" (id: %s). Expected version %s, but database has version %s. The entity was modified by another process.',
            $entityClass,
            is_array($entityId) ? json_encode($entityId) : (string) $entityId,
            (string) $expectedVersion,
            (string) $actualVersion,
        ));
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): mixed
    {
        return $this->entityId;
    }
}
