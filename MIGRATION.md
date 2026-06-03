# Migration Guide: shedeza/sybase-orm v2.x → v3.0 + shedeza/sybase-orm-bundle

## Overview

Starting with v3.0, the monolithic `shedeza/sybase-orm` package has been split into two independent packages:

1. **shedeza/sybase-orm** (v3.0.0) — A pure PHP ORM library with zero Symfony dependencies, usable in any PHP project (Laravel, Slim, standalone scripts, etc.)
2. **shedeza/sybase-orm-bundle** (v1.0.0) — A thin Symfony bundle that depends on `shedeza/sybase-orm` and provides Symfony-specific integration (DI container, console commands, profiler, Flex recipe)

This separation allows the ORM core to be used without Symfony while preserving full backward compatibility for existing Symfony users. The bundle declares `replace: {"shedeza/sybase-orm": "2.9.2"}` in its `composer.json`, so Composer treats it as a drop-in replacement for the monolithic package.

---

## Composer Changes

### For Symfony Projects (most users)

Replace your v2.x dependency with the new bundle package:

```bash
# Step 1: Remove the old monolithic package
composer remove shedeza/sybase-orm

# Step 2: Require the new bundle package
composer require shedeza/sybase-orm-bundle
```

The bundle package automatically pulls in `shedeza/sybase-orm` v3.0 as a dependency. No other Composer changes are needed.

### For Non-Symfony Projects (new use case)

If you want to use the ORM without Symfony:

```bash
composer require shedeza/sybase-orm
```

This installs only the framework-agnostic ORM library with no Symfony dependencies.

---

## Namespace Changes

Symfony-specific classes have been relocated from the `SybaseORM\` namespace to the `SybaseORM\Bundle\` namespace. The following table lists every relocated class:

| Old FQCN (v2.x) | New FQCN (v3.0 bundle) |
|------------------|------------------------|
| `SybaseORM\SybaseORMBundle` | `SybaseORM\Bundle\SybaseORMBundle` |
| `SybaseORM\Command\InstallCommand` | `SybaseORM\Bundle\Command\InstallCommand` |
| `SybaseORM\Command\MigrationsGenerateCommand` | `SybaseORM\Bundle\Command\MigrationsGenerateCommand` |
| `SybaseORM\Command\MigrationsMigrateCommand` | `SybaseORM\Bundle\Command\MigrationsMigrateCommand` |
| `SybaseORM\Command\ProxyGenerateCommand` | `SybaseORM\Bundle\Command\ProxyGenerateCommand` |
| `SybaseORM\Command\CacheClearCommand` | `SybaseORM\Bundle\Command\CacheClearCommand` |
| `SybaseORM\Command\SchemaValidateCommand` | `SybaseORM\Bundle\Command\SchemaValidateCommand` |
| `SybaseORM\DataCollector\SybaseQueryCollector` | `SybaseORM\Bundle\DataCollector\SybaseQueryCollector` |
| `SybaseORM\DependencyInjection\Configuration` | `SybaseORM\Bundle\DependencyInjection\Configuration` |
| `SybaseORM\DependencyInjection\SybaseORMExtension` | `SybaseORM\Bundle\DependencyInjection\SybaseORMExtension` |

---

## No Changes Required For

The following public API components remain unchanged and require **no code modifications**:

- **EntityManager and EntityManagerInterface** — `SybaseORM\ORM\EntityManager`, `SybaseORM\ORM\EntityManagerInterface`
- **ConnectionManager and ConnectionManagerInterface** — `SybaseORM\Connection\ConnectionManager`, `SybaseORM\Connection\ConnectionManagerInterface`
- **EntityRepository** — `SybaseORM\ORM\EntityRepository`
- **All mapping attributes** — `#[Entity]`, `#[Column]`, `#[Id]`, `#[GeneratedValue]`, `#[OneToOne]`, `#[OneToMany]`, `#[ManyToOne]`, `#[ManyToMany]`, `#[JoinColumn]`, etc.
- **Lifecycle hook attributes** — `#[HasLifecycleHooks]`, `#[PrePersist]`, `#[PostPersist]`, `#[PreUpdate]`, `#[PostUpdate]`, `#[PreRemove]`, `#[PostRemove]`
- **UnitOfWork and UnitOfWorkInterface** — `SybaseORM\ORM\UnitOfWork`, `SybaseORM\ORM\UnitOfWorkInterface`
- **IdentityMap and IdentityMapInterface** — `SybaseORM\ORM\IdentityMap`, `SybaseORM\ORM\IdentityMapInterface`
- **Hydrator and HydratorInterface** — `SybaseORM\Hydrator\Hydrator`, `SybaseORM\Hydrator\HydratorInterface`
- **MetadataReader and MetadataReaderInterface** — `SybaseORM\Metadata\MetadataReader`, `SybaseORM\Metadata\MetadataReaderInterface`
- **SybaseDialect and DialectInterface** — `SybaseORM\Dialect\SybaseDialect`, `SybaseORM\Dialect\DialectInterface`
- **TypeCaster and TypeCasterInterface** — `SybaseORM\Type\TypeCaster`, `SybaseORM\Type\TypeCasterInterface`
- **CacheManager and CacheManagerInterface** — `SybaseORM\Cache\CacheManager`, `SybaseORM\Cache\CacheManagerInterface`
- **QueryBuilder** — `SybaseORM\Query\QueryBuilder`
- **OQL parser** — `SybaseORM\Query\OqlParser`
- **All exception classes** — `SybaseORM\Exception\SybaseORMException` and subclasses
- **Console command names** — `sybase:install`, `sybase:migrations:generate`, `sybase:migrations:migrate`, `sybase:proxy:generate`, `sybase:cache:clear`, `sybase:schema:validate`
- **Bundle configuration key** — `sybase_orm` with all existing options
- **DI service IDs and interface aliases** — all registered services retain their original IDs

If your application code depends exclusively on these classes and interfaces, the migration requires only the Composer dependency change described above.

---

## Breaking Changes

The following breaking changes affect users who directly imported Symfony-specific internal classes:

### 1. Relocated class namespaces

If your code contains `use` statements referencing any of the classes in the [Namespace Changes](#namespace-changes) table above, you must update those imports. For example:

```php
// Before (v2.x)
use SybaseORM\DependencyInjection\Configuration;
use SybaseORM\Command\InstallCommand;

// After (v3.0)
use SybaseORM\Bundle\DependencyInjection\Configuration;
use SybaseORM\Bundle\Command\InstallCommand;
```

### 2. Bundle class registration

If you manually register the bundle in `config/bundles.php` (instead of using Flex), update the FQCN:

```php
// Before (v2.x)
return [
    SybaseORM\SybaseORMBundle::class => ['all' => true],
];

// After (v3.0)
return [
    SybaseORM\Bundle\SybaseORMBundle::class => ['all' => true],
];
```

> **Note:** If you use Symfony Flex, the bundle is registered automatically via the Flex recipe and no manual change is needed.

### 3. Custom command subclasses

If you extended any of the bundle console commands, update your `extends` declarations to reference the new namespace under `SybaseORM\Bundle\Command\`.

### 4. Custom DataCollector extensions

If you extended `SybaseQueryCollector`, update the import to `SybaseORM\Bundle\DataCollector\SybaseQueryCollector`.

### 5. Custom DependencyInjection extensions

If you extended or referenced `SybaseORMExtension` or `Configuration` directly, update imports to the `SybaseORM\Bundle\DependencyInjection\` namespace.

---

## Summary

| User Profile | Action Required |
|-------------|----------------|
| Uses only public API (EntityManager, repositories, attributes, commands via CLI) | Change `composer.json` only |
| Imports Symfony-specific internal classes directly | Change `composer.json` + update `use` statements per namespace table |
| Uses ORM in a non-Symfony project (new) | `composer require shedeza/sybase-orm` — no bundle needed |
