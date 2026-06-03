<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Constantes de modo de hidratación para EntityManager::query().
 */
final class HydrationMode
{
    /**
     * Constantes de modo de hidratación para EntityManager::query().
     */
    public const HYDRATE_OBJECT = 1;
    public const HYDRATE_ARRAY = 2;

    /**
     * Returns true if the given mode is a valid hydration mode.
     */
    public static function isValid(int $mode): bool
    {
        return $mode === self::HYDRATE_OBJECT || $mode === self::HYDRATE_ARRAY;
    }
}
