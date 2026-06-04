# Manual de Operación — Sybase ORM

Guía completa para el despliegue, configuración, optimización y operación del ORM en entornos productivos. Este manual está orientado a administradores de sistemas, equipos DevOps y desarrolladores responsables de la operación en producción.

## Tabla de Contenidos

| # | Sección | Descripción |
|---|---------|-------------|
| 1 | [Despliegue y Requisitos](./despliegue-requisitos.md) | Extensiones PHP necesarias, configuración de php.ini y variables de entorno para el despliegue del ORM |
| 2 | [Configuración de Entornos](./configuracion-entornos.md) | Diferencias de configuración entre desarrollo, staging y producción en caché, logging y conexiones |
| 3 | [Optimización de Conexiones](./optimizacion-conexiones.md) | Connection pooling, conexiones persistentes, límites de conexión y estrategias de reciclado |
| 4 | [Caché en Producción](./cache-produccion.md) | Configuración y tuning de Redis para caché de segundo nivel: TTL, evicción y monitoreo de hit-rate |
| 5 | [Optimización de Consultas](./optimizacion-consultas.md) | Uso de queryCached(), identificación de N+1, eager loading y caché LRU de sentencias preparadas |
| 6 | [Logging y Monitoreo](./logging-monitoreo.md) | Integración con PSR-3, métricas de rendimiento del pool de conexiones y alertas recomendadas |
| 7 | [Troubleshooting](./troubleshooting.md) | Diagnóstico y solución de problemas comunes: conexiones perdidas, deadlocks, timeouts, memoria y charset |
| 8 | [Retry y Reconexión](./retry-reconexion.md) | Patrones de retry, reconexión automática y configuración recomendada para producción |
| 9 | [Migraciones en Producción](./migraciones-produccion.md) | Estrategias de rollback, preview, verificación post-migración y manejo de migraciones fallidas |
| 10 | [Mantenimiento de Soft Delete](./mantenimiento-soft-delete.md) | Limpieza programada de registros eliminados, impacto en rendimiento y queries de auditoría |
| 11 | [Backup y Restauración](./backup-restauracion.md) | Consideraciones de caché, Identity Map y transacciones durante procedimientos de backup |

## Cómo usar este manual

- Si estás **desplegando por primera vez**, comienza por [Despliegue y Requisitos](./despliegue-requisitos.md) y [Configuración de Entornos](./configuracion-entornos.md).
- Si necesitas **optimizar el rendimiento**, consulta las secciones de [Optimización de Conexiones](./optimizacion-conexiones.md), [Caché en Producción](./cache-produccion.md) y [Optimización de Consultas](./optimizacion-consultas.md).
- Si estás **resolviendo un problema**, ve directamente a [Troubleshooting](./troubleshooting.md) o [Retry y Reconexión](./retry-reconexion.md).
- Para **operaciones planificadas**, consulta [Migraciones en Producción](./migraciones-produccion.md), [Mantenimiento de Soft Delete](./mantenimiento-soft-delete.md) y [Backup y Restauración](./backup-restauracion.md).

## Documentación relacionada

- [Manual de Usuario](../usuario-manual/README.md) — Uso detallado de cada módulo del ORM
- [Manual Técnico](../manual-tecnico/README.md) — Arquitectura interna, patrones de diseño y extensibilidad
- [README del Proyecto](../../README.md) — Introducción y configuración rápida
