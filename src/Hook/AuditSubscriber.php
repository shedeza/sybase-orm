<?php

declare(strict_types=1);

namespace SybaseORM\Hook;

use SybaseORM\ORM\UnitOfWorkInterface;

/**
 * Event subscriber that records audit trail entries for #[Auditable] entities.
 *
 * Captures INSERT, UPDATE, and DELETE operations with changed field data.
 *
 * Usage:
 *     $auditSubscriber = new AuditSubscriber($unitOfWork);
 *     $auditSubscriber->setCurrentUser('admin@example.com');
 *     $hookDispatcher->addSubscriber($auditSubscriber);
 *
 *     // After operations, retrieve the audit log:
 *     $entries = $auditSubscriber->getEntries();
 *
 * For persistent storage, extend this class and override `persistEntry()`.
 */
class AuditSubscriber implements EventSubscriberInterface
{
    /** @var array<int, AuditEntry> */
    private array $entries = [];

    private ?string $currentUser = null;

    public function __construct(
        private readonly ?UnitOfWorkInterface $unitOfWork = null,
    ) {}

    /**
     * Sets the current user identifier for audit entries.
     */
    public function setCurrentUser(?string $user): void
    {
        $this->currentUser = $user;
    }

    public function getSubscribedEvents(): array
    {
        return ['PostPersist', 'PostUpdate', 'PostRemove'];
    }

    public function onEvent(object $entity, string $hookType): void
    {
        $reflection = new \ReflectionClass($entity);
        $attrs = $reflection->getAttributes(\SybaseORM\Attribute\Auditable::class);

        if (empty($attrs)) {
            return;
        }

        $operation = match ($hookType) {
            'PostPersist' => 'INSERT',
            'PostUpdate' => 'UPDATE',
            'PostRemove' => 'DELETE',
            default => null,
        };

        if ($operation === null) {
            return;
        }

        $changeset = [];
        if ($operation === 'UPDATE' && $this->unitOfWork !== null) {
            $changeset = $this->unitOfWork->computeChangeset($entity);
        }

        $entityId = $this->extractEntityId($entity);

        $entry = new AuditEntry(
            entityClass: $entity::class,
            entityId: $entityId,
            operation: $operation,
            changeset: $changeset,
            user: $this->currentUser,
            timestamp: new \DateTimeImmutable(),
        );

        $this->entries[] = $entry;
        $this->persistEntry($entry);
    }

    /**
     * Returns all audit entries collected in this session.
     *
     * @return AuditEntry[]
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Clears collected entries.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Override this method to persist audit entries to a database/file/queue.
     * Default implementation is no-op (entries are only kept in memory).
     */
    protected function persistEntry(AuditEntry $entry): void
    {
        // Override in subclass for persistent storage
    }

    private function extractEntityId(object $entity): mixed
    {
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $prop) {
            $idAttrs = $prop->getAttributes(\SybaseORM\Attribute\Id::class);
            if (!empty($idAttrs)) {
                $prop->setAccessible(true);

                return $prop->isInitialized($entity) ? $prop->getValue($entity) : null;
            }
        }

        return null;
    }
}
