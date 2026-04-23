<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando se pierde la conexión a Sybase ASE durante una operación.
 */
class ConnectionLostException extends SybaseORMException
{
    public function __construct(
        string $message = 'Connection to Sybase ASE was lost.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
