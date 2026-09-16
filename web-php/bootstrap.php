<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Dependências ausentes. Execute: composer install'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

function ocMakerIsApiRequest(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    return str_contains($script, '/api/');
}

if (ocMakerIsApiRequest()) {
    ini_set('display_errors', '0');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

/** @var array<string, string> */
$GLOBALS['oc_maker_db'] = [];

/** Defaults (menor prioridade). */
$dbConfigFile = __DIR__ . '/config/database.php';
if (is_file($dbConfigFile)) {
    /** @var array<string, string> $dbConfig */
    $dbConfig = require $dbConfigFile;
    foreach ($dbConfig as $key => $value) {
        if (is_string($value) && $value !== '') {
            $GLOBALS['oc_maker_db'][$key] = $value;
        }
    }
}

$dbLocalFile = __DIR__ . '/config/database.local.php';
if (is_file($dbLocalFile)) {
    /** @var array<string, string> $dbLocal */
    $dbLocal = require $dbLocalFile;
    foreach ($dbLocal as $key => $value) {
        if (is_string($value) && $value !== '') {
            $GLOBALS['oc_maker_db'][$key] = $value;
        }
    }
}

/** @var array<string, mixed> */
$GLOBALS['oc_maker_app'] = [];

/** .env tem prioridade sobre config/database*.php */
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $envContents = file_get_contents($envFile);
    if ($envContents !== false) {
        $envContents = preg_replace('/^\xEF\xBB\xBF/', '', $envContents) ?? $envContents;
        foreach (preg_split('/\R/', $envContents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
            if ($key === '') {
                continue;
            }
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            } elseif (!str_starts_with($value, '"') && !str_starts_with($value, "'")) {
                $value = preg_split('/\s+#/', $value, 2)[0] ?? $value;
                $value = trim($value);
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            if (function_exists('putenv')) {
                putenv("{$key}={$value}");
            }
            $dbMap = [
                'DB_HOST' => 'host',
                'DB_PORT' => 'port',
                'DB_NAME' => 'name',
                'DB_USER' => 'user',
                'DB_PASS' => 'pass',
                'DB_SOCKET' => 'socket',
            ];
            if (isset($dbMap[$key])) {
                $GLOBALS['oc_maker_db'][$dbMap[$key]] = $value;
            }
        }
    }
}

foreach (['config/app.php', 'config/app.local.php'] as $appConfigRelative) {
    $appConfigPath = __DIR__ . '/' . $appConfigRelative;
    if (!is_file($appConfigPath)) {
        continue;
    }
    $loaded = require $appConfigPath;
    if (is_array($loaded)) {
        $GLOBALS['oc_maker_app'] = array_merge($GLOBALS['oc_maker_app'], $loaded);
    }
}

function dbSetting(string $key, string $default = ''): string
{
    if (isset($GLOBALS['oc_maker_db'][$key]) && $GLOBALS['oc_maker_db'][$key] !== '') {
        return (string) $GLOBALS['oc_maker_db'][$key];
    }

    $envMap = [
        'host' => 'DB_HOST',
        'port' => 'DB_PORT',
        'name' => 'DB_NAME',
        'user' => 'DB_USER',
        'pass' => 'DB_PASS',
        'socket' => 'DB_SOCKET',
    ];
    $envKey = $envMap[$key] ?? strtoupper($key);

    return env($envKey, $default);
}

function env(string $key, string $default = ''): string
{
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return (string) $fromEnv;
    }

    return $default;
}

function appEnv(): string
{
    $value = strtolower(env('APP_ENV', 'production'));
    if (in_array($value, ['dev', 'development', 'local'], true)) {
        return 'development';
    }

    return 'production';
}

function isDevEnvironment(): bool
{
    return appEnv() === 'development';
}

function appConfig(string $key, string $default = ''): string
{
    $cfg = $GLOBALS['oc_maker_app'] ?? [];
    if (!isset($cfg[$key])) {
        return $default;
    }
    $value = $cfg[$key];
    if (!is_string($value) && !is_numeric($value)) {
        return $default;
    }
    $value = trim((string) $value);

    return $value !== '' ? $value : $default;
}

/** Caminho base inferido a partir do SCRIPT_NAME (caminho físico exposto pelo Apache). */
function appBasePathFromScriptName(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);

    // api/* e admin/* ficam dentro de public/ — a base da app é a pasta public, não o subdiretório do script
    if (preg_match('#(/api(/.*)?|/admin(/.*)?)$#', $dir, $m)) {
        $dir = substr($dir, 0, -strlen($m[0]));
    }

    if ($dir === '/' || $dir === '.' || $dir === '') {
        return '';
    }

    return rtrim($dir, '/');
}

/** Caminho base inferido a partir da URL pública (REQUEST_URI), útil com Alias /maker. */
function appBasePathFromRequestUri(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $path = str_replace('\\', '/', $path);

    if (preg_match('#^(.*)/index\.php$#', $path, $m)) {
        $base = rtrim($m[1], '/');

        return $base === '' ? '' : $base;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!str_ends_with($script, 'index.php')) {
        return '';
    }

    $basename = basename($path);
    if ($basename !== '' && str_contains($basename, '.')) {
        return '';
    }

    $base = rtrim($path, '/');

    return $base === '' ? '' : $base;
}

/** Caminho base da app na URL (ex.: /maker ou /oc_maker/web-php/public). */
function appBasePath(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $fromConfig = trim(appConfig('base_path', ''), '/');
    if ($fromConfig !== '') {
        $cached = '/' . $fromConfig;

        return $cached;
    }

    $configured = trim(env('APP_BASE_PATH', ''), '/');
    if ($configured !== '') {
        $cached = '/' . $configured;

        return $cached;
    }

    $forwardedPrefix = trim((string) ($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''), '/');
    if ($forwardedPrefix !== '') {
        $cached = '/' . $forwardedPrefix;

        return $cached;
    }

    $fromScript = appBasePathFromScriptName();
    $fromRequest = appBasePathFromRequestUri();

    if ($fromRequest !== '' && $fromScript !== '' && $fromRequest !== $fromScript) {
        // Alias externo (/maker) apontando para public/ com SCRIPT_NAME interno diferente
        $cached = $fromRequest;

        return $cached;
    }

    $cached = $fromScript !== '' ? $fromScript : $fromRequest;

    return $cached;
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = appBasePath();
    if ($base === '') {
        return $path === '' ? '/' : '/' . $path;
    }

    return $path === '' ? $base : $base . '/' . $path;
}

function assetUrl(string $path): string
{
    $rel = ltrim($path, '/');
    $file = __DIR__ . '/public/' . $rel;
    $version = is_file($file) ? (string) filemtime($file) : '1';

    return url($path) . '?v=' . $version;
}

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** @param array<string, mixed>|null $details */
function auditLog(
    string $action,
    ?string $entityType = null,
    ?string $entityId = null,
    ?string $message = null,
    ?array $details = null,
): void {
    try {
        \OcMaker\AuditLogService::record($action, $entityType, $entityId, $message, $details);
    } catch (Throwable) {
        // Não interrompe a operação principal se o log falhar.
    }
}

function ensureStorageDirectory(string $dir, string $label): void
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            jsonResponse([
                'error' => "Pasta de {$label} não pôde ser criada. Crie storage/ com permissão de escrita para o usuário do Apache.",
                'path' => $dir,
            ], 500);
        }
    }

    if (!is_writable($dir)) {
        jsonResponse([
            'error' => "Sem permissão de escrita em {$label}. No servidor Linux: sudo chown -R www-data:www-data storage && sudo chmod -R 775 storage",
            'path' => $dir,
        ], 500);
    }
}

function uploadsStorageDir(): string
{
    $dir = __DIR__ . '/storage/uploads';
    ensureStorageDirectory($dir, 'uploads');

    return $dir;
}

function pdfStorageDir(): string
{
    $dir = __DIR__ . '/storage/pdf';
    ensureStorageDirectory($dir, 'PDFs');

    return $dir;
}

/** Evita socket Unix ao usar "localhost" (comum no PHP/Linux). */
function dbHost(): string
{
    $host = dbSetting('host', '127.0.0.1');
    return strtolower($host) === 'localhost' ? '127.0.0.1' : $host;
}

function dbPort(): string
{
    return dbSetting('port', '3306');
}

function dbName(): string
{
    return dbSetting('name', 'oc_maker');
}

function dbUser(): string
{
    return dbSetting('user', 'theled');
}

function dbPass(): string
{
    return dbSetting('pass', 'abc@1234Led.');
}

function dbSocket(): string
{
    return dbSetting('socket', '');
}

function isWsl(): bool
{
    if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/version')) {
        return false;
    }

    $version = (string) file_get_contents('/proc/version');

    return stripos($version, 'microsoft') !== false || stripos($version, 'wsl') !== false;
}

/** IP do Windows host quando PHP roda no WSL2 (MySQL no XAMPP/WAMP do Windows). */
function wslWindowsHostIp(): ?string
{
    if (!isWsl() || !is_readable('/etc/resolv.conf')) {
        return null;
    }

    foreach (file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^nameserver\s+(\S+)/', trim($line), $m)) {
            return $m[1];
        }
    }

    return null;
}

/** @return list<string> */
function dbHostProbeCandidates(): array
{
    $hosts = [dbHost()];
    if (isDevEnvironment() && PHP_OS_FAMILY === 'Linux') {
        $hosts[] = 'host.docker.internal';
        $hosts[] = '172.17.0.1';
        $wslIp = wslWindowsHostIp();
        if ($wslIp !== null) {
            $hosts[] = $wslIp;
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

function requireUpload(): string
{
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Arquivo não enviado ou inválido.'], 400);
    }
    $name = $_FILES['file']['name'] ?? '';
    if (!preg_match('/\.xlsx$/i', $name)) {
        jsonResponse(['error' => 'Apenas arquivos .xlsx são aceitos.'], 400);
    }
    $dir = uploadsStorageDir();
    $dest = $dir . '/' . uniqid('upload_', true) . '.xlsx';
    if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        jsonResponse(['error' => 'Falha ao salvar upload. Verifique permissões em storage/uploads.'], 500);
    }
    return $dest;
}

function spreadsheetStorageDir(): string
{
    $dir = __DIR__ . '/storage/spreadsheets';
    ensureStorageDirectory($dir, 'planilhas arquivadas');

    return $dir;
}

/** @return array{key: string, path: string, source_name: string|null} */
function archiveUploadedSpreadsheet(string $uploadPath, ?string $originalName = null): array
{
    $key = bin2hex(random_bytes(16));
    $stored = spreadsheetStorageDir() . '/' . $key . '.xlsx';
    if (!@rename($uploadPath, $stored)) {
        if (!copy($uploadPath, $stored)) {
            jsonResponse(['error' => 'Falha ao arquivar planilha.'], 500);
        }
        @unlink($uploadPath);
    }
    return ['key' => $key, 'path' => $stored, 'source_name' => $originalName];
}

function campaignSnapshotCacheNamespace(string $spreadsheetPath, string $spreadsheetKey, int $sourceDocumentId = 0): string
{
    if ($spreadsheetKey !== '' && preg_match('/^[a-f0-9]{32}$/', $spreadsheetKey)) {
        return $spreadsheetKey;
    }
    if ($sourceDocumentId > 0) {
        return 'doc-' . $sourceDocumentId;
    }
    $mtime = is_file($spreadsheetPath) ? (int) filemtime($spreadsheetPath) : 0;

    return 'path-' . md5($spreadsheetPath . '|' . $mtime);
}

function campaignSnapshotCachePath(string $cacheNamespace, string $adsId): string
{
    $dir = spreadsheetStorageDir() . '/campaign-cache';
    ensureStorageDirectory($dir, 'cache de campanhas');
    return $dir . '/' . $cacheNamespace . '_' . md5($adsId) . '.json';
}

/** @param array<string, mixed> $campaign */
function campaignSnapshotPayload(array $campaign): array
{
    return [
        'ads_id' => $campaign['ads_id'],
        'campanha' => $campaign['campanha'],
        'anunciante' => $campaign['anunciante'],
        'agencia' => $campaign['agencia'],
        'inicio' => $campaign['inicio'],
        'termino' => $campaign['termino'],
        'inventoryCount' => count($campaign['inventory']),
        'totals' => $campaign['totals'],
    ];
}

/** @return array<string, mixed>|null */
function readCampaignSnapshotCache(string $spreadsheetPath, string $spreadsheetKey, string $adsId, int $sourceDocumentId = 0): ?array
{
    if ($adsId === '') {
        return null;
    }
    $namespace = campaignSnapshotCacheNamespace($spreadsheetPath, $spreadsheetKey, $sourceDocumentId);
    $cachePath = campaignSnapshotCachePath($namespace, $adsId);
    if (!is_file($cachePath) || filemtime($cachePath) < filemtime($spreadsheetPath)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($cachePath), true);
    return is_array($data) ? $data : null;
}

/** @param array<string, mixed> $campaign */
function writeCampaignSnapshotCache(string $spreadsheetPath, string $spreadsheetKey, string $adsId, array $campaign, int $sourceDocumentId = 0): void
{
    if ($adsId === '') {
        return;
    }
    $namespace = campaignSnapshotCacheNamespace($spreadsheetPath, $spreadsheetKey, $sourceDocumentId);
    $cachePath = campaignSnapshotCachePath($namespace, $adsId);
    file_put_contents($cachePath, json_encode($campaign, JSON_UNESCAPED_UNICODE));
}

/** @return array{path: string, stored_path: string, source_name: string|null} */
function resolveExistingSpreadsheetPath(): array
{
    $sourceDocumentId = (int) ($_POST['sourceDocumentId'] ?? 0);
    if ($sourceDocumentId > 0) {
        $repo = new OcMaker\DocumentRepository();
        $existing = $repo->findById($sourceDocumentId);
        $storedPath = (string) ($existing['source_path'] ?? '');
        if ($existing === null || $storedPath === '' || !is_file($storedPath)) {
            jsonResponse(['error' => 'Planilha original não encontrada. Envie o arquivo novamente.'], 400);
        }
        return [
            'path' => $storedPath,
            'stored_path' => $storedPath,
            'source_name' => $existing['source_file'] ?? null,
        ];
    }

    $key = trim((string) ($_POST['spreadsheetKey'] ?? ''));
    if ($key !== '' && preg_match('/^[a-f0-9]{32}$/', $key)) {
        $storedPath = spreadsheetStorageDir() . '/' . $key . '.xlsx';
        if (!is_file($storedPath)) {
            jsonResponse(['error' => 'Sessão da planilha expirou. Envie o arquivo novamente.'], 400);
        }
        return [
            'path' => $storedPath,
            'stored_path' => $storedPath,
            'source_name' => null,
        ];
    }

    jsonResponse(['error' => 'Planilha não informada. Envie o arquivo novamente.'], 400);
}

/** @return array{path: string, stored_path: string, source_name: string|null} */
function resolveSpreadsheetPath(): array
{
    $sourceDocumentId = (int) ($_POST['sourceDocumentId'] ?? 0);
    $key = trim((string) ($_POST['spreadsheetKey'] ?? ''));
    if ($sourceDocumentId > 0 || ($key !== '' && preg_match('/^[a-f0-9]{32}$/', $key))) {
        return resolveExistingSpreadsheetPath();
    }

    $uploaded = requireUpload();
    $archived = archiveUploadedSpreadsheet($uploaded, $_FILES['file']['name'] ?? null);

    return [
        'path' => $archived['path'],
        'stored_path' => $archived['path'],
        'source_name' => $archived['source_name'],
    ];
}

/** @return array<string, mixed> */
function readDocumentOptionsFromPost(string $documentId): array
{
    return [
        'document_id' => $documentId,
        'document_title' => (string) ($_POST['documentTitle'] ?? 'Informe de Campanha'),
        'tipo_venda' => (string) ($_POST['tipoVenda'] ?? 'SSP'),
        'tipo_deal' => (string) ($_POST['tipoDeal'] ?? ''),
        'planejador_ssp' => (string) ($_POST['planejadorSsp'] ?? 'Admooh'),
        'deal_id' => trim((string) ($_POST['dealId'] ?? '')),
        'oc_informe_ssp' => trim((string) ($_POST['ocInformeSsp'] ?? '')),
        'checking_fotografico' => filter_var($_POST['checkingFotografico'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'relatorios_adicionais' => filter_var($_POST['relatoriosAdicionais'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'prazo_pagamento' => (int) ($_POST['prazoPagamento'] ?? 15),
        'prazo_unidade' => (string) ($_POST['prazoUnidade'] ?? 'DFM'),
    ];
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI === 'cli') {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    $cookiePath = appBasePath();
    if ($cookiePath === '') {
        $cookiePath = '/';
    } else {
        $cookiePath = rtrim($cookiePath, '/') . '/';
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('oc_maker_sid');
    session_start();
}

function csrfToken(): string
{
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function validateCsrf(?string $token = null): void
{
    startSecureSession();
    $token = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $token)) {
        jsonResponse(['error' => 'Token CSRF inválido. Recarregue a página.'], 419);
    }
}

function sanitizeString(string $value, int $maxLength = 255): string
{
    $value = trim(strip_tags($value));
    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

if (PHP_SAPI !== 'cli') {
    startSecureSession();
}
