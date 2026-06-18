<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Provides introspection over the entity model for tooling and profilers.
 */
final class MetadataIntrospection implements MetadataIntrospectionInterface
{
    /** @var string[] Entity FQCN list */
    private array $entityClasses;

    /**
     * @param MetadataReaderInterface $metadataReader
     * @param string[] $entityClasses List of known entity FQCNs
     */
    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
        array $entityClasses = [],
    ) {
        $this->entityClasses = $entityClasses;
    }

    /**
     * Updates the entity class list (called after discovery).
     *
     * @param string[] $entityClasses
     */
    public function setEntityClasses(array $entityClasses): void
    {
        $this->entityClasses = $entityClasses;
    }

    public function getAllClassMetadata(): array
    {
        $result = [];

        foreach ($this->entityClasses as $fqcn) {
            $result[$fqcn] = $this->metadataReader->getClassMetadata($fqcn);
        }

        return $result;
    }

    public function getEntityCount(): int
    {
        return count($this->entityClasses);
    }

    public function getRelationshipMap(): array
    {
        $map = [];

        foreach ($this->entityClasses as $fqcn) {
            $metadata = $this->metadataReader->getClassMetadata($fqcn);

            foreach ($metadata->relationships as $relationship) {
                $map[] = [
                    'source' => $fqcn,
                    'target' => $relationship->targetEntity,
                    'type' => $relationship->type,
                    'property' => $relationship->propertyName,
                    'inversedBy' => $relationship->inversedBy,
                    'mappedBy' => $relationship->mappedBy,
                ];
            }
        }

        return $map;
    }

    /**
     * Returns a summary of the entity model for debugging.
     *
     * @return array<string, array{table: string, columns: int, relationships: int, inheritance: string|null}>
     */
    public function getSummary(): array
    {
        $summary = [];

        foreach ($this->entityClasses as $fqcn) {
            $metadata = $this->metadataReader->getClassMetadata($fqcn);
            $summary[$fqcn] = [
                'table' => $metadata->getQualifiedTableName(),
                'columns' => count($metadata->columns),
                'relationships' => count($metadata->relationships),
                'inheritance' => $metadata->inheritanceType,
            ];
        }

        return $summary;
    }
}
