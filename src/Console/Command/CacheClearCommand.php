<?php

declare(strict_types=1);

namespace SybaseORM\Console\Command;

use SybaseORM\Console\CommandInterface;
use SybaseORM\Console\IO;

/**
 * Clears ORM proxy and metadata caches.
 *
 * Usage: cache:clear
 */
final class CacheClearCommand implements CommandInterface
{
    public function __construct(
        private readonly ?string $proxyDirectory = null,
        private readonly ?string $metadataCacheDir = null,
    ) {}

    public function getName(): string
    {
        return 'cache:clear';
    }

    public function getDescription(): string
    {
        return 'Clear proxy and metadata caches';
    }

    public function execute(array $args): int
    {
        $cleared = 0;

        if ($this->proxyDirectory !== null && is_dir($this->proxyDirectory)) {
            $this->clearDirectory($this->proxyDirectory);
            $cleared++;
            IO::success('Proxy cache cleared: ' . $this->proxyDirectory);
        }

        if ($this->metadataCacheDir !== null && is_dir($this->metadataCacheDir)) {
            $this->clearDirectory($this->metadataCacheDir);
            $cleared++;
            IO::success('Metadata cache cleared: ' . $this->metadataCacheDir);
        }

        if ($cleared === 0) {
            IO::info('No cache directories configured.');
        }

        return 0;
    }

    private function clearDirectory(string $dir): void
    {
        $files = glob($dir . '/*');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
