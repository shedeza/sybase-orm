<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hook\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\HasLifecycleHooks;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\PostPersist;
use SybaseORM\Attribute\PostRemove;
use SybaseORM\Attribute\PostUpdate;
use SybaseORM\Attribute\PrePersist;
use SybaseORM\Attribute\PreRemove;
use SybaseORM\Attribute\PreUpdate;

#[Entity(table: 'hookable')]
#[HasLifecycleHooks]
class HookableEntity
{
    #[Id]
    #[Column(type: 'int')]
    private int $id = 0;

    /** @var list<string> */
    public array $calledHooks = [];

    public ?\Throwable $throwOn = null;
    public string $throwOnHook = '';

    #[PrePersist]
    public function onPrePersist(): void
    {
        $this->recordHook('PrePersist');
    }

    #[PostPersist]
    public function onPostPersist(): void
    {
        $this->recordHook('PostPersist');
    }

    #[PreUpdate]
    public function onPreUpdate(): void
    {
        $this->recordHook('PreUpdate');
    }

    #[PostUpdate]
    public function onPostUpdate(): void
    {
        $this->recordHook('PostUpdate');
    }

    #[PreRemove]
    public function onPreRemove(): void
    {
        $this->recordHook('PreRemove');
    }

    #[PostRemove]
    public function onPostRemove(): void
    {
        $this->recordHook('PostRemove');
    }

    private function recordHook(string $hookName): void
    {
        $this->calledHooks[] = $hookName;

        if ($this->throwOn !== null && $this->throwOnHook === $hookName) {
            throw $this->throwOn;
        }
    }
}
