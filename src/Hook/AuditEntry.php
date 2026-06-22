<?php

declare(strict_types=1);

namespace SybaseORM\Hook;

/**
 * Represents a single audit trail entry.
 */
final class AuditEntry implements \JsonSerializable
{
    public function __construct(
        public readonly string $entityClass,
        public readonly mixed $entityId,
        public readonly string $operation,
        /** @var array<string, array{old: mixed, new: mixed}> */
        public readonly array $changeset,
        public readonly ?string $user,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'entity' => $this->entityClass,
            'id' => $this->entityId,
            'operation' => $this->operation,
            'changeset' => $this->changeset,
            'user' => $this->user,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
        ];
    }
}
