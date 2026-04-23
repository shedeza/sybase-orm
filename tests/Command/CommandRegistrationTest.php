<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Command;

use PHPUnit\Framework\TestCase;
use SybaseORM\Command\CacheClearCommand;
use SybaseORM\Command\MigrationsGenerateCommand;
use SybaseORM\Command\MigrationsMigrateCommand;
use SybaseORM\Command\ProxyGenerateCommand;

/**
 * Verifies that console commands are properly defined with correct names and attributes.
 */
final class CommandRegistrationTest extends TestCase
{
    public function testMigrationsGenerateCommandName(): void
    {
        $reflection = new \ReflectionClass(MigrationsGenerateCommand::class);
        $attributes = $reflection->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);

        $this->assertNotEmpty($attributes, 'MigrationsGenerateCommand should have #[AsCommand] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sybase:migrations:generate', $instance->name);
    }

    public function testMigrationsMigrateCommandName(): void
    {
        $reflection = new \ReflectionClass(MigrationsMigrateCommand::class);
        $attributes = $reflection->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);

        $this->assertNotEmpty($attributes, 'MigrationsMigrateCommand should have #[AsCommand] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sybase:migrations:migrate', $instance->name);
    }

    public function testProxyGenerateCommandName(): void
    {
        $reflection = new \ReflectionClass(ProxyGenerateCommand::class);
        $attributes = $reflection->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);

        $this->assertNotEmpty($attributes, 'ProxyGenerateCommand should have #[AsCommand] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sybase:proxy:generate', $instance->name);
    }

    public function testCacheClearCommandName(): void
    {
        $reflection = new \ReflectionClass(CacheClearCommand::class);
        $attributes = $reflection->getAttributes(\Symfony\Component\Console\Attribute\AsCommand::class);

        $this->assertNotEmpty($attributes, 'CacheClearCommand should have #[AsCommand] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sybase:cache:clear', $instance->name);
    }

    public function testAllCommandsExtendSymfonyCommand(): void
    {
        $commands = [
            MigrationsGenerateCommand::class,
            MigrationsMigrateCommand::class,
            ProxyGenerateCommand::class,
            CacheClearCommand::class,
        ];

        foreach ($commands as $commandClass) {
            $this->assertTrue(
                is_subclass_of($commandClass, \Symfony\Component\Console\Command\Command::class),
                sprintf('%s should extend Symfony Command', $commandClass)
            );
        }
    }
}
