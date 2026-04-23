<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando ocurre un error al parsear una consulta OQL.
 */
final class OqlParseException extends SybaseORMException
{
    public function __construct(
        string $message = 'Failed to parse OQL query.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
