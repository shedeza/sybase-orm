<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Exception\OqlParseException;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\GroupByClause;
use SybaseORM\Query\AST\JoinClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\OrderByClause;
use SybaseORM\Query\AST\OrderByItem;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\WhereClause;

/**
 * Parses OQL (Object Query Language) strings into AST nodes.
 *
 * OQL syntax:
 *   SELECT expr[, expr...] FROM EntityName alias
 *   [JOIN alias.property joinAlias]
 *   [LEFT JOIN alias.property joinAlias]
 *   [WHERE condition]
 *   [GROUP BY alias.prop[, alias.prop...]]
 *   [ORDER BY alias.prop [ASC|DESC][, ...]]
 */
final class OqlParser
{
    /** @var string[] */
    private array $tokens = [];
    private int $pos = 0;

    private const COMPARISON_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'IN'];

    public function parse(string $oql): SelectStatement
    {
        $this->tokenize($oql);
        $this->pos = 0;

        return $this->parseSelectStatement();
    }

    private function tokenize(string $oql): void
    {
        $this->tokens = [];
        $length = strlen($oql);
        $i = 0;

        while ($i < $length) {
            // Skip whitespace
            if (ctype_space($oql[$i])) {
                $i++;
                continue;
            }

            // String literal
            if ($oql[$i] === "'") {
                $start = $i;
                $i++;
                while ($i < $length && $oql[$i] !== "'") {
                    if ($oql[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                if ($i < $length) {
                    $i++; // closing quote
                }
                $this->tokens[] = substr($oql, $start, $i - $start);
                continue;
            }

            // Parameter :name
            if ($oql[$i] === ':') {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_alnum($oql[$i]) || $oql[$i] === '_')) {
                    $i++;
                }
                $this->tokens[] = substr($oql, $start, $i - $start);
                continue;
            }

            // Multi-char operators
            if ($i + 1 < $length) {
                $two = $oql[$i] . $oql[$i + 1];
                if (in_array($two, ['!=', '<=', '>='], true)) {
                    $this->tokens[] = $two;
                    $i += 2;
                    continue;
                }
            }

            // Single-char operators/punctuation
            if (in_array($oql[$i], ['=', '<', '>', ',', '(', ')'], true)) {
                $this->tokens[] = $oql[$i];
                $i++;
                continue;
            }

            // Dot-separated identifiers and keywords
            if (ctype_alnum($oql[$i]) || $oql[$i] === '_' || $oql[$i] === '.') {
                $start = $i;
                while ($i < $length && (ctype_alnum($oql[$i]) || $oql[$i] === '_' || $oql[$i] === '.')) {
                    $i++;
                }
                $this->tokens[] = substr($oql, $start, $i - $start);
                continue;
            }

            // Unknown character - skip
            $i++;
        }
    }

    private function parseSelectStatement(): SelectStatement
    {
        $this->expect('SELECT');

        $selectExpressions = $this->parseSelectExpressions();

        $this->expect('FROM');

        $from = $this->parseFromClause();

        $joins = [];
        while ($this->isAtJoinKeyword()) {
            $joins[] = $this->parseJoinClause();
        }

        $where = null;
        if ($this->isAt('WHERE')) {
            $this->advance();
            $where = new WhereClause($this->parseCondition());
        }

        $groupBy = null;
        if ($this->isAt('GROUP')) {
            $this->advance();
            $this->expect('BY');
            $groupBy = $this->parseGroupByClause();
        }

        $orderBy = null;
        if ($this->isAt('ORDER')) {
            $this->advance();
            $this->expect('BY');
            $orderBy = $this->parseOrderByClause();
        }

        if ($this->pos < count($this->tokens)) {
            throw new OqlParseException(sprintf(
                'Unexpected token "%s" at position %d.',
                $this->tokens[$this->pos],
                $this->pos,
            ));
        }

        return new SelectStatement(
            selectExpressions: $selectExpressions,
            from: $from,
            where: $where,
            joins: $joins,
            orderBy: $orderBy,
            groupBy: $groupBy,
        );
    }

    /**
     * @return SelectExpression[]
     */
    private function parseSelectExpressions(): array
    {
        $expressions = [];
        $expressions[] = $this->parseSelectExpression();

        while ($this->isAt(',')) {
            $this->advance();
            $expressions[] = $this->parseSelectExpression();
        }

        return $expressions;
    }

    private function parseSelectExpression(): SelectExpression
    {
        $expr = $this->current();
        $this->advance();

        return new SelectExpression($expr);
    }

    private function parseFromClause(): FromClause
    {
        $entityName = $this->current();
        $this->advance();

        $alias = $this->current();
        $this->advance();

        return new FromClause($entityName, $alias);
    }

    private function isAtJoinKeyword(): bool
    {
        if ($this->isAt('JOIN')) {
            return true;
        }
        if ($this->isAt('LEFT') && $this->peek() !== null && strtoupper($this->peek()) === 'JOIN') {
            return true;
        }
        if ($this->isAt('INNER') && $this->peek() !== null && strtoupper($this->peek()) === 'JOIN') {
            return true;
        }

        return false;
    }

    private function parseJoinClause(): JoinClause
    {
        $joinType = 'JOIN';

        if ($this->isAt('LEFT')) {
            $joinType = 'LEFT JOIN';
            $this->advance();
            $this->expect('JOIN');
        } elseif ($this->isAt('INNER')) {
            $joinType = 'JOIN';
            $this->advance();
            $this->expect('JOIN');
        } else {
            $this->expect('JOIN');
        }

        $propertyPath = $this->current();
        $this->advance();

        $parts = explode('.', $propertyPath);
        if (count($parts) !== 2) {
            throw new OqlParseException(sprintf(
                'Expected property access (alias.property) in JOIN, got "%s".',
                $propertyPath,
            ));
        }

        $property = new PropertyAccess($parts[0], $parts[1]);

        $alias = $this->current();
        $this->advance();

        return new JoinClause($joinType, $property, $alias);
    }

    private function parseCondition(): Comparison|LogicalExpression
    {
        $left = $this->parseComparison();

        while ($this->isAt('AND') || $this->isAt('OR')) {
            $operator = strtoupper($this->current());
            $this->advance();
            $right = $this->parseComparison();
            $left = new LogicalExpression($left, $operator, $right);
        }

        return $left;
    }

    private function parseComparison(): Comparison
    {
        $left = $this->parseOperand();

        $operator = strtoupper($this->current());
        if (!in_array($operator, self::COMPARISON_OPERATORS, true)) {
            throw new OqlParseException(sprintf(
                'Expected comparison operator, got "%s".',
                $this->current(),
            ));
        }
        $this->advance();

        $right = $this->parseOperand();

        return new Comparison($left, $operator, $right);
    }

    private function parseOperand(): PropertyAccess|Literal|Parameter
    {
        $token = $this->current();

        // Parameter
        if (str_starts_with($token, ':')) {
            $this->advance();
            return new Parameter(substr($token, 1));
        }

        // String literal
        if (str_starts_with($token, "'") && str_ends_with($token, "'")) {
            $this->advance();
            return new Literal(substr($token, 1, -1), 'string');
        }

        // Numeric literal
        if (is_numeric($token)) {
            $this->advance();
            if (str_contains($token, '.')) {
                return new Literal((float) $token, 'float');
            }
            return new Literal((int) $token, 'integer');
        }

        // Property access (alias.property)
        if (str_contains($token, '.')) {
            $parts = explode('.', $token);
            if (count($parts) === 2) {
                $this->advance();
                return new PropertyAccess($parts[0], $parts[1]);
            }
        }

        throw new OqlParseException(sprintf(
            'Unexpected token "%s" at position %d.',
            $token,
            $this->pos,
        ));
    }

    private function parseOrderByClause(): OrderByClause
    {
        $items = [];
        $items[] = $this->parseOrderByItem();

        while ($this->isAt(',')) {
            $this->advance();
            $items[] = $this->parseOrderByItem();
        }

        return new OrderByClause($items);
    }

    private function parseOrderByItem(): OrderByItem
    {
        $token = $this->current();
        $this->advance();

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new OqlParseException(sprintf(
                'Expected property access (alias.property) in ORDER BY, got "%s".',
                $token,
            ));
        }

        $property = new PropertyAccess($parts[0], $parts[1]);
        $direction = 'ASC';

        if ($this->pos < count($this->tokens) && in_array(strtoupper($this->currentOrEmpty()), ['ASC', 'DESC'], true)) {
            $direction = strtoupper($this->current());
            $this->advance();
        }

        return new OrderByItem($property, $direction);
    }

    private function parseGroupByClause(): GroupByClause
    {
        $properties = [];
        $properties[] = $this->parsePropertyAccess();

        while ($this->isAt(',')) {
            $this->advance();
            $properties[] = $this->parsePropertyAccess();
        }

        return new GroupByClause($properties);
    }

    private function parsePropertyAccess(): PropertyAccess
    {
        $token = $this->current();
        $this->advance();

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw new OqlParseException(sprintf(
                'Expected property access (alias.property), got "%s".',
                $token,
            ));
        }

        return new PropertyAccess($parts[0], $parts[1]);
    }

    // ── Token helpers ───────────────────────────────────────────────

    private function current(): string
    {
        if ($this->pos >= count($this->tokens)) {
            throw new OqlParseException('Unexpected end of OQL query.');
        }

        return $this->tokens[$this->pos];
    }

    private function currentOrEmpty(): string
    {
        return $this->tokens[$this->pos] ?? '';
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos + 1] ?? null;
    }

    private function advance(): void
    {
        $this->pos++;
    }

    private function expect(string $expected): void
    {
        $token = $this->current();
        if (strtoupper($token) !== strtoupper($expected)) {
            throw new OqlParseException(sprintf(
                'Expected "%s", got "%s" at position %d.',
                $expected,
                $token,
                $this->pos,
            ));
        }
        $this->advance();
    }

    private function isAt(string $keyword): bool
    {
        if ($this->pos >= count($this->tokens)) {
            return false;
        }

        return strtoupper($this->tokens[$this->pos]) === strtoupper($keyword);
    }
}
