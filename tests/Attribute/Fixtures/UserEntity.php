<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\ManyToMany;
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\OneToMany;
use SybaseORM\Attribute\OneToOne;

#[Entity(table: 'users')]
class UserEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[OneToOne(targetEntity: ProfileEntity::class, inversedBy: 'user', cascade: ['persist', 'remove'], fetch: 'EAGER')]
    #[JoinColumn(name: 'profile_id', referencedColumnName: 'id')]
    private ?object $profile = null;

    #[OneToMany(targetEntity: PostEntity::class, mappedBy: 'author', cascade: ['persist'])]
    private array $posts = [];

    #[ManyToOne(targetEntity: DepartmentEntity::class, inversedBy: 'employees', cascade: ['persist'], fetch: 'EAGER')]
    #[JoinColumn(name: 'department_id', referencedColumnName: 'id')]
    private ?object $department = null;

    #[ManyToMany(targetEntity: RoleEntity::class, inversedBy: 'users', joinTable: 'user_roles', cascade: ['persist', 'remove'])]
    private array $roles = [];
}
