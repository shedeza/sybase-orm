<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\DiscriminatorColumn;
use SybaseORM\Attribute\DiscriminatorMap;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\InheritanceType;

#[Entity(table: 'animals')]
#[InheritanceType(strategy: 'TPH')]
#[DiscriminatorColumn(name: 'animal_type', type: 'string')]
#[DiscriminatorMap(map: ['dog' => DogEntity::class, 'cat' => CatEntity::class])]
class AnimalEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $name = '';
}
