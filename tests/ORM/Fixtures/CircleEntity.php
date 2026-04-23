<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;

#[Entity(table: 'circles')]
class CircleEntity extends ShapeEntity
{
    #[Column(type: 'float')]
    private float $radius = 0.0;

    public function getRadius(): float
    {
        return $this->radius;
    }
}
