<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Dialect;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;

/**
 * Tests for SybaseDialect::generateTruncate().
 */
final class SybaseDialectTruncateTest extends TestCase
{
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new SybaseDialect();
    }

    public function testGenerateTruncateSimpleTable(): void
    {
        $sql = $this->dialect->generateTruncate('users');

        $this->assertSame('TRUNCATE TABLE [users]', $sql);
    }

    public function testGenerateTruncateSchemaQualifiedTable(): void
    {
        $sql = $this->dialect->generateTruncate('dbo.users');

        $this->assertSame('TRUNCATE TABLE [dbo].[users]', $sql);
    }
}
