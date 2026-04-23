<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type\Fixtures;

enum IntPriority: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}
