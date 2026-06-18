<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a method as a mutator (setter transformation) for a property.
 *
 * The mutator method is called when a value is assigned to the property
 * during hydration or manual set. It receives the incoming value and
 * should set the property to the transformed value.
 *
 * Usage:
 *     #[Mutator(property: 'email')]
 *     public function setEmailAttribute(string $value): void {
 *         $this->email = strtolower(trim($value));
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Mutator
{
    public function __construct(
        public readonly string $property,
    ) {}
}
