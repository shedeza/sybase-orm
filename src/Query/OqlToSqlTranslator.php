<?php

declare(strict_types=1);

namespace SybaseORM\Query;

use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Metadata\MetadataReaderInterface;
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

/**
 * Translates an OQL AST into SQL compatible with Sybase ASE via the DialectInterface.
 *
 * Resolves entity names to table names and property names to column names
 * using MetadataReaderInterface. User values are parameterized.
 */
final class OqlToSqlTranslator
{
    /**
     * Maps OQL alias → fully qualified entity class name.
     * @var array<string, string>
     */
    private array $aliasToEntity = [];

    /**
     * Maps OQL alias → SQL table alias.
     * @var array<string, string>
     */
    private array $aliasToTable = [];

    /**
     * When true, resolvePropertyToColumn omits the alias prefix.
     * Used for UPDATE/DELETE where Sybase ASE doesn't support table aliases.
     */
    private bool $stripAlias = false;

    /**
     * Maps custom function name → SQL template.
     * @var array<string, string>
     */
    private array $customFunctionSql = [];

    /**
     * Maps entity short name → fully qualified class name.
     * @var array<string, string>
     */
    private array $entityMap;

    public function __construct(
        private readonly DialectInterface $dialect,
        private readonly MetadataReaderInterface $metadataReader,
        private readonly array $entityClasses = [],
        array $precomputedEntityMap = [],
    ) {
        if ($precomputedEntityMap !== []) {
            $this->entityMap = $precomputedEntityMap;
        } else {
            $this->entityMap = [];
            foreach ($this->entityClasses as $fqcn) {
                $shortName = (new \ReflectionClass($fqcn))->getShortName();
                $this->entityMap[$shortName] = $fqcn;
            }
        }
    }

    /**
     * Registers a custom function with its SQL template.
     */
    public function registerFunction(string $name, string $sqlTemplate): void
    {
        $this->customFunctionSql[strtoupper($name)] = $sqlTemplate;
    }

    /**
     * Translates an AST into a SQL string.
     *
     * @return array{sql: string, parameters: string[]} SQL and parameter names
     */
    public function translate(SelectStatement|UpdateStatement|DeleteStatement $statement): array
    {
        $this->aliasToEntity = [];
        $this->aliasToTable = [];
        $this->stripAlias = false;

        if ($statement instanceof UpdateStatement) {
            return $this->translateUpdate($statement);
        }

        if ($statement instanceof DeleteStatement) {
            return $this->translateDelete($statement);
        }

        return $this->translateSelect($statement);
    }

    /**
     * Translates a SelectStatement AST into a SQL string.
     *
     * @return array{sql: string, parameters: string[]}
     */
    private function translateSelect(SelectStatement $statement): array
    {
        $parameters = [];

        // Resolve FROM
        $fromSql = $this->resolveFrom($statement->from);

        // Resolve JOINs
        $joinsSql = '';
        foreach ($statement->joins as $join) {
            $joinsSql .= ' ' . $this->resolveJoin($join, $parameters);
        }

        // Resolve SELECT
        $selectSql = $this->resolveSelect($statement->selectExpressions);

        // Build base SQL
        $distinctKeyword = $statement->distinct ? 'DISTINCT ' : '';
        $sql = 'SELECT ' . $distinctKeyword . $selectSql . ' FROM ' . $fromSql . $joinsSql;

        // WHERE
        if ($statement->where !== null) {
            $whereSql = $this->resolveCondition($statement->where->condition, $parameters);
            $sql .= ' WHERE ' . $whereSql;
        }

        // GROUP BY
        if ($statement->groupBy !== null) {
            $sql .= ' GROUP BY ' . $this->resolveGroupBy($statement->groupBy);
        }

        // HAVING
        if ($statement->havingClause !== null) {
            $sql .= ' HAVING ' . $this->resolveHaving($statement->havingClause, $parameters);
        }

        // ORDER BY
        if ($statement->orderBy !== null) {
            $sql .= ' ORDER BY ' . $this->resolveOrderBy($statement->orderBy);
        }

        return ['sql' => $sql, 'parameters' => $parameters];
    }

    /**
     * Translates an UpdateStatement AST into SQL.
     *
     * @return array{sql: string, parameters: string[]}
     */
    private function translateUpdate(UpdateStatement $statement): array
    {
        $parameters = [];

        // Resolve entity name to table name
        $entityClass = $this->resolveEntityName($statement->entityName);
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        $this->aliasToEntity[$statement->alias] = $entityClass;
        $this->aliasToTable[$statement->alias] = $statement->alias;

        // Sybase ASE doesn't support table aliases in UPDATE statements
        $this->stripAlias = true;

        $tableName = $this->dialect->quoteIdentifier($metadata->tableName);

        // Build SET clauses
        $setClauses = [];
        foreach ($statement->setClauses as $setClause) {
            $columnSql = $this->resolvePropertyToColumn($setClause->property->alias, $setClause->property->property);
            $valueSql = $this->resolveSetValue($setClause->value, $parameters);
            $setClauses[] = $columnSql . ' = ' . $valueSql;
        }

        $sql = 'UPDATE ' . $tableName . ' SET ' . implode(', ', $setClauses);

        // WHERE
        if ($statement->where !== null) {
            $whereSql = $this->resolveCondition($statement->where->condition, $parameters);
            $sql .= ' WHERE ' . $whereSql;
        }

        $this->stripAlias = false;

        return ['sql' => $sql, 'parameters' => $parameters];
    }

    /**
     * Translates a DeleteStatement AST into SQL.
     *
     * @return array{sql: string, parameters: string[]}
     */
    private function translateDelete(DeleteStatement $statement): array
    {
        $parameters = [];

        // Resolve entity name to table name
        $entityClass = $this->resolveEntityName($statement->entityName);
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        $this->aliasToEntity[$statement->alias] = $entityClass;
        $this->aliasToTable[$statement->alias] = $statement->alias;

        // Sybase ASE doesn't support table aliases in DELETE statements
        $this->stripAlias = true;

        $tableName = $this->dialect->quoteIdentifier($metadata->tableName);

        $sql = 'DELETE FROM ' . $tableName;

        // WHERE
        if ($statement->where !== null) {
            $whereSql = $this->resolveCondition($statement->where->condition, $parameters);
            $sql .= ' WHERE ' . $whereSql;
        }

        $this->stripAlias = false;

        return ['sql' => $sql, 'parameters' => $parameters];
    }

    /**
     * Resolves a SET clause value to SQL.
     */
    private function resolveSetValue(Parameter|Literal|CustomFunctionCall $value, array &$parameters): string
    {
        if ($value instanceof Parameter) {
            $parameters[] = $value->name;
            return ':' . $value->name;
        }

        if ($value instanceof Literal) {
            if ($value->type === 'null') {
                return 'NULL';
            }
            if ($value->type === 'string') {
                return "'" . str_replace("'", "''", (string) $value->value) . "'";
            }
            return (string) $value->value;
        }

        if ($value instanceof CustomFunctionCall) {
            return $this->resolveCustomFunctionCall($value, $parameters);
        }

        return '';
    }

    /**
     * Resolves a CustomFunctionCall to SQL.
     * CONVERT(expr AS type) in OQL → CONVERT(type, expr) in Sybase SQL
     * RAND() → RAND()
     * User-registered functions → their SQL template
     */
    private function resolveCustomFunctionCall(CustomFunctionCall $func, array &$parameters): string
    {
        $upperName = strtoupper($func->functionName);

        // CONVERT: OQL uses CONVERT(expr AS type), Sybase SQL uses CONVERT(type, expr)
        if ($upperName === 'CONVERT') {
            $argParts = [];
            foreach ($func->arguments as $arg) {
                if ($arg instanceof CustomFunctionCall) {
                    $argParts[] = $this->resolveCustomFunctionCall($arg, $parameters);
                } elseif ($arg instanceof Parameter) {
                    $parameters[] = $arg->name;
                    $argParts[] = ':' . $arg->name;
                } elseif ($arg instanceof PropertyAccess) {
                    $argParts[] = $this->resolvePropertyToColumn($arg->alias, $arg->property);
                } elseif ($arg instanceof Literal) {
                    if ($arg->type === 'null') {
                        $argParts[] = 'NULL';
                    } elseif ($arg->type === 'string') {
                        $argParts[] = "'" . str_replace("'", "''", (string) $arg->value) . "'";
                    } else {
                        $argParts[] = (string) $arg->value;
                    }
                }
            }

            return 'CONVERT(' . $func->castType . ', ' . implode(', ', $argParts) . ')';
        }

        // User-registered custom functions
        if (isset($this->customFunctionSql[$upperName])) {
            $template = $this->customFunctionSql[$upperName];

            // If function has arguments, replace ? placeholders in the template
            if (!empty($func->arguments)) {
                $resolvedArgs = [];
                foreach ($func->arguments as $arg) {
                    if ($arg instanceof CustomFunctionCall) {
                        $resolvedArgs[] = $this->resolveCustomFunctionCall($arg, $parameters);
                    } elseif ($arg instanceof Parameter) {
                        $parameters[] = $arg->name;
                        $resolvedArgs[] = ':' . $arg->name;
                    } elseif ($arg instanceof PropertyAccess) {
                        $resolvedArgs[] = $this->resolvePropertyToColumn($arg->alias, $arg->property);
                    } elseif ($arg instanceof Literal) {
                        if ($arg->type === 'null') {
                            $resolvedArgs[] = 'NULL';
                        } elseif ($arg->type === 'string') {
                            $resolvedArgs[] = "'" . str_replace("'", "''", (string) $arg->value) . "'";
                        } else {
                            $resolvedArgs[] = (string) $arg->value;
                        }
                    }
                }

                // Replace ? placeholders in template with resolved arguments
                $result = $template;
                foreach ($resolvedArgs as $resolved) {
                    $pos = strpos($result, '?');
                    if ($pos !== false) {
                        $result = substr_replace($result, $resolved, $pos, 1);
                    }
                }

                return $result;
            }

            // No-arg: return template as-is
            return $template;
        }

        // Fallback: emit as FUNCNAME(args...)
        $argParts = [];
        foreach ($func->arguments as $arg) {
            if ($arg instanceof CustomFunctionCall) {
                $argParts[] = $this->resolveCustomFunctionCall($arg, $parameters);
            } elseif ($arg instanceof Parameter) {
                $parameters[] = $arg->name;
                $argParts[] = ':' . $arg->name;
            } elseif ($arg instanceof PropertyAccess) {
                $argParts[] = $this->resolvePropertyToColumn($arg->alias, $arg->property);
            } elseif ($arg instanceof Literal) {
                if ($arg->type === 'null') {
                    $argParts[] = 'NULL';
                } elseif ($arg->type === 'string') {
                    $argParts[] = "'" . str_replace("'", "''", (string) $arg->value) . "'";
                } else {
                    $argParts[] = (string) $arg->value;
                }
            }
        }

        return $upperName . '(' . implode(', ', $argParts) . ')';
    }

    private function resolveFrom(FromClause $from): string
    {
        $entityClass = $this->resolveEntityName($from->entityName);
        $metadata = $this->metadataReader->getClassMetadata($entityClass);

        $this->aliasToEntity[$from->alias] = $entityClass;
        $this->aliasToTable[$from->alias] = $from->alias;

        return $this->dialect->quoteIdentifier($metadata->tableName)
            . ' ' . $this->dialect->quoteIdentifier($from->alias);
    }

    private function resolveJoin(JoinClause $join, array &$parameters): string
    {
        // Entity-based JOIN with WITH condition
        if ($join->entityName !== null) {
            return $this->resolveEntityJoin($join, $parameters);
        }

        $ownerAlias = $join->property->alias;
        $relationProperty = $join->property->property;

        $ownerEntity = $this->aliasToEntity[$ownerAlias]
            ?? throw new \RuntimeException(sprintf('Unknown alias "%s" in JOIN.', $ownerAlias));

        $ownerMeta = $this->metadataReader->getClassMetadata($ownerEntity);
        $relation = $ownerMeta->getRelationship($relationProperty);

        if ($relation === null) {
            throw new \RuntimeException(sprintf(
                'No relationship "%s" found on entity "%s".',
                $relationProperty,
                $ownerEntity,
            ));
        }

        $targetEntity = $relation->targetEntity;
        $targetMeta = $this->metadataReader->getClassMetadata($targetEntity);

        $this->aliasToEntity[$join->alias] = $targetEntity;
        $this->aliasToTable[$join->alias] = $join->alias;

        $joinColumns = $relation->joinColumns;
        if (empty($joinColumns)) {
            $joinColumns = [$relationProperty . '_id' => 'id'];
        }

        $ownerIdCol = $ownerMeta->getIdColumn();
        $targetIdCol = $targetMeta->getIdColumn();

        // Determine ON condition based on relationship type
        $onConditions = [];
        if ($relation->type === 'ManyToOne' || $relation->type === 'OneToOne') {
            foreach ($joinColumns as $jc => $refCol) {
                $onConditions[] = sprintf(
                    '%s.%s = %s.%s',
                    $this->dialect->quoteIdentifier($ownerAlias),
                    $this->dialect->quoteIdentifier($jc),
                    $this->dialect->quoteIdentifier($join->alias),
                    $this->dialect->quoteIdentifier($refCol),
                );
            }
        } else {
            // OneToMany / ManyToMany: target has FK to owner
           foreach ($joinColumns as $jc => $refCol) {
                $onConditions[] = sprintf(
                    '%s.%s = %s.%s',
                    $this->dialect->quoteIdentifier($ownerAlias),
                    $this->dialect->quoteIdentifier($refCol),
                    $this->dialect->quoteIdentifier($join->alias),
                    $this->dialect->quoteIdentifier($jc),
                );
            }
        }

        $onCondition = implode(' AND ', $onConditions);
        
        return sprintf(
            '%s %s %s ON %s',
            $join->joinType,
            $this->dialect->quoteIdentifier($targetMeta->tableName),
            $this->dialect->quoteIdentifier($join->alias),
            $onCondition,
        );
    }

    /**
     * @param SelectExpression[] $expressions
     */
    private function resolveSelect(array $expressions): string
    {
        $parts = [];

        foreach ($expressions as $expr) {
            $partSql = '';

            if ($expr->expression instanceof FunctionCall) {
                $partSql = $this->resolveFunctionCall($expr->expression);
            } elseif ($expr->expression === '*') {
                $partSql = '*';
            } elseif (str_contains($expr->expression, '.')) {
                // Property access: alias.property
                $dotParts = explode('.', $expr->expression);
                $partSql = $this->resolvePropertyToColumn($dotParts[0], $dotParts[1]);
            } elseif (isset($this->aliasToEntity[$expr->expression])) {
                // Alias only: select all columns for that alias
                $partSql = $this->dialect->quoteIdentifier($expr->expression) . '.*';
            } else {
                $partSql = $expr->expression;
            }

            if ($expr->alias !== null) {
                $partSql .= ' AS ' . $this->dialect->quoteIdentifier($expr->alias);
            }

            $parts[] = $partSql;
        }

        return implode(', ', $parts);
    }

    private function resolveCondition(Comparison|LogicalExpression|IsNullExpression|InExpression $condition, array &$parameters): string
    {
        if ($condition instanceof IsNullExpression) {
            return $this->resolveIsNull($condition);
        }

        if ($condition instanceof InExpression) {
            return $this->resolveInExpression($condition, $parameters);
        }

        if ($condition instanceof Comparison) {
            return $this->resolveComparison($condition, $parameters);
        }

        // LogicalExpression: wrap in parentheses to preserve operator precedence
        $left = $this->resolveCondition($condition->left, $parameters);
        $right = $this->resolveCondition($condition->right, $parameters);

        return '(' . $left . ' ' . $condition->operator . ' ' . $right . ')';
    }

    private function resolveComparison(Comparison $comparison, array &$parameters): string
    {
        $left = $this->resolveOperand($comparison->left, $parameters);
        $right = $this->resolveOperand($comparison->right, $parameters);

        return $left . ' ' . $comparison->operator . ' ' . $right;
    }

    private function resolveOperand(PropertyAccess|Literal|Parameter|FunctionCall|CustomFunctionCall $operand, array &$parameters): string
    {
        if ($operand instanceof PropertyAccess) {
            return $this->resolvePropertyToColumn($operand->alias, $operand->property);
        }

        if ($operand instanceof Parameter) {
            $parameters[] = $operand->name;
            return ':' . $operand->name;
        }

        if ($operand instanceof Literal) {
            if ($operand->type === 'string') {
                return "'" . str_replace("'", "''", (string) $operand->value) . "'";
            }
            return (string) $operand->value;
        }

        if ($operand instanceof FunctionCall) {
            return $this->resolveFunctionCall($operand);
        }

        if ($operand instanceof CustomFunctionCall) {
            return $this->resolveCustomFunctionCall($operand, $parameters);
        }

        return '';
    }

    private function resolveOrderBy(OrderByClause $orderBy): string
    {
        return implode(', ', array_map(
            fn(OrderByItem $item) => $this->resolvePropertyToColumn(
                $item->property->alias,
                $item->property->property,
            ) . ' ' . $item->direction,
            $orderBy->items,
        ));
    }

    private function resolveGroupBy(GroupByClause $groupBy): string
    {
        return implode(', ', array_map(
            fn(PropertyAccess $p) => $this->resolvePropertyToColumn($p->alias, $p->property),
            $groupBy->properties,
        ));
    }

    private function resolveIsNull(IsNullExpression $expr): string
    {
        $column = $this->resolvePropertyToColumn($expr->property->alias, $expr->property->property);

        return $expr->negated
            ? $column . ' IS NOT NULL'
            : $column . ' IS NULL';
    }

    private function resolveInExpression(InExpression $expr, array &$parameters): string
    {
        $column = $this->resolvePropertyToColumn($expr->property->alias, $expr->property->property);

        $valueParts = [];
        foreach ($expr->values as $value) {
            if ($value instanceof Parameter) {
                $parameters[] = $value->name;
                $valueParts[] = ':' . $value->name;
            } elseif ($value instanceof Literal) {
                if ($value->type === 'string') {
                    $valueParts[] = "'" . str_replace("'", "''", (string) $value->value) . "'";
                } else {
                    $valueParts[] = (string) $value->value;
                }
            }
        }

        $keyword = $expr->negated ? 'NOT IN' : 'IN';

        return $column . ' ' . $keyword . ' (' . implode(', ', $valueParts) . ')';
    }

    private function resolveFunctionCall(FunctionCall $func): string
    {
        if ($func->argument === '*') {
            return $func->functionName . '(*)';
        }

        $distinctKeyword = $func->distinct ? 'DISTINCT ' : '';

        if ($func->argument instanceof PropertyAccess) {
            $column = $this->resolvePropertyToColumn($func->argument->alias, $func->argument->property);
            return $func->functionName . '(' . $distinctKeyword . $column . ')';
        }

        return $func->functionName . '(' . $distinctKeyword . $func->argument . ')';
    }

    private function resolveHaving(HavingClause $having, array &$parameters): string
    {
        return $this->resolveCondition($having->condition, $parameters);
    }

    private function resolveEntityJoin(JoinClause $join, array &$parameters): string
    {
        $entityClass = $this->resolveEntityName($join->entityName);
        $targetMeta = $this->metadataReader->getClassMetadata($entityClass);

        $this->aliasToEntity[$join->alias] = $entityClass;
        $this->aliasToTable[$join->alias] = $join->alias;

        $onCondition = '';
        if ($join->withCondition !== null) {
            $onCondition = $this->resolveCondition($join->withCondition, $parameters);
        }

        return sprintf(
            '%s %s %s ON %s',
            $join->joinType,
            $this->dialect->quoteIdentifier($targetMeta->tableName),
            $this->dialect->quoteIdentifier($join->alias),
            $onCondition,
        );
    }

    private function resolvePropertyToColumn(string $alias, string $property): string
    {
        $entityClass = $this->aliasToEntity[$alias] ?? null;

        if ($entityClass === null) {
            if ($this->stripAlias) {
                return $this->dialect->quoteIdentifier($property);
            }

            return $this->dialect->quoteIdentifier($alias) . '.' . $this->dialect->quoteIdentifier($property);
        }

        $metadata = $this->metadataReader->getClassMetadata($entityClass);
        $column = $metadata->getColumn($property);

        if ($column !== null) {
            $columnName = $column->columnName;
        } else {
            // Check if it's a relationship property (ManyToOne or OneToOne owning side)
            $relationship = $metadata->getRelationship($property);
            if ($relationship !== null && $relationship->joinColumn !== null) {
                $columnName = $relationship->joinColumn;
            } else {
                $columnName = $property;
            }
        }

        if ($this->stripAlias) {
            return $this->dialect->quoteIdentifier($columnName);
        }

        return $this->dialect->quoteIdentifier($alias) . '.' . $this->dialect->quoteIdentifier($columnName);
    }

    private function resolveEntityName(string $shortName): string
    {
        if (isset($this->entityMap[$shortName])) {
            return $this->entityMap[$shortName];
        }

        // If it looks like a FQCN, use directly
        if (str_contains($shortName, '\\')) {
            return $shortName;
        }

        throw new \RuntimeException(sprintf(
            'Cannot resolve entity name "%s". Register it in the entityClasses array.',
            $shortName,
        ));
    }
}
