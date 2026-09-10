<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\SqlParameterExpander;

final class SqlParameterExpanderEscapedQuotesTest extends TestCase
{
    private SqlParameterExpander $expander;

    protected function setUp(): void
    {
        $this->expander = new SqlParameterExpander();
    }

    public function testHandlesDoubledQuotesInsideStringLiterals(): void
    {
        $sql = "SELECT * FROM users WHERE name = 'O''Reilly ?' AND id IN (?)";
        $params = [[1, 2, 3]];

        [$expandedSql, $flatParams] = $this->expander->expand($sql, $params);

        $expectedSql = "SELECT * FROM users WHERE name = 'O''Reilly ?' AND id IN (?, ?, ?)";
        $this->assertSame($expectedSql, $expandedSql);
        $this->assertSame([1, 2, 3], $flatParams);
    }

    public function testHandlesMultipleDoubledQuotesInSameString(): void
    {
        $sql = "SELECT * FROM logs WHERE message = 'It''s a ''test'' ?' AND code IN (?)";
        $params = [['ERR1', 'ERR2']];

        [$expandedSql, $flatParams] = $this->expander->expand($sql, $params);

        $expectedSql = "SELECT * FROM logs WHERE message = 'It''s a ''test'' ?' AND code IN (?, ?)";
        $this->assertSame($expectedSql, $expandedSql);
        $this->assertSame(['ERR1', 'ERR2'], $flatParams);
    }

    public function testHandlesBackslashEscapesInsideLiterals(): void
    {
        $sql = "SELECT * FROM items WHERE path = 'C:\\\\temp\\?test' AND status IN (?)";
        $params = [[10, 20]];

        [$expandedSql, $flatParams] = $this->expander->expand($sql, $params);

        $expectedSql = "SELECT * FROM items WHERE path = 'C:\\\\temp\\?test' AND status IN (?, ?)";
        $this->assertSame($expectedSql, $expandedSql);
        $this->assertSame([10, 20], $flatParams);
    }

    public function testHandlesEmptyArrayParameter(): void
    {
        $sql = "SELECT * FROM items WHERE status = 'active' AND id IN (?)";
        $params = [[]];

        [$expandedSql, $flatParams] = $this->expander->expand($sql, $params);

        $this->assertSame("SELECT * FROM items WHERE status = 'active' AND id IN (1 = 0)", $expandedSql);
        $this->assertSame([], $flatParams);
    }
}
