# Configuración y Conexión

El ORM ofrece dos formas de configurar la conexión a Sybase ASE: mediante un array de opciones o mediante una URL DSN. Ambas producen el mismo resultado internamente y se pasan a `OrmFactory::create()` para obtener un `EntityManager` completamente configurado.

## Configuración por Array

La forma más explícita de configurar una conexión es mediante un array asociativo:

```php
use SybaseORM\ORM\OrmFactory;

$em = OrmFactory::create([
    'connection' => [
        'host'               => '192.168.1.100',
        'port'               => 5000,
        'dbname'             => 'mi_base',
        'username'           => 'sa',
        'password'           => 'secret',
        'charset'            => 'UTF-8',
        'persistent'         => false,
        'charset_conversion' => true,
        'read_only'          => false,
    ],
    'entity_directories'  => [__DIR__ . '/Entity'],
    'proxy_directory'     => __DIR__ . '/../var/proxies',
    'metadata_cache_dir'  => __DIR__ . '/../var/cache/metadata',
]);
```

### Referencia de opciones de conexión

| Opción | Tipo | Por defecto | Descripción |
|--------|------|-------------|-------------|
| `host` | `string` | `'localhost'` | Dirección del servidor Sybase ASE |
| `port` | `int` | `5000` | Puerto del servidor (1–65535) |
| `dbname` | `string` | — (requerido) | Nombre de la base de datos |
| `username` | `string` | `''` | Usuario de conexión |
| `password` | `string` | `''` | Contraseña del usuario |
| `charset` | `string` | `'UTF-8'` | Charset del DSN dblib |
| `persistent` | `bool` | `false` | Usar conexiones PDO persistentes |
| `charset_conversion` | `bool` | `false` | Activar conversión UTF-8 ↔ ISO-8859-1 |
| `read_only` | `bool` | `false` | Impedir operaciones de escritura |

## Configuración por URL DSN

Puedes condensar toda la configuración en una URL con formato:

```
sybase://usuario:password@host:puerto/base_de_datos?charset=UTF-8&persistent=true
```

El parser acepta los esquemas `sybase://` y `dblib://`.

```php
use SybaseORM\ORM\OrmFactory;

$em = OrmFactory::createFromUrl(
    'sybase://sa:secret@192.168.1.100:5000/mi_base?charset=UTF-8',
    entityDirectories: [__DIR__ . '/Entity'],
);
```

### Codificación de caracteres especiales

Si el usuario o contraseña contienen caracteres especiales (`@`, `:`, `/`, `#`), deben codificarse con URL-encoding:

```php
// Contraseña real: p@ss/w0rd#1
$url = 'sybase://admin:p%40ss%2Fw0rd%231@db.example.com:5000/produccion';

$em = OrmFactory::createFromUrl($url, [__DIR__ . '/Entity']);
```

El parser aplica `urldecode()` automáticamente sobre usuario y contraseña.

### Opciones en query string

Las opciones booleanas se pasan como parámetros de query:

```
sybase://sa:secret@host:5000/mydb?charset=ISO-8859-1&persistent=true&charset_conversion=true&read_only=true
```

## Conexiones Múltiples con EntityManagerRegistry

Cuando la aplicación accede a varias bases de datos, `EntityManagerRegistry` gestiona múltiples instancias de `EntityManager`:

```php
use SybaseORM\ORM\OrmFactory;
use SybaseORM\ORM\EntityManagerRegistry;

// Crear EntityManagers para cada base de datos
$emPrincipal = OrmFactory::create([
    'connection' => [
        'host'   => 'db-principal.local',
        'port'   => 5000,
        'dbname' => 'app_main',
        'username' => 'app_user',
        'password' => 'secret',
    ],
    'entity_directories' => [__DIR__ . '/Entity/Main'],
]);

$emReportes = OrmFactory::create([
    'connection' => [
        'host'      => 'db-reportes.local',
        'port'      => 5000,
        'dbname'    => 'app_reports',
        'username'  => 'report_user',
        'password'  => 'read_only_pass',
        'read_only' => true,
    ],
    'entity_directories' => [__DIR__ . '/Entity/Reports'],
]);

// Registrar en el registry
$registry = new EntityManagerRegistry(
    managers: [
        'default'  => $emPrincipal,
        'reportes' => $emReportes,
    ],
    defaultConnection: 'default',
);

// Uso
$em = $registry->getManager('reportes');
$defaultEm = $registry->getDefaultManager();

// Obtener repositorio automáticamente según la entidad
$repo = $registry->getRepository(\App\Entity\Reports\Venta::class);

// Limpiar todos los Identity Maps (útil en workers)
$registry->clearAll();
```

### Métodos principales de EntityManagerRegistry

| Método | Descripción |
|--------|-------------|
| `addManager(string $name, EntityManagerInterface $em)` | Registra un EM con nombre |
| `getManager(string $name)` | Obtiene un EM por nombre |
| `getDefaultManager()` | Obtiene el EM por defecto |
| `hasManager(string $name)` | Verifica si existe un EM registrado |
| `getManagerNames()` | Lista nombres de EMs registrados |
| `getManagerForEntity(string $class)` | EM según el atributo `#[Entity(connection:)]` |
| `getRepository(string $class)` | Repositorio usando el EM correcto |
| `clearAll()` | Limpia todos los Identity Maps |

## Opciones del ORM

### proxy_directory

Directorio donde el ORM genera las clases Proxy para lazy loading de relaciones:

```php
$em = OrmFactory::create([
    'connection' => [...],
    'proxy_directory' => '/var/app/var/proxies',
]);
```

**Valor por defecto:** `sys_get_temp_dir() . '/sybase-orm-proxies'` (e.g., `/tmp/sybase-orm-proxies`).

En producción se recomienda un directorio persistente para evitar regenerar proxies en cada despliegue.

### metadata_cache_dir

Directorio para cachear los metadatos de entidades leídos por reflection. Evita re-parsear atributos PHP en cada request:

```php
$em = OrmFactory::create([
    'connection' => [...],
    'metadata_cache_dir' => '/var/app/var/cache/metadata',
]);
```

**Valor por defecto:** `null` (sin caché en disco; los metadatos se leen en cada instanciación).

En producción, configurar este directorio mejora el rendimiento de arranque.

## Modo Read-Only

Una conexión con `read_only => true` rechaza cualquier operación de escritura. Al intentar ejecutar `executeStatement()` o `beginTransaction()`, el ORM lanza una `PersistenceException`:

```php
$emReplica = OrmFactory::create([
    'connection' => [
        'host'      => 'replica.local',
        'port'      => 5000,
        'dbname'    => 'app_main',
        'username'  => 'reader',
        'password'  => 'read_pass',
        'read_only' => true,
    ],
]);

// Funciona: lectura
$users = $emReplica->query('SELECT u FROM App\Entity\User u');

// Lanza PersistenceException: escritura bloqueada
$emReplica->persist($newUser);
$emReplica->flush();
```

Esto es útil para conectarse a réplicas de lectura y garantizar que no se ejecuten escrituras accidentales.

## Conversión de Charset (UTF-8 ↔ ISO-8859-1)

Sybase ASE frecuentemente almacena datos en ISO-8859-1. Si tu aplicación PHP trabaja en UTF-8, puedes activar la conversión automática:

```php
$em = OrmFactory::create([
    'connection' => [
        'host'               => 'legacy-server.local',
        'port'               => 5000,
        'dbname'             => 'legacy_db',
        'username'           => 'sa',
        'password'           => 'secret',
        'charset_conversion' => true,
    ],
]);
```

### Comportamiento

- **Al escribir:** Los parámetros `string` se convierten de UTF-8 a ISO-8859-1 antes de enviarlos al servidor.
- **Al leer:** Los valores `string` de los resultados se convierten de ISO-8859-1 a UTF-8.

### Cuándo activarla

| Escenario | charset_conversion |
|-----------|-------------------|
| Servidor Sybase con charset ISO-8859-1 y app PHP en UTF-8 | `true` |
| Servidor ya configurado en UTF-8 | `false` |
| Datos binarios que no deben transformarse | `false` |

Si la conversión falla para un valor específico (carácter no representable), se preserva el string original y se emite un warning vía el logger PSR-3.

---

[Índice](./README.md) | [Siguiente →](./mapeo-entidades.md)
