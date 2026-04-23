<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity]
class MetadataReaderSnakeCaseFixture
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string')]
    private string $firstName = '';

    #[Column(type: 'string')]
    private string $lastLoginDate = '';
}
