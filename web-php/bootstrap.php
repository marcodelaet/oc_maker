<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Dependências ausentes. Execute: composer install'], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/vendor/autoload.php';

/** @var array<string, string> */
$GLOBALS['oc_maker_db'] = [];

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $map = [
            'DB_HOST' => 'host',
            'DB_PORT' => 'port',
            'DB_NAME' => 'name',
            'DB_USER' => 'user',
            'DB_PASS' => 'pass',
        ];
        if (isset($map[$key])) {
            $GLOBALS['oc_maker_db'][$map[$key]] = $value;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        if (function_exists('putenv')) {
            putenv("{$key}={$value}");
        }
    }
}

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
    ];
    $envKey = $envMap[$key] ?? strtoupper($key);
    if (isset($_ENV[$envKey]) && $_ENV[$envKey] !== '') {
        return (string) $_ENV[$envKey];
    }
    if (isset($_SERVER[$envKey]) && $_SERVER[$envKey] !== '') {
        return (string) $_SERVER[$envKey];
    }
    $fromEnv = getenv($envKey);
    if ($fromEnv !== false && $fromEnv !== '') {
        return (string) $fromEnv;
    }

    return $default;
}

function jsonResponse(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
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

function requireUpload(): string
{
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Arquivo não enviado ou inválido.'], 400);
    }
    $name = $_FILES['file']['name'] ?? '';
    if (!preg_match('/\.xlsx$/i', $name)) {
        jsonResponse(['error' => 'Apenas arquivos .xlsx são aceitos.'], 400);
    }
    $dir = __DIR__ . '/storage/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $dest = $dir . '/' . uniqid('upload_', true) . '.xlsx';
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        jsonResponse(['error' => 'Falha ao salvar upload.'], 500);
    }
    return $dest;
}

function spreadsheetStorageDir(): string
{
    $dir = __DIR__ . '/storage/spreadsheets';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
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

function campaignSnapshotCachePath(string $spreadsheetKey, string $adsId): string
{
    $dir = spreadsheetStorageDir() . '/campaign-cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/' . $spreadsheetKey . '_' . md5($adsId) . '.json';
}

/** @return array<string, mixed>|null */
function readCampaignSnapshotCache(string $spreadsheetPath, string $spreadsheetKey, string $adsId): ?array
{
    if ($adsId === '' || !preg_match('/^[a-f0-9]{32}$/', $spreadsheetKey)) {
        return null;
    }
    $cachePath = campaignSnapshotCachePath($spreadsheetKey, $adsId);
    if (!is_file($cachePath) || filemtime($cachePath) < filemtime($spreadsheetPath)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($cachePath), true);
    return is_array($data) ? $data : null;
}

/** @param array<string, mixed> $campaign */
function writeCampaignSnapshotCache(string $spreadsheetKey, string $adsId, array $campaign): void
{
    if ($adsId === '' || !preg_match('/^[a-f0-9]{32}$/', $spreadsheetKey)) {
        return;
    }
    $cachePath = campaignSnapshotCachePath($spreadsheetKey, $adsId);
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
