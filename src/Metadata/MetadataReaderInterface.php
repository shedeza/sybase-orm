<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Reads PHP Attributes and builds mapping metadata.
 */
interface MetadataReaderInterface
{
    /** Reads and returns the complete metadata for an entity class. */
    public function getClassMetadata(string $entityClass): ClassMetadata;

    /** Checks if a class has entity mapping metadata. */
    public function isEntity(string $className): bool;
}
