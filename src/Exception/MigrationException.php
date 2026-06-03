<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando ocurre un error durante la ejecución o generación de migraciones.
 */
final class MigrationException extends SybaseORMException
{
    public function __construct(
        string $message = 'An error occurred during migration.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Creates a MigrationException for a specific version failure.
     */
    public static function forVersion(string $version, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Migration "%s" failed: %s', $version, $reason),
            0,
            $previous,
        );
    }
}
