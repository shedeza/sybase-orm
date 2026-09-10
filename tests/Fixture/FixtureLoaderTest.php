<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Fixture;

use PHPUnit\Framework\TestCase;
use SybaseORM\Fixture\FixtureLoader;
use SybaseORM\Fixture\FixtureInterface;
use SybaseORM\ORM\EntityManagerInterface;

/**
 * @covers \SybaseORM\Fixture\FixtureLoader
 */
final class FixtureLoaderTest extends TestCase
{
    public function testFixtureLoaderExecutesFixtures(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $fixture1 = $this->createMock(FixtureInterface::class);
        $fixture1->expects($this->once())->method('load')->with($em);

        $fixture2 = $this->createMock(FixtureInterface::class);
        $fixture2->expects($this->once())->method('load')->with($em);

        $em->expects($this->once())->method('flush');

        $loader = new FixtureLoader();
        $loader->addFixture($fixture1);
        $loader->addFixture($fixture2);

        $loader->execute($em);
    }
}
