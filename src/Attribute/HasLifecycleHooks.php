<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks an entity class as having lifecycle hook methods.
 *
 * The Hook_Dispatcher will only inspect methods for lifecycle
 * hook attributes on entities decorated with this attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class HasLifecycleHooks
{
}
