<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type\Fixtures;

enum StringStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}
