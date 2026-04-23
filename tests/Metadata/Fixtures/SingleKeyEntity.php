<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'products')]
class SingleKeyEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'varchar', length: 100)]
    private string $name = '';
}
