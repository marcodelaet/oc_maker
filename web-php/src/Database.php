<?php

declare(strict_types=1);

namespace OcMaker;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;
    private static ?string $resolvedHost = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        if (dbSocket() !== '') {
            try {
                self::$pdo = self::open('');
                self::$resolvedHost = 'socket:' . dbSocket();

                return self::$pdo;
            } catch (PDOException $e) {
                throw new \RuntimeException(
                    'Falha na conexão via socket (' . dbSocket() . '): ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $lastError = null;
        foreach (self::hostCandidates() as $host) {
            try {
                self::$pdo = self::open($host);
                self::$resolvedHost = $host;

                return self::$pdo;
            } catch (PDOException $e) {
                $lastError = $e;
            }
        }

        $attempted = implode(', ', self::hostCandidates());
        $hint = isDevEnvironment()
            ? ' Em desenvolvimento com Apache em Docker/Linux, tente DB_HOST=host.docker.internal no .env.'
            : ' Configure DB_HOST no .env com o host MySQL do servidor.';
        throw new \RuntimeException(
            'Falha na conexão com o banco (tentativas: ' . $attempted . '): '
            . ($lastError?->getMessage() ?? 'erro desconhecido')
            . '.' . $hint,
            0,
            $lastError
        );
    }

    public static function resolvedHost(): ?string
    {
        return self::$resolvedHost;
    }

    /** @return list<string> */
    private static function hostCandidates(): array
    {
        $hosts = [];
        $primary = dbHost();
        if ($primary !== '') {
            $hosts[] = $primary;
        }

        if (isDevEnvironment() && PHP_OS_FAMILY === 'Linux') {
            foreach (['127.0.0.1', 'host.docker.internal', '172.17.0.1'] as $fallback) {
                $hosts[] = $fallback;
            }
            $wslIp = wslWindowsHostIp();
            if ($wslIp !== null) {
                $hosts[] = $wslIp;
            }
        } elseif ($primary === '' || $primary === '127.0.0.1' || $primary === 'localhost') {
            if (!in_array('127.0.0.1', $hosts, true)) {
                $hosts[] = '127.0.0.1';
            }
            if (PHP_OS_FAMILY !== 'Linux') {
                $hosts[] = 'host.docker.internal';
            }
        }

        $unique = [];
        foreach ($hosts as $host) {
            $host = trim($host);
            if ($host === '') {
                continue;
            }
            $normalized = strtolower($host) === 'localhost' ? '127.0.0.1' : $host;
            if (!in_array($normalized, $unique, true)) {
                $unique[] = $normalized;
            }
        }

        return $unique;
    }

    private static function open(string $host): PDO
    {
        $socket = dbSocket();
        if ($socket !== '') {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
                $socket,
                dbName()
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host,
                dbPort(),
                dbName()
            );
        }

        return new PDO(
            $dsn,
            dbUser(),
            dbPass(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
    }
}
