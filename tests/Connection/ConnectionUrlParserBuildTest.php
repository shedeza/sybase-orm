<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionUrlParser;

/**
 * Tests for ConnectionUrlParser::build().
 */
final class ConnectionUrlParserBuildTest extends TestCase
{
    public function testBuildBasicUrl(): void
    {
        $url = ConnectionUrlParser::build([
            'host' => '192.168.1.100',
            'port' => 5000,
            'dbname' => 'mydb',
            'username' => 'sa',
            'password' => 'secret',
        ]);

        $this->assertSame('sybase://sa:secret@192.168.1.100:5000/mydb', $url);
    }

    public function testBuildWithSpecialCharsInPassword(): void
    {
        $url = ConnectionUrlParser::build([
            'host' => 'localhost',
            'port' => 5000,
            'dbname' => 'testdb',
            'username' => 'sa',
            'password' => 'p@ss w0rd',
        ]);

        $this->assertStringContainsString('p%40ss+w0rd', $url);
    }

    public function testBuildWithPersistent(): void
    {
        $url = ConnectionUrlParser::build([
            'host' => 'localhost',
            'port' => 5000,
            'dbname' => 'mydb',
            'username' => 'sa',
            'password' => '',
            'persistent' => true,
        ]);

        $this->assertStringContainsString('persistent=true', $url);
    }

    public function testBuildWithNonDefaultCharset(): void
    {
        $url = ConnectionUrlParser::build([
            'host' => 'localhost',
            'port' => 5000,
            'dbname' => 'mydb',
            'username' => 'sa',
            'password' => '',
            'charset' => 'ISO-8859-1',
        ]);

        $this->assertStringContainsString('charset=ISO-8859-1', $url);
    }

    public function testBuildRoundTrip(): void
    {
        $original = [
            'host' => '192.168.1.100',
            'port' => 5000,
            'dbname' => 'production',
            'username' => 'admin',
            'password' => 'secret123',
        ];

        $url = ConnectionUrlParser::build($original);
        $parsed = ConnectionUrlParser::parse($url);

        $this->assertSame($original['host'], $parsed['host']);
        $this->assertSame($original['port'], $parsed['port']);
        $this->assertSame($original['dbname'], $parsed['dbname']);
        $this->assertSame($original['username'], $parsed['username']);
        $this->assertSame($original['password'], $parsed['password']);
    }
}
