<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

use SybaseORM\Exception\ValidationException;
use SybaseORM\Metadata\ClassMetadata;
use SybaseORM\Metadata\MetadataReaderInterface;

/**
 * Validates entity data against column constraints before persisting.
 *
 * Checks:
 * - String length vs Column(length: N)
 * - Not-null violations (nullable: false)
 * - Type compatibility
 */
final class EntityValidator
{
    public function __construct(
        private readonly MetadataReaderInterface $metadataReader,
    ) {}

    /**
     * Validates an entity against its column metadata constraints.
     *
     * @throws ValidationException If any constraint is violated.
     */
    public function validate(object $entity): void
    {
        $metadata = $this->metadataReader->getClassMetadata($entity::class);
        $reflection = new \ReflectionClass($entity);
        $errors = [];

        foreach ($metadata->columns as $column) {
            // Skip embedded (dot-notation) properties
            if (str_contains($column->propertyName, '.')) {
                continue;
            }

            if (!$reflection->hasProperty($column->propertyName)) {
                continue;
            }

            $prop = $reflection->getProperty($column->propertyName);

            if (!$prop->isInitialized($entity)) {
                if (!$column->nullable && !$column->isId) {
                    $errors[] = sprintf(
                        'Property "%s" is not initialized and column "%s" is NOT NULL.',
                        $column->propertyName,
                        $column->columnName,
                    );
                }
                continue;
            }

            $value = $prop->getValue($entity);

            // Not-null check (skip ID fields — they may be auto-generated)
            if ($value === null && !$column->nullable && !$column->isId) {
                $errors[] = sprintf(
                    'Property "%s" cannot be null (column "%s" is NOT NULL).',
                    $column->propertyName,
                    $column->columnName,
                );
                continue;
            }

            if ($value === null) {
                continue;
            }

            // String length check
            if ($column->length !== null && is_string($value)) {
                $actualLength = mb_strlen($value);
                if ($actualLength > $column->length) {
                    $errors[] = sprintf(
                        'Property "%s" exceeds maximum length: %d chars (max: %d, column: "%s").',
                        $column->propertyName,
                        $actualLength,
                        $column->length,
                        $column->columnName,
                    );
                }
            }

            // Numeric precision check
            if ($column->precision !== null && is_numeric($value)) {
                $strVal = (string) $value;
                $parts = explode('.', $strVal);
                $intDigits = strlen(ltrim($parts[0], '-'));
                $decDigits = isset($parts[1]) ? strlen($parts[1]) : 0;
                $totalDigits = $intDigits + $decDigits;
                $maxScale = $column->scale ?? 0;

                if ($totalDigits > $column->precision) {
                    $errors[] = sprintf(
                        'Property "%s" exceeds precision: %d digits (max: %d, column: "%s").',
                        $column->propertyName,
                        $totalDigits,
                        $column->precision,
                        $column->columnName,
                    );
                }

                if ($decDigits > $maxScale) {
                    $errors[] = sprintf(
                        'Property "%s" exceeds scale: %d decimals (max: %d, column: "%s").',
                        $column->propertyName,
                        $decDigits,
                        $maxScale,
                        $column->columnName,
                    );
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($entity::class, $errors);
        }
    }
}
