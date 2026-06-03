<?php

declare(strict_types=1);

namespace SybaseORM\Bundle\Command;

use SybaseORM\Connection\ConnectionManagerInterface;
use SybaseORM\Metadata\EntityDiscovery;
use SybaseORM\Metadata\MetadataReaderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates that all mapped entities have correct metadata and that
 * their tables/columns exist in the database.
 */
#[AsCommand(
    name: 'sybase:schema:validate',
    description: 'Validate entity mapping against the database schema',
)]
final class SchemaValidateCommand extends Command
{
    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
        private readonly ConnectionManagerInterface $connection,
        /** @var string[] */
        private readonly array $entityDirectories,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sybase ORM - Schema Validation');

        $discovery = new EntityDiscovery($this->metadataReader);
        $entityClasses = $discovery->discoverEntityClasses($this->entityDirectories);

        if (empty($entityClasses)) {
            $io->warning('No entity classes found in configured directories.');

            return Command::SUCCESS;
        }

        $errors = 0;
        $validated = 0;

        foreach ($entityClasses as $entityClass) {
            try {
                $metadata = $this->metadataReader->getClassMetadata($entityClass);
            } catch (\Throwable $e) {
                $io->error(sprintf('[%s] Metadata error: %s', $entityClass, $e->getMessage()));
                $errors++;
                continue;
            }

            // Check table exists
            $tableExists = $this->tableExists($metadata->tableName);
            if (!$tableExists) {
                $io->error(sprintf('[%s] Table "%s" does not exist in database.', $entityClass, $metadata->tableName));
                $errors++;
                continue;
            }

            // Check columns exist
            $dbColumns = $this->getTableColumns($metadata->tableName);
            foreach ($metadata->columns as $column) {
                // Skip embedded columns (dot notation)
                if (str_contains($column->propertyName, '.')) {
                    // Check the prefixed column name
                    if (!in_array($column->columnName, $dbColumns, true)) {
                        $io->warning(sprintf('[%s] Column "%s" not found in table "%s".', $entityClass, $column->columnName, $metadata->tableName));
                    }
                    continue;
                }

                if (!in_array($column->columnName, $dbColumns, true)) {
                    $io->warning(sprintf('[%s] Column "%s" not found in table "%s".', $entityClass, $column->columnName, $metadata->tableName));
                }
            }

            $validated++;
            $io->text(sprintf('  <info>✓</info> %s → %s (%d columns)', $entityClass, $metadata->tableName, count($metadata->columns)));
        }

        $io->newLine();

        if ($errors > 0) {
            $io->error(sprintf('Validation failed: %d error(s), %d entity(ies) validated.', $errors, $validated));

            return Command::FAILURE;
        }

        $io->success(sprintf('All %d entities validated successfully.', $validated));

        return Command::SUCCESS;
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->connection->executeQuery(
            "SELECT 1 FROM sysobjects WHERE name = ? AND type = 'U'",
            [$tableName],
        );
        $exists = $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
        $stmt->closeCursor();

        return $exists;
    }

    /**
     * @return string[]
     */
    private function getTableColumns(string $tableName): array
    {
        $stmt = $this->connection->executeQuery(
            "SELECT c.name FROM syscolumns c JOIN sysobjects o ON c.id = o.id WHERE o.name = ? AND o.type = 'U'",
            [$tableName],
        );

        $columns = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $columns[] = $row['name'];
        }
        $stmt->closeCursor();

        return $columns;
    }
}
