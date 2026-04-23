<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;

#[Entity(table: 'my_table')]
class SampleEntity
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(name: 'user_name', type: 'varchar', length: 100)]
    private string $name = '';

    #[Column(type: 'decimal', nullable: true, precision: 10, scale: 2)]
    private ?float $balance = null;
}
