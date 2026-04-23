<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\InheritanceHandler;
use SybaseORM\Tests\Attribute\Fixtures\AnimalEntity;
use SybaseORM\Tests\Attribute\Fixtures\CatEntity;
use SybaseORM\Tests\Attribute\Fixtures\DogEntity;
use SybaseORM\Tests\ORM\Fixtures\CarEntity;
use SybaseORM\Tests\ORM\Fixtures\CircleEntity;
use SybaseORM\Tests\ORM\Fixtures\RectangleEntity;
use SybaseORM\Tests\ORM\Fixtures\ShapeEntity;
use SybaseORM\Tests\ORM\Fixtures\TruckEntity;
use SybaseORM\Tests\ORM\Fixtures\VehicleEntity;

/**
 * @covers \SybaseORM\ORM\InheritanceHandler
 */
final class InheritanceHandlerTest extends TestCase
{
    private MetadataReader $metadataReader;
    private InheritanceHandler $handler;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();
        $this->metadataReader = new MetadataReader();
        $this->handler = new InheritanceHandler($this->metadataReader);
    }

    // ── TPH: Resolve correct subclass from discriminator (Req 9.1, 9.4) ──

    public function testTPHResolveDogClassFromDiscriminator(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $row = ['id' => 1, 'name' => 'Rex', 'animal_type' => 'dog', 'breed' => 'Labrador'];
        $resolvedClass = $this->handler->resolveTPHClass($row, $metadata);

        self::assertSame(DogEntity::class, $resolvedClass);
    }

    public function testTPHResolveCatClassFromDiscriminator(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $row = ['id' => 2, 'name' => 'Whiskers', 'animal_type' => 'cat', 'color' => 'black'];
        $resolvedClass = $this->handler->resolveTPHClass($row, $metadata);

        self::assertSame(CatEntity::class, $resolvedClass);
    }

    public function testTPHFallsBackToBaseClassForUnknownDiscriminator(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $row = ['id' => 3, 'name' => 'Unknown', 'animal_type' => 'bird'];
        $resolvedClass = $this->handler->resolveTPHClass($row, $metadata);

        self::assertSame(AnimalEntity::class, $resolvedClass);
    }

    public function testTPHFallsBackToBaseClassWhenDiscriminatorIsNull(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $row = ['id' => 4, 'name' => 'NoType', 'animal_type' => null];
        $resolvedClass = $this->handler->resolveTPHClass($row, $metadata);

        self::assertSame(AnimalEntity::class, $resolvedClass);
    }

    public function testTPHFallsBackToBaseClassWhenDiscriminatorColumnMissing(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $row = ['id' => 5, 'name' => 'NoColumn'];
        $resolvedClass = $this->handler->resolveTPHClass($row, $metadata);

        self::assertSame(AnimalEntity::class, $resolvedClass);
    }

    // ── TPH: Discriminator value lookup ──

    public function testTPHGetDiscriminatorValueForDog(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $value = $this->handler->getTPHDiscriminatorValue(DogEntity::class, $metadata);

        self::assertSame('dog', $value);
    }

    public function testTPHGetDiscriminatorValueForCat(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $value = $this->handler->getTPHDiscriminatorValue(CatEntity::class, $metadata);

        self::assertSame('cat', $value);
    }

    public function testTPHGetDiscriminatorValueReturnsNullForUnmappedClass(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $value = $this->handler->getTPHDiscriminatorValue(AnimalEntity::class, $metadata);

        self::assertNull($value);
    }

    // ── TPH: Insert data includes discriminator (Req 9.1) ──

    public function testTPHBuildInsertDataIncludesDiscriminator(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $columnData = ['name' => 'Rex', 'breed' => 'Labrador'];
        $result = $this->handler->buildTPHInsertData($columnData, DogEntity::class, $metadata);

        self::assertSame('dog', $result['animal_type']);
        self::assertSame('Rex', $result['name']);
        self::assertSame('Labrador', $result['breed']);
    }

    // ── TPT: Generate JOINs for base class query (Req 9.2, 9.5) ──

    public function testTPTBuildJoinsForBaseClass(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(VehicleEntity::class);

        $joins = $this->handler->buildTPTJoins($metadata);

        self::assertCount(2, $joins);

        // Verify car join
        $carJoin = $joins[0];
        self::assertSame('cars', $carJoin['table']);
        self::assertSame('cars', $carJoin['alias']);
        self::assertStringContainsString('vehicles.id', $carJoin['condition']);
        self::assertStringContainsString('cars.id', $carJoin['condition']);

        // Verify truck join
        $truckJoin = $joins[1];
        self::assertSame('trucks', $truckJoin['table']);
        self::assertSame('trucks', $truckJoin['alias']);
        self::assertStringContainsString('vehicles.id', $truckJoin['condition']);
        self::assertStringContainsString('trucks.id', $truckJoin['condition']);
    }

    public function testTPTBuildJoinsReturnsEmptyForNonTPT(): void
    {
        $metadata = $this->metadataReader->getClassMetadata(AnimalEntity::class);

        $joins = $this->handler->buildTPTJoins($metadata);

        self::assertSame([], $joins);
    }

    // ── TPT: Split insert data across base and derived tables (Req 9.2) ──

    public function testTPTSplitInsertDataSeparatesBaseAndDerived(): void
    {
        $baseMetadata = $this->metadataReader->getClassMetadata(VehicleEntity::class);

        $columnData = [
            'id' => 1,
            'manufacturer' => 'Toyota',
            'doors' => 4,
        ];

        $result = $this->handler->splitTPTInsertData($columnData, CarEntity::class, $baseMetadata);

        self::assertSame(['id' => 1, 'manufacturer' => 'Toyota'], $result['base']);
        self::assertSame(['doors' => 4], $result['derived']);
    }

    // ── TPC: Independent table per concrete class (Req 9.3) ──

    public function testTPCGetColumnsIncludesInheritedColumns(): void
    {
        $columns = $this->handler->getTPCColumns(CircleEntity::class);

        // Should include inherited columns (id, color) and own columns (radius)
        self::assertArrayHasKey('id', $columns);
        self::assertArrayHasKey('color', $columns);
        self::assertArrayHasKey('radius', $columns);
        self::assertSame('id', $columns['id']);
        self::assertSame('color', $columns['color']);
        self::assertSame('radius', $columns['radius']);
    }

    public function testTPCGetColumnsForRectangleIncludesAllColumns(): void
    {
        $columns = $this->handler->getTPCColumns(RectangleEntity::class);

        self::assertArrayHasKey('id', $columns);
        self::assertArrayHasKey('color', $columns);
        self::assertArrayHasKey('width', $columns);
        self::assertArrayHasKey('height', $columns);
    }

    public function testTPCEachConcreteClassHasOwnTable(): void
    {
        $circleTable = $this->handler->getTPCTableName(CircleEntity::class);
        $rectangleTable = $this->handler->getTPCTableName(RectangleEntity::class);

        self::assertSame('circles', $circleTable);
        self::assertSame('rectangles', $rectangleTable);
        self::assertNotSame($circleTable, $rectangleTable);
    }

    // ── Strategy detection ──

    public function testGetInheritanceStrategyForTPH(): void
    {
        $strategy = $this->handler->getInheritanceStrategy(AnimalEntity::class);

        self::assertSame('TPH', $strategy);
    }

    public function testGetInheritanceStrategyForTPHSubclass(): void
    {
        $strategy = $this->handler->getInheritanceStrategy(DogEntity::class);

        self::assertSame('TPH', $strategy);
    }

    public function testGetInheritanceStrategyForTPT(): void
    {
        $strategy = $this->handler->getInheritanceStrategy(VehicleEntity::class);

        self::assertSame('TPT', $strategy);
    }

    public function testGetInheritanceStrategyForTPC(): void
    {
        $strategy = $this->handler->getInheritanceStrategy(ShapeEntity::class);

        self::assertSame('TPC', $strategy);
    }

    // ── Root metadata resolution ──

    public function testGetRootMetadataFromSubclass(): void
    {
        $rootMetadata = $this->handler->getRootMetadata(DogEntity::class);

        self::assertSame(AnimalEntity::class, $rootMetadata->entityClass);
        self::assertSame('animals', $rootMetadata->tableName);
        self::assertSame('TPH', $rootMetadata->inheritanceType);
    }

    public function testGetRootMetadataFromBaseClass(): void
    {
        $rootMetadata = $this->handler->getRootMetadata(AnimalEntity::class);

        self::assertSame(AnimalEntity::class, $rootMetadata->entityClass);
    }
}
