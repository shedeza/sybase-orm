<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type\Fixtures;

/**
 * Simple Value Object for testing custom type conversion.
 */
class Money
{
    private function __construct(private readonly int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public function getAmountInCents(): int
    {
        return $this->cents;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }
}
