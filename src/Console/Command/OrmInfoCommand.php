<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;
use SybaseORM\Metadata\MetadataReaderInterface;

/**
 * Displays information about mapped entities.
 *
 * Usage: orm:info
 */
final class OrmInfoCommand implements CommandInterface
{
    /**
     * @param MetadataReaderInterface $metadataReader
     * @param string[] $entityClasses Known entity FQCNs
     */
    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
        private readonly array $entityClasses,
    ) {}

    public function getName(): string
    {
        return 'orm:info';
    }

    public function getDescription(): string
    {
        return 'Show mapped entities and their tables';
    }

    public function execute(array $args): int
    {
        if (empty($this->entityClasses)) {
            IO::info('No entity classes registered.');
            IO::info('Provide entity_classes or entity_directories in your configuration.');

            return 0;
        }

        IO::output(sprintf('Found %d mapped entity(ies):', count($this->entityClasses)));
        IO::output('');

        $rows = [];
        foreach ($this->entityClasses as $fqcn) {
            try {
                $metadata = $this->metadataReader->getClassMetadata($fqcn);
                $rows[] = [
                    $fqcn,
                    $metadata->getQualifiedTableName(),
                    (string) count($metadata->columns),
                    (string) count($metadata->relationships),
                    $metadata->softDeleteColumn !== null ? 'Yes' : '-',
                ];
            } catch (\Throwable $e) {
                $rows[] = [$fqcn, 'ERROR: ' . $e->getMessage(), '-', '-', '-'];
            }
        }

        IO::table(
            ['Entity', 'Table', 'Columns', 'Relations', 'SoftDelete'],
            $rows,
        );

        return 0;
    }
}
