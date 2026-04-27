<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando ocurre un error durante la persistencia (flush) de entidades.
 */
final class PersistenceException extends SybaseORMException
{
    public function __construct(
        string $message = 'An error occurred during persistence.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Creates a PersistenceException for a failed entity operation.
     */
    public static function forEntity(string $entityClass, string $operation, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to %s entity "%s".', $operation, $entityClass),
            0,
            $previous,
        );
    }
}
