<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Type\Fixtures;

use SybaseORM\Type\CustomTypeInterface;

class MoneyType implements CustomTypeInterface
{
    public function toDatabaseValue(mixed $value): mixed
    {
        if ($value instanceof Money) {
            return $value->getAmountInCents();
        }

        throw new \InvalidArgumentException('Expected a Money instance.');
    }

    public function toPhpValue(mixed $value): mixed
    {
        if (is_int($value)) {
            return Money::fromCents($value);
        }

        throw new \InvalidArgumentException('Expected an integer (cents).');
    }
}
