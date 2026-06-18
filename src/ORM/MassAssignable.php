<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Trait that adds mass assignment protection to entities.
 *
 * Usage:
 *     class User {
 *         use MassAssignable;
 *
 *         // Only these properties can be mass-assigned
 *         protected array $fillable = ['name', 'email'];
 *
 *         // OR: These properties are protected from mass assignment
 *         protected array $guarded = ['id', 'role', 'isAdmin'];
 *     }
 *
 *     $user->fill(['name' => 'Juan', 'role' => 'admin']); // 'role' is ignored
 */
trait MassAssignable
{
    /**
     * Mass-assigns values to the entity, respecting fillable/guarded rules.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function fill(array $attributes): static
    {
        $reflection = new \ReflectionClass($this);
        $fillable = property_exists($this, 'fillable') ? $this->fillable : [];
        $guarded = property_exists($this, 'guarded') ? $this->guarded : ['id'];

        foreach ($attributes as $key => $value) {
            // If fillable is defined, only allow listed properties
            if (!empty($fillable) && !in_array($key, $fillable, true)) {
                continue;
            }

            // If guarded is defined, block listed properties
            if (!empty($guarded) && in_array($key, $guarded, true)) {
                continue;
            }

            if ($reflection->hasProperty($key)) {
                $prop = $reflection->getProperty($key);
                $prop->setAccessible(true);
                $prop->setValue($this, $value);
            }
        }

        return $this;
    }

    /**
     * Mass-assigns values without protection (forceFill).
     * Use with caution — bypasses fillable/guarded.
     *
     * @param array<string, mixed> $attributes
     * @return static
     */
    public function forceFill(array $attributes): static
    {
        $reflection = new \ReflectionClass($this);

        foreach ($attributes as $key => $value) {
            if ($reflection->hasProperty($key)) {
                $prop = $reflection->getProperty($key);
                $prop->setAccessible(true);
                $prop->setValue($this, $value);
            }
        }

        return $this;
    }
}
