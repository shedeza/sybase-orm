<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'org_users')]
class CompositeKeyEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $orgId = null;

    #[Id]
    #[Column(type: 'integer')]
    private ?int $userId = null;

    #[Column(type: 'varchar', length: 50)]
    private string $role = '';
}
