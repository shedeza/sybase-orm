<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

/**
 * Tests for QueryBuilder accessor methods: getFrom, getFromAlias, getSelectColumns, isDistinct.
 */
final class QueryBuilderAccessorsTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = new QueryBuilder(new SybaseDialect());
    }

    public function testGetFromReturnsNullByDefault(): void
    {
        $this->assertNull($this->qb->getFrom());
        $this->assertNull($this->qb->getFromAlias());
    }

    public function testGetFromReturnsTableName(): void
    {
        $this->qb->from('users', 'u');

        $this->assertSame('users', $this->qb->getFrom());
        $this->assertSame('u', $this->qb->getFromAlias());
    }

    public function testGetSelectColumnsReturnsEmptyByDefault(): void
    {
        $this->assertSame([], $this->qb->getSelectColumns());
    }

    public function testGetSelectColumnsReturnsColumns(): void
    {
        $this->qb->select('id', 'name', 'email');

        $this->assertSame(['id', 'name', 'email'], $this->qb->getSelectColumns());
    }

    public function testIsDistinctReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->qb->isDistinct());
    }

    public function testIsDistinctReturnsTrueWhenEnabled(): void
    {
        $this->qb->distinct();

        $this->assertTrue($this->qb->isDistinct());
    }

    public function testAccessorsResetOnReset(): void
    {
        $this->qb->select('id')->from('users', 'u')->distinct();
        $this->qb->reset();

        $this->assertNull($this->qb->getFrom());
        $this->assertNull($this->qb->getFromAlias());
        $this->assertSame([], $this->qb->getSelectColumns());
        $this->assertFalse($this->qb->isDistinct());
    }
}
