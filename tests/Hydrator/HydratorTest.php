<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator;

use PHPUnit\Framework\TestCase;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\Tests\Hydrator\Fixtures\CategoryEntity;
use SybaseORM\Tests\Hydrator\Fixtures\ProductEntity;
use SybaseORM\Type\TypeCaster;

/**
 * @covers \SybaseORM\Hydrator\Hydrator
 */
final class HydratorTest extends TestCase
{
    private MetadataReader $metadataReader;
    private TypeCaster $typeCaster;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
        $this->metadataReader = new MetadataReader();
        $this->typeCaster = new TypeCaster();
    }

    // ── 11.1 Basic hydration with types (Req 6.1, 6.2) ─────────

    public function testHydrateCreatesEntityInstanceWithCorrectTypes(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $row = [
            'id' => '42',
            'name' => 'Widget',
            'price' => '19.99',
            'active' => '1',
            'created_at' => '2024-01-15 10:30:00',
        ];

        $entity = $hydrator->hydrate($row, ProductEntity::class);

        self::assertInstanceOf(ProductEntity::class, $entity);
        self::assertSame(42, $entity->getId());
        self::assertSame('Widget', $entity->getName());
        self::assertSame(19.99, $entity->getPrice());
        self::assertTrue($entity->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $entity->getCreatedAt());
        self::assertSame('2024-01-15', $entity->getCreatedAt()->format('Y-m-d'));
    }

    public function testHydrateHandlesNullValues(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $row = [
            'id' => '1',
            'name' => 'Test',
            'price' => '0.0',
            'active' => '0',
            'created_at' => null,
        ];

        $entity = $hydrator->hydrate($row, ProductEntity::class);

        self::assertNull($entity->getCreatedAt());
    }

    // ── 11.1 Unmapped columns ignored (Req 6.5) ────────────────

    public function testHydrateIgnoresUnmappedColumns(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $row = [
            'id' => '1',
            'name' => 'Widget',
            'price' => '9.99',
            'active' => '1',
            'created_at' => null,
            'unknown_column' => 'should be ignored',
            'another_extra' => 12345,
        ];

        $entity = $hydrator->hydrate($row, ProductEntity::class);

        self::assertInstanceOf(ProductEntity::class, $entity);
        self::assertSame(1, $entity->getId());
        self::assertSame('Widget', $entity->getName());
    }

    // ── 11.1 hydrateAll delegates to hydrate (Req 6.1) ─────────

    public function testHydrateAllReturnsArrayOfEntities(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $rows = [
            ['id' => '1', 'name' => 'A', 'price' => '1.0', 'active' => '1', 'created_at' => null],
            ['id' => '2', 'name' => 'B', 'price' => '2.0', 'active' => '0', 'created_at' => null],
            ['id' => '3', 'name' => 'C', 'price' => '3.0', 'active' => '1', 'created_at' => null],
        ];

        $entities = $hydrator->hydrateAll($rows, ProductEntity::class);

        self::assertCount(3, $entities);
        self::assertSame('A', $entities[0]->getName());
        self::assertSame('B', $entities[1]->getName());
        self::assertSame('C', $entities[2]->getName());
    }

    // ── 11.2 Identity Map integration (Req 6.4) ────────────────

    public function testHydrateReturnsExistingEntityFromIdentityMap(): void
    {
        $existingProduct = (new \ReflectionClass(ProductEntity::class))
            ->newInstanceWithoutConstructor();
        $refProp = new \ReflectionProperty(ProductEntity::class, 'id');
        $refProp->setAccessible(true);
        $refProp->setValue($existingProduct, 42);
        $refName = new \ReflectionProperty(ProductEntity::class, 'name');
        $refName->setAccessible(true);
        $refName->setValue($existingProduct, 'Original');

        $identityMap = $this->createMock(IdentityMapInterface::class);
        $identityMap->method('get')
            ->with(ProductEntity::class, 42)
            ->willReturn($existingProduct);

        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster, $identityMap);

        $row = [
            'id' => '42',
            'name' => 'Updated',
            'price' => '5.0',
            'active' => '1',
            'created_at' => null,
        ];

        $entity = $hydrator->hydrate($row, ProductEntity::class);

        self::assertSame($existingProduct, $entity);
        self::assertSame('Original', $entity->getName());
    }

    public function testHydrateStoresNewEntityInIdentityMap(): void
    {
        $identityMap = $this->createMock(IdentityMapInterface::class);
        $identityMap->method('get')->willReturn(null);
        $identityMap->expects(self::once())
            ->method('put')
            ->with(
                ProductEntity::class,
                42,
                self::isInstanceOf(ProductEntity::class),
            );

        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster, $identityMap);

        $row = [
            'id' => '42',
            'name' => 'New',
            'price' => '10.0',
            'active' => '1',
            'created_at' => null,
        ];

        $hydrator->hydrate($row, ProductEntity::class);
    }

    public function testHydrateAllUsesIdentityMapForDuplicateRows(): void
    {
        $identityMap = $this->createMock(IdentityMapInterface::class);

        // First call: not in map → returns null, then stores
        // Second call: in map → returns the entity from first hydration
        $callCount = 0;
        $storedEntity = null;

        $identityMap->method('get')
            ->willReturnCallback(function (string $class, mixed $id) use (&$callCount, &$storedEntity) {
                $callCount++;
                if ($callCount === 1) {
                    return null; // First time: not in map
                }
                return $storedEntity; // Second time: return stored
            });

        $identityMap->method('put')
            ->willReturnCallback(function (string $class, mixed $id, object $entity) use (&$storedEntity) {
                $storedEntity = $entity;
            });

        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster, $identityMap);

        $rows = [
            ['id' => '1', 'name' => 'A', 'price' => '1.0', 'active' => '1', 'created_at' => null],
            ['id' => '1', 'name' => 'A', 'price' => '1.0', 'active' => '1', 'created_at' => null],
        ];

        $entities = $hydrator->hydrateAll($rows, ProductEntity::class);

        self::assertCount(2, $entities);
        self::assertSame($entities[0], $entities[1]);
    }

    // ── 11.2 Eager loading hydration (Req 6.3) ─────────────────

    public function testHydrateEagerLoadedRelationship(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $row = [
            'id' => '5',
            'title' => 'Electronics',
            'parent.id' => '1',
            'parent.title' => 'Root',
        ];

        $entity = $hydrator->hydrate($row, CategoryEntity::class);

        self::assertInstanceOf(CategoryEntity::class, $entity);
        self::assertSame(5, $entity->getId());
        self::assertSame('Electronics', $entity->getTitle());

        $parent = $entity->getParent();
        self::assertInstanceOf(CategoryEntity::class, $parent);
        self::assertSame(1, $parent->getId());
        self::assertSame('Root', $parent->getTitle());
    }

    public function testHydrateEagerLoadedRelationshipAllNullSkipped(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $row = [
            'id' => '5',
            'title' => 'Root',
            'parent.id' => null,
            'parent.title' => null,
        ];

        $entity = $hydrator->hydrate($row, CategoryEntity::class);

        self::assertNull($entity->getParent());
    }

    // ── Without Identity Map (no crash) ─────────────────────────

    public function testHydrateWorksWithoutIdentityMap(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        $row = [
            'id' => '1',
            'name' => 'Test',
            'price' => '5.0',
            'active' => '0',
            'created_at' => null,
        ];

        $entity = $hydrator->hydrate($row, ProductEntity::class);

        self::assertInstanceOf(ProductEntity::class, $entity);
        self::assertSame(1, $entity->getId());
    }

    public function testHydrateMissingColumnsInRowAreSkipped(): void
    {
        $hydrator = new Hydrator($this->metadataReader, $this->typeCaster);

        // Row only has id and name, missing price/active/created_at
        $row = [
            'id' => '7',
            'name' => 'Partial',
        ];

        $entity = $hydrator->hydrate($row, ProductEntity::class);

        self::assertSame(7, $entity->getId());
        self::assertSame('Partial', $entity->getName());
        // price and active keep their default values
        self::assertSame(0.0, $entity->getPrice());
        self::assertFalse($entity->isActive());
    }
}
