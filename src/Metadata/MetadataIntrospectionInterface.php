<?php

declare(strict_types=1);

namespace SybaseORM\Metadata;

/**
 * Provides introspection capabilities over the mapped entity model.
 *
 * Useful for profiler panels, entity map visualizations, and schema tooling.
 */
interface MetadataIntrospectionInterface
{
    /**
     * Returns metadata for all discovered/registered entity classes.
     *
     * @return array<string, ClassMetadata> Indexed by FQCN
     */
    public function getAllClassMetadata(): array;

    /**
     * Returns the number of discovered/registered entity classes.
     */
    public function getEntityCount(): int;

    /**
     * Returns a relationship map between all entities.
     *
     * Each entry describes: source entity → target entity, relationship type, property name.
     *
     * @return array<int, array{source: string, target: string, type: string, property: string, inversedBy: string|null, mappedBy: string|null}>
     */
    public function getRelationshipMap(): array;
}
