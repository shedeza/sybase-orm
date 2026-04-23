<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'trucks')]
class TruckEntity extends VehicleEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'float')]
    private float $payload = 0.0;

    public function getPayload(): float
    {
        return $this->payload;
    }
}
