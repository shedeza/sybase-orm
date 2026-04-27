<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ColumnMetadata;

/**
 * Tests for ColumnMetadata::__toString().
 */
final class ColumnMetadataToStringTest extends TestCase
{
    public function testBasicColumn(): void
    {
        $col = new ColumnMetadata(propertyName: 'name', columnName: 'name', type: 'string');

        $this->assertSame('name string', (string) $col);
    }

    public function testIdColumn(): void
    {
        $col = new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true);

        $this->assertSame('id integer PK', (string) $col);
    }

    public function testNullableColumn(): void
    {
        $col = new ColumnMetadata(propertyName: 'email', columnName: 'email', type: 'string', nullable: true);

        $this->assertSame('email string nullable', (string) $col);
    }

    public function testGeneratedValueColumn(): void
    {
        $col = new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true, generatedValue: 'IDENTITY');

        $this->assertSame('id integer PK IDENTITY', (string) $col);
    }
}
