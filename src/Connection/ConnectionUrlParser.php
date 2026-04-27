<?php

declare(strict_types=1);

namespace SybaseORM\Connection;

/**
 * Parsea una URL de conexión estilo DSN en un array de configuración.
 *
 * Formato soportado:
 *   sybase://usuario:password@host:puerto/base_de_datos?charset=UTF-8&persistent=true
 *
 * Ejemplos:
 *   sybase://sa:secret@192.168.1.100:5000/mi_base?charset=UTF-8
 *   sybase://admin@db.example.com/production
 *   sybase://sa:p%40ss@localhost:5000/testdb  (password con caracteres especiales URL-encoded)
 *
 * @see https://www.php.net/manual/en/function.parse-url.php
 */
final class ConnectionUrlParser
{
    /**
     * Parsea una URL de conexión y retorna un array de configuración
     * compatible con ConnectionManager.
     *
     * @param string $url URL de conexión (e.g. "sybase://sa:secret@host:5000/mydb?charset=UTF-8")
     * @return array{host: string, port: int, dbname: string, username: string, password: string, charset: string, persistent: bool}
     *
     * @throws \InvalidArgumentException Si la URL no es válida o no tiene el formato esperado.
     */
    public static function parse(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new \InvalidArgumentException(sprintf(
                'URL de conexión inválida: "%s".',
                $url,
            ));
        }

        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== '' && $scheme !== 'sybase' && $scheme !== 'dblib') {
            throw new \InvalidArgumentException(sprintf(
                'Esquema de URL no soportado: "%s". Use "sybase://" o "dblib://".',
                $scheme,
            ));
        }

        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5000;
        $username = isset($parts['user']) ? urldecode($parts['user']) : '';
        $password = isset($parts['pass']) ? urldecode($parts['pass']) : '';

        // El path contiene el nombre de la base de datos (e.g. "/mi_base" → "mi_base")
        $dbname = '';
        if (isset($parts['path'])) {
            $dbname = ltrim($parts['path'], '/');
        }

        if ($dbname === '') {
            throw new \InvalidArgumentException(
                'La URL de conexión debe incluir el nombre de la base de datos (e.g. sybase://user:pass@host/database).',
            );
        }

        // Parsear query string para opciones adicionales (charset, persistent)
        $queryParams = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParams);
        }

        $charset = $queryParams['charset'] ?? 'UTF-8';
        $persistent = filter_var($queryParams['persistent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $charsetConversion = filter_var($queryParams['charset_conversion'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'host' => $host,
            'port' => (int) $port,
            'dbname' => $dbname,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
            'persistent' => $persistent,
            'charset_conversion' => $charsetConversion,
        ];
    }
}
