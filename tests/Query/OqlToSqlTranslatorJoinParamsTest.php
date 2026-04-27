<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\ColumnMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlToSqlTranslator;

/**
 * Tests that JOIN WITH parameters are properly propagated to the result.
 */
final class OqlToSqlTranslatorJoinParamsTest extends TestCase
{
    public function testEntityJoinWithParameterIsReported(): void
    {
        $userMeta = new ClassMetadata(
            entityClass: 'App\\Entity\\User',
            tableName: 'users',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'name', columnName: 'name', type: 'string'),
            ],
            idFields: ['id'],
        );

        $addressMeta = new ClassMetadata(
            entityClass: 'App\\Entity\\Address',
            tableName: 'addresses',
            columns: [
                new ColumnMetadata(propertyName: 'id', columnName: 'id', type: 'integer', isId: true),
                new ColumnMetadata(propertyName: 'userId', columnName: 'user_id', type: 'integer'),
                new ColumnMetadata(propertyName: 'city', columnName: 'city', type: 'string'),
            ],
            idFields: ['id'],
        );

        $metadataReader = $this->createMock(MetadataReaderInterface::class);
        $metadataReader->method('getClassMetadata')
            ->willReturnCallback(fn(string $class) => match ($class) {
                'App\\Entity\\User' => $userMeta,
                'App\\Entity\\Address' => $addressMeta,
                default => throw new \RuntimeException("Unknown class: $class"),
            });

        $entityMap = [
            'User' => 'App\\Entity\\User',
            'Address' => 'App\\Entity\\Address',
        ];

        $translator = new OqlToSqlTranslator(
            new SybaseDialect(),
            $metadataReader,
            array_values($entityMap),
            $entityMap,
        );

        $parser = new OqlParser();
        $ast = $parser->parse('SELECT u FROM User u JOIN Address a WITH a.userId = u.id WHERE a.city = :city');

        $result = $translator->translate($ast);

        // The parameter :city from WHERE should be reported
        $this->assertContains('city', $result['parameters']);

        // The SQL should contain the JOIN
        $this->assertStringContainsString('JOIN', $result['sql']);
        $this->assertStringContainsString('[addresses]', $result['sql']);
    }
}
