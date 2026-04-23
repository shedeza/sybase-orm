<?php

declare(strict_types=1);

namespace SybaseORM\Attribute;

use Attribute;

/**
 * Marks a method to be executed after removing the entity from the database.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class PostRemove
{
}
