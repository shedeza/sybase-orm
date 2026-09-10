<?php

declare(strict_types=1);

namespace SybaseORM\Fixture;

use SybaseORM\ORM\EntityManagerInterface;

class FixtureLoader
{
    /** @var FixtureInterface[] */
    private array $fixtures = [];

    public function addFixture(FixtureInterface $fixture): void
    {
        $this->fixtures[] = $fixture;
    }

    public function loadFromDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = scandir($directory);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $class = require_once rtrim($directory, '/') . '/' . $file;

                // If the file returns a class string or instance
                if (is_string($class) && class_exists($class)) {
                    $instance = new $class();
                    if ($instance instanceof FixtureInterface) {
                        $this->addFixture($instance);
                    }
                } elseif ($class instanceof FixtureInterface) {
                    $this->addFixture($class);
                } else {
                    // Fallback to get declared classes and find which one implements FixtureInterface
                    $classes = get_declared_classes();
                    $latestClass = end($classes);

                    if ($latestClass && is_subclass_of($latestClass, FixtureInterface::class)) {
                        $this->addFixture(new $latestClass());
                    }
                }
            }
        }
    }

    public function execute(EntityManagerInterface $em): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->load($em);
        }
        $em->flush();
    }
}
