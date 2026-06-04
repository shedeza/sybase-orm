# Extensión: Funciones OQL Personalizadas

El ORM permite registrar funciones SQL personalizadas que se invocan desde consultas OQL. Esto permite usar funciones específicas de Sybase ASE sin escribir SQL raw.

## Mecanismo de Registro

### registerOqlFunction()

Las funciones se registran en el EntityManager mediante `registerOqlFunction()`:

```php
public function registerOqlFunction(string $name, string $sqlTemplate): void
```

**Parámetros:**
- `$name`: Nombre de la función como se usará en OQL (case-insensitive en la consulta)
- `$sqlTemplate`: Template SQL que se insertará en la consulta generada. Usa `%s` como placeholder para los argumentos

```php
// Registrar funciones
$em->registerOqlFunction('YEAR', 'DATEPART(yy, %s)');
$em->registerOqlFunction('MONTH', 'DATEPART(mm, %s)');
$em->registerOqlFunction('UPPER', 'UPPER(%s)');
$em->registerOqlFunction('LOWER', 'LOWER(%s)');
$em->registerOqlFunction('LEN', 'LEN(%s)');
$em->registerOqlFunction('RAND2', 'RAND2()');
```

## Uso en Consultas OQL

Una vez registrada, la función se usa directamente en OQL:

```php
// Filtrar por año
$ordenes = $em->query(
    'SELECT e FROM Orden e WHERE YEAR(e.fecha) = :anio',
    ['anio' => 2024]
);

// Ordenar por longitud de nombre
$usuarios = $em->query(
    'SELECT e FROM Usuario e ORDER BY LEN(e.nombre) DESC'
);

// Usar en condiciones
$resultados = $em->query(
    'SELECT e FROM Producto e WHERE UPPER(e.codigo) = :codigo',
    ['codigo' => 'ABC-123']
);
```

## Cómo Funciona Internamente

El flujo de una función personalizada en el ORM:

1. **Registro**: `registerOqlFunction()` almacena el mapping `nombre → sqlTemplate`
2. **Parsing**: El `OqlParser` detecta la llamada a función y crea un nodo `CustomFunctionCall` en el AST
3. **Traducción**: El `OqlToSqlTranslator` busca el template registrado y reemplaza `%s` con los argumentos traducidos
4. **Ejecución**: El SQL resultante se envía al `ConnectionManager`

```
OQL: SELECT e FROM Orden e WHERE YEAR(e.fecha) = :anio
                                      ↓
AST: CustomFunctionCall(name='YEAR', args=[PropertyAccess('e.fecha')])
                                      ↓
SQL: SELECT ... FROM ordenes WHERE DATEPART(yy, ordenes.fecha) = ?
```

## Nodo AST: CustomFunctionCall

Cuando el parser encuentra una función registrada, crea un nodo `CustomFunctionCall`:

```php
namespace SybaseORM\Query\AST;

class CustomFunctionCall
{
    public function __construct(
        public readonly string $functionName,  // Nombre registrado
        public readonly array $arguments,      // Nodos AST de los argumentos
    ) {}
}
```

## Ejemplo Completo: Funciones de Fecha

### Registro

```php
// Funciones de fecha para Sybase ASE
$em->registerOqlFunction('YEAR', 'DATEPART(yy, %s)');
$em->registerOqlFunction('MONTH', 'DATEPART(mm, %s)');
$em->registerOqlFunction('DAY', 'DATEPART(dd, %s)');
$em->registerOqlFunction('DATEDIFF_DAYS', 'DATEDIFF(dd, %s, %s)');
```

### Consultas

```php
// Ordenes del mes actual
$ordenes = $em->query(
    'SELECT e FROM Orden e WHERE YEAR(e.fecha) = :anio AND MONTH(e.fecha) = :mes',
    ['anio' => 2024, 'mes' => 6]
);

// Con QueryBuilder
$qb = $em->createQueryBuilder(Orden::class)
    ->where('YEAR(e.fecha) = :anio')
    ->andWhere('MONTH(e.fecha) = :mes')
    ->setParameter('anio', 2024)
    ->setParameter('mes', 6);

$resultados = $qb->getResult();
```

## Ejemplo Completo: Funciones de Texto

### Registro

```php
$em->registerOqlFunction('UPPER', 'UPPER(%s)');
$em->registerOqlFunction('LOWER', 'LOWER(%s)');
$em->registerOqlFunction('LTRIM', 'LTRIM(%s)');
$em->registerOqlFunction('RTRIM', 'RTRIM(%s)');
$em->registerOqlFunction('SUBSTRING', 'SUBSTRING(%s, %s, %s)');
$em->registerOqlFunction('CHARINDEX', 'CHARINDEX(%s, %s)');
```

### Consultas

```php
// Búsqueda case-insensitive
$usuarios = $em->query(
    'SELECT e FROM Usuario e WHERE UPPER(e.email) = UPPER(:email)',
    ['email' => 'Admin@Ejemplo.com']
);

// Filtrar por substring
$resultados = $em->query(
    'SELECT e FROM Producto e WHERE CHARINDEX(:termino, e.descripcion) > 0',
    ['termino' => 'premium']
);
```

## Ejemplo Completo: Función de Conversión

### Registro

```php
// Conversión de tipos en Sybase ASE
$em->registerOqlFunction('TO_INT', 'CONVERT(INT, %s)');
$em->registerOqlFunction('TO_VARCHAR', 'CONVERT(VARCHAR(255), %s)');
$em->registerOqlFunction('TO_MONEY', 'CONVERT(MONEY, %s)');
```

### Uso

```php
$resultados = $em->query(
    'SELECT e FROM Venta e WHERE TO_INT(e.codigoExterno) > :min',
    ['min' => 1000]
);
```

## Ejemplo: Función Sin Argumentos

Para funciones que no reciben parámetros:

```php
$em->registerOqlFunction('AHORA', 'GETDATE()');
$em->registerOqlFunction('NEWID', 'NEWID()');

// Uso
$recientes = $em->query(
    'SELECT e FROM Tarea e WHERE e.vencimiento < AHORA()'
);
```

## Funciones con Múltiples Argumentos

El template `%s` se reemplaza en orden con cada argumento:

```php
$em->registerOqlFunction('DATEDIFF_DAYS', 'DATEDIFF(dd, %s, %s)');

// Uso: primer argumento = fecha inicio, segundo = fecha fin
$tareas = $em->query(
    'SELECT e FROM Tarea e WHERE DATEDIFF_DAYS(e.fechaInicio, e.fechaFin) > :dias',
    ['dias' => 30]
);
```

## Consideraciones

- **Nombres únicos**: No registrar funciones con el mismo nombre que funciones OQL built-in (COUNT, SUM, AVG, etc.)
- **SQL injection**: Los templates son estáticos; los valores de usuario se pasan como parámetros named, no como parte del template
- **Portabilidad**: Las funciones registradas son específicas de Sybase ASE. Si migras a otro motor, deberás actualizar los templates
- **Scope**: Las funciones se registran por instancia de EntityManager. Si usas múltiples EntityManagers, registra en cada uno
- **Validación**: No se valida la sintaxis SQL del template al registrar; errores se detectan al ejecutar la consulta

## Patrón: Configuración Centralizada

```php
class OqlFunctionsConfigurator
{
    public static function registrar(EntityManagerInterface $em): void
    {
        // Fecha
        $em->registerOqlFunction('YEAR', 'DATEPART(yy, %s)');
        $em->registerOqlFunction('MONTH', 'DATEPART(mm, %s)');
        $em->registerOqlFunction('DAY', 'DATEPART(dd, %s)');

        // Texto
        $em->registerOqlFunction('UPPER', 'UPPER(%s)');
        $em->registerOqlFunction('LOWER', 'LOWER(%s)');
        $em->registerOqlFunction('LEN', 'LEN(%s)');

        // Conversión
        $em->registerOqlFunction('TO_INT', 'CONVERT(INT, %s)');
        $em->registerOqlFunction('TO_DATE', 'CONVERT(DATETIME, %s)');
    }
}

// Al inicializar la aplicación
$em = OrmFactory::create($config);
OqlFunctionsConfigurator::registrar($em);
```

---

← [Anterior](./extension-event-subscribers.md) | [Índice](./README.md) | [Siguiente →](./flujo-persistencia.md)
