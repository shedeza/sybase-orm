<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

/**
 * Safely expands array parameters into individual positional placeholders.
 *
 * Handles edge cases:
 * - Avoids replacing ? inside string literals ('...')
 * - Avoids replacing ? inside SQL comments
 * - Handles empty arrays with impossible condition (1 = 0)
 * - Normalizes non-scalar array values using array_keys()
 *
 * Usage:
 *  $expander = new SqlParameterExpander();
 *  [$sql, $flatParams] = $expander->expand('SELECT * FROM t WHERE id IN (?)', [['a', 'b']]);
 *  // $sql = 'SELECT * FROM t WHERE id IN (?, ?)'
 *  // $flatParams = ['a', 'b']
 */
final class SqlParameterExpander
{
    /**
     * Expands array parameters into individual positional placeholders.
     *
     * @param string $sql SQL with ? placeholders
     * @param array  $params Positional parameters (may contain arrays for IN clauses)
     * @return array{0: string, 1: array} Tuple of [expanded SQL, flat params]
     */
    public function expand(string $sql, array $params): array
    {
        // Quick check: if no arrays in params, return as-is
        $hasArray = false;
        foreach ($params as $value) {
            if (is_array($value)) {
                $hasArray = true;
                break;
            }
        }

        if (!$hasArray) {
            return [$sql, $params];
        }

        $flatParams = [];
        $paramIndex = 0;
        $result = '';
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            // Handle string literals to avoid replacing ? inside them
            if ($char === "'") {
                $result .= "'";
                $i++;
                while ($i < $length && $sql[$i] !== "'") {
                    $result .= $sql[$i];
                    $i++;
                }
                if ($i < $length) {
                    $result .= "'";
                }
                continue;
            }

            // Handle SQL comments (--) to avoid replacing ? inside them
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                $result .= '--';
                $i += 2;
                while ($i < $length && $sql[$i] !== "\n") {
                    $result .= $sql[$i];
                    $i++;
                }
                if ($i < $length) {
                    $result .= "\n";
                    $i++;
                }
                continue;
            }

            // Handle /* */ comments
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $result .= '/*';
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $result .= $sql[$i];
                    $i++;
                }
                if ($i + 1 < $length) {
                    $result .= '*/';
                    $i += 2;
                }
                continue;
            }

            // Replace ? with expanded placeholders or flat param
            if ($char === '?') {
                $value = $params[$paramIndex] ?? null;
                $paramIndex++;

                if (is_array($value)) {
                    $scalarValues = $this->normalizeValues($value);
                    $count = count($scalarValues);

                    if ($count === 0) {
                        // Empty array: use impossible condition (1 = 0) to match nothing
                        $result .= '1 = 0';
                    } else {
                        $result .= implode(', ', array_fill(0, $count, '?'));
                        foreach ($scalarValues as $item) {
                            $flatParams[] = $item;
                        }
                    }
                } else {
                    $result .= '?';
                    $flatParams[] = $value;
                }
                continue;
            }

            $result .= $char;
        }

        return [$result, $flatParams];
    }

    /**
     * Normalizes array values for SQL expansion.
     * If all values are scalar/null, returns them as-is.
     * If values contain arrays/objects, uses array_keys() instead (Doctrine compatibility).
     *
     * @param array<mixed> $value The array to normalize
     * @return list<scalar|null> Flat list of scalar values
     */
    private function normalizeValues(array $value): array
    {
        foreach ($value as $item) {
            if (is_array($item) || is_object($item)) {
                // Values are non-scalar — use keys as the actual values
                /** @var list<scalar|null> */
                return array_values(array_map(
                    static fn(int|string $k): int|string => $k,
                    array_keys($value),
                ));
            }
        }

        /** @var list<scalar|null> */
        return array_values($value);
    }
}
