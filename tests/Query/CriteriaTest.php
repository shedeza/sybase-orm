<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Query;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SybaseORM\Query\Criteria\Criteria;
use SybaseORM\ORM\EntityRepository;
use SybaseORM\ORM\EntityManagerInterface;
use SybaseORM\Query\QueryBuilderInterface;

/**
 * @covers \SybaseORM\Query\Criteria\Criteria
 * @covers \SybaseORM\Query\Criteria\ExpressionBuilder
 * @covers \SybaseORM\Query\Criteria\Comparison
 * @covers \SybaseORM\Query\Criteria\CompositeExpression
 * @covers \SybaseORM\ORM\EntityRepository::matching
 */
final class CriteriaTest extends TestCase
{
    public function testCriteriaBuildsCorrectly(): void
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('status', 'active'))
            ->andWhere(Criteria::expr()->gt('age', 18))
            ->orWhere(Criteria::expr()->in('role', ['admin', 'manager']))
            ->orderBy(['createdAt' => Criteria::DESC])
            ->setFirstResult(10)
            ->setMaxResults(20);

        $this->assertSame(10, $criteria->getFirstResult());
        $this->assertSame(20, $criteria->getMaxResults());
        $this->assertArrayHasKey('createdAt', $criteria->getOrderings());
        $this->assertNotNull($criteria->getWhereExpression());
    }

    public function testRepositoryMatchingTranslatesCriteriaToQueryBuilder(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $qb = $this->createMock(QueryBuilderInterface::class);

        // Expectation chain
        $em->method('createQueryBuilder')->with('App\Entity\User')->willReturn($qb);
        $qb->expects($this->once())->method('select')->with('User');
        $qb->expects($this->once())->method('fromEntity')->with('App\Entity\User', 'User');
        $qb->expects($this->once())->method('where')->with("((User.status = 'active') AND (User.age > 18)) OR (User.role IN ('admin', 'manager'))");
        $qb->expects($this->once())->method('orderBy')->with('User.createdAt', Criteria::DESC);
        $qb->expects($this->once())->method('setFirstResult')->with(10);
        $qb->expects($this->once())->method('setMaxResults')->with(20);

        $qb->method('getQuery')->willReturnSelf();
        $qb->method('getResult')->willReturn([]);

        $repo = new class ($em, 'App\Entity\User') extends EntityRepository {};

        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('status', 'active'))
            ->andWhere(Criteria::expr()->gt('age', 18))
            ->orWhere(Criteria::expr()->in('role', ['admin', 'manager']))
            ->orderBy(['createdAt' => Criteria::DESC])
            ->setFirstResult(10)
            ->setMaxResults(20);

        $result = $repo->matching($criteria);
        $this->assertSame([], $result);
    }
}
