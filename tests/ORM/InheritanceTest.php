<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\TestCase;
use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\DiscriminatorColumn;
use SybaseORM\Attribute\DiscriminatorMap;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\InheritanceType;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Hydrator\Hydrator;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\ORM\IdentityMap;
use SybaseORM\ORM\InheritanceHandler;
use SybaseORM\ORM\UnitOfWork;
use SybaseORM\Type\TypeCaster;

#[Entity(table: 'test_notifications')]
#[InheritanceType(strategy: 'TPH')]
#[DiscriminatorColumn(name: 'type')]
#[DiscriminatorMap([
    'email' => TestEmailNotification::class,
    'sms'   => TestSmsNotification::class,
])]
class TestNotification
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public ?int $id = null;

    #[Column(type: 'string')]
    public string $message = '';
}

#[Entity]
class TestEmailNotification extends TestNotification
{
    #[Column(type: 'string', nullable: true)]
    public ?string $email = null;
}

#[Entity]
class TestSmsNotification extends TestNotification
{
    #[Column(type: 'string', nullable: true)]
    public ?string $phoneNumber = null;
}

/**
 * @covers \SybaseORM\ORM\InheritanceHandler
 * @covers \SybaseORM\Hydrator\Hydrator
 * @covers \SybaseORM\ORM\UnitOfWork
 * @covers \SybaseORM\Metadata\MetadataReader
 */
final class InheritanceTest extends TestCase
{
    private MetadataReader $metadataReader;
    private InheritanceHandler $inheritanceHandler;
    private TypeCaster $typeCaster;
    private SybaseDialect $dialect;

    protected function setUp(): void
    {
        $this->metadataReader = new MetadataReader();
        $this->inheritanceHandler = new InheritanceHandler($this->metadataReader);
        $this->typeCaster = new TypeCaster();
        $this->dialect = new SybaseDialect();
    }

    public function testMetadataInheritancePropagatesToChildClass(): void
    {
        $childMeta = $this->metadataReader->getClassMetadata(TestEmailNotification::class);

        // Subclass inherits root table name in TPH
        $this->assertSame('test_notifications', $childMeta->tableName);
        $this->assertSame('TPH', $childMeta->inheritanceType);
        $this->assertSame('type', $childMeta->discriminatorColumn);
        $this->assertSame(TestNotification::class, $childMeta->rootEntityClass);
        $this->assertArrayHasKey('email', $childMeta->discriminatorMap);

        // Subclass inherits properties from parent class
        $this->assertNotNull($childMeta->getColumn('id'));
        $this->assertNotNull($childMeta->getColumn('message'));
        $this->assertNotNull($childMeta->getColumn('email'));
    }

    public function testInheritanceHandlerResolvesSubclass(): void
    {
        $baseMeta = $this->metadataReader->getClassMetadata(TestNotification::class);

        $this->assertSame(
            TestEmailNotification::class,
            $this->inheritanceHandler->resolveTPHClass(['type' => 'email'], $baseMeta)
        );
        $this->assertSame(
            TestSmsNotification::class,
            $this->inheritanceHandler->resolveTPHClass(['type' => 'sms'], $baseMeta)
        );
        $this->assertSame(
            TestNotification::class,
            $this->inheritanceHandler->resolveTPHClass(['type' => 'unknown'], $baseMeta)
        );

        $this->assertSame(
            'email',
            $this->inheritanceHandler->getTPHDiscriminatorValue(TestEmailNotification::class, $baseMeta)
        );
        $this->assertSame(
            'sms',
            $this->inheritanceHandler->getTPHDiscriminatorValue(TestSmsNotification::class, $baseMeta)
        );

        $data = $this->inheritanceHandler->buildTPHInsertData(
            ['message' => 'hello'],
            TestEmailNotification::class,
            $baseMeta
        );
        $this->assertSame('email', $data['type']);
    }

    public function testHydratorPolymorphicHydration(): void
    {
        $hydrator = new Hydrator(
            metadataReader: $this->metadataReader,
            typeCaster: $this->typeCaster,
            inheritanceHandler: $this->inheritanceHandler,
        );

        $rows = [
            [
                'id'           => 1,
                'message'      => 'Welcome Email',
                'type'         => 'email',
                'email'        => 'test@example.com',
                'phone_number' => null,
            ],
            [
                'id'           => 2,
                'message'      => 'Verification Code',
                'type'         => 'sms',
                'email'        => null,
                'phone_number' => '+1234567890',
            ],
        ];

        $entities = $hydrator->hydrateAll($rows, TestNotification::class);

        $this->assertCount(2, $entities);
        $this->assertInstanceOf(TestEmailNotification::class, $entities[0]);
        $this->assertInstanceOf(TestSmsNotification::class, $entities[1]);

        $this->assertSame('Welcome Email', $entities[0]->message);
        $this->assertSame('test@example.com', $entities[0]->email);

        $this->assertSame('Verification Code', $entities[1]->message);
        $this->assertSame('+1234567890', $entities[1]->phoneNumber);
    }

    public function testUnitOfWorkInjectsDiscriminatorOnInsert(): void
    {
        $executedSql = null;
        $executedParams = null;

        $connMock = $this->createMock(ConnectionManagerInterface::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executedSql, &$executedParams) {
                $executedSql = $sql;
                $executedParams = $params;
                return 1;
            });

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('fetch')->willReturn([10]);
        $connMock->method('executeQuery')->willReturn($stmtMock);

        $identityMap = new IdentityMap();
        $uow = new UnitOfWork(
            connectionManager: $connMock,
            metadataReader: $this->metadataReader,
            dialect: $this->dialect,
            typeCaster: $this->typeCaster,
            identityMap: $identityMap,
            inheritanceHandler: $this->inheritanceHandler,
        );

        $emailNotif = new TestEmailNotification();
        $emailNotif->message = 'Order Confirmation';
        $emailNotif->email = 'buyer@shop.com';

        $uow->registerNew($emailNotif);
        $uow->commit();

        $this->assertNotNull($executedSql);
        $this->assertNotNull($executedParams);
        $this->assertIsString($executedSql);
        $this->assertIsArray($executedParams);

        // Verify the SQL contains the discriminator column 'type'
        $this->assertStringContainsString('type', $executedSql);
        // Verify the bound parameters contain 'email' as the discriminator value
        $this->assertContains('email', $executedParams);
        $this->assertContains('Order Confirmation', $executedParams);
        $this->assertContains('buyer@shop.com', $executedParams);

        // Verify identity was populated
        $this->assertSame(10, $emailNotif->id);
    }
}
