<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;

#[Entity]
class DogEntity extends AnimalEntity
{
    #[Column(type: 'string')]
    private string $breed = '';
}
