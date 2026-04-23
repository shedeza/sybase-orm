<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\InheritanceType;

#[Entity(table: 'vehicles')]
#[InheritanceType(strategy: 'TPT')]
class VehicleEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $manufacturer = '';
}
