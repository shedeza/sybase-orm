<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionUrlParser;

final class ConnectionUrlParserTest extends TestCase
{
    public function testParseFullUrl(): void
    {
        $config = ConnectionUrlParser::parse('sybase://admin:secret@192.168.1.100:4100/production?charset=iso_1&persistent=true');

        $this->assertSame('192.168.1.100', $config['host']);
        $this->assertSame(4100, $config['port']);
        $this->assertSame('production', $config['dbname']);
        $this->assertSame('admin', $config['username']);
        $this->assertSame('secret', $config['password']);
        $this->assertSame('iso_1', $config['charset']);
        $this->assertTrue($config['persistent']);
    }

    public function testParseMinimalUrl(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa@localhost/testdb');

        $this->assertSame('localhost', $config['host']);
        $this->assertSame(5000, $config['port']);
        $this->assertSame('testdb', $config['dbname']);
        $this->assertSame('sa', $config['username']);
        $this->assertSame('', $config['password']);
        $this->assertSame('UTF-8', $config['charset']);
        $this->assertFalse($config['persistent']);
    }

    public function testParseUrlWithDefaultPort(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa:pass@myhost/mydb');

        $this->assertSame('myhost', $config['host']);
        $this->assertSame(5000, $config['port']);
        $this->assertSame('mydb', $config['dbname']);
    }

    public function testParseUrlWithEncodedPassword(): void
    {
        // Password "p@ss:w0rd" URL-encoded como "p%40ss%3Aw0rd"
        $config = ConnectionUrlParser::parse('sybase://sa:p%40ss%3Aw0rd@localhost/testdb');

        $this->assertSame('p@ss:w0rd', $config['password']);
    }

    public function testParseUrlWithDblibScheme(): void
    {
        $config = ConnectionUrlParser::parse('dblib://sa:secret@host:5000/mydb');

        $this->assertSame('host', $config['host']);
        $this->assertSame('mydb', $config['dbname']);
        $this->assertSame('sa', $config['username']);
    }

    public function testParseUrlWithOnlyCharsetParam(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa@localhost/mydb?charset=iso_1');

        $this->assertSame('iso_1', $config['charset']);
        $this->assertFalse($config['persistent']);
    }

    public function testParseUrlPersistentFalseByDefault(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa@localhost/mydb');

        $this->assertFalse($config['persistent']);
    }

    public function testParseUrlPersistentTrue(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa@localhost/mydb?persistent=true');

        $this->assertTrue($config['persistent']);
    }

    public function testParseUrlPersistentOne(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa@localhost/mydb?persistent=1');

        $this->assertTrue($config['persistent']);
    }

    public function testThrowsOnMissingDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nombre de la base de datos/');

        ConnectionUrlParser::parse('sybase://sa@localhost');
    }

    public function testThrowsOnMissingDatabaseSlashOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ConnectionUrlParser::parse('sybase://sa@localhost/');
    }

    public function testThrowsOnUnsupportedScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Esquema.*no soportado/');

        ConnectionUrlParser::parse('mysql://sa@localhost/mydb');
    }

    public function testParseUrlWithEmptyPassword(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa:@localhost/mydb');

        $this->assertSame('sa', $config['username']);
        $this->assertSame('', $config['password']);
    }

    public function testParseUrlWithSpecialCharsInDbName(): void
    {
        $config = ConnectionUrlParser::parse('sybase://sa@localhost/my_database_v2');

        $this->assertSame('my_database_v2', $config['dbname']);
    }
}
