<?php

declare(strict_types=1);

namespace SybaseORM\Console;

/**
 * CLI command dispatcher.
 *
 * Registers commands and routes argv to the appropriate handler.
 */
final class CommandRunner
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    /**
     * Registers a command.
     */
    public function add(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Dispatches CLI arguments to the appropriate command.
     *
     * @param string[] $argv Raw argv (argv[0] is script name)
     * @return int Exit code
     */
    public function run(array $argv): int
    {
        $commandName = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            return $this->showHelp();
        }

        if ($commandName === 'list') {
            return $this->showHelp();
        }

        if (!isset($this->commands[$commandName])) {
            $this->error(sprintf('Unknown command: "%s". Run "help" for available commands.', $commandName));
            $this->suggestSimilar($commandName);

            return 1;
        }

        return $this->commands[$commandName]->execute($args);
    }

    private function showHelp(): int
    {
        $this->output('Sybase ORM CLI v3.6');
        $this->output('');
        $this->output('Usage: php bin/sybase-orm <command> [arguments]');
        $this->output('');
        $this->output('Available commands:');

        // Group commands by prefix
        $groups = [];
        foreach ($this->commands as $name => $command) {
            $prefix = str_contains($name, ':') ? explode(':', $name)[0] : 'general';
            $groups[$prefix][$name] = $command->getDescription();
        }

        ksort($groups);
        foreach ($groups as $group => $cmds) {
            $this->output('');
            $this->output("  [{$group}]");
            ksort($cmds);
            foreach ($cmds as $name => $desc) {
                $this->output(sprintf('    %-24s %s', $name, $desc));
            }
        }

        $this->output('');

        return 0;
    }

    private function suggestSimilar(string $input): void
    {
        $suggestions = [];
        foreach (array_keys($this->commands) as $name) {
            if (str_contains($name, $input) || str_contains($input, explode(':', $name)[0])) {
                $suggestions[] = $name;
            }
        }

        if (!empty($suggestions)) {
            $this->output('');
            $this->output('Did you mean?');
            foreach ($suggestions as $s) {
                $this->output('  ' . $s);
            }
        }
    }

    private function output(string $message): void
    {
        echo $message . PHP_EOL;
    }

    private function error(string $message): void
    {
        fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    }
}
