<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;

/**
 * Property-based test for ClassMetadata Id Column Accessor Consistency.
 *
 * **Validates: Requirements 1.3, 1.4**
 *
 * For any ClassMetadata constructed with a set of columns where one or more have isId = true,
 * getIdColumns() SHALL return exactly those columns (same count, same property names, all with
 * isId = true), and getIdColumn() SHALL return the first element of getIdColumns().
 */
final class ClassMetadataPropertyTest extends TestCase
{
    /**
     * @dataProvider randomColumnConfigurationsProvider
     *
     * @param ColumnMetadata[] $columns
     * @param string[]         $expectedIdPropertyNames
     */
    public function testIdColumnAccessorConsistency(array $columns, array $expectedIdPropertyNames): void
    {
        $idFields = $expectedIdPropertyNames;

        $meta = new ClassMetadata(
            entityClass: 'App\\Entity\\Generated',
            tableName: 'generated',
            columns: $columns,
            idFields: $idFields,
        );

        $idColumns = $meta->getIdColumns();

        // getIdColumns() count must match the number of isId=true columns
        $this->assertCount(
            count($expectedIdPropertyNames),
            $idColumns,
            'getIdColumns() must return exactly the columns with isId=true',
        );

        // Each returned column must have the correct property name and isId=true
        $returnedPropertyNames = [];
        foreach ($idColumns as $idCol) {
            $this->assertTrue($idCol->isId, sprintf(
                'Column "%s" returned by getIdColumns() must have isId=true',
                $idCol->propertyName,
            ));
            $returnedPropertyNames[] = $idCol->propertyName;
        }

        // Property names must match exactly in order
        $this->assertSame(
            $expectedIdPropertyNames,
            $returnedPropertyNames,
            'getIdColumns() property names must match the expected id property names in order',
        );

        // getIdColumn() must return the first element of getIdColumns(), or null if empty
        $firstIdColumn = $meta->getIdColumn();
        if (count($idColumns) > 0) {
            $this->assertNotNull($firstIdColumn, 'getIdColumn() must not be null when id columns exist');
            $this->assertSame(
                $idColumns[0]->propertyName,
                $firstIdColumn->propertyName,
                'getIdColumn() must return the first element of getIdColumns()',
            );
            $this->assertTrue($firstIdColumn->isId, 'getIdColumn() result must have isId=true');
        } else {
            $this->assertNull($firstIdColumn, 'getIdColumn() must be null when no id columns exist');
        }
    }

    /**
     * Generates 120 random column configurations with 1–5 columns and varying isId counts.
     *
     * @return \Generator<string, array{ColumnMetadata[], string[]}>
     */
    public static function randomColumnConfigurationsProvider(): \Generator
    {
        $types = ['int', 'string', 'decimal', 'datetime', 'bool'];

        for ($i = 0; $i < 120; $i++) {
            $numColumns = mt_rand(1, 5);
            $columns = [];
            $idPropertyNames = [];

            // Decide how many columns will be id columns (at least 0, at most all)
            $numIdColumns = mt_rand(0, $numColumns);

            // Randomly pick which column indices will be id columns
            $allIndices = range(0, $numColumns - 1);
            shuffle($allIndices);
            $idIndices = array_slice($allIndices, 0, $numIdColumns);
            sort($idIndices); // preserve insertion order for id fields

            for ($j = 0; $j < $numColumns; $j++) {
                $propertyName = 'prop' . $j . '_' . mt_rand(100, 999);
                $columnName = 'col_' . $propertyName;
                $isId = in_array($j, $idIndices, true);
                $type = $types[mt_rand(0, count($types) - 1)];

                $columns[] = new ColumnMetadata(
                    propertyName: $propertyName,
                    columnName: $columnName,
                    type: $type,
                    nullable: !$isId && (bool) mt_rand(0, 1),
                    isId: $isId,
                );

                if ($isId) {
                    $idPropertyNames[] = $propertyName;
                }
            }

            yield "iteration_{$i}_cols_{$numColumns}_ids_{$numIdColumns}" => [$columns, $idPropertyNames];
        }
    }
}
