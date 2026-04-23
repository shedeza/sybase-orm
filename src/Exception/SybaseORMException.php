<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Excepción base para todos los errores del ORM SybaseORM.
 *
 * Permite capturar cualquier error del ORM con un solo catch:
 *
 *     try {
 *         $em->flush();
 *     } catch (SybaseORMException $e) {
 *         // Maneja cualquier error del ORM
 *     }
 */
class SybaseORMException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
