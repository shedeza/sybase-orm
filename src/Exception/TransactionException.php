<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando se intenta commit o rollback sin una transacción activa.
 */
final class TransactionException extends SybaseORMException
{
    public function __construct(
        string $message = 'No active transaction.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
