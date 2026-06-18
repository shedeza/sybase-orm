<?php

declare(strict_types=1);

namespace SybaseORM\Console;

/**
 * Contract for CLI commands.
 */
interface CommandInterface
{
    /** Returns the command name (e.g. 'migrate', 'make:migration'). */
    public function getName(): string;

    /** Returns a short description for the help screen. */
    public function getDescription(): string;

    /**
     * Executes the command.
     *
     * @param string[] $args Arguments after the command name
     * @return int Exit code (0 = success)
     */
    public function execute(array $args): int;
}
