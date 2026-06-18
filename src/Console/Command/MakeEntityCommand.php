<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;

/**
 * Generates a skeleton entity class file.
 *
 * Usage: make:entity User [--table=users] [--dir=src/Entity]
 */
final class MakeEntityCommand implements CommandInterface
{
    public function __construct(
        private readonly string $defaultEntityDirectory,
        private readonly string $defaultNamespace = 'App\\Entity',
    ) {}

    public function getName(): string
    {
        return 'make:entity';
    }

    public function getDescription(): string
    {
        return 'Generate a skeleton entity class';
    }

    public function execute(array $args): int
    {
        if (empty($args)) {
            IO::error('Usage: make:entity EntityName [--table=table_name] [--dir=src/Entity]');

            return 1;
        }

        $entityName = $args[0];
        $table = null;
        $dir = $this->defaultEntityDirectory;
        $namespace = $this->defaultNamespace;

        // Parse options
        foreach (array_slice($args, 1) as $arg) {
            if (str_starts_with($arg, '--table=')) {
                $table = substr($arg, 8);
            } elseif (str_starts_with($arg, '--dir=')) {
                $dir = substr($arg, 6);
            } elseif (str_starts_with($arg, '--namespace=')) {
                $namespace = substr($arg, 12);
            }
        }

        if ($table === null) {
            $table = $this->toSnakeCase($entityName);
        }

        $filePath = rtrim($dir, '/') . '/' . $entityName . '.php';

        if (file_exists($filePath)) {
            IO::error(sprintf('Entity file already exists: %s', $filePath));

            return 1;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $content = $this->generateEntityCode($entityName, $table, $namespace);
        $written = file_put_contents($filePath, $content);

        if ($written === false) {
            IO::error('Failed to write entity file: ' . $filePath);

            return 1;
        }

        IO::success(sprintf('Entity created: %s\\%s', $namespace, $entityName));
        IO::info('Path: ' . $filePath);
        IO::info('Table: ' . $table);

        return 0;
    }

    private function generateEntityCode(string $entityName, string $table, string $namespace): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use SybaseORM\\Attribute\\Entity;
            use SybaseORM\\Attribute\\Id;
            use SybaseORM\\Attribute\\Column;
            use SybaseORM\\Attribute\\GeneratedValue;

            #[Entity(table: '{$table}')]
            class {$entityName}
            {
                #[Id]
                #[GeneratedValue]
                #[Column(type: 'integer')]
                public ?int \$id = null;

                // Add your properties here:
                // #[Column(type: 'string', length: 100)]
                // public string \$name = '';
            }

            PHP;
    }

    private function toSnakeCase(string $input): string
    {
        $result = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $input);
        $result = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $result ?? $input);

        return strtolower($result ?? $input);
    }
}
