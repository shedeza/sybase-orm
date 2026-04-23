<?php

declare(strict_types=1);

namespace SybaseORM\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Migration\MigrationManager;

/**
 * Generates a new migration file by comparing entity metadata with the current schema.
 */
#[AsCommand(
    name: 'sybase:migrations:generate',
    description: 'Generate a new migration by comparing entity metadata with the database schema',
)]
final class MigrationsGenerateCommand extends Command
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
        private readonly MetadataReaderInterface $metadataReader,
        /** @var string[] */
        private readonly array $entityDirectories,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sybase ORM - Generate Migration');

        $entityClasses = $this->discoverEntityClasses();

        if (empty($entityClasses)) {
            $io->warning('No entity classes found in configured directories.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d entity class(es).', count($entityClasses)));

        $filePath = $this->migrationManager->generateMigration($entityClasses);

        if ($filePath === null) {
            $io->success('No schema changes detected. No migration generated.');
            return Command::SUCCESS;
        }

        $io->success(sprintf('Migration generated: %s', $filePath));

        return Command::SUCCESS;
    }

    /**
     * Discovers entity classes from configured directories.
     *
     * @return string[]
     */
    private function discoverEntityClasses(): array
    {
        $classes = [];

        foreach ($this->entityDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->extractClassName($file->getPathname());
                if ($className !== null && $this->metadataReader->isEntity($className)) {
                    $classes[] = $className;
                }
            }
        }

        return $classes;
    }

    /**
     * Extracts the fully qualified class name from a PHP file.
     */
    private function extractClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        return $namespace !== null ? $namespace . '\\' . $class : $class;
    }
}
