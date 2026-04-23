<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Connection;

use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for ConnectionManager charset conversion.
 *
 * **Property 7: Charset Conversion Round-Trip**
 * **Validates: Requirements 12.1, 12.2**
 *
 * For any string containing only characters representable in ISO-8859-1,
 * converting from UTF-8 to ISO-8859-1 (outbound) and then from ISO-8859-1
 * back to UTF-8 (inbound) SHALL produce a string identical to the original.
 */
final class ConnectionManagerPropertyTest extends TestCase
{
    /**
     * @dataProvider iso88591RoundTripProvider
     */
    public function testCharsetConversionRoundTrip(string $utf8String): void
    {
        $manager = new TestableConnectionManager([
            'dbname' => 'testdb',
            'charset_conversion' => true,
        ]);

        // Outbound: UTF-8 → ISO-8859-1
        $iso8859 = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8String);
        $this->assertNotFalse($iso8859, 'iconv UTF-8 → ISO-8859-1 should not fail for ISO-8859-1 representable strings');

        // Inbound: ISO-8859-1 → UTF-8 via convertResultRow
        $row = ['value' => $iso8859];
        $converted = $manager->convertResultRow($row);

        $this->assertSame(
            $utf8String,
            $converted['value'],
            sprintf(
                'Round-trip failed for string: %s (hex: %s)',
                $utf8String,
                bin2hex($utf8String),
            ),
        );
    }

    /**
     * Generates 120 random strings from the ISO-8859-1 character range (0x20–0xFF).
     *
     * @return iterable<string, array{string}>
     */
    public static function iso88591RoundTripProvider(): iterable
    {
        // Fixed seed for reproducibility
        mt_srand(42);

        for ($i = 0; $i < 120; $i++) {
            $length = mt_rand(1, 50);
            $bytes = '';

            for ($j = 0; $j < $length; $j++) {
                // Generate a random byte in the printable ISO-8859-1 range (0x20–0xFF)
                $byte = mt_rand(0x20, 0xFF);
                $bytes .= chr($byte);
            }

            // The bytes represent an ISO-8859-1 string; convert to UTF-8 for the input
            $utf8 = iconv('ISO-8859-1', 'UTF-8', $bytes);
            if ($utf8 === false) {
                continue;
            }

            yield sprintf('random string #%d (len=%d)', $i, $length) => [$utf8];
        }
    }

    /**
     * @dataProvider specificIso88591CharactersProvider
     */
    public function testCharsetConversionRoundTripSpecificCharacters(string $utf8String, string $description): void
    {
        $manager = new TestableConnectionManager([
            'dbname' => 'testdb',
            'charset_conversion' => true,
        ]);

        // Outbound: UTF-8 → ISO-8859-1
        $iso8859 = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8String);
        $this->assertNotFalse($iso8859);

        // Inbound: ISO-8859-1 → UTF-8
        $row = ['value' => $iso8859];
        $converted = $manager->convertResultRow($row);

        $this->assertSame($utf8String, $converted['value'], "Round-trip failed for: $description");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function specificIso88591CharactersProvider(): iterable
    {
        yield 'ASCII only' => ['Hello World', 'plain ASCII'];
        yield 'Spanish accents' => ['café señor niño', 'Spanish accented characters'];
        yield 'French accents' => ['résumé naïve', 'French accented characters'];
        yield 'German umlauts' => ['über Straße Ärger', 'German umlauts and eszett'];
        yield 'copyright and registered' => ['© ®', 'copyright and registered symbols in ISO-8859-1'];
        yield 'currency symbols' => ['£ ¥ ¢', 'currency symbols in ISO-8859-1'];
        yield 'math symbols' => ['± × ÷', 'math symbols in ISO-8859-1'];
        yield 'all high bytes' => [
            iconv('ISO-8859-1', 'UTF-8', implode('', array_map('chr', range(0xA0, 0xFF)))),
            'all ISO-8859-1 high bytes (0xA0-0xFF)',
        ];
        yield 'empty-like single space' => [' ', 'single space character'];
        yield 'mixed ASCII and accented' => ['abc123éàü', 'mixed ASCII and accented'];
    }
}
