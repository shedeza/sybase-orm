<?php

declare(strict_types=1);

namespace SybaseORM\Bundle\Command;

use SybaseORM\Metadata\MetadataReaderInterface;
use SybaseORM\Proxy\ProxyGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generates proxy classes for all mapped entities.
 */
#[AsCommand(
    name: 'sybase:proxy:generate',
    description: 'Generate proxy classes for lazy loading of all mapped entities',
)]
final class ProxyGenerateCommand extends Command
{
    public function __construct(
        private readonly ProxyGenerator $proxyGenerator,
        private readonly MetadataReaderInterface $metadataReader,
        /** @var string[] */
        private readonly array $entityDirectories,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Sybase ORM - Generate Proxies');

        $entityClasses = $this->discoverEntityClasses();

        if (empty($entityClasses)) {
            $io->warning('No entity classes found in configured directories.');

            return Command::SUCCESS;
        }

        $generated = 0;
        foreach ($entityClasses as $entityClass) {
            $proxyClass = $this->proxyGenerator->generateProxyClass($entityClass);
            $io->text(sprintf('Generated proxy: %s', $proxyClass));
            $generated++;
        }

        $io->success(sprintf('Generated %d proxy class(es).', $generated));

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
