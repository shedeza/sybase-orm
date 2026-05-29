# Guía de desarrollo

## Preparar el entorno

```bash
composer install
```

## Ejecutar pruebas

```bash
# Ejecutar todos los tests
composer test

# Ejecutar tests de un archivo específico
composer test tests/Connection/ConnectionManagerTest.php

# Ejecutar tests con más verbosidad
composer test -- --verbose
```

## Análisis de código

```bash
# Ejecutar análisis estático
composer analyse

# Verificar estilo de código
composer check-style

# Arreglar automáticamente el estilo
composer fix
```

## Estructura del proyecto

```
src/
  Attribute/       - Atributos PHP para mapeo de entidades
  Cache/          - Gestión de caché de primer y segundo nivel
  Collection/     - Colecciones persistentes
  Command/        - Comandos de consola Symfony
  Connection/     - Gestión de conexiones PDO a Sybase ASE
  DependencyInjection/  - Configuración del bundle Symfony
  Dialect/        - Generación SQL específica de Sybase ASE
  Exception/      - Excepciones personalizadas
  Hook/           - Hooks de ciclo de vida de entidades
  Hydrator/       - Conversión de resultados SQL a objetos
  Metadata/       - Introspección de entidades
  Migration/      - Gestión de migraciones
  ORM/           - Núcleo del ORM (EntityManager, UnitOfWork, etc.)
  Proxy/         - Carga lazy de entidades
  Query/         - Lenguaje OQL y construcción de consultas
  Type/          - Conversión de tipos SQL ↔ PHP

tests/
  [misma estructura que src/]   - Pruebas unitarias
  fixtures/                     - Datos de prueba
```

## Antes de hacer commit

1. Ejecutar tests: `composer test`
2. Ejecutar análisis: `composer analyse`
3. Verificar estilo: `composer check-style`
4. Si hay problemas de estilo: `composer fix`

## Hacer un PR

1. Crear rama desde `main` o `develop`
2. Hacer cambios y commits
3. Push a la rama
4. Abrir PR con descripción clara
5. CI corre automáticamente (tests + análisis)
6. Una vez aprobado, mergear a `develop` o `main`

## Estructura de commits

- Mensajes claros y descriptivos
- Referencia a issues si corresponde (ej: `Fixes #123`)
- Incluir cambios relacionados en un commit

## Agregar nuevas funcionalidades

1. Escribir tests primero (TDD)
2. Implementar funcionalidad
3. Ejecutar `composer test`
4. Ejecutar `composer analyse`
5. Ejecutar `composer fix`
6. Hacer commit

## Documentación

- Las docstrings en código deben ser claras y en inglés
- Actualizar `CHANGELOG.md` al hacer cambios relevantes
- Actualizar `README.md` si hay cambios en configuración o API pública
