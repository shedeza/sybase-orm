<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Lee PHP Attributes y construye metadatos de mapeo.
 */
interface MetadataReaderInterface
{
    /** Lee y retorna los metadatos completos de una clase de entidad. */
    public function getClassMetadata(string $entityClass): ClassMetadata;

    /** Verifica si una clase tiene metadatos de entidad. */
    public function isEntity(string $className): bool;
}
