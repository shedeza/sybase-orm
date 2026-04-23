<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PostPersist;
use SybaseORM\Attribute\PreUpdate;
use SybaseORM\Attribute\PostUpdate;
use SybaseORM\Attribute\PreRemove;
use SybaseORM\Attribute\PostRemove;

#[Entity(table: 'auditable')]
#[HasLifecycleHooks]
class AuditableEntity
{
    #[Id]
    #[Column(type: 'int')]
    private int $id;

    #[Column(type: 'string')]
    private string $name;

    #[PrePersist]
    public function onPrePersist(): void
    {
    }

    #[PostPersist]
    public function onPostPersist(): void
    {
    }

    #[PreUpdate]
    public function onPreUpdate(): void
    {
    }

    #[PostUpdate]
    public function onPostUpdate(): void
    {
    }

    #[PreRemove]
    public function onPreRemove(): void
    {
    }

    #[PostRemove]
    public function onPostRemove(): void
    {
    }
}
