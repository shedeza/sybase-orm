<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionUrlParser;

/**
 * Tests for charset_conversion query parameter support in ConnectionUrlParser.
 */
final class ConnectionUrlParserCharsetConversionTest extends TestCase
{
    public function testParseUrlWithCharsetConversionTrue(): void
    {
        $result = ConnectionUrlParser::parse('sybase://sa:pass@host:5000/mydb?charset_conversion=true');

        $this->assertTrue($result['charset_conversion']);
    }

    public function testParseUrlWithCharsetConversionFalse(): void
    {
        $result = ConnectionUrlParser::parse('sybase://sa:pass@host:5000/mydb?charset_conversion=false');

        $this->assertFalse($result['charset_conversion']);
    }

    public function testParseUrlWithoutCharsetConversionDefaultsFalse(): void
    {
        $result = ConnectionUrlParser::parse('sybase://sa:pass@host:5000/mydb');

        $this->assertFalse($result['charset_conversion']);
    }

    public function testParseUrlWithCharsetConversionAndOtherParams(): void
    {
        $result = ConnectionUrlParser::parse('sybase://sa:pass@host:5000/mydb?charset=UTF-8&charset_conversion=true&persistent=true');

        $this->assertTrue($result['charset_conversion']);
        $this->assertSame('UTF-8', $result['charset']);
        $this->assertTrue($result['persistent']);
    }
}
