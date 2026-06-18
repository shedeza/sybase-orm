<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Trait that adds toArray()/toJson() serialization capabilities to entities.
 *
 * Usage:
 *     #[Entity(table: 'users')]
 *     class User {
 *         use Serializable;
 *
 *         // Properties hidden from serialization
 *         protected array $hidden = ['password', 'token'];
 *
 *         // Properties visible in serialization (whitelist; if set, only these are included)
 *         protected array $visible = [];
 *     }
 */
trait Serializable
{
    /**
     * Converts the entity to an associative array.
     *
     * @param array<string> $only Only include these properties (overrides hidden/visible)
     * @param array<string> $except Exclude these properties
     * @return array<string, mixed>
     */
    public function toArray(array $only = [], array $except = []): array
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE);

        $hidden = property_exists($this, 'hidden') ? $this->hidden : [];
        $visible = property_exists($this, 'visible') ? $this->visible : [];

        $result = [];

        foreach ($properties as $property) {
            $name = $property->getName();

            // Skip internal properties
            if (in_array($name, ['hidden', 'visible'], true)) {
                continue;
            }

            // Apply "only" filter
            if (!empty($only) && !in_array($name, $only, true)) {
                continue;
            }

            // Apply "except" filter
            if (!empty($except) && in_array($name, $except, true)) {
                continue;
            }

            // Apply visible whitelist
            if (!empty($visible) && !in_array($name, $visible, true)) {
                continue;
            }

            // Apply hidden blacklist
            if (in_array($name, $hidden, true)) {
                continue;
            }

            $property->setAccessible(true);

            if (!$property->isInitialized($this)) {
                continue;
            }

            $value = $property->getValue($this);

            // Handle nested serializable objects
            if (is_object($value) && method_exists($value, 'toArray')) {
                $value = $value->toArray();
            } elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Converts the entity to a JSON string.
     *
     * @param int $flags JSON encoding flags (default: JSON_UNESCAPED_UNICODE)
     */
    public function toJson(int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->toArray(), $flags) ?: '{}';
    }

    /**
     * Creates an entity instance from an associative array.
     * Only sets properties that exist on the class.
     *
     * @param array<string, mixed> $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $instance = new static();
        $reflection = new \ReflectionClass($instance);

        foreach ($data as $key => $value) {
            if ($reflection->hasProperty($key)) {
                $prop = $reflection->getProperty($key);
                $prop->setAccessible(true);
                $prop->setValue($instance, $value);
            }
        }

        return $instance;
    }
}
