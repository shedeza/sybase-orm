<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;

#[Entity(table: 'child_entities')]
class ChildEntity extends BaseEntity
{
    #[Column(type: 'string', length: 200)]
    private string $description = '';

    public function getDescription(): string
    {
        return $this->description;
    }
}
