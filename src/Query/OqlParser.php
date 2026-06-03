<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Exception\OqlParseException;
use SybaseORM\Query\AST\Comparison;
use SybaseORM\Query\AST\CustomFunctionCall;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\FromClause;
use SybaseORM\Query\AST\FunctionCall;
use SybaseORM\Query\AST\GroupByClause;
use SybaseORM\Query\AST\HavingClause;
use SybaseORM\Query\AST\InExpression;
use SybaseORM\Query\AST\IsNullExpression;
use SybaseORM\Query\AST\JoinClause;
use SybaseORM\Query\AST\Literal;
use SybaseORM\Query\AST\LogicalExpression;
use SybaseORM\Query\AST\OrderByClause;
use SybaseORM\Query\AST\OrderByItem;
use SybaseORM\Query\AST\Parameter;
use SybaseORM\Query\AST\PropertyAccess;
use SybaseORM\Query\AST\SelectExpression;
use SybaseORM\Query\AST\SelectStatement;
use SybaseORM\Query\AST\SetClause;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\AST\WhereClause;

/**
 * Parses OQL (Object Query Language) strings into AST nodes.
 *
 * OQL syntax:
 *   SELECT [DISTINCT] expr[, expr...] FROM EntityName alias
 *   [JOIN alias.property joinAlias]
 *   [JOIN EntityName joinAlias WITH condition]
 *   [LEFT JOIN alias.property joinAlias]
 *   [LEFT JOIN EntityName joinAlias WITH condition]
 *   [WHERE condition]
 *   [GROUP BY alias.prop[, alias.prop...]]
 *   [HAVING condition]
 *   [ORDER BY alias.prop [ASC|DESC][, ...]]
 *
 *   UPDATE EntityName alias SET alias.prop = value [, ...] [WHERE condition]
 *   DELETE FROM EntityName alias [WHERE condition]
 */
final class OqlParser
{
    /** @var string[] */
    private array $tokens = [];
    private int $pos = 0;

    /** @var string The original OQL string being parsed (for error messages) */
    private string $originalOql = '';

    private const COMPARISON_OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'LIKE'];

    private const AGGREGATE_FUNCTIONS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    /** @var string[] */
    private array $customFunctions = ['CONVERT', 'RAND'];

    /**
     * Registers a custom function name so the parser recognizes it.
     */
    public function registerFunction(string $name): void
    {
        $normalized = strtoupper($name);
        if (!in_array($normalized, $this->customFunctions, true)) {
            $this->customFunctions[] = $normalized;
        }
    }

    public function parse(string $oql): SelectStatement|UpdateStatement|DeleteStatement
    {
        $this->originalOql = $oql;
        $this->tokenize($oql);
        $this->pos = 0;

        $firstToken = strtoupper($this->current());

        return match ($firstToken) {
            'SELECT' => $this->parseSelectStatement(),
            'UPDATE' => $this->parseUpdateStatement(),
            'DELETE' => $this->parseDeleteStatement(),
            default => throw new OqlParseException(sprintf(
                'Expected SELECT, UPDATE, or DELETE, got "%s" at position %d.',
                $this->current(),
                $this->pos,
            )),
        };
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

            // String literal (supports both backslash escaping and Sybase doubled-quote escaping)
            if ($oql[$i] === "'") {
                $start = $i;
                $i++;
                while ($i < $length) {
                    if ($oql[$i] === '\\') {
                        $i += 2; // skip escaped character
                        continue;
                    }
                    if ($oql[$i] === "'") {
                        // Check for doubled quote (Sybase escape: '')
                        if ($i + 1 < $length && $oql[$i + 1] === "'") {
                            $i += 2; // skip both quotes
                            continue;
                        }
                        break; // closing quote
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

            // Single-char operators/punctuation (including * for wildcards and COUNT(*))
            if (in_array($oql[$i], ['=', '<', '>', ',', '(', ')', '*'], true)) {
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

        // Task 6.6: Check for DISTINCT after SELECT
        $distinct = false;
        if ($this->isAt('DISTINCT')) {
            $distinct = true;
            $this->advance();
        }

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

        // Task 6.4: Check for HAVING after GROUP BY
        $havingClause = null;
        if ($this->isAt('HAVING')) {
            $this->advance();
            $havingClause = new HavingClause($this->parseCondition());
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
            havingClause: $havingClause,
            distinct: $distinct,
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
        // Task 6.6: Handle * wildcard
        if ($this->isAt('*')) {
            $this->advance();

            return new SelectExpression('*');
        }

        // Task 6.3: Handle aggregate functions
        $token = $this->current();
        if (in_array(strtoupper($token), self::AGGREGATE_FUNCTIONS, true)) {
            $functionCall = $this->parseFunctionCall();

            // Task 6.7: Check for AS alias after function call
            $alias = null;
            if ($this->isAt('AS')) {
                $this->advance();
                $alias = $this->current();
                $this->advance();
            }

            return new SelectExpression($functionCall, $alias);
        }

        $expr = $this->current();
        $this->advance();

        // Task 6.7: Check for AS alias after expression
        $alias = null;
        if ($this->isAt('AS')) {
            $this->advance();
            $alias = $this->current();
            $this->advance();
        }

        return new SelectExpression($expr, $alias);
    }

    /**
     * Parses an aggregate function call: FUNC([DISTINCT] argument)
     */
    private function parseFunctionCall(): FunctionCall
    {
        $functionName = strtoupper($this->current());
        $this->advance();

        $this->expect('(');

        // Check for DISTINCT
        $distinct = false;
        if ($this->isAt('DISTINCT')) {
            $distinct = true;
            $this->advance();
        }

        // Check for * (COUNT(*))
        if ($this->isAt('*')) {
            $argument = '*';
            $this->advance();
        } else {
            // Parse property access as argument
            $token = $this->current();
            $this->advance();

            if (str_contains($token, '.')) {
                $parts = explode('.', $token);
                if (count($parts) === 2) {
                    $argument = new PropertyAccess($parts[0], $parts[1]);
                } else {
                    throw new OqlParseException(sprintf(
                        'Expected property access (alias.property) in function argument, got "%s".',
                        $token,
                    ));
                }
            } else {
                throw new OqlParseException(sprintf(
                    'Expected property access (alias.property) or "*" in function argument, got "%s".',
                    $token,
                ));
            }
        }

        $this->expect(')');

        return new FunctionCall($functionName, $argument, $distinct);
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

    /**
     * Task 6.5: Extended to support entity-based JOIN with WITH condition.
     * If the identifier after JOIN contains a dot, it's a relationship-based join.
     * Otherwise, it's an entity-based join: JOIN EntityName alias WITH condition.
     */
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

        $identifier = $this->current();
        $this->advance();

        // Check if this is a relationship-based join (contains dot) or entity-based join
        if (str_contains($identifier, '.')) {
            // Relationship-based join: alias.property joinAlias
            $parts = explode('.', $identifier);
            if (count($parts) !== 2) {
                throw new OqlParseException(sprintf(
                    'Expected property access (alias.property) in JOIN, got "%s".',
                    $identifier,
                ));
            }

            $property = new PropertyAccess($parts[0], $parts[1]);

            $alias = $this->current();
            $this->advance();

            return new JoinClause($joinType, $property, $alias);
        }

        // Entity-based join: EntityName alias WITH condition
        $entityName = $identifier;

        $alias = $this->current();
        $this->advance();

        $this->expect('WITH');

        $withCondition = $this->parseCondition();

        return new JoinClause(
            $joinType,
            new PropertyAccess($entityName, ''),
            $alias,
            $entityName,
            $withCondition,
        );
    }

    /**
     * Task 6.1 & 6.2: Extended to support IS NULL, IS NOT NULL, IN, NOT IN
     * in addition to standard comparisons and logical expressions.
     *
     * @return Comparison|LogicalExpression|IsNullExpression|InExpression
     */
    private function parseCondition(): Comparison|LogicalExpression|IsNullExpression|InExpression
    {
        $left = $this->parseSingleCondition();

        while ($this->isAt('AND') || $this->isAt('OR')) {
            $operator = strtoupper($this->current());
            $this->advance();
            $right = $this->parseSingleCondition();
            $left = new LogicalExpression($left, $operator, $right);
        }

        return $left;
    }

    /**
     * Parses a single condition: comparison, IS NULL, IS NOT NULL, IN, NOT IN,
     * or a parenthesized condition group.
     *
     * @return Comparison|IsNullExpression|InExpression|LogicalExpression
     */
    private function parseSingleCondition(): Comparison|IsNullExpression|InExpression|LogicalExpression
    {
        // Parenthesized condition group: ( condition )
        if ($this->isAt('(')) {
            $this->advance(); // consume '('
            $inner = $this->parseCondition();
            $this->expect(')');

            return $inner;
        }

        // Check if the left operand is an aggregate function (for HAVING conditions)
        $token = $this->current();
        if (in_array(strtoupper($token), self::AGGREGATE_FUNCTIONS, true)) {
            $left = $this->parseFunctionCall();

            // After a function call, expect a comparison operator
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

        // Check if the left operand is a custom function (CONVERT, RAND)
        if (in_array(strtoupper($token), $this->customFunctions, true)) {
            $left = $this->parseCustomFunctionCall();

            // After a custom function call, expect a comparison operator
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

        $left = $this->parseOperand();

        // Task 6.1: Check for IS [NOT] NULL
        if ($this->isAt('IS')) {
            if (!($left instanceof PropertyAccess)) {
                throw new OqlParseException(sprintf(
                    'IS NULL/IS NOT NULL requires a property access on the left side at position %d.',
                    $this->pos,
                ));
            }
            $this->advance();

            if ($this->isAt('NOT')) {
                $this->advance();
                $this->expect('NULL');

                return new IsNullExpression($left, negated: true);
            }

            if ($this->isAt('NULL')) {
                $this->advance();

                return new IsNullExpression($left, negated: false);
            }

            throw new OqlParseException(sprintf(
                'Expected "NULL" or "NOT", got "%s" at position %d.',
                $this->currentOrEmpty(),
                $this->pos,
            ));
        }

        // Task 6.2: Check for NOT IN
        if ($this->isAt('NOT') && $this->peek() !== null && strtoupper($this->peek()) === 'IN') {
            if (!($left instanceof PropertyAccess)) {
                throw new OqlParseException(sprintf(
                    'NOT IN requires a property access on the left side at position %d.',
                    $this->pos,
                ));
            }
            $this->advance(); // consume NOT
            $this->advance(); // consume IN

            $values = $this->parseInValueList();

            return new InExpression($left, $values, negated: true);
        }

        // Task 6.2: Check for IN
        if ($this->isAt('IN')) {
            if (!($left instanceof PropertyAccess)) {
                throw new OqlParseException(sprintf(
                    'IN requires a property access on the left side at position %d.',
                    $this->pos,
                ));
            }
            $this->advance(); // consume IN

            $values = $this->parseInValueList();

            return new InExpression($left, $values, negated: false);
        }

        // Standard comparison
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

    /**
     * Parses the value list for IN/NOT IN: ( valueList )
     * valueList := parameter | literal (',' literal)*
     *
     * @return array<Parameter|Literal>
     */
    private function parseInValueList(): array
    {
        $this->expect('(');

        $values = [];
        $values[] = $this->parseInValue();

        while ($this->isAt(',')) {
            $this->advance();
            $values[] = $this->parseInValue();
        }

        $this->expect(')');

        return $values;
    }

    /**
     * Parses a single value inside an IN list: parameter or literal.
     */
    private function parseInValue(): Parameter|Literal
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

        throw new OqlParseException(sprintf(
            'Expected parameter or literal in IN list, got "%s" at position %d.',
            $token,
            $this->pos,
        ));
    }

    private function parseOperand(): PropertyAccess|Literal|Parameter|CustomFunctionCall
    {
        $token = $this->current();

        // Custom function call as operand
        if (in_array(strtoupper($token), $this->customFunctions, true)) {
            return $this->parseCustomFunctionCall();
        }

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

    private function parseUpdateStatement(): UpdateStatement
    {
        $this->expect('UPDATE');

        $entityName = $this->current();
        $this->advance();

        $alias = $this->current();
        $this->advance();

        $this->expect('SET');

        $setClauses = [];
        $setClauses[] = $this->parseSetClause();

        while ($this->isAt(',')) {
            $this->advance();
            $setClauses[] = $this->parseSetClause();
        }

        $where = null;
        if ($this->isAt('WHERE')) {
            $this->advance();
            $where = new WhereClause($this->parseCondition());
        }

        if ($this->pos < count($this->tokens)) {
            throw new OqlParseException(sprintf(
                'Unexpected token "%s" at position %d.',
                $this->tokens[$this->pos],
                $this->pos,
            ));
        }

        return new UpdateStatement($entityName, $alias, $setClauses, $where);
    }

    private function parseSetClause(): SetClause
    {
        $property = $this->parsePropertyAccess();

        $this->expect('=');

        $value = $this->parseSetValue();

        return new SetClause($property, $value);
    }

    private function parseSetValue(): Parameter|Literal|CustomFunctionCall
    {
        $token = $this->current();

        // NULL literal
        if (strtoupper($token) === 'NULL') {
            $this->advance();

            return new Literal('NULL', 'null');
        }

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

        // Custom function call
        if (in_array(strtoupper($token), $this->customFunctions, true)) {
            return $this->parseCustomFunctionCall();
        }

        throw new OqlParseException(sprintf(
            'Expected value (parameter, literal, NULL, or function) in SET clause, got "%s" at position %d.',
            $token,
            $this->pos,
        ));
    }

    private function parseDeleteStatement(): DeleteStatement
    {
        $this->expect('DELETE');
        $this->expect('FROM');

        $entityName = $this->current();
        $this->advance();

        $alias = $this->current();
        $this->advance();

        $where = null;
        if ($this->isAt('WHERE')) {
            $this->advance();
            $where = new WhereClause($this->parseCondition());
        }

        if ($this->pos < count($this->tokens)) {
            throw new OqlParseException(sprintf(
                'Unexpected token "%s" at position %d.',
                $this->tokens[$this->pos],
                $this->pos,
            ));
        }

        return new DeleteStatement($entityName, $alias, $where);
    }

    /**
     * Parses a custom function call: RAND() or CONVERT(expr AS type)
     * Supports nesting: CONVERT(RAND() AS REAL)
     * For user-registered functions, defaults to no-arg pattern: FUNCNAME()
     */
    private function parseCustomFunctionCall(): CustomFunctionCall
    {
        $functionName = strtoupper($this->current());
        $this->advance();

        $this->expect('(');

        // No-arg functions: immediate closing paren
        if ($this->isAt(')')) {
            $this->expect(')');

            return new CustomFunctionCall($functionName, [], null);
        }

        if ($functionName === 'CONVERT') {
            // CONVERT(expr AS type)
            $expr = $this->parseCustomFunctionArgument();

            $this->expect('AS');

            $castType = strtoupper($this->current());
            $this->advance();

            $this->expect(')');

            return new CustomFunctionCall('CONVERT', [$expr], $castType);
        }

        // Generic function with arguments: FUNC(arg1, arg2, ...)
        $arguments = [];
        $arguments[] = $this->parseCustomFunctionArgument();

        while ($this->isAt(',')) {
            $this->advance();
            $arguments[] = $this->parseCustomFunctionArgument();
        }

        $this->expect(')');

        return new CustomFunctionCall($functionName, $arguments, null);
    }

    /**
     * Parses an argument inside a custom function call.
     * Can be: Parameter, Literal, PropertyAccess, or nested CustomFunctionCall.
     */
    private function parseCustomFunctionArgument(): PropertyAccess|Literal|Parameter|CustomFunctionCall
    {
        $token = $this->current();

        // Nested custom function
        if (in_array(strtoupper($token), $this->customFunctions, true)) {
            return $this->parseCustomFunctionCall();
        }

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
            'Expected expression in function argument, got "%s" at position %d.',
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
                'Expected "%s", got "%s" at position %d in OQL: %s',
                $expected,
                $token,
                $this->pos,
                $this->originalOql,
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
