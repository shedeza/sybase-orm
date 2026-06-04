# Características Avanzadas

Funcionalidades avanzadas del ORM para escenarios que requieren control directo sobre la conexión, el ciclo de vida de entidades o el rendimiento.

## SQL Directo (Raw SQL)

Para consultas que no se expresan fácilmente en OQL, se puede acceder directamente al `ConnectionManager`:

### executeQuery()

Ejecuta una consulta SELECT y retorna un `PDOStatement`:

```php
$connection = $em->getConnection();

$stmt = $connection->executeQuery(
    'SELECT TOP 10 * FROM usuarios WHERE departamento = ?',
    ['ventas']
);

while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    echo $row['nombre'];
}
$stmt->closeCursor();
```

### executeStatement()

Ejecuta INSERT, UPDATE o DELETE y retorna el número de filas afectadas:

```php
$connection = $em->getConnection();

$affected = $connection->executeStatement(
    'UPDATE usuarios SET ultimo_acceso = GETDATE() WHERE id = ?',
    [42]
);
echo "Filas actualizadas: $affected";
```

> **Nota:** Las consultas raw no pasan por el Identity Map ni por el UnitOfWork. Las entidades cargadas previamente no se actualizan automáticamente.

## Gestión del Identity Map

### clear()

Limpia el Identity Map y desasocia todas las entidades gestionadas. Útil en procesos batch para liberar memoria:

```php
// Limpiar todas las entidades
$em->clear();

// Limpiar solo entidades de una clase específica
$em->clear(Usuario::class);
```

### detach()

Desasocia una entidad específica del contexto de persistencia. Los cambios posteriores no se rastrean:

```php
$em->detach($usuario);
$usuario->nombre = 'Nuevo'; // Este cambio NO se persistirá en flush()
```

### merge()

Re-asocia una entidad desasociada (detached) al EntityManager. Retorna la instancia gestionada:

```php
$usuarioGestionado = $em->merge($usuarioDetached);
```

### refresh()

Recarga una entidad desde la base de datos, descartando cambios en memoria:

```php
$usuario->nombre = 'Temporal';
$em->refresh($usuario);
echo $usuario->nombre; // Valor original de la DB
```

### contains() / isManaged()

Verifica si una entidad está siendo gestionada por el UnitOfWork:

```php
if ($em->isManaged($usuario)) {
    // La entidad está en el Identity Map
}

// Alias compatible con Doctrine
if ($em->contains($usuario)) {
    // ...
}
```

## Modo Read-Only

Las conexiones pueden configurarse como solo lectura, bloqueando operaciones de escritura:

```php
$config = [
    'host' => 'replica.ejemplo.com',
    'dbname' => 'mi_db',
    'username' => 'lector',
    'password' => 'secret',
    'read_only' => true,
];
```

En modo read-only:
- `executeStatement()` lanza una excepción
- `beginTransaction()` es rechazado
- Solo se permiten `executeQuery()` y consultas SELECT

Útil para conexiones a réplicas de lectura:

```php
use SybaseORM\ORM\EntityManagerRegistry;

$registry = new EntityManagerRegistry([
    'default' => $configEscritura,
    'replica' => $configLectura, // read_only: true
]);

// Lectura desde réplica
$emReplica = $registry->getManager('replica');
$usuarios = $emReplica->query('SELECT e FROM Usuario e');

// Escritura en conexión principal
$emDefault = $registry->getManager('default');
$emDefault->persist($nuevoUsuario);
$emDefault->flush();
```

### Verificar modo read-only

```php
if ($em->getConnection()->isReadOnly()) {
    // No intentar escrituras
}
```

## Caché LRU de Sentencias Preparadas

El `ConnectionManager` mantiene un caché LRU (Least Recently Used) de sentencias preparadas para evitar re-prepararlas en cada ejecución.

**Comportamiento:**
- Las sentencias se cachean automáticamente al ejecutarse por primera vez
- Cuando el caché alcanza su tamaño máximo, se evicta la sentencia menos usada recientemente
- Al reconectarse, el caché se limpia automáticamente

**Beneficios:**
- Reduce llamadas a `PDO::prepare()` para consultas repetidas
- Mejora el rendimiento en loops y operaciones batch
- Transparente para el usuario: no requiere configuración

```php
// Estas consultas repetidas usan sentencias cacheadas automáticamente
foreach ($ids as $id) {
    $stmt = $connection->executeQuery('SELECT * FROM usuarios WHERE id = ?', [$id]);
    // La segunda iteración reutiliza el statement preparado
}
```

## Reconexión Automática

### ping()

Verifica si la conexión está activa ejecutando `SELECT 1`:

```php
$connection = $em->getConnection();

if (!$connection->ping()) {
    $connection->reconnect();
}
```

### reconnect()

Fuerza una reconexión cerrando la conexión actual. La siguiente operación establece una nueva conexión:

```php
$connection->reconnect();
```

**Efectos de `reconnect()`:**
- Cierra la conexión PDO actual
- Limpia el caché de sentencias preparadas
- Resetea el estado de transacción
- Limpia la pila de savepoints

### Patrón de reconexión en producción

```php
function ejecutarConReconexion(ConnectionManagerInterface $conn, callable $operation): mixed
{
    try {
        return $operation($conn);
    } catch (ConnectionLostException $e) {
        $conn->reconnect();
        return $operation($conn); // Reintentar una vez
    }
}
```

## Generación de Proxies

El ORM genera clases proxy para implementar lazy loading en relaciones.

### ProxyGenerator

Genera clases proxy dinámicamente en un directorio configurable:

```php
$config = [
    'host' => 'localhost',
    'dbname' => 'mi_db',
    'username' => 'user',
    'password' => 'pass',
    'proxy_directory' => '/var/cache/orm/proxies',
];
```

### Comportamiento de los proxies

Los proxies implementan `LazyLoadingProxy` y se cargan en el primer acceso a una propiedad:

```php
$orden = $em->find(Orden::class, 1);

// $orden->cliente es un proxy (no cargado aún)
echo $orden->cliente->nombre; // Aquí se ejecuta el SELECT de Cliente
```

**Interfaz `LazyLoadingProxy`:**
- `__isInitialized()`: Indica si el proxy ya cargó la entidad real
- `__initialize()`: Fuerza la carga del proxy
- `__setInitializer()`: Define el closure de inicialización
- `__getInitializer()`: Obtiene el closure actual

### Pre-generación de proxies

Para producción, se recomienda pre-generar los proxies para evitar overhead en runtime:

```php
use SybaseORM\Proxy\ProxyGenerator;

$generator = new ProxyGenerator('/var/cache/orm/proxies');

// Generar proxy para una entidad
$proxyFile = $generator->generateProxyClass(Cliente::class);

// Obtener el nombre de la clase proxy
$proxyClassName = $generator->getProxyClassName(Cliente::class);
```

## Información del Servidor

```php
$connection = $em->getConnection();

echo $connection->getServerVersion();  // Versión de Sybase ASE
echo $connection->getDatabaseName();   // Nombre de la BD
echo $connection->getHost();           // Host configurado
echo $connection->getPort();           // Puerto (default: 5000)
```

---

← [Anterior](./manejo-errores.md) | [Índice](./README.md)
