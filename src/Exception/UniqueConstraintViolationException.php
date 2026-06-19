<?php

declare(strict_types=1);

namespace SybaseORM\Exception;

/**
 * Thrown when a #[UniqueEntity] validation fails before persisting.
 *
 * Contains the entity class, the fields that violated uniqueness,
 * and the custom message from the attribute.
 */
final class UniqueConstraintViolationException extends SybaseORMException
{
    /** @var string[] */
    private array $fields;
    private string $entityClass;

    /**
     * @param string $entityClass Entity FQCN
     * @param string[] $fields Fields that violated uniqueness
     * @param string $message Custom error message
     */
    public function __construct(string $entityClass, array $fields, string $message)
    {
        $this->entityClass = $entityClass;
        $this->fields = $fields;

        parent::__construct($message);
    }

    /**
     * Returns the fields that violated the unique constraint.
     *
     * @return string[]
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Returns the entity class that has the violation.
     */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }
}
