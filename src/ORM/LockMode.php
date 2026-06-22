<?php

declare(strict_types=1);

namespace SybaseORM\ORM;

/**
 * Lock modes for pessimistic and optimistic locking.
 *
 * Sybase ASE equivalents:
 * - PESSIMISTIC_READ  → SELECT ... AT ISOLATION READ COMMITTED (HOLDLOCK on page)
 * - PESSIMISTIC_WRITE → SELECT ... AT ISOLATION SERIALIZABLE (HOLDLOCK exclusive)
 * - OPTIMISTIC        → Uses #[Version] column for conflict detection
 */
final class LockMode
{
    /** No locking. */
    public const NONE = 0;

    /** Optimistic locking via #[Version] column. */
    public const OPTIMISTIC = 1;

    /** Pessimistic read lock (shared — prevents writes by others). */
    public const PESSIMISTIC_READ = 2;

    /** Pessimistic write lock (exclusive — prevents reads and writes by others). */
    public const PESSIMISTIC_WRITE = 3;

    private function __construct() {}
}
