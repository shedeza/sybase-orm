<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\ManyToOne;

#[Entity(table: 'posts')]
class OqlPostEntity
{
    #[Id]
    #[Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[Column(name: 'title', type: 'string')]
    private string $title = '';

    #[Column(name: 'body', type: 'string')]
    private string $body = '';

    #[ManyToOne(targetEntity: OqlUserEntity::class, inversedBy: 'posts')]
    #[JoinColumn(name: 'author_id', referencedColumnName: 'id')]
    private ?OqlUserEntity $author = null;
}
