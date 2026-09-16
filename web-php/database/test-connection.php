<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use OcMaker\Database;

$isCli = PHP_SAPI === 'cli';

function probeTcpPort(string $host, int $port, float $timeout = 2.0): string
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (is_resource($fp)) {
        fclose($fp);

        return 'porta aberta';
    }

    return $errstr !== '' ? $errstr : ('erro ' . $errno);
}

/** @param list<string> $lines */
function emitDiagnostic(array $lines, bool $ok, string $extraHtml = ''): never
{
    global $isCli;

    if ($isCli) {
        foreach ($lines as $line) {
            echo $line . PHP_EOL;
        }
        exit($ok ? 0 : 1);
    }

    header('Content-Type: text/html; charset=utf-8');
    http_response_code($ok ? 200 : 503);
    $title = $ok ? 'Conexão OK' : 'Falha na conexão';
    $statusClass = $ok ? 'ok' : 'fail';
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
    echo '<title>OC Maker — teste MySQL</title>';
    echo '<style>';
    echo 'body{font-family:system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;line-height:1.5}';
    echo '.ok{color:#047857}.fail{color:#b91c1c}';
    echo 'pre{background:#f8fafc;padding:1rem;border-radius:8px;overflow:auto;white-space:pre-wrap;word-break:break-word}';
    echo 'code{background:#eef2ff;padding:0.1rem 0.35rem;border-radius:4px}';
    echo '.tips{margin-top:1rem;padding:1rem;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px}';
    echo '</style></head><body>';
    echo '<h1 class="' . $statusClass . '">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<pre>';
    foreach ($lines as $line) {
        echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "\n";
    }
    echo '</pre>';
    if ($extraHtml !== '') {
        echo $extraHtml;
    }
    echo '</body></html>';
    exit($ok ? 0 : 1);
}

$lines = [
    'Testando conexão MySQL (mesma lógica da aplicação web)...',
    '  SAPI: ' . PHP_SAPI,
    '  Ambiente: ' . appEnv(),
    '  .env: ' . (is_file($root . '/.env') ? $root . '/.env' : '(não encontrado)'),
    '  config/database.php: ' . (is_file($root . '/config/database.php') ? 'sim (defaults)' : 'não'),
    '  Prioridade: .env sobrescreve config/database*.php',
    '  pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'sim' : 'NÃO'),
    '  WSL: ' . (isWsl() ? 'sim' : 'não'),
    '  Host efetivo (após .env): ' . dbHost(),
    '  Porta: ' . dbPort(),
    '  Banco: ' . dbName(),
    '  Usuário: ' . dbUser(),
];

if (dbSocket() !== '') {
    $lines[] = '  Socket: ' . dbSocket();
}

$lines[] = '';
$lines[] = 'Sondagem TCP (porta ' . dbPort() . '):';

$port = (int) dbPort();
$openHosts = [];
foreach (dbHostProbeCandidates() as $host) {
    $result = probeTcpPort($host, $port);
    $lines[] = sprintf('  %-24s → %s', $host, $result);
    if ($result === 'porta aberta') {
        $openHosts[] = $host;
    }
}

if (isWsl()) {
    $wslIp = wslWindowsHostIp();
    if ($wslIp !== null) {
        $lines[] = '';
        $lines[] = 'WSL detectado — IP do Windows host: ' . $wslIp;
    }
}

$extraHtml = '';
if (!$openHosts) {
    $lines[] = '';
    $lines[] = 'Nenhum host respondeu na porta ' . dbPort() . '.';
    $lines[] = 'Provável causa: MySQL/MariaDB não está rodando ou não aceita conexões TCP.';

    $extraHtml = '<div class="tips"><strong>O que verificar:</strong><ol>';
    $extraHtml .= '<li>Inicie o MySQL (XAMPP, WAMP, serviço Windows ou <code>sudo service mysql start</code> no Linux).</li>';
    if (isWsl()) {
        $wslIp = wslWindowsHostIp();
        $extraHtml .= '<li>MySQL no <strong>Windows</strong> + Apache no <strong>WSL/Docker</strong>: use no <code>.env</code>:<br>';
        $extraHtml .= '<code>DB_HOST=' . htmlspecialchars($wslIp ?? 'IP_DO_WINDOWS', ENT_QUOTES, 'UTF-8') . '</code></li>';
        $extraHtml .= '<li>No MySQL do Windows, confirme que escuta conexões externas (não só 127.0.0.1).</li>';
    } else {
        $extraHtml .= '<li>Apache em Docker: <code>DB_HOST=host.docker.internal</code> no <code>.env</code>.</li>';
    }
    $extraHtml .= '<li>MySQL só via socket local: <code>DB_SOCKET=/var/run/mysqld/mysqld.sock</code> no <code>.env</code>.</li>';
    $extraHtml .= '</ol></div>';
} elseif (!in_array(dbHost(), $openHosts, true)) {
    $suggested = $openHosts[0];
    $lines[] = '';
    $lines[] = 'Sugestão: a porta está aberta em ' . $suggested . ' — use no .env:';
    $lines[] = '  DB_HOST=' . $suggested;

    $extraHtml = '<div class="tips"><strong>Ação sugerida</strong><br>Edite <code>web-php/.env</code>:<br>';
    $extraHtml .= '<code>DB_HOST=' . htmlspecialchars($suggested, ENT_QUOTES, 'UTF-8') . '</code></div>';
}

$lines[] = '';
$lines[] = 'Teste PDO (login + banco):';

try {
    Database::connection()->query('SELECT 1');
    $lines[] = 'Conexão OK.';
    $resolved = Database::resolvedHost();
    if ($resolved !== null && $resolved !== dbHost()) {
        $lines[] = '  Host efetivo: ' . $resolved;
    }
    emitDiagnostic($lines, true);
} catch (Throwable $e) {
    $lines[] = 'Falhou: ' . $e->getMessage();
    if ($openHosts) {
        $lines[] = '';
        $lines[] = 'A porta TCP responde, mas o login falhou — verifique DB_USER, DB_PASS e se o banco "' . dbName() . '" existe.';
        $lines[] = 'Rode: php database/setup.php';
    }
    emitDiagnostic($lines, false, $extraHtml);
}
