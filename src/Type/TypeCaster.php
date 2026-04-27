<?php

declare(strict_types=1);

namespace SybaseORM\Type;

use SybaseORM\Exception\TypeConversionException;

/**
 * Convierte tipos de datos entre PHP y Sybase ASE.
 */
final class TypeCaster implements TypeCasterInterface
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.v';

    /** @var \DateTimeZone Timezone used for parsing database datetime values */
    private static ?\DateTimeZone $utcTimezone = null;

    private static function getUtcTimezone(): \DateTimeZone
    {
        if (self::$utcTimezone === null) {
            self::$utcTimezone = new \DateTimeZone('UTC');
        }

        return self::$utcTimezone;
    }

    /** @var array<string, string> Mapa de tipos registrados (nombre → clase) */
    private array $customTypes = [];

    /** @var array<string, CustomTypeInterface> Instancias cacheadas de tipos personalizados */
    private array $customTypeInstances = [];

    /** @var string[] Built-in type names */
    private const BUILTIN_TYPES = [
        'bool', 'boolean',
        'datetime',
        'int', 'integer', 'tinyint', 'smallint', 'bigint',
        'float', 'double', 'decimal', 'real', 'numeric',
        'string', 'varchar', 'text',
    ];

    /**
     * Returns true if the given type name is a built-in type.
     */
    public function isBuiltinType(string $type): bool
    {
        return in_array($type, self::BUILTIN_TYPES, true);
    }

    /**
     * Returns true if the given type name is registered as a custom type.
     */
    public function isRegisteredType(string $typeName): bool
    {
        return isset($this->customTypes[$typeName]);
    }

    /**
     * Returns the list of registered custom type names.
     *
     * @return string[]
     */
    public function getRegisteredTypeNames(): array
    {
        return array_keys($this->customTypes);
    }

    public function toDatabaseValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool', 'boolean' => $this->boolToDatabaseValue($value),
            'datetime' => $this->dateTimeToDatabaseValue($value),
            'int', 'integer', 'tinyint', 'smallint', 'bigint' => $this->intToDatabaseValue($value),
            'decimal', 'numeric' => $this->decimalToDatabaseValue($value),
            'float', 'double', 'real' => $this->floatToDatabaseValue($value),
            'string', 'varchar', 'text' => $this->stringToDatabaseValue($value),
            default => $this->resolveCustomToDatabaseValue($value, $type),
        };
    }

    public function toPhpValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool', 'boolean' => $this->boolToPhpValue($value),
            'datetime' => $this->dateTimeToPhpValue($value),
            'int', 'integer', 'tinyint', 'smallint', 'bigint' => $this->intToPhpValue($value),
            'decimal', 'numeric' => $this->decimalToPhpValue($value),
            'float', 'double', 'real' => $this->floatToPhpValue($value),
            'string', 'varchar', 'text' => $this->stringToPhpValue($value),
            default => $this->resolveCustomToPhpValue($value, $type),
        };
    }

    public function registerType(string $typeName, string $typeClass): void
    {
        if (!is_a($typeClass, CustomTypeInterface::class, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Type class "%s" must implement %s.',
                $typeClass,
                CustomTypeInterface::class,
            ));
        }

        $this->customTypes[$typeName] = $typeClass;
    }

    public function getDatabaseValueSQL(string $sqlExpr, string $type): string
    {
        // Built-in float types need explicit CONVERT for Sybase ASE
        // (Sybase rejects implicit VARCHAR → REAL/FLOAT conversion)
        if (in_array($type, ['float', 'double', 'real'], true)) {
            return 'CONVERT(REAL, ' . $sqlExpr . ')';
        }

        if (in_array($type, ['decimal', 'numeric'], true)) {
            return $sqlExpr; // Decimal values are already strings, no CONVERT needed
        }

        if (isset($this->customTypes[$type])) {
            $typeInstance = $this->getCustomTypeInstance($type);
            if ($typeInstance instanceof SqlWrappingTypeInterface) {
                return $typeInstance->convertToDatabaseValueSQL($sqlExpr);
            }
        }

        return $sqlExpr;
    }

    // ── Custom / Enum resolution ────────────────────────────────

    private function resolveCustomToDatabaseValue(mixed $value, string $type): mixed
    {
        // Check registered custom types first
        if (isset($this->customTypes[$type])) {
            return $this->applyCustomTypeToDatabaseValue($value, $type);
        }

        // Check if the type is a BackedEnum FQCN
        if ($this->isBackedEnumClass($type)) {
            return $this->enumToDatabaseValue($value, $type);
        }

        throw new TypeConversionException(
            get_debug_type($value),
            $type,
            $value,
            sprintf('Unsupported type "%s" for database conversion.', $type),
        );
    }

    private function resolveCustomToPhpValue(mixed $value, string $type): mixed
    {
        // Check registered custom types first
        if (isset($this->customTypes[$type])) {
            return $this->applyCustomTypeToPhpValue($value, $type);
        }

        // Check if the type is a BackedEnum FQCN
        if ($this->isBackedEnumClass($type)) {
            return $this->enumToPhpValue($value, $type);
        }

        throw new TypeConversionException(
            get_debug_type($value),
            $type,
            $value,
            sprintf('Unsupported type "%s" for PHP conversion.', $type),
        );
    }

    // ── BackedEnum ↔ scalar ─────────────────────────────────────

    private function isBackedEnumClass(string $type): bool
    {
        return is_a($type, \BackedEnum::class, true);
    }

    private function enumToDatabaseValue(mixed $value, string $type): int|string
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        throw new TypeConversionException(
            get_debug_type($value),
            $type,
            $value,
            sprintf('Expected a BackedEnum instance of "%s", got "%s".', $type, get_debug_type($value)),
        );
    }

    private function enumToPhpValue(mixed $value, string $type): \BackedEnum
    {
        if (!is_string($value) && !is_int($value)) {
            throw new TypeConversionException(
                get_debug_type($value),
                $type,
                $value,
                sprintf('Expected a scalar value to convert to enum "%s", got "%s".', $type, get_debug_type($value)),
            );
        }

        try {
            /** @var class-string<\BackedEnum> $type */
            return $type::from($value);
        } catch (\ValueError $e) {
            throw new TypeConversionException(
                get_debug_type($value),
                $type,
                $value,
                sprintf('Value "%s" is not valid for enum "%s".', (string) $value, $type),
                0,
                $e,
            );
        }
    }

    // ── Custom Types (Value Objects) ────────────────────────────

    private function applyCustomTypeToDatabaseValue(mixed $value, string $type): mixed
    {
        try {
            $typeInstance = $this->getCustomTypeInstance($type);

            return $typeInstance->toDatabaseValue($value);
        } catch (TypeConversionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TypeConversionException(
                get_debug_type($value),
                $type,
                $value,
                sprintf('Custom type "%s" failed to convert to database value: %s', $type, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function applyCustomTypeToPhpValue(mixed $value, string $type): mixed
    {
        try {
            $typeInstance = $this->getCustomTypeInstance($type);

            return $typeInstance->toPhpValue($value);
        } catch (TypeConversionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TypeConversionException(
                get_debug_type($value),
                $type,
                $value,
                sprintf('Custom type "%s" failed to convert to PHP value: %s', $type, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Obtiene o crea la instancia cacheada de un tipo personalizado.
     */
    private function getCustomTypeInstance(string $type): CustomTypeInterface
    {
        if (!isset($this->customTypeInstances[$type])) {
            $this->customTypeInstances[$type] = new ($this->customTypes[$type])();
        }

        return $this->customTypeInstances[$type];
    }

    // ── Bool ↔ BIT ──────────────────────────────────────────────

    private function boolToDatabaseValue(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value;
        }

        throw new TypeConversionException(get_debug_type($value), 'bool', $value);
    }

    private function boolToPhpValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_string($value)) {
            $intVal = (int) $value;
            if ($intVal === 0 || $intVal === 1) {
                return $intVal === 1;
            }
        }

        throw new TypeConversionException(get_debug_type($value), 'bool', $value);
    }

    // ── DateTime ↔ Sybase format ────────────────────────────────

    private function dateTimeToDatabaseValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::DATETIME_FORMAT);
        }

        throw new TypeConversionException(get_debug_type($value), 'datetime', $value);
    }

    private function dateTimeToPhpValue(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value)) {
            $tz = self::getUtcTimezone();

            $dt = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value, $tz);
            if ($dt !== false) {
                return $dt;
            }

            // Try standard datetime formats as fallback
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
            if ($dt !== false) {
                return $dt;
            }

            try {
                return new \DateTimeImmutable($value, $tz);
            } catch (\Exception) {
                // Fall through to exception
            }
        }

        throw new TypeConversionException(get_debug_type($value), 'datetime', $value);
    }

    // ── Int ─────────────────────────────────────────────────────

    private function intToDatabaseValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw new TypeConversionException(get_debug_type($value), 'int', $value);
    }

    private function intToPhpValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        throw new TypeConversionException(get_debug_type($value), 'int', $value);
    }

    // ── Float ───────────────────────────────────────────────────

    private function floatToDatabaseValue(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new TypeConversionException(get_debug_type($value), 'float', $value);
    }

    private function floatToPhpValue(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new TypeConversionException(get_debug_type($value), 'float', $value);
    }

    // ── Decimal (precision-preserving) ──────────────────────────

    /**
     * Converts a decimal value to database format, preserving precision as string.
     * Accepts float, int, or numeric string.
     */
    private function decimalToDatabaseValue(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return sprintf('%.17g', $value);
        }

        throw new TypeConversionException(get_debug_type($value), 'decimal', $value);
    }

    /**
     * Converts a database decimal value to PHP string to preserve precision.
     * Returns string instead of float to avoid precision loss for DECIMAL(19,4) etc.
     */
    private function decimalToPhpValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new TypeConversionException(get_debug_type($value), 'decimal', $value);
    }

    // ── String ──────────────────────────────────────────────────

    private function stringToDatabaseValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new TypeConversionException(get_debug_type($value), 'string', $value);
    }

    private function stringToPhpValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new TypeConversionException(get_debug_type($value), 'string', $value);
    }
}
