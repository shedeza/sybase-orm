<?php

declare(strict_types=1);

namespace SybaseORM\Console;

/**
 * Simple I/O helper for CLI commands.
 */
final class IO
{
    public static function output(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public static function error(string $message): void
    {
        fwrite(STDERR, '  ERROR: ' . $message . PHP_EOL);
    }

    public static function success(string $message): void
    {
        self::output('  ✓ ' . $message);
    }

    public static function info(string $message): void
    {
        self::output('  ' . $message);
    }

    public static function warning(string $message): void
    {
        self::output('  ⚠ ' . $message);
    }

    public static function table(array $headers, array $rows): void
    {
        // Calculate column widths
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        // Header
        $line = '  ';
        foreach ($headers as $i => $header) {
            $line .= str_pad($header, $widths[$i] + 2);
        }
        self::output($line);

        // Separator
        $sep = '  ';
        foreach ($widths as $w) {
            $sep .= str_repeat('-', $w + 2);
        }
        self::output($sep);

        // Rows
        foreach ($rows as $row) {
            $line = '  ';
            foreach ($row as $i => $cell) {
                $line .= str_pad((string) $cell, ($widths[$i] ?? 0) + 2);
            }
            self::output($line);
        }
    }
}
