<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Hydrator;

use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\EmbeddedMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * @covers \SybaseORM\Hydrator\Hydrator
 * @covers \SybaseORM\Hydrator\HydrationPlan
 */
final class HydratorTest extends TestCase
{
    private Hydrator $hydrator;
    private MetadataReaderInterface&MockObject $metadataReader;
    private IdentityMapInterface&MockObject $identityMap;
    private TypeCasterInterface&MockObject $typeCaster;

    protected function setUp(): void
    {
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->identityMap = $this->createMock(IdentityMapInterface::class);
        $this->typeCaster = $this->createMock(TypeCasterInterface::class);

        $this->hydrator = new Hydrator(
            $this->metadataReader,
            $this->typeCaster,
            $this->identityMap
        );
    }

    public function testHydrateSingleEntity(): void
    {
        $column = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: 'string'
        );

        $meta = new ClassMetadata(
            entityClass: DummyHydratorEntity::class,
            tableName: 'users',
            columns: [$column]
        );

        $this->metadataReader->method('getClassMetadata')->willReturn($meta);

        // Pass the raw row through typecaster
        $this->typeCaster->method('toPhpValue')->willReturnArgument(0);

        $row = ['name' => 'John Doe'];

        $result = $this->hydrator->hydrateAll([$row], DummyHydratorEntity::class);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(DummyHydratorEntity::class, $result[0]);
        $this->assertSame('John Doe', $result[0]->name);
    }

    public function testHydrateWithEmbeddedObject(): void
    {
        $streetCol = new ColumnMetadata(
            propertyName: 'address.street',
            columnName: 'street_col',
            type: 'string'
        );
        $cityCol = new ColumnMetadata(
            propertyName: 'address.city',
            columnName: 'city_col',
            type: 'string'
        );

        $meta = new ClassMetadata(
            entityClass: DummyEntityWithEmbedded::class,
            tableName: 'users_with_address',
            columns: [$streetCol, $cityCol],
            embeddeds: [
                new EmbeddedMetadata(
                    propertyName: 'address',
                    class: DummyAddress::class,
                    columnPrefix: 'address_',
                ),
            ]
        );

        $this->metadataReader->method('getClassMetadata')
            ->willReturnCallback(function (string $cls) use ($meta) {
                if ($cls === DummyEntityWithEmbedded::class) {
                    return $meta;
                }
                return new ClassMetadata($cls, 'addresses');
            });

        $this->typeCaster->method('toPhpValue')->willReturnArgument(0);

        $row = [
            'street_col' => 'Main St 123',
            'city_col' => 'Metropolis',
        ];

        /** @var DummyEntityWithEmbedded $result */
        $result = $this->hydrator->hydrate($row, DummyEntityWithEmbedded::class);

        $this->assertInstanceOf(DummyEntityWithEmbedded::class, $result);
        $this->assertInstanceOf(DummyAddress::class, $result->address);
        $this->assertSame('Main St 123', $result->address->street);
        $this->assertSame('Metropolis', $result->address->city);
    }

    public function testHydrateConvertsDateTimeToDateTimeImmutableTarget(): void
    {
        $dateCol = new ColumnMetadata(
            propertyName: 'createdAt',
            columnName: 'created_at',
            type: 'datetime'
        );

        $meta = new ClassMetadata(
            entityClass: DummyEntityWithDate::class,
            tableName: 'items',
            columns: [$dateCol]
        );

        $this->metadataReader->method('getClassMetadata')->willReturn($meta);

        $inputDate = new DateTime('2026-09-10 12:00:00');
        $this->typeCaster->method('toPhpValue')->willReturn($inputDate);

        /** @var DummyEntityWithDate $result */
        $result = $this->hydrator->hydrate(['created_at' => '2026-09-10 12:00:00'], DummyEntityWithDate::class);

        $this->assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
        $this->assertSame('2026-09-10 12:00:00', $result->createdAt->format('Y-m-d H:i:s'));
    }

    public function testSinglePassCharsetConversionWithConnectionManager(): void
    {
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('convertResultRow')
            ->with(['name' => "Caf\xE9"])
            ->willReturn(['name' => 'Café']);

        $this->hydrator->setConnectionManager($connectionManager);

        $column = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: 'string'
        );

        $meta = new ClassMetadata(
            entityClass: DummyHydratorEntity::class,
            tableName: 'users',
            columns: [$column]
        );

        $this->metadataReader->method('getClassMetadata')->willReturn($meta);
        $this->typeCaster->method('toPhpValue')->willReturnArgument(0);

        /** @var DummyHydratorEntity $result */
        $result = $this->hydrator->hydrate(['name' => "Caf\xE9"], DummyHydratorEntity::class);

        $this->assertSame('Café', $result->name);
    }

    public function testHydrationPlanIsCached(): void
    {
        $column = new ColumnMetadata(
            propertyName: 'name',
            columnName: 'name',
            type: 'string'
        );

        $meta = new ClassMetadata(
            entityClass: DummyHydratorEntity::class,
            tableName: 'users',
            columns: [$column]
        );

        // getClassMetadata should be called only once when plan is created and cached
        $this->metadataReader->expects($this->once())
            ->method('getClassMetadata')
            ->willReturn($meta);

        $this->typeCaster->method('toPhpValue')->willReturnArgument(0);

        $rows = [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Charlie'],
        ];

        $results = $this->hydrator->hydrateAll($rows, DummyHydratorEntity::class);

        $this->assertCount(3, $results);
        $this->assertSame('Alice', $results[0]->name);
        $this->assertSame('Bob', $results[1]->name);
        $this->assertSame('Charlie', $results[2]->name);
    }
}

class DummyHydratorEntity
{
    public string $name;
}

class DummyAddress
{
    public string $street;
    public string $city;
}

class DummyEntityWithEmbedded
{
    public ?DummyAddress $address = null;
}

class DummyEntityWithDate
{
    public DateTimeImmutable $createdAt;
}
