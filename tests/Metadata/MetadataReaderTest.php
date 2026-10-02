<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Metadata\MetadataReader;

class MetadataReaderTest extends TestCase
{
    public function testReadsDefaultPropertyOnColumnInPhp82(): void
    {
        // This will trigger a Warning on PHP 8.2+ if $default property is not defined
        $reader = new MetadataReader();
        $meta = $reader->getClassMetadata(DummyEntityWithDefault::class);

        $this->assertEquals('active', $meta->getColumn('status')->default);
    }
}

#[Entity(table: 'test_default_table')]
class DummyEntityWithDefault
{
    #[Column(name: 'status', default: 'active')]
    public string $status;
}
