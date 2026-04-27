<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\EntityDiscovery;
use SybaseORM\Metadata\MetadataReaderInterface;

/**
 * Tests for EntityDiscovery.
 */
final class EntityDiscoveryTest extends TestCase
{
    public function testDiscoverEntityClassesReturnsEmptyForNonExistentDirectory(): void
    {
        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $discovery = new EntityDiscovery($metadataReader);

        $result = $discovery->discoverEntityClasses(['/nonexistent/path']);

        $this->assertSame([], $result);
    }

    public function testExtractClassNameReturnsNullForNonExistentFile(): void
    {
        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $discovery = new EntityDiscovery($metadataReader);

        $result = $discovery->extractClassName('/nonexistent/file.php');

        $this->assertNull($result);
    }

    public function testExtractClassNameReturnsNullForFileWithoutClass(): void
    {
        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $discovery = new EntityDiscovery($metadataReader);

        // phpunit.xml is a valid file but has no PHP class
        $result = $discovery->extractClassName(__DIR__ . '/../../phpunit.xml');

        $this->assertNull($result);
    }
}
