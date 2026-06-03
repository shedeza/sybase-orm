<?php

declare(strict_types=1);

namespace SybaseORM\Bundle\Command;

use SybaseORM\Cache\CacheManagerInterface;
use SybaseORM\Metadata\MetadataReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Clears the ORM cache (metadata file cache, identity map, and second-level cache).
 */
#[AsCommand(
    name: 'sybase:cache:clear',
    description: 'Clear the SybaseORM metadata and entity caches',
)]
final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly CacheManagerInterface $cacheManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sybase ORM - Clear Cache');

        // Clear runtime caches (identity map + second level)
        $this->cacheManager->clear();
        $io->text('Cleared identity map and second-level cache.');

        // Clear static metadata memory cache
        MetadataReader::clearMemoryCache();
        $io->text('Cleared metadata memory cache.');

        $io->success('All SybaseORM caches cleared.');

        return Command::SUCCESS;
    }
}
