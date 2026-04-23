<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hook\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'no_hooks')]
class NoHooksEntity
{
    #[Id]
    #[Column(type: 'int')]
    private int $id = 0;
}
