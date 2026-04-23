<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Lanzada cuando un valor no puede convertirse entre un tipo PHP y un tipo Sybase ASE.
 */
class TypeConversionException extends SybaseORMException
{
    public function __construct(
        private readonly string $sourceType,
        private readonly string $targetType,
        private readonly mixed $problematicValue,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf(
                'Could not convert value of type "%s" to type "%s". Value: %s',
                $this->sourceType,
                $this->targetType,
                is_scalar($this->problematicValue) ? (string) $this->problematicValue : get_debug_type($this->problematicValue),
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getProblematicValue(): mixed
    {
        return $this->problematicValue;
    }
}
