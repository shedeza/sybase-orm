<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\DiscriminatorMap;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\InheritanceType;

#[Entity(table: 'vehicles')]
#[InheritanceType(strategy: 'TPT')]
#[DiscriminatorMap(map: ['car' => CarEntity::class, 'truck' => TruckEntity::class])]
class VehicleEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $manufacturer = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getManufacturer(): string
    {
        return $this->manufacturer;
    }
}
