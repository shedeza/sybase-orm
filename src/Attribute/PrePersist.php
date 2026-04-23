<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a method to be executed before inserting the entity into the database.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PrePersist
{
}
