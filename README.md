# shedeza/sybase-orm

A pure PHP ORM for Sybase ASE — framework-agnostic, zero Symfony dependencies.

Use it standalone, with Laravel, Slim, or any PHP project. Requires only `ext-pdo_dblib` and `psr/log`.

## Installation

```bash
composer require shedeza/sybase-orm
```

## Requirements

- PHP 8.1+
- ext-pdo_dblib

## Quick Start

### Array-based configuration

```php
<?php

use SybaseORM\ORM\OrmFactory;

$em = OrmFactory::create([
    'connection' => [
        'host' => '192.168.1.100',
        'port' => 5000,
        'dbname' => 'my_database',
        'username' => 'sa',
        'password' => 'secret',
        'charset' => 'UTF-8',
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
]);
```

### URL-based configuration

```php
<?php

use SybaseORM\ORM\OrmFactory;

$em = OrmFactory::createFromUrl(
    'sybase://sa:secret@192.168.1.100:5000/my_database?charset=UTF-8',
    entityDirectories: [__DIR__ . '/src/Entity'],
);
```

## Connection Configuration

### Array-based

All available connection options:

```php
$em = OrmFactory::create([
    'connection' => [
        'host' => '192.168.1.100',       // Required: database host
        'port' => 5000,                   // Optional: defaults to 5000
        'dbname' => 'my_database',        // Required: database name
        'username' => 'sa',              // Required: database user
        'password' => 'secret',          // Optional: user password
        'charset' => 'UTF-8',            // Optional: defaults to UTF-8
        'persistent' => false,           // Optional: use persistent connections
        'charset_conversion' => false,   // Optional: UTF-8 ↔ ISO-8859-1 conversion
        'read_only' => false,            // Optional: read-only mode
    ],
    'entity_directories' => [__DIR__ . '/src/Entity'],
    'proxy_directory' => __DIR__ . '/var/proxies',
    'metadata_cache_dir' => __DIR__ . '/var/cache/metadata',
]);
```

### URL-based

The URL format follows the pattern:

```
sybase://username:password@host:port/dbname?charset=UTF-8&persistent=true&charset_conversion=false&read_only=false
```

Examples:

```php
// Basic connection
$em = OrmFactory::createFromUrl('sybase://sa:secret@localhost:5000/my_database');

// With all query options
$em = OrmFactory::createFromUrl(
    'sybase://admin:p%40ssw0rd@db.example.com:5000/production?charset=UTF-8&persistent=true&charset_conversion=true&read_only=false',
    entityDirectories: [__DIR__ . '/src/Entity'],
);
```

Passwords with special characters should be URL-encoded (e.g., `p@ss` → `p%40ss`).

## Usage Examples

### Persist a new entity

```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';

$em->persist($user);
$em->flush();

// After flush, $user->id is populated with the generated value
```

### Find an entity by ID

```php
$user = $em->find(User::class, 1);

if ($user !== null) {
    echo $user->name; // "John Doe"
}
```

### Update an entity

```php
$user = $em->find(User::class, 1);
$user->email = 'newemail@example.com';

$em->flush(); // UnitOfWork detects dirty properties automatically
```

### Remove an entity

```php
$user = $em->find(User::class, 1);

$em->remove($user);
$em->flush();
```

### Query with OQL

OQL (Object Query Language) lets you query entities using their class names and properties:

```php
// Find all active users
$users = $em->query(
    'SELECT u FROM User u WHERE u.active = :active',
    ['active' => true],
);

// Single result
$user = $em->queryOne(
    'SELECT u FROM User u WHERE u.email = :email',
    ['email' => 'john@example.com'],
);

// Scalar value
$count = $em->queryScalar('SELECT COUNT(u) FROM User u');

// Streaming large result sets
foreach ($em->queryIterator('SELECT u FROM User u') as $user) {
    // Process one entity at a time without loading all into memory
}
```

### QueryBuilder

```php
$users = $em->createQueryBuilder(User::class)
    ->where('active = :active')
    ->andWhere('createdAt > :date')
    ->orderBy('name', 'ASC')
    ->setParameter('active', true)
    ->setParameter('date', new \DateTime('-30 days'))
    ->setMaxResults(10)
    ->getResult();
```

## Entity Mapping

Define entities using PHP 8.1 attributes:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\GeneratedValue;
use SybaseORM\Attribute\Id;

#[Entity(table: 'users')]
class User
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public ?int $id = null;

    #[Column(type: 'string', length: 100)]
    public string $name;

    #[Column(type: 'string', length: 255, unique: true)]
    public string $email;

    #[Column(type: 'boolean')]
    public bool $active = true;

    #[Column(type: 'datetime', nullable: true)]
    public ?\DateTime $createdAt = null;
}
```

### Available attributes

| Attribute | Purpose |
|-----------|---------|
| `#[Entity(table: '...')]` | Maps a class to a database table |
| `#[Column(type: '...')]` | Maps a property to a column |
| `#[Id]` | Marks the primary key property |
| `#[GeneratedValue]` | Indicates the ID is auto-generated |
| `#[ManyToOne]` | Many-to-one relationship |
| `#[OneToMany]` | One-to-many relationship |
| `#[OneToOne]` | One-to-one relationship |
| `#[ManyToMany]` | Many-to-many relationship |
| `#[JoinColumn]` | Configures join column for relationships |

## Multiple Connections

Use `EntityManagerRegistry` to manage multiple database connections:

```php
<?php

use SybaseORM\ORM\EntityManagerRegistry;
use SybaseORM\ORM\OrmFactory;

// Create EntityManagers for each database
$defaultEm = OrmFactory::create([
    'connection' => [
        'host' => 'db1.example.com',
        'port' => 5000,
        'dbname' => 'primary_db',
        'username' => 'sa',
        'password' => 'secret',
    ],
    'entity_directories' => [__DIR__ . '/src/Entity/Primary'],
]);

$analyticsEm = OrmFactory::create([
    'connection' => [
        'host' => 'db2.example.com',
        'port' => 5000,
        'dbname' => 'analytics_db',
        'username' => 'reader',
        'password' => 'readonly',
    ],
    'entity_directories' => [__DIR__ . '/src/Entity/Analytics'],
]);

// Register them in the registry
$registry = new EntityManagerRegistry(
    managers: ['default' => $defaultEm, 'analytics' => $analyticsEm],
    defaultConnection: 'default',
);

// Retrieve by name
$em = $registry->getManager('analytics');

// Or get the default
$em = $registry->getDefaultManager();
```

## License

MIT
