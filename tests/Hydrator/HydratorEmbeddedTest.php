<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Embeddable;
use SybaseORM\Attribute\Embedded;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Type\TypeCaster;

/**
 * Tests for Hydrator embedded object hydration.
 */
final class HydratorEmbeddedTest extends TestCase
{
    private Hydrator $hydrator;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
        $reader = new MetadataReader();
        $this->hydrator = new Hydrator($reader, new TypeCaster());
    }

    public function testHydrateEntityWithEmbeddedObject(): void
    {
        $row = [
            'id' => 1,
            'name' => 'Alice',
            'address_street' => '123 Main St',
            'address_city' => 'Springfield',
        ];

        $entity = $this->hydrator->hydrate($row, HydratorEmbUser::class);

        $this->assertSame(1, $entity->id);
        $this->assertSame('Alice', $entity->name);
        $this->assertNotNull($entity->address);
        $this->assertInstanceOf(HydratorEmbAddress::class, $entity->address);
        $this->assertSame('123 Main St', $entity->address->street);
        $this->assertSame('Springfield', $entity->address->city);
    }

    public function testHydrateEntityWithNullEmbeddedColumns(): void
    {
        $row = [
            'id' => 2,
            'name' => 'Bob',
            'address_street' => null,
            'address_city' => null,
        ];

        $entity = $this->hydrator->hydrate($row, HydratorEmbUser::class);

        $this->assertSame(2, $entity->id);
        // When all embedded columns are null, the embedded object is not created
        $this->assertNull($entity->address);
    }
}

#[Embeddable]
class HydratorEmbAddress
{
    #[Column(type: 'string')]
    public string $street = '';

    #[Column(type: 'string')]
    public string $city = '';
}

#[Entity(table: 'hydrator_emb_users')]
class HydratorEmbUser
{
    #[Id]
    #[Column(type: 'integer')]
    public ?int $id = null;

    #[Column(type: 'string')]
    public string $name = '';

    #[Embedded(class: HydratorEmbAddress::class)]
    public ?HydratorEmbAddress $address = null;
}
