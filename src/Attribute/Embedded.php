<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Maps a property to an embeddable value object.
 *
 * The embeddable's columns are prefixed with the property name (or custom prefix)
 * and stored in the parent entity's table.
 *
 * @see Embeddable
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Embedded
{
    /**
     * @param string      $class        Fully qualified class name of the embeddable
     * @param string|null $columnPrefix Column name prefix (defaults to property name + '_')
     */
    public function __construct(
        public readonly string $class,
        public readonly ?string $columnPrefix = null,
    ) {
    }
}
