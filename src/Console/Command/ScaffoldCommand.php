<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Scaffold\SchemaIntrospector;
use SybaseORM\Scaffold\EntityGenerator;

class ScaffoldCommand implements CommandInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connectionManager
    ) {}

    public function getName(): string
    {
        return 'orm:scaffold';
    }

    public function getDescription(): string
    {
        return 'Generates Entity classes from existing database tables (Reverse Engineering)';
    }

    public function execute(array $args): int
    {
        $io = new IO();
        $table = null;
        $dir = 'src/Entity';
        $namespace = 'App\\Entity';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--table=')) {
                $table = substr($arg, 8);
            } elseif (str_starts_with($arg, '--dir=')) {
                $dir = substr($arg, 6);
            } elseif (str_starts_with($arg, '--namespace=')) {
                $namespace = substr($arg, 12);
            }
        }

        $introspector = new SchemaIntrospector($this->connectionManager);
        $generator = new EntityGenerator($namespace);

        try {
            $tables = $table ? [$table] : $introspector->getTables();

            if (empty($tables)) {
                $io->warning('No tables found to scaffold.');
                return 0;
            }

            foreach ($tables as $t) {
                $io->info("Introspecting table '$t'...");
                $def = $introspector->getTableDefinition($t);
                $filePath = $generator->generate($def, $dir);
                $io->success("Generated entity at: $filePath");
            }

            return 0;
        } catch (\Throwable $e) {
            $io->error('Scaffold failed: ' . $e->getMessage());
            return 1;
        }
    }
}
