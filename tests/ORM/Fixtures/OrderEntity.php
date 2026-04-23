<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\ManyToOne;
use SybaseORM\Attribute\OneToMany;

#[Entity(table: 'orders')]
class OrderEntity
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 100)]
    private string $description = '';

    #[Column(type: 'decimal', precision: 10, scale: 2)]
    private float $total = 0.0;

    #[ManyToOne(targetEntity: CustomerEntity::class, inversedBy: 'orders', cascade: ['persist'])]
    #[JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    private ?CustomerEntity $customer = null;

    #[OneToMany(targetEntity: OrderItemEntity::class, mappedBy: 'order', cascade: ['persist'])]
    private array $items = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function setTotal(float $total): void
    {
        $this->total = $total;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function setItems(array $items): void
    {
        $this->items = $items;
    }
}
