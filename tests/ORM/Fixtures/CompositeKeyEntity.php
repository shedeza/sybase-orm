<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'composite_entities')]
class CompositeKeyEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $orgId = null;

    #[Id]
    #[Column(type: 'integer')]
    private ?int $userId = null;

    #[Column(type: 'string', length: 100)]
    private string $role = '';

    public function getOrgId(): ?int
    {
        return $this->orgId;
    }

    public function setOrgId(?int $orgId): void
    {
        $this->orgId = $orgId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $this->role = $role;
    }
}
