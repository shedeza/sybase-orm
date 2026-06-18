<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a method as an accessor (getter transformation) for a property.
 *
 * The accessor method is called when the property value is read during
 * toArray()/toJson() serialization. It receives the raw value and returns
 * the transformed value.
 *
 * Usage:
 *     #[Accessor(property: 'name')]
 *     public function getNameAttribute(string $value): string {
 *         return ucfirst($value);
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Accessor
{
    public function __construct(
        public readonly string $property,
    ) {}
}
