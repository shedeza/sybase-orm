<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use SybaseORM\Dialect\SybaseDialect;
use SybaseORM\Query\QueryBuilder;

final class QueryBuilderSetParameterTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $this->qb = new QueryBuilder(new SybaseDialect());
    }

    // ── setParameter() ──────────────────────────────────────────────

    public function testSetParameterStoresNamedParameter(): void
    {
        $this->qb->setParameter('name', 'Alice');

        $this->assertSame(['name' => 'Alice'], $this->qb->getParameters());
    }

    public function testSetParameterReturnsSameInstance(): void
    {
        $result = $this->qb->setParameter('id', 1);

        $this->assertSame($this->qb, $result);
    }

    public function testSetParameterOverwritesExistingKey(): void
    {
        $this->qb->setParameter('name', 'Alice');
        $this->qb->setParameter('name', 'Bob');

        $this->assertSame(['name' => 'Bob'], $this->qb->getParameters());
    }

    public function testSetParameterAccumulatesWithWhereParams(): void
    {
        $this->qb
            ->select('id')
            ->from('users')
            ->where('active = :active', [':active' => 1])
            ->setParameter('role', 'admin');

        $params = $this->qb->getParameters();
        $this->assertSame(1, $params[':active']);
        $this->assertSame('admin', $params['role']);
    }

    public function testSetParameterWithNullValue(): void
    {
        $this->qb->setParameter('deleted_at', null);

        $this->assertSame(['deleted_at' => null], $this->qb->getParameters());
    }

    public function testSetParameterWithIntValue(): void
    {
        $this->qb->setParameter('id', 42);

        $this->assertSame(['id' => 42], $this->qb->getParameters());
    }

    public function testSetParameterWithFloatValue(): void
    {
        $this->qb->setParameter('price', 19.99);

        $this->assertSame(['price' => 19.99], $this->qb->getParameters());
    }

    public function testSetParameterWithBoolValue(): void
    {
        $this->qb->setParameter('active', true);

        $this->assertSame(['active' => true], $this->qb->getParameters());
    }

    // ── setParameters() ─────────────────────────────────────────────

    public function testSetParametersMergesMultipleParams(): void
    {
        $this->qb->setParameters(['name' => 'Alice', 'role' => 'admin']);

        $this->assertSame(['name' => 'Alice', 'role' => 'admin'], $this->qb->getParameters());
    }

    public function testSetParametersReturnsSameInstance(): void
    {
        $result = $this->qb->setParameters(['id' => 1]);

        $this->assertSame($this->qb, $result);
    }

    public function testSetParametersMergesWithExisting(): void
    {
        $this->qb->setParameter('name', 'Alice');
        $this->qb->setParameters(['role' => 'admin', 'age' => 30]);

        $params = $this->qb->getParameters();
        $this->assertSame('Alice', $params['name']);
        $this->assertSame('admin', $params['role']);
        $this->assertSame(30, $params['age']);
    }

    public function testSetParametersOverwritesExistingKeys(): void
    {
        $this->qb->setParameter('name', 'Alice');
        $this->qb->setParameters(['name' => 'Bob']);

        $this->assertSame(['name' => 'Bob'], $this->qb->getParameters());
    }

    public function testSetParametersWithEmptyArrayIsNoOp(): void
    {
        $this->qb->setParameter('name', 'Alice');
        $this->qb->setParameters([]);

        $this->assertSame(['name' => 'Alice'], $this->qb->getParameters());
    }

    public function testSetParametersMergesWithWhereParams(): void
    {
        $this->qb
            ->select('id')
            ->from('users')
            ->where('active = :active', [':active' => 1])
            ->setParameters([':role' => 'admin', ':age' => 25]);

        $params = $this->qb->getParameters();
        $this->assertSame(1, $params[':active']);
        $this->assertSame('admin', $params[':role']);
        $this->assertSame(25, $params[':age']);
    }

    // ── Combined with getSQL() ──────────────────────────────────────

    public function testSetParameterWorksWithFullQuery(): void
    {
        $sql = $this->qb
            ->select('id', 'name')
            ->from('users')
            ->where('name = :name')
            ->andWhere('role = :role')
            ->setParameter('name', 'Alice')
            ->setParameter('role', 'admin')
            ->getSQL();

        $this->assertSame('SELECT id, name FROM users WHERE name = :name AND role = :role', $sql);
        $this->assertSame(['name' => 'Alice', 'role' => 'admin'], $this->qb->getParameters());
    }

    // ── Reset clears parameters set via setParameter/setParameters ──

    public function testResetClearsSetParameters(): void
    {
        $this->qb
            ->setParameter('name', 'Alice')
            ->setParameters(['role' => 'admin'])
            ->reset();

        $this->assertSame([], $this->qb->getParameters());
    }
}
