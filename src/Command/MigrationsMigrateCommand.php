<?php

declare(strict_types=1);

namespace SybaseORM\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use SybaseORM\Migration\MigrationManager;

/**
 * Executes all pending database migrations.
 */
#[AsCommand(
    name: 'sybase:migrations:migrate',
    description: 'Execute all pending database migrations',
)]
final class MigrationsMigrateCommand extends Command
{
    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sybase ORM - Execute Migrations');

        try {
            $executed = $this->migrationManager->migrate();

            if (empty($executed)) {
                $io->success('No pending migrations to execute.');
                return Command::SUCCESS;
            }

            $io->success(sprintf('Executed %d migration(s):', count($executed)));
            $io->listing($executed);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Migration failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }
    }
}
