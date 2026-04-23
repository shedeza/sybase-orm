<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Attribute\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'invoices', schema: 'billing')]
class SchemaEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 100)]
    private string $number = '';
}
