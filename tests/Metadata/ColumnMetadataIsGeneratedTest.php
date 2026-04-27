<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\ColumnMetadata;

/**
 * Tests for ColumnMetadata::isGenerated().
 */
final class ColumnMetadataIsGeneratedTest extends TestCase
{
    public function testIsGeneratedReturnsTrueWhenIdentity(): void
    {
        $col = new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true, generatedValue: 'IDENTITY');

        $this->assertTrue($col->isGenerated());
    }

    public function testIsGeneratedReturnsFalseWhenNull(): void
    {
        $col = new ColumnMetadata(propertyName: 'name', columnName: 'name', type: 'string');

        $this->assertFalse($col->isGenerated());
    }
}
