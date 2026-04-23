<?php

declare(strict_types=1);

namespace SybaseORM\Proxy;

/**
 * Interface implemented by all generated proxy classes.
 *
 * Provides the contract for lazy loading behavior: an initializer closure
 * that loads the real entity data on first property access, and a flag
 * to track whether initialization has occurred.
 */
interface LazyLoadingProxy
{
    /** Returns true if the proxy has been initialized (data loaded). */
    public function __isInitialized(): bool;

    /** Forces initialization of the proxy, loading all pending data. */
    public function __initialize(): void;

    /** Sets the initializer closure that will load the real data. */
    public function __setInitializer(?\Closure $initializer): void;

    /** Returns the initializer closure, or null if already consumed. */
    public function __getInitializer(): ?\Closure;
}
