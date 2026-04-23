<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\OneToMany;

#[Entity(table: 'users')]
class OqlUserEntity
{
    #[Id]
    #[Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[Column(name: 'name', type: 'string')]
    private string $name = '';

    #[Column(name: 'email', type: 'string')]
    private string $email = '';

    #[Column(name: 'age', type: 'integer')]
    private int $age = 0;

    #[OneToMany(targetEntity: OqlPostEntity::class, mappedBy: 'author')]
    private array $posts = [];
}
