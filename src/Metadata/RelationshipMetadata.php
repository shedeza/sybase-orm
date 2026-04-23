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
}
