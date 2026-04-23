<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\InheritanceType;

#[Entity(table: 'shapes')]
#[InheritanceType(strategy: 'TPC')]
class ShapeEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $color = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColor(): string
    {
        return $this->color;
    }
}
