<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Value object representing the metadata of a single entity relationship.
 */
final class RelationshipMetadata
{
    public readonly ?string $joinColumn;
    public readonly ?string $referencedColumnName;

    public function __construct(
        public readonly string $propertyName,
        public readonly string $type,
        public readonly string $targetEntity,
        public readonly ?string $mappedBy = null,
        public readonly ?string $inversedBy = null,
        /** @var array<string, string> Map of joinColumn => referencedColumnName */
        public array $joinColumns = [],
        public readonly ?string $joinTable = null,
        public readonly array $cascade = [],
        public readonly string $fetch = 'LAZY',
        public readonly bool $orphanRemoval = false,
        ?string $joinColumn = null,
        ?string $referencedColumnName = null,
    ) {
        if ($joinColumn !== null) {
            $this->joinColumns[$joinColumn] = $referencedColumnName ?? 'id';
        }

        if (!empty($this->joinColumns)) {
            $this->joinColumn = (string) array_key_first($this->joinColumns);
            $this->referencedColumnName = $this->joinColumns[$this->joinColumn];
        } else {
            $this->joinColumn = null;
            $this->referencedColumnName = null;
        }
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

    /**
     * Returns true if this is the owning side of the relationship.
     * The owning side has the join column or is not the mappedBy side.
     */
    public function isOwningSide(): bool
    {
        return $this->mappedBy === null;
    }

    /**
     * Returns true if this is the inverse side of the relationship.
     */
    public function isInverseSide(): bool
    {
        return $this->mappedBy !== null;
    }
}
