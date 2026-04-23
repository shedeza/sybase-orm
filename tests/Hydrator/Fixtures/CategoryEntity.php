<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\ManyToOne;

#[Entity(table: 'categories')]
class CategoryEntity
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 100)]
    private string $title = '';

    #[ManyToOne(targetEntity: CategoryEntity::class, fetch: 'EAGER')]
    #[JoinColumn(name: 'parent_id', referencedColumnName: 'id')]
    private ?CategoryEntity $parent = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getParent(): ?CategoryEntity
    {
        return $this->parent;
    }
}
