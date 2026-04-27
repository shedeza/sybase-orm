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

    /**
     * Creates a TransactionException for commit/rollback without an active transaction.
     */
    public static function noActiveTransaction(string $operation): self
    {
        return new self(sprintf(
            'Cannot %s: no active transaction.',
            $operation,
        ));
    }

    /**
     * Creates a TransactionException when a transaction is already active.
     */
    public static function alreadyActive(): self
    {
        return new self('A transaction is already active.');
    }
}
