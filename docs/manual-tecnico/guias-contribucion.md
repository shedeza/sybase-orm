# Guías de Contribución

Guía para contribuir al proyecto `shedeza/sybase-orm`. Cubre estándares de código, estructura de tests, proceso de PRs y convenciones de commits basados en la configuración real del proyecto.

## Requisitos Previos

- PHP 8.1 o superior
- Extensiones: `pdo_dblib`, `pdo`, `json`
- Composer v2
- Git

```bash
# Clonar e instalar dependencias
git clone <repo-url>
cd sybase-orm
composer install
```

## Estándares de Código

### PHP-CS-Fixer

El proyecto usa PHP-CS-Fixer con el preset `@auto`. La configuración se encuentra en `.php-cs-fixer.dist.php`:

```php
return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@auto' => true
    ])
    ->setFinder(
        (new Finder())->in(__DIR__)
    );
```

**Comandos:**

```bash
# Verificar estilo (sin modificar archivos)
vendor/bin/php-cs-fixer fix --dry-run --diff

# Corregir estilo automáticamente
vendor/bin/php-cs-fixer fix
```

El CI ejecuta `--dry-run --diff` y falla si hay diferencias. Ejecuta el fixer antes de hacer commit.

### PHPStan (Análisis Estático)

El proyecto usa PHPStan nivel 5. Configuración en `phpstan.neon`:

```yaml
parameters:
    level: 5
    paths:
        - src
    ignoreErrors:
        - '#PDOException#'
        - '#SplObjectStorage#'
        - identifier: missingType.iterableValue
    reportUnmatchedIgnoredErrors: false

rules:
    - SybaseORM\PHPStan\NoSymfonyImportsRule
```

**Comandos:**

```bash
# Ejecutar análisis estático
vendor/bin/phpstan analyse

# Con baseline (errores existentes ignorados)
vendor/bin/phpstan analyse --generate-baseline
```

**Regla personalizada `NoSymfonyImportsRule`:** El proyecto prohíbe imports directos de `Symfony\` en `src/`. Esta regla se ejecuta como parte del análisis y también se verifica con un guard en CI.

### Prohibición de Dependencias Symfony

El CI incluye un guard explícito:

```bash
if grep -rn 'use Symfony\\' src/; then
    echo "ERROR: Symfony namespace detected in ORM library"
    exit 1
fi
```

Si necesitas integración con Symfony, usa las interfaces del proyecto (ej: `SymfonyEventDispatcherSubscriber`) sin importar namespaces de Symfony directamente en `src/`.

## Estructura de Tests

### PHPUnit

Configuración en `phpunit.xml`:

```xml
<phpunit bootstrap="vendor/autoload.php"
         colors="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

**Directorio:** Todos los tests se ubican en `tests/`.

**Comandos:**

```bash
# Ejecutar toda la suite
vendor/bin/phpunit

# Ejecutar un test específico
vendor/bin/phpunit tests/NombreDelTest.php

# Con filtro de método
vendor/bin/phpunit --filter testNombreMetodo

# Con cobertura (requiere Xdebug/PCOV)
vendor/bin/phpunit --coverage-text
```

### Convenciones de Tests

- Un archivo de test por clase: `tests/NombreClassTest.php`
- Nombre de clase: `NombreClassTest extends TestCase`
- Métodos de test: `public function testDescripcionDelComportamiento(): void`
- Usar assertions claras y descriptivas
- Evitar mocks cuando sea posible; preferir implementaciones in-memory

```php
<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use SybaseORM\Connection\ConnectionUrlParser;

final class ConnectionUrlParserTest extends TestCase
{
    public function testParseValidDsn(): void
    {
        $parser = new ConnectionUrlParser();
        $config = $parser->parse('sybase://user:pass@host:5000/dbname');

        $this->assertSame('host', $config['host']);
        $this->assertSame(5000, $config['port']);
        $this->assertSame('dbname', $config['dbname']);
    }
}
```

## Pipeline de CI

El workflow de GitHub Actions (`.github/workflows/ci.yml`) se ejecuta en:

- **Ramas:** `main`, `develop`
- **Eventos:** push y pull request
- **Matriz PHP:** 8.1, 8.2, 8.3, 8.4

### Pasos del Pipeline

| Paso | Comando | Descripción |
|------|---------|-------------|
| Install | `composer install --no-interaction --prefer-dist` | Instala dependencias |
| PHPUnit | `vendor/bin/phpunit` | Ejecuta tests unitarios |
| PHPStan | `vendor/bin/phpstan analyse` | Análisis estático nivel 5 |
| PHP-CS-Fixer | `vendor/bin/php-cs-fixer fix --dry-run --diff` | Verifica estilo de código |
| Symfony guard | `grep -rn 'use Symfony\\' src/` | Prohíbe imports de Symfony |

Todos los pasos deben pasar para que un PR sea aprobado.

## Ejecución Local Completa

Antes de abrir un PR, ejecuta la suite completa localmente:

```bash
# 1. Tests
vendor/bin/phpunit

# 2. Análisis estático
vendor/bin/phpstan analyse

# 3. Estilo de código (corregir)
vendor/bin/php-cs-fixer fix

# 4. Verificar que no hay imports de Symfony
grep -rn 'use Symfony\\' src/ && echo "FALLO" || echo "OK"
```

## Proceso de Pull Requests

### Requisitos para PRs

1. **Todos los checks de CI pasan** (PHPUnit, PHPStan, PHP-CS-Fixer, Symfony guard)
2. **Tests incluidos** para funcionalidad nueva o correcciones de bugs
3. **Descripción clara** del cambio y su motivación
4. **Un PR = un cambio lógico** (evitar PRs gigantes con múltiples features)
5. **Base branch:** PRs de feature van a `develop`, hotfixes a `main`

### Checklist del PR

- [ ] Tests pasan localmente (`vendor/bin/phpunit`)
- [ ] PHPStan no reporta errores nuevos
- [ ] Código formateado con PHP-CS-Fixer
- [ ] No hay imports de `Symfony\` en `src/`
- [ ] Documentación actualizada si la API pública cambia

## Convenciones de Commits

El proyecto sigue un formato de commits convencional:

```
tipo(alcance): descripción breve

Cuerpo opcional con más contexto.
```

### Tipos

| Tipo | Uso |
|------|-----|
| `feat` | Nueva funcionalidad |
| `fix` | Corrección de bug |
| `docs` | Solo documentación |
| `style` | Formato (no afecta lógica) |
| `refactor` | Refactorización sin cambio funcional |
| `test` | Agregar o corregir tests |
| `chore` | Mantenimiento (CI, dependencias) |

### Ejemplos

```
feat(query): agregar soporte para HAVING en QueryBuilder
fix(connection): manejar reconexión en transacciones activas
docs(readme): actualizar ejemplo de configuración DSN
test(metadata): agregar test para embeddables anidados
chore(ci): agregar PHP 8.4 a la matriz de CI
```

### Reglas

- Primera línea máximo 72 caracteres
- Usar imperativo: "agregar" no "agregado" ni "agrega"
- No terminar la primera línea con punto
- Separar cuerpo de la primera línea con línea en blanco

---

← [Anterior](./diagramas-clases.md) | [Índice](./README.md)
