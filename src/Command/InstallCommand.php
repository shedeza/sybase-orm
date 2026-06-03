<?php

declare(strict_types=1);

namespace SybaseORM\Bundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Genera los archivos de configuración necesarios para usar SybaseORM en un proyecto Symfony.
 *
 * Crea:
 * - config/packages/sybase_orm.yaml (configuración del bundle)
 * - Agrega DATABASE_URL al .env si no existe
 * - Crea el directorio de migraciones
 */
#[AsCommand(
    name: 'sybase:install',
    description: 'Instala y configura SybaseORM en el proyecto Symfony actual',
)]
final class InstallCommand extends Command
{
    private const CONFIG_TEMPLATE = <<<'YAML'
# Configuración de SybaseORM Bundle
# Documentación: https://github.com/shedeza/sybase-orm
sybase_orm:
    connection:
        # Opción 1 (recomendada): URL de conexión única
        url: '%env(DATABASE_URL)%'

        # Opción 2: Parámetros individuales (descomentar y comentar url)
        # host: '%env(SYBASE_HOST)%'
        # port: '%env(int:SYBASE_PORT)%'
        # database: '%env(SYBASE_DATABASE)%'
        # username: '%env(SYBASE_USERNAME)%'
        # password: '%env(SYBASE_PASSWORD)%'
        # charset: UTF-8
        # persistent: false

    entity_directories:
        - '%kernel.project_dir%/src/Entity'

    proxy_directory: '%kernel.cache_dir%/sybase_orm/proxies'
    migrations_directory: '%kernel.project_dir%/sybase_ase/migrations'

    cache:
        enabled: false
        # adapter: redis
        # dsn: '%env(REDIS_URL)%'
        # default_ttl: 3600

YAML;

    private const ENV_LINE = 'DATABASE_URL="sybase://sa:!ChangeMe!@127.0.0.1:5000/app?charset=UTF-8"';

    private const BUNDLE_LINE = "SybaseORM\\Bundle\\SybaseORMBundle::class => ['all' => true],";

    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Sobreescribir archivos existentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title('SybaseORM - Instalación');

        $this->createConfigFile($io, $force);
        $this->updateEnvFile($io);
        $this->createMigrationsDirectory($io);
        $this->checkBundleRegistration($io);

        $io->success('SybaseORM instalado correctamente. Edita DATABASE_URL en .env con tus datos de conexión.');

        return Command::SUCCESS;
    }

    /**
     * Crea config/packages/sybase_orm.yaml si no existe.
     */
    private function createConfigFile(SymfonyStyle $io, bool $force): void
    {
        $configPath = $this->projectDir . '/config/packages/sybase_orm.yaml';

        if (file_exists($configPath) && !$force) {
            $io->text("  <info>✓</info> config/packages/sybase_orm.yaml ya existe (usa --force para sobreescribir)");

            return;
        }

        $dir = dirname($configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, self::CONFIG_TEMPLATE);
        $io->text('  <info>✓</info> Creado config/packages/sybase_orm.yaml');
    }

    /**
     * Agrega DATABASE_URL al .env si no existe.
     */
    private function updateEnvFile(SymfonyStyle $io): void
    {
        $envPath = $this->projectDir . '/.env';

        if (!file_exists($envPath)) {
            file_put_contents($envPath, "###> sybase-orm/sybase-ase-orm-bundle ###\n" . self::ENV_LINE . "\n###< sybase-orm/sybase-ase-orm-bundle ###\n");
            $io->text('  <info>✓</info> Creado .env con DATABASE_URL');

            return;
        }

        $content = file_get_contents($envPath);

        if (str_contains($content, 'DATABASE_URL')) {
            $io->text('  <info>✓</info> DATABASE_URL ya existe en .env');

            return;
        }

        $block = "\n###> sybase-orm/sybase-ase-orm-bundle ###\n" . self::ENV_LINE . "\n###< sybase-orm/sybase-ase-orm-bundle ###\n";
        file_put_contents($envPath, $content . $block);
        $io->text('  <info>✓</info> Agregado DATABASE_URL a .env');
    }

    /**
     * Crea el directorio de migraciones si no existe.
     */
    private function createMigrationsDirectory(SymfonyStyle $io): void
    {
        $migrationsDir = $this->projectDir . '/sybase_ase/migrations';

        if (is_dir($migrationsDir)) {
            $io->text('  <info>✓</info> Directorio sybase_ase/migrations/ ya existe');

            return;
        }

        mkdir($migrationsDir, 0755, true);
        file_put_contents($migrationsDir . '/.gitkeep', '');
        $io->text('  <info>✓</info> Creado directorio sybase_ase/migrations/');
    }

    /**
     * Verifica si el bundle está registrado en bundles.php.
     */
    private function checkBundleRegistration(SymfonyStyle $io): void
    {
        $bundlesPath = $this->projectDir . '/config/bundles.php';

        if (!file_exists($bundlesPath)) {
            $io->warning('No se encontró config/bundles.php. Registra el bundle manualmente:');
            $io->text('  ' . self::BUNDLE_LINE);

            return;
        }

        $content = file_get_contents($bundlesPath);

        if (str_contains($content, 'SybaseORMBundle')) {
            $io->text('  <info>✓</info> Bundle registrado en config/bundles.php');

            return;
        }

        // Insertar antes del cierre del array
        $newContent = str_replace(
            '];',
            "    " . self::BUNDLE_LINE . "\n];",
            $content,
        );

        file_put_contents($bundlesPath, $newContent);
        $io->text('  <info>✓</info> Bundle registrado en config/bundles.php');
    }
}
