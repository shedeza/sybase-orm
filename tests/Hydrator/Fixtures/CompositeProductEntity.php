<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;

#[Entity(table: 'org_products')]
class CompositeProductEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $orgId = null;

    #[Id]
    #[Column(type: 'integer')]
    private ?int $productId = null;

    #[Column(type: 'string', length: 255)]
    private string $name = '';

    public function getOrgId(): ?int
    {
        return $this->orgId;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
