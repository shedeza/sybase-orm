<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Constantes de modo de hidratación para EntityManager::query().
 */
final class HydrationMode
{
    public const HYDRATE_OBJECT = 1;
    public const HYDRATE_ARRAY = 2;
}
