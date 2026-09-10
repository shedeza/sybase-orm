<?php

declare(strict_types=1);

namespace SybaseORM\Query\Criteria;

class ExpressionBuilder
{
    public function eq(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Comparison::EQ, $value);
    }

    public function neq(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Comparison::NEQ, $value);
    }

    public function lt(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Comparison::LT, $value);
    }

    public function lte(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Comparison::LTE, $value);
    }

    public function gt(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Comparison::GT, $value);
    }

    public function gte(string $field, mixed $value): Comparison
    {
        return new Comparison($field, Comparison::GTE, $value);
    }

    public function in(string $field, array $values): Comparison
    {
        return new Comparison($field, Comparison::IN, $values);
    }

    public function notIn(string $field, array $values): Comparison
    {
        return new Comparison($field, Comparison::NIN, $values);
    }

    public function like(string $field, string $value): Comparison
    {
        return new Comparison($field, Comparison::LIKE, $value);
    }

    public function andX(ExpressionInterface ...$expressions): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_AND, $expressions);
    }

    public function orX(ExpressionInterface ...$expressions): CompositeExpression
    {
        return new CompositeExpression(CompositeExpression::TYPE_OR, $expressions);
    }
}
