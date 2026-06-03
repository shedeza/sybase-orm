<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando se pierde la conexión a Sybase ASE durante una operación.
 */
final class ConnectionLostException extends SybaseORMException
{
    private ?string $sqlState;

    public function __construct(
        string $message = 'Connection to Sybase ASE was lost.',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $sqlState = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->sqlState = $sqlState;
    }

    /**
     * Returns the SQLSTATE error code from the original PDOException, or null.
     */
    public function getSqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * Creates a ConnectionLostException from a PDOException.
     */
    public static function fromPdoException(\PDOException $e): self
    {
        return new self(
            'Connection to Sybase ASE was lost: ' . $e->getMessage(),
            0,
            $e,
            is_string($e->getCode()) ? $e->getCode() : null,
        );
    }
}
