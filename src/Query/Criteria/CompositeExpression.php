<?php

declare(strict_types=1);

namespace SybaseORM\Query\Criteria;

class CompositeExpression implements ExpressionInterface
{
    public const TYPE_AND = 'AND';
    public const TYPE_OR = 'OR';

    /** @var ExpressionInterface[] */
    private array $expressions;

    public function __construct(
        private readonly string $type,
        array $expressions
    ) {
        $this->expressions = $expressions;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return ExpressionInterface[]
     */
    public function getExpressions(): array
    {
        return $this->expressions;
    }
}
