<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionUrlParser;

/**
 * Tests for port validation in ConnectionUrlParser.
 */
final class ConnectionUrlParserPortValidationTest extends TestCase
{
    public function testValidPort(): void
    {
        $result = ConnectionUrlParser::parse('sybase://sa:pass@host:5000/mydb');

        $this->assertSame(5000, $result['port']);
    }

    public function testPortZeroThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Puerto inválido');

        ConnectionUrlParser::parse('sybase://sa:pass@host:0/mydb');
    }

    public function testPortAbove65535Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConnectionUrlParser::parse('sybase://sa:pass@host:70000/mydb');
    }

    public function testDefaultPortIsValid(): void
    {
        $result = ConnectionUrlParser::parse('sybase://sa:pass@host/mydb');

        $this->assertSame(5000, $result['port']);
    }
}
