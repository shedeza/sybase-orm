<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;

#[Entity(table: 'rectangles')]
class RectangleEntity extends ShapeEntity
{
    #[Column(type: 'float')]
    private float $width = 0.0;

    #[Column(type: 'float')]
    private float $height = 0.0;

    public function getWidth(): float
    {
        return $this->width;
    }

    public function getHeight(): float
    {
        return $this->height;
    }
}
