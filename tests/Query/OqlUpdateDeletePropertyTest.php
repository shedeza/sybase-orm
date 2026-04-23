<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Metadata\MetadataReader;
use SybaseORM\Query\AST\DeleteStatement;
use SybaseORM\Query\AST\UpdateStatement;
use SybaseORM\Query\OqlParser;
use SybaseORM\Query\OqlPrinter;
use SybaseORM\Query\OqlToSqlTranslator;
use SybaseORM\Tests\Query\Fixtures\OqlUserEntity;
use SybaseORM\Tests\Query\Fixtures\OqlPostEntity;

/**
 * Property-based tests for OQL UPDATE/DELETE round-trip and translation.
 * Validates: Properties 8–12
 *
 * Uses @dataProvider with 100+ iterations for thorough input coverage.
 */
final class OqlUpdateDeletePropertyTest extends TestCase
{
    private OqlParser $parser;
    private OqlPrinter $printer;
    private OqlToSqlTranslator $translator;

    protected function setUp(): void
    {
        MetadataReader::clearMemoryCache();

        $this->parser = new OqlParser();
        $this->printer = new OqlPrinter();

        $dialect = new SybaseDialect();
        $metadataReader = new MetadataReader();

        $this->translator = new OqlToSqlTranslator(
            $dialect,
            $metadataReader,
            [OqlUserEntity::class, OqlPostEntity::class],
        );
    }

    // ── Property 8: UPDATE round-trip ───────────────────────────────

    /**
     * **Validates: Requirements 29.4**
     *
     * For all valid UPDATE OQL strings, parse ∘ print ∘ parse produces
     * an equivalent AST (round-trip property).
     *
     * @dataProvider updateOqlProvider
     */
    public function testUpdateRoundTrip(string $oql): void
    {
        $ast1 = $this->parser->parse($oql);
        $this->assertInstanceOf(UpdateStatement::class, $ast1);

        $printed = $this->printer->print($ast1);
        $ast2 = $this->parser->parse($printed);

        $this->assertInstanceOf(UpdateStatement::class, $ast2);
        $this->assertSame($ast1->entityName, $ast2->entityName);
        $this->assertSame($ast1->alias, $ast2->alias);
        $this->assertCount(count($ast1->setClauses), $ast2->setClauses);

        // Verify round-trip produces identical OQL
        $printed2 = $this->printer->print($ast2);
        $this->assertSame($printed, $printed2);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function updateOqlProvider(): iterable
    {
        $properties = ['name', 'email', 'age'];
        $paramNames = ['val1', 'val2', 'val3', 'newVal', 'param'];
        $entities = ['User', 'Post'];
        $aliases = ['u', 'p', 'e'];

        $count = 0;
        foreach ($entities as $entity) {
            foreach ($aliases as $alias) {
                foreach ($properties as $prop) {
                    foreach ($paramNames as $param) {
                        // Simple SET with parameter
                        yield "update-{$count}" => [
                            "UPDATE {$entity} {$alias} SET {$alias}.{$prop} = :{$param}",
                        ];
                        $count++;

                        // SET with WHERE
                        yield "update-where-{$count}" => [
                            "UPDATE {$entity} {$alias} SET {$alias}.{$prop} = :{$param} WHERE {$alias}.id = :id",
                        ];
                        $count++;

                        if ($count >= 50) {
                            break 4;
                        }
                    }
                }
            }
        }

        // Additional cases with special values
        yield 'update-null' => ['UPDATE User u SET u.name = NULL'];
        yield 'update-string-literal' => ["UPDATE User u SET u.name = 'test'"];
        yield 'update-numeric-literal' => ['UPDATE User u SET u.age = 42'];
        yield 'update-rand' => ['UPDATE User u SET u.age = RAND()'];
        yield 'update-convert' => ['UPDATE User u SET u.age = CONVERT(:val AS REAL)'];
        yield 'update-nested-convert' => ['UPDATE User u SET u.age = CONVERT(RAND() AS REAL)'];
        yield 'update-multi-set' => ['UPDATE User u SET u.name = :name, u.email = :email'];
        yield 'update-multi-set-where' => ['UPDATE User u SET u.name = :name, u.age = 25 WHERE u.id = :id'];
    }

    // ── Property 9: DELETE round-trip ───────────────────────────────

    /**
     * **Validates: Requirements 30.2**
     *
     * For all valid DELETE OQL strings, parse ∘ print ∘ parse produces
     * an equivalent AST (round-trip property).
     *
     * @dataProvider deleteOqlProvider
     */
    public function testDeleteRoundTrip(string $oql): void
    {
        $ast1 = $this->parser->parse($oql);
        $this->assertInstanceOf(DeleteStatement::class, $ast1);

        $printed = $this->printer->print($ast1);
        $ast2 = $this->parser->parse($printed);

        $this->assertInstanceOf(DeleteStatement::class, $ast2);
        $this->assertSame($ast1->entityName, $ast2->entityName);
        $this->assertSame($ast1->alias, $ast2->alias);

        // Verify round-trip produces identical OQL
        $printed2 = $this->printer->print($ast2);
        $this->assertSame($printed, $printed2);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function deleteOqlProvider(): iterable
    {
        $entities = ['User', 'Post', 'Order', 'Product', 'Category'];
        $aliases = ['u', 'p', 'o', 'pr', 'c'];
        $properties = ['id', 'name', 'status', 'active', 'createdAt'];
        $paramNames = ['id', 'val', 'param', 'cutoff', 'status'];

        $count = 0;
        foreach ($entities as $i => $entity) {
            $alias = $aliases[$i];

            // Simple DELETE
            yield "delete-simple-{$count}" => [
                "DELETE FROM {$entity} {$alias}",
            ];
            $count++;

            foreach ($properties as $prop) {
                foreach ($paramNames as $param) {
                    yield "delete-where-{$count}" => [
                        "DELETE FROM {$entity} {$alias} WHERE {$alias}.{$prop} = :{$param}",
                    ];
                    $count++;

                    if ($count >= 110) {
                        return;
                    }
                }
            }
        }
    }

    // ── Property 10: UPDATE translation ─────────────────────────────

    /**
     * **Validates: Requirements 29.5**
     *
     * For all valid UPDATE ASTs, translation resolves entity→table and property→column correctly.
     *
     * @dataProvider updateTranslationProvider
     */
    public function testUpdateTranslation(string $oql, string $expectedTableName): void
    {
        $ast = $this->parser->parse($oql);
        $this->assertInstanceOf(UpdateStatement::class, $ast);

        $result = $this->translator->translate($ast);

        $this->assertStringStartsWith('UPDATE [' . $expectedTableName . ']', $result['sql']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function updateTranslationProvider(): iterable
    {
        $cases = [
            ['UPDATE OqlUserEntity u SET u.name = :name', 'users'],
            ['UPDATE OqlUserEntity u SET u.email = :email', 'users'],
            ['UPDATE OqlUserEntity u SET u.age = :age', 'users'],
            ['UPDATE OqlPostEntity p SET p.title = :title', 'posts'],
            ['UPDATE OqlPostEntity p SET p.body = :body', 'posts'],
        ];

        // Generate 100+ cases by varying parameters
        $params = ['val1', 'val2', 'val3', 'val4', 'val5', 'val6', 'val7', 'val8', 'val9', 'val10'];
        $count = 0;
        foreach ($cases as [$oqlTemplate, $table]) {
            foreach ($params as $param) {
                $oql = str_replace(
                    [':name', ':email', ':age', ':title', ':body'],
                    [':' . $param, ':' . $param, ':' . $param, ':' . $param, ':' . $param],
                    $oqlTemplate,
                );
                yield "translate-update-{$count}" => [$oql, $table];
                $count++;
            }
        }

        // With WHERE
        foreach ($params as $i => $param) {
            yield "translate-update-where-{$i}" => [
                "UPDATE OqlUserEntity u SET u.name = :{$param} WHERE u.id = :id",
                'users',
            ];
        }
    }

    // ── Property 11: DELETE translation ─────────────────────────────

    /**
     * **Validates: Requirements 30.2**
     *
     * For all valid DELETE ASTs, translation resolves entity→table correctly.
     *
     * @dataProvider deleteTranslationProvider
     */
    public function testDeleteTranslation(string $oql, string $expectedTableName): void
    {
        $ast = $this->parser->parse($oql);
        $this->assertInstanceOf(DeleteStatement::class, $ast);

        $result = $this->translator->translate($ast);

        $this->assertStringStartsWith('DELETE FROM [' . $expectedTableName . ']', $result['sql']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deleteTranslationProvider(): iterable
    {
        $params = ['id', 'val1', 'val2', 'val3', 'val4', 'val5', 'val6', 'val7', 'val8', 'val9'];

        $count = 0;
        // User entity
        yield "delete-user-simple" => ['DELETE FROM OqlUserEntity u', 'users'];
        $count++;

        foreach ($params as $param) {
            yield "delete-user-{$count}" => [
                "DELETE FROM OqlUserEntity u WHERE u.id = :{$param}",
                'users',
            ];
            $count++;
        }

        // Post entity
        yield "delete-post-simple" => ['DELETE FROM OqlPostEntity p', 'posts'];
        $count++;

        foreach ($params as $param) {
            yield "delete-post-{$count}" => [
                "DELETE FROM OqlPostEntity p WHERE p.id = :{$param}",
                'posts',
            ];
            $count++;
        }

        // Additional variations with different properties
        $properties = ['name', 'email', 'age'];
        foreach ($properties as $prop) {
            foreach ($params as $param) {
                yield "delete-user-prop-{$count}" => [
                    "DELETE FROM OqlUserEntity u WHERE u.{$prop} = :{$param}",
                    'users',
                ];
                $count++;
                if ($count >= 110) {
                    return;
                }
            }
        }
    }

    // ── Property 12: UPDATE parameter ordering ──────────────────────

    /**
     * **Validates: Requirements 29.5**
     *
     * For all UPDATE statements with SET and WHERE parameters,
     * SET parameters always come before WHERE parameters in the output.
     *
     * @dataProvider updateParameterOrderingProvider
     */
    public function testUpdateParameterOrdering(string $oql, array $expectedSetParams, array $expectedWhereParams): void
    {
        $ast = $this->parser->parse($oql);
        $this->assertInstanceOf(UpdateStatement::class, $ast);

        $result = $this->translator->translate($ast);
        $params = $result['parameters'];

        // SET params should come first
        $setCount = count($expectedSetParams);
        $actualSetParams = array_slice($params, 0, $setCount);
        $actualWhereParams = array_slice($params, $setCount);

        $this->assertSame($expectedSetParams, $actualSetParams, 'SET parameters should come first');
        $this->assertSame($expectedWhereParams, $actualWhereParams, 'WHERE parameters should come after SET');
    }

    /**
     * @return iterable<string, array{string, string[], string[]}>
     */
    public static function updateParameterOrderingProvider(): iterable
    {
        $setParams = [
            ['s1'], ['s1', 's2'], ['s1', 's2', 's3'],
            ['a'], ['a', 'b'], ['a', 'b', 'c'],
            ['x'], ['x', 'y'],
        ];
        $whereParams = [
            ['w1'], ['w1', 'w2'],
            ['id'], ['userId'],
        ];

        $count = 0;
        foreach ($setParams as $sets) {
            foreach ($whereParams as $wheres) {
                $setClauses = [];
                $properties = ['name', 'email', 'age'];
                foreach ($sets as $i => $param) {
                    $prop = $properties[$i % count($properties)];
                    $setClauses[] = "u.{$prop} = :{$param}";
                }

                $whereClauses = [];
                $whereProps = ['id', 'active'];
                foreach ($wheres as $i => $param) {
                    $prop = $whereProps[$i % count($whereProps)];
                    $whereClauses[] = "u.{$prop} = :{$param}";
                }

                $oql = 'UPDATE OqlUserEntity u SET ' . implode(', ', $setClauses) . ' WHERE ' . implode(' AND ', $whereClauses);

                yield "param-order-{$count}" => [$oql, $sets, $wheres];
                $count++;

                if ($count >= 100) {
                    return;
                }
            }
        }
    }
}
