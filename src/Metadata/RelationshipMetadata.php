<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Value object representing the metadata of a single entity relationship.
 */
final class RelationshipMetadata
{
    public function __construct(
        public readonly string $propertyName,
        public readonly string $type,
        public readonly string $targetEntity,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        public readonly ?string $joinColumn = null,
        public readonly ?string $referencedColumnName = null,
        public readonly ?string $joinTable = null,
        public readonly array $cascade = [],
        public readonly string $fetch = 'LAZY',
    ) {
    }

    /**
     * Returns true if this relationship has cascade persist enabled.
     */
    public function hasCascadePersist(): bool
    {
        return in_array('persist', $this->cascade, true);
    }

    /**
     * Returns true if this relationship has cascade remove enabled.
     */
    public function hasCascadeRemove(): bool
    {
        return in_array('remove', $this->cascade, true);
    }

    /**
     * Returns true if this is a to-one relationship (ManyToOne or OneToOne).
     */
    public function isToOne(): bool
    {
        return $this->type === 'ManyToOne' || $this->type === 'OneToOne';
    }

    /**
     * Returns true if this is a to-many relationship (OneToMany or ManyToMany).
     */
    public function isToMany(): bool
    {
        return $this->type === 'OneToMany' || $this->type === 'ManyToMany';
    }

    /**
     * Returns a human-readable string representation of this relationship.
     */
    public function __toString(): string
    {
        return sprintf('%s %s → %s', $this->type, $this->propertyName, $this->targetEntity);
    }
}
