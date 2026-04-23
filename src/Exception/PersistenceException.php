<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando ocurre un error durante la persistencia (flush) de entidades.
 */
class PersistenceException extends SybaseORMException
{
    public function __construct(
        string $message = 'An error occurred during persistence.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
