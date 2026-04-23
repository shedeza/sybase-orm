<?php

declare(strict_types=1);

namespace SybaseORM\Tests\ORM;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Dialect\DialectInterface;
use SybaseORM\Hook\HookDispatcher;
use SybaseORM\Hydrator\HydratorInterface;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\ORM\EntityManager;
use SybaseORM\ORM\HydrationMode;
use SybaseORM\ORM\IdentityMapInterface;
use SybaseORM\ORM\UnitOfWorkInterface;
use SybaseORM\Type\TypeCasterInterface;

/**
 * Property-based tests for EntityManager IN parameter expansion.
 *
 * **Validates: Requirements 6.4**
 */
final class EntityManagerPropertyTest extends TestCase
{
    private ConnectionManagerInterface&MockObject $connectionManager;
    private MetadataReaderInterface&MockObject $metadataReader;
    private DialectInterface&MockObject $dialect;
    private TypeCasterInterface&MockObject $typeCaster;
    private HydratorInterface&MockObject $hydrator;
    private UnitOfWorkInterface&MockObject $unitOfWork;
    private IdentityMapInterface&MockObject $identityMap;
    private CacheManagerInterface&MockObject $cacheManager;
    private EntityManager $em;

    protected function setUp(): void
    {
        $this->connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $this->metadataReader = $this->createMock(MetadataReaderInterface::class);
        $this->dialect = $this->createMock(DialectInterface::class);
        $this->typeCaster = $this->createMock(TypeCasterInterface::class);
        $this->hydrator = $this->createMock(HydratorInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->identityMap = $this->createMock(IdentityMapInterface::class);
        $this->cacheManager = $this->createMock(CacheManagerInterface::class);

        $metadata = new ClassMetadata(
            entityClass: Fixtures\CustomerEntity::class,
            tableName: 'customers',
            columns: [
                new ColumnMetadata('id', 'id', 'integer', false, null, null, null, true, 'IDENTITY'),
                new ColumnMetadata('name', 'name', 'string', false, 200),
            ],
            idField: 'id',
            lifecycleHooks: [],
        );

        $this->metadataReader->method('getClassMetadata')->willReturn($metadata);

        $this->dialect->method('quoteIdentifier')
            ->willReturnCallback(fn(string $id) => '[' . $id . ']');

        $hookDispatcher = new HookDispatcher($this->metadataReader);

        $this->em = new EntityManager(
            $this->connectionManager,
            $this->metadataReader,
            $this->dialect,
            $this->typeCaster,
            $this->hydrator,
            $this->unitOfWork,
            $this->identityMap,
            $hookDispatcher,
            $this->cacheManager,
        );

        $this->em->setEntityClasses([Fixtures\CustomerEntity::class]);
    }

    /**
     * **Property 5: IN Parameter Expansion Placeholder Count**
     *
     * **Validates: Requirement 6.4**
     *
     * For any array parameter of N elements (1–50) bound to an IN expression,
     * the EntityManager's parameter expansion SHALL produce exactly N positional
     * placeholders in the SQL output, and the ordered parameter array SHALL
     * contain exactly those N values in order.
     *
     * @dataProvider inParameterExpansionProvider
     */
    public function testInParameterExpansionPlaceholderCount(array $values): void
    {
        $expectedCount = count($values);

        $capturedSql = '';
        $capturedParams = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('closeCursor');

        $this->connectionManager->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->callback(function (string $sql) use (&$capturedSql): bool {
                    $capturedSql = $sql;
                    return true;
                }),
                $this->callback(function (array $params) use (&$capturedParams): bool {
                    $capturedParams = $params;
                    return true;
                }),
            )
            ->willReturn($stmt);

        $this->em->query(
            'SELECT c.id, c.name FROM CustomerEntity c WHERE c.id IN (:ids)',
            ['ids' => $values],
            HydrationMode::HYDRATE_ARRAY,
        );

        // Verify: expanded SQL contains exactly N placeholders in the IN clause
        $this->assertNotEmpty($capturedSql, 'SQL should have been captured');

        // Extract the IN clause content between parentheses
        preg_match('/IN\s*\(([^)]+)\)/', $capturedSql, $matches);
        $this->assertNotEmpty($matches, 'SQL should contain an IN (...) clause');

        $placeholderString = $matches[1];
        $placeholders = array_map('trim', explode(',', $placeholderString));
        $this->assertCount($expectedCount, $placeholders, sprintf(
            'Expected %d placeholders in IN clause, got %d. SQL: %s',
            $expectedCount,
            count($placeholders),
            $capturedSql,
        ));

        // Each placeholder should be '?'
        foreach ($placeholders as $placeholder) {
            $this->assertSame('?', $placeholder, 'Each placeholder should be a positional ?');
        }

        // Verify: ordered parameter array contains exactly N values in order
        $this->assertCount($expectedCount, $capturedParams, sprintf(
            'Expected %d parameters, got %d',
            $expectedCount,
            count($capturedParams),
        ));
        $this->assertSame($values, $capturedParams, 'Parameters should match input values in order');
    }

    /**
     * Generates 100+ random arrays of 1–50 elements for property testing.
     *
     * @return \Generator<string, array{array<int>}>
     */
    public static function inParameterExpansionProvider(): \Generator
    {
        for ($i = 0; $i < 110; $i++) {
            $size = random_int(1, 50);
            $values = [];
            for ($j = 0; $j < $size; $j++) {
                $values[] = random_int(1, 10000);
            }
            yield "array of {$size} elements (iteration {$i})" => [$values];
        }
    }
}
