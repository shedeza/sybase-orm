<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use SybaseORM\Command\InstallCommand;

final class InstallCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/sybase_orm_install_test_' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testCreatesConfigFile(): void
    {
        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $configPath = $this->projectDir . '/config/packages/sybase_orm.yaml';
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString('sybase_orm:', $content);
        $this->assertStringContainsString('connection:', $content);
        $this->assertStringContainsString("url: '%env(DATABASE_URL)%'", $content);
        $this->assertStringContainsString('entity_directories:', $content);
        $this->assertStringContainsString('proxy_directory:', $content);
        $this->assertStringContainsString('migrations_directory:', $content);
    }

    public function testDoesNotOverwriteExistingConfigWithoutForce(): void
    {
        $configDir = $this->projectDir . '/config/packages';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/sybase_orm.yaml', 'existing_content');

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame('existing_content', file_get_contents($configDir . '/sybase_orm.yaml'));
        $this->assertStringContainsString('ya existe', $tester->getDisplay());
    }

    public function testOverwritesConfigWithForce(): void
    {
        $configDir = $this->projectDir . '/config/packages';
        mkdir($configDir, 0755, true);
        file_put_contents($configDir . '/sybase_orm.yaml', 'old_content');

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute(['--force' => true]);

        $content = file_get_contents($configDir . '/sybase_orm.yaml');
        $this->assertStringContainsString('sybase_orm:', $content);
        $this->assertStringNotContainsString('old_content', $content);
    }

    public function testCreatesEnvFileWithDatabaseUrl(): void
    {
        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $envPath = $this->projectDir . '/.env';
        $this->assertFileExists($envPath);

        $content = file_get_contents($envPath);
        $this->assertStringContainsString('DATABASE_URL=', $content);
        $this->assertStringContainsString('sybase://', $content);
        $this->assertStringContainsString('###> sybase-orm/sybase-ase-orm-bundle ###', $content);
    }

    public function testAppendsDatabaseUrlToExistingEnv(): void
    {
        file_put_contents($this->projectDir . '/.env', "APP_ENV=dev\nAPP_SECRET=abc123\n");

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $content = file_get_contents($this->projectDir . '/.env');
        $this->assertStringContainsString('APP_ENV=dev', $content);
        $this->assertStringContainsString('DATABASE_URL=', $content);
    }

    public function testSkipsDatabaseUrlIfAlreadyExists(): void
    {
        file_put_contents($this->projectDir . '/.env', "DATABASE_URL=\"sybase://custom@host/db\"\n");

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $content = file_get_contents($this->projectDir . '/.env');
        $this->assertSame(1, substr_count($content, 'DATABASE_URL'));
        $this->assertStringContainsString('ya existe', $tester->getDisplay());
    }

    public function testCreatesMigrationsDirectory(): void
    {
        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertDirectoryExists($this->projectDir . '/sybase_ase/migrations');
        $this->assertFileExists($this->projectDir . '/sybase_ase/migrations/.gitkeep');
    }

    public function testSkipsExistingMigrationsDirectory(): void
    {
        mkdir($this->projectDir . '/sybase_ase/migrations', 0755, true);

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertStringContainsString('ya existe', $tester->getDisplay());
    }

    public function testRegistersBundleInBundlesPhp(): void
    {
        $bundlesDir = $this->projectDir . '/config';
        mkdir($bundlesDir, 0755, true);
        file_put_contents($bundlesDir . '/bundles.php', "<?php\n\nreturn [\n    Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ['all' => true],\n];\n");

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $content = file_get_contents($bundlesDir . '/bundles.php');
        $this->assertStringContainsString('SybaseORMBundle', $content);
    }

    public function testSkipsBundleRegistrationIfAlreadyPresent(): void
    {
        $bundlesDir = $this->projectDir . '/config';
        mkdir($bundlesDir, 0755, true);
        file_put_contents($bundlesDir . '/bundles.php', "<?php\nreturn [\n    SybaseORM\\SybaseORMBundle::class => ['all' => true],\n];\n");

        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertStringContainsString('Bundle registrado', $tester->getDisplay());
    }

    public function testWarnsWhenBundlesPhpNotFound(): void
    {
        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertStringContainsString('Registra el bundle manualmente', $tester->getDisplay());
    }

    public function testOutputShowsSuccessMessage(): void
    {
        $command = new InstallCommand($this->projectDir);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('instalado correctamente', $tester->getDisplay());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
