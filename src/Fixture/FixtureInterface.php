<?php

declare(strict_types=1);

namespace SybaseORM\Fixture;

use SybaseORM\ORM\EntityManagerInterface;

interface FixtureInterface
{
    public function load(EntityManagerInterface $manager): void;
}
