<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Thrown when entity validation fails before persisting.
 *
 * Contains all validation errors for the entity.
 */
final class ValidationException extends SybaseORMException
{
    /** @var string[] */
    private array $errors;
    private string $entityClass;

    /**
     * @param string $entityClass Entity FQCN
     * @param string[] $errors List of validation error messages
     */
    public function __construct(string $entityClass, array $errors)
    {
        $this->entityClass = $entityClass;
        $this->errors = $errors;

        $message = sprintf(
            'Validation failed for "%s": %s',
            $entityClass,
            implode('; ', $errors),
        );

        parent::__construct($message);
    }

    /**
     * Returns all validation error messages.
     *
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns the entity class that failed validation.
     */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }
}
