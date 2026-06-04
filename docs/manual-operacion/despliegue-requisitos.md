# Despliegue y Requisitos

Guía completa de requisitos de entorno y procedimientos de instalación para desplegar `shedeza/sybase-orm` en entornos de desarrollo, staging y producción.

## Requisitos del Sistema

### PHP

| Requisito | Valor |
|-----------|-------|
| Versión mínima | PHP 8.1 |
| Extensión obligatoria | `ext-pdo_dblib` |
| Dependencia runtime | `psr/log` ^2.0 o ^3.0 |

### Extensiones PHP Necesarias

| Extensión | Propósito | Obligatoria |
|-----------|-----------|:-----------:|
| `pdo_dblib` | Conexión PDO a Sybase ASE via FreeTDS | ✅ |
| `pdo` | Capa base de PDO (normalmente incluida) | ✅ |
| `redis` | Caché de segundo nivel con Redis | Opcional |
| `mbstring` | Conversión de charset UTF-8 ↔ ISO-8859-1 | Recomendada |

La extensión `pdo_dblib` utiliza FreeTDS como driver subyacente para comunicarse con Sybase ASE. Debe estar compilada y habilitada en el entorno PHP.

### Verificar extensiones instaladas

```bash
php -m | grep -i pdo
# Debe mostrar:
# PDO
# pdo_dblib
```

```bash
php -i | grep -i freetds
# Verifica la versión de FreeTDS vinculada
```

## Configuración de php.ini

### Directivas relevantes

```ini
; Extensión PDO dblib (verificar que esté habilitada)
extension=pdo_dblib

; Memoria — ajustar según volumen de entidades gestionadas
memory_limit = 256M

; Tiempo de ejecución — ajustar para migraciones pesadas
max_execution_time = 300

; PDO configuración general
pdo_dblib.charset = "UTF-8"
```

### Directivas de FreeTDS (freetds.conf)

FreeTDS se configura en `/etc/freetds/freetds.conf` (Linux) o la ruta definida por la variable `FREETDS_CONF`:

```ini
[global]
    tds version = 5.0
    client charset = UTF-8
    text size = 64512

[sybase_server]
    host = 192.168.1.100
    port = 5000
    tds version = 5.0
```

> **Nota:** La versión TDS 5.0 es la recomendada para Sybase ASE. Versiones anteriores pueden causar problemas con tipos de datos o charset.

## Variables de Entorno Recomendadas

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `SYBASE_ORM_HOST` | Host del servidor Sybase ASE | `192.168.1.100` |
| `SYBASE_ORM_PORT` | Puerto del servidor | `5000` |
| `SYBASE_ORM_DBNAME` | Nombre de la base de datos | `mi_base` |
| `SYBASE_ORM_USERNAME` | Usuario de conexión | `sa` |
| `SYBASE_ORM_PASSWORD` | Contraseña de conexión | `secret` |
| `SYBASE_ORM_CHARSET` | Charset de conexión | `UTF-8` |
| `FREETDS_CONF` | Ruta al archivo freetds.conf | `/etc/freetds/freetds.conf` |
| `REDIS_HOST` | Host de Redis (si se usa caché L2) | `127.0.0.1` |
| `REDIS_PORT` | Puerto de Redis | `6379` |

### Uso en configuración del ORM

```php
use SybaseORM\ORM\OrmFactory;

$config = [
    'host'     => getenv('SYBASE_ORM_HOST'),
    'port'     => (int) getenv('SYBASE_ORM_PORT'),
    'dbname'   => getenv('SYBASE_ORM_DBNAME'),
    'username' => getenv('SYBASE_ORM_USERNAME'),
    'password' => getenv('SYBASE_ORM_PASSWORD'),
    'charset'  => getenv('SYBASE_ORM_CHARSET') ?: 'UTF-8',
];

$em = OrmFactory::create($config);
```

## Instalación via Composer

```bash
composer require shedeza/sybase-orm
```

### Dependencias de desarrollo (opcionales)

```bash
composer require --dev phpunit/phpunit:^10.0
composer require --dev phpstan/phpstan:^1.10
composer require --dev friendsofphp/php-cs-fixer:^3.50
```

## Instalación por Entorno

### Debian / Ubuntu

```bash
# 1. Instalar FreeTDS y las librerías de desarrollo
sudo apt-get update
sudo apt-get install -y freetds-dev freetds-bin

# 2. Instalar la extensión pdo_dblib para PHP
sudo apt-get install -y php8.1-sybase
# O para PHP 8.2/8.3:
# sudo apt-get install -y php8.2-sybase
# sudo apt-get install -y php8.3-sybase

# 3. Verificar la instalación
php -m | grep pdo_dblib

# 4. (Opcional) Instalar extensión Redis
sudo apt-get install -y php8.1-redis

# 5. Reiniciar PHP-FPM si aplica
sudo systemctl restart php8.1-fpm
```

#### Configurar FreeTDS

```bash
sudo nano /etc/freetds/freetds.conf
```

Agregar la entrada del servidor:

```ini
[mi_servidor_sybase]
    host = 192.168.1.100
    port = 5000
    tds version = 5.0
```

Probar la conexión:

```bash
tsql -S mi_servidor_sybase -U sa -P secret
```

### Alpine Linux (Docker)

```dockerfile
FROM php:8.1-fpm-alpine

# Instalar FreeTDS y dependencias de compilación
RUN apk add --no-cache \
    freetds \
    freetds-dev \
    unixodbc-dev

# Compilar e instalar pdo_dblib
RUN docker-php-ext-install pdo_dblib

# (Opcional) Instalar extensión Redis
RUN apk add --no-cache autoconf g++ make \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make

# Copiar configuración de FreeTDS
COPY freetds.conf /etc/freetds/freetds.conf

# Verificar
RUN php -m | grep pdo_dblib
```

#### docker-compose.yml (ejemplo)

```yaml
services:
  app:
    build: .
    environment:
      SYBASE_ORM_HOST: sybase
      SYBASE_ORM_PORT: "5000"
      SYBASE_ORM_DBNAME: mi_base
      SYBASE_ORM_USERNAME: sa
      SYBASE_ORM_PASSWORD: secret
      FREETDS_CONF: /etc/freetds/freetds.conf
    volumes:
      - ./freetds.conf:/etc/freetds/freetds.conf:ro
```

### CentOS / RHEL / Rocky Linux

```bash
# 1. Instalar FreeTDS
sudo dnf install -y freetds freetds-devel

# 2. Instalar PHP y la extensión pdo_dblib
sudo dnf install -y php-pdo php-mssql
# En algunos repos el paquete se llama php-sybase o php-pdo_dblib

# 3. Si no está disponible como paquete, compilar manualmente:
sudo dnf install -y php-devel
cd /tmp
pecl install pdo_dblib
echo "extension=pdo_dblib.so" | sudo tee /etc/php.d/30-pdo_dblib.ini

# 4. Verificar
php -m | grep pdo_dblib

# 5. (Opcional) Instalar Redis
sudo dnf install -y php-redis

# 6. Reiniciar PHP-FPM
sudo systemctl restart php-fpm
```

## Verificación Post-Instalación

Ejecutar el siguiente script para validar que el entorno está correctamente configurado:

```php
<?php
// verify-environment.php

$checks = [];

// PHP version
$checks['PHP >= 8.1'] = version_compare(PHP_VERSION, '8.1.0', '>=');

// pdo_dblib extension
$checks['ext-pdo_dblib'] = extension_loaded('pdo_dblib');

// PDO drivers
$checks['PDO driver dblib'] = in_array('dblib', \PDO::getAvailableDrivers());

// Optional: Redis
$checks['ext-redis (opcional)'] = extension_loaded('redis');

// Optional: mbstring
$checks['ext-mbstring (recomendada)'] = extension_loaded('mbstring');

echo "=== Verificación de Entorno para Sybase ORM ===\n\n";
foreach ($checks as $name => $result) {
    $status = $result ? '✅ OK' : '❌ FALTA';
    echo sprintf("  %s  %s\n", $status, $name);
}

$required = ['PHP >= 8.1', 'ext-pdo_dblib', 'PDO driver dblib'];
$allOk = true;
foreach ($required as $req) {
    if (!$checks[$req]) {
        $allOk = false;
    }
}

echo "\n" . ($allOk ? '✅ Entorno listo para Sybase ORM' : '❌ Faltan requisitos obligatorios') . "\n";
```

```bash
php verify-environment.php
```

## Resolución de Problemas Comunes en la Instalación

| Problema | Causa probable | Solución |
|----------|---------------|----------|
| `PDO driver [dblib] not found` | Extensión pdo_dblib no instalada | Instalar el paquete php-sybase o compilar pdo_dblib |
| `Unable to connect: Adaptive Server is unavailable` | FreeTDS no puede alcanzar el servidor | Verificar host/port en freetds.conf y conectividad de red |
| `Charset conversion error` | Charset mal configurado en FreeTDS | Ajustar `client charset` en freetds.conf |
| `Login failed for user` | Credenciales incorrectas o permisos | Verificar usuario/contraseña y permisos en Sybase ASE |
| `TDS version error` | Versión TDS incompatible | Usar `tds version = 5.0` en freetds.conf |

---

[Índice](./README.md) | [Siguiente →](./configuracion-entornos.md)
