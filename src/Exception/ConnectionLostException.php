<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando se pierde la conexión a Sybase ASE durante una operación.
 */
final class ConnectionLostException extends SybaseORMException
{
    public function __construct(
        string $message = 'Connection to Sybase ASE was lost.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Creates a ConnectionLostException from a PDOException.
     */
    public static function fromPdoException(\PDOException $e): self
    {
        return new self(
            'Connection to Sybase ASE was lost: ' . $e->getMessage(),
            (int) $e->getCode(),
            $e,
        );
    }
}
