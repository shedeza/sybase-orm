<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Embeddable;
use SybaseORM\Attribute\Embedded;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Metadata\MetadataReader;

/**
 * Integration tests for Embeddable/Embedded metadata reading.
 */
final class EmbeddedIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
    }

    public function testEmbeddedColumnsAreExpandedInMetadata(): void
    {
        $reader = new MetadataReader();
        $metadata = $reader->getClassMetadata(EmbeddedTestUser::class);

        // Should have id + name + address_street + address_city = 4 columns
        $this->assertCount(4, $metadata->columns);

        // Check expanded column names
        $columnNames = $metadata->getColumnNames();
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('address_street', $columnNames);
        $this->assertContains('address_city', $columnNames);
    }

    public function testEmbeddedMetadataIsStored(): void
    {
        $reader = new MetadataReader();
        $metadata = $reader->getClassMetadata(EmbeddedTestUser::class);

        $this->assertCount(1, $metadata->embeddeds);
        $this->assertSame('address', $metadata->embeddeds[0]->propertyName);
        $this->assertSame(EmbeddedTestAddress::class, $metadata->embeddeds[0]->class);
        $this->assertSame('address_', $metadata->embeddeds[0]->columnPrefix);
    }

    public function testEmbeddedColumnPropertyNamesUseDotNotation(): void
    {
        $reader = new MetadataReader();
        $metadata = $reader->getClassMetadata(EmbeddedTestUser::class);

        $propertyNames = $metadata->getColumnPropertyNames();
        $this->assertContains('address.street', $propertyNames);
        $this->assertContains('address.city', $propertyNames);
    }

    public function testCustomColumnPrefix(): void
    {
        $reader = new MetadataReader();
        $metadata = $reader->getClassMetadata(EmbeddedTestOrder::class);

        $columnNames = $metadata->getColumnNames();
        $this->assertContains('billing_street', $columnNames);
        $this->assertContains('billing_city', $columnNames);
    }

    public function testEmbeddedWithoutEmbeddableAttributeThrows(): void
    {
        $reader = new MetadataReader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('#[Embeddable]');

        $reader->getClassMetadata(EmbeddedTestInvalid::class);
    }
}

#[Embeddable]
class EmbeddedTestAddress
{
    #[Column(type: 'string', length: 200)]
    public string $street = '';

    #[Column(type: 'string', length: 100)]
    public string $city = '';
}

#[Entity(table: 'embedded_users')]
class EmbeddedTestUser
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public string $name = '';

    #[Embedded(class: EmbeddedTestAddress::class)]
    public ?EmbeddedTestAddress $address = null;
}

#[Entity(table: 'embedded_orders')]
class EmbeddedTestOrder
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Embedded(class: EmbeddedTestAddress::class, columnPrefix: 'billing_')]
    public ?EmbeddedTestAddress $billingAddress = null;
}

// This class is NOT annotated with #[Embeddable]
class NotAnEmbeddable
{
    public string $value = '';
}

#[Entity(table: 'embedded_invalid')]
class EmbeddedTestInvalid
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Embedded(class: NotAnEmbeddable::class)]
    public ?NotAnEmbeddable $bad = null;
}
