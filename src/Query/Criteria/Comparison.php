<?php

declare(strict_types=1);

namespace SybaseORM\Query\Criteria;

class Comparison implements ExpressionInterface
{
    public const EQ = '=';
    public const NEQ = '!=';
    public const LT = '<';
    public const LTE = '<=';
    public const GT = '>';
    public const GTE = '>=';
    public const IN = 'IN';
    public const NIN = 'NOT IN';
    public const LIKE = 'LIKE';

    public function __construct(
        private readonly string $field,
        private readonly string $operator,
        private readonly mixed $value
    ) {}

    public function getField(): string
    {
        return $this->field;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
