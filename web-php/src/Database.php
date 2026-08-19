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
        throw new \RuntimeException(
            'Falha na conexão com o banco (tentativas: ' . $attempted . '): '
            . ($lastError?->getMessage() ?? 'erro desconhecido')
            . '. Configure DB_HOST no .env ou config/database.local.php com o host MySQL do servidor.',
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

        $explicitHost = trim(dbSetting('host', ''));
        $envHost = getenv('DB_HOST');
        $hasExplicitHost = $explicitHost !== ''
            || ($envHost !== false && $envHost !== '');

        // Em produção, use somente o host configurado — não tente DNS/resolv do Docker.
        if (!$hasExplicitHost) {
            $hosts[] = '127.0.0.1';

            if (PHP_OS_FAMILY !== 'Linux') {
                $hosts[] = 'host.docker.internal';
            } elseif (getenv('DB_ALLOW_DOCKER_HOST') === '1') {
                $hosts[] = 'host.docker.internal';
            }
        } elseif ($primary !== '127.0.0.1' && $primary !== 'localhost') {
            // Host remoto explícito: sem fallbacks automáticos.
        } elseif (!in_array('127.0.0.1', $hosts, true)) {
            $hosts[] = '127.0.0.1';
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
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host,
                dbPort(),
                dbName()
            ),
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
