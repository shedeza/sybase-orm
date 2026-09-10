<?php

declare(strict_types=1);

namespace SybaseORM\Query\Criteria;

class Criteria
{
    public const ASC = 'ASC';
    public const DESC = 'DESC';

    private static ?ExpressionBuilder $expressionBuilder = null;

    private ?ExpressionInterface $whereExpression = null;
    private array $orderings = [];
    private ?int $firstResult = null;
    private ?int $maxResults = null;

    public static function create(): self
    {
        return new self();
    }

    public static function expr(): ExpressionBuilder
    {
        if (self::$expressionBuilder === null) {
            self::$expressionBuilder = new ExpressionBuilder();
        }

        return self::$expressionBuilder;
    }

    public function where(ExpressionInterface $expression): self
    {
        $this->whereExpression = $expression;
        return $this;
    }

    public function andWhere(ExpressionInterface $expression): self
    {
        if ($this->whereExpression === null) {
            return $this->where($expression);
        }

        $this->whereExpression = new CompositeExpression(
            CompositeExpression::TYPE_AND,
            [$this->whereExpression, $expression]
        );

        return $this;
    }

    public function orWhere(ExpressionInterface $expression): self
    {
        if ($this->whereExpression === null) {
            return $this->where($expression);
        }

        $this->whereExpression = new CompositeExpression(
            CompositeExpression::TYPE_OR,
            [$this->whereExpression, $expression]
        );

        return $this;
    }

    public function orderBy(array $orderings): self
    {
        $this->orderings = $orderings;
        return $this;
    }

    public function setFirstResult(?int $firstResult): self
    {
        $this->firstResult = $firstResult;
        return $this;
    }

    public function setMaxResults(?int $maxResults): self
    {
        $this->maxResults = $maxResults;
        return $this;
    }

    public function getWhereExpression(): ?ExpressionInterface
    {
        return $this->whereExpression;
    }

    public function getOrderings(): array
    {
        return $this->orderings;
    }

    public function getFirstResult(): ?int
    {
        return $this->firstResult;
    }

    public function getMaxResults(): ?int
    {
        return $this->maxResults;
    }
}
