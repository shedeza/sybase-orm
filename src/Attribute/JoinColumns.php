<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Defines multiple join columns for relationships to entities with composite primary keys.
 *
 * Usage:
 *     #[ManyToOne(targetEntity: Inscripcion::class)]
 *     #[JoinColumns([
 *         new JoinColumn(name: 'estudiante_id', referencedColumnName: 'estudiante_id'),
 *         new JoinColumn(name: 'curso_id', referencedColumnName: 'curso_id'),
 *     ])]
 *     private ?Inscripcion $inscripcion = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JoinColumns
{
    /** @param JoinColumn[] $value */
    public function __construct(
        public readonly array $value
    ) {
    }
}
