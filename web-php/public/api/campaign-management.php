<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require dirname(__DIR__, 2) . '/bootstrap.php';

use OcMaker\CampaignApprovedService;
use OcMaker\CampaignCreativeRepository;
use OcMaker\CampaignManagementService;
use OcMaker\SessionAuth;

try {
    $user = SessionAuth::requireRole('campaigns');
    $userId = (int) ($user['id'] ?? 0);
    $service = new CampaignManagementService();
    $approved = new CampaignApprovedService();
    $isAdmin = ($user['role'] ?? '') === 'administrador';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string) ($_GET['action'] ?? 'summary');

        if ($action === 'summary') {
            jsonResponse([
                'counts' => $service->statusCounts(),
                'statuses' => CampaignManagementService::WORKFLOW_STATUSES,
                'is_admin' => $isAdmin,
            ]);
        }

        if ($action === 'list') {
            $status = (string) ($_GET['status'] ?? 'aguardando_aprovacao');
            $query = (string) ($_GET['q'] ?? '');
            $page = (int) ($_GET['page'] ?? 1);
            jsonResponse($service->listCampaigns($status, $query, $page, 25));
        }

        if ($action === 'detail') {
            $documentId = (int) ($_GET['document_id'] ?? 0);
            if ($documentId <= 0) {
                jsonResponse(['error' => 'Documento inválido.'], 400);
            }
            jsonResponse($service->documentDetail($documentId));
        }

        if ($action === 'offline_screens') {
            $documentId = (int) ($_GET['document_id'] ?? 0);
            if ($documentId <= 0) {
                jsonResponse(['error' => 'Documento inválido.'], 400);
            }
            jsonResponse($service->offlineScreensPayload($documentId));
        }

        if ($action === 'approved_context') {
            $documentId = (int) ($_GET['document_id'] ?? 0);
            if ($documentId <= 0) {
                jsonResponse(['error' => 'Documento inválido.'], 400);
            }
            jsonResponse($approved->context($documentId));
        }

        if ($action === 'screen_lookup') {
            $code = (string) ($_GET['code'] ?? '');
            jsonResponse([
                'screen' => $service->lookupScreen($code),
            ]);
        }

        if ($action === 'creative_file') {
            $creativeId = (int) ($_GET['creative_id'] ?? 0);
            $row = (new CampaignCreativeRepository())->findById($creativeId);
            if ($row === null || !is_file((string) $row['file_path'])) {
                jsonResponse(['error' => 'Arquivo não encontrado.'], 404);
            }
            $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . basename((string) $row['file_name']) . '"');
            readfile((string) $row['file_path']);
            exit;
        }

        jsonResponse(['error' => 'Ação inválida.'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }

    validateCsrf();

    $uploadAction = (string) ($_POST['action'] ?? '');
    if ($uploadAction === 'upload_creative') {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
            jsonResponse([
                'error' => 'Arquivo muito grande para o limite do servidor (post_max_size / upload_max_filesize). Envie um arquivo menor ou ajuste o PHP.',
            ], 413);
        }

        $documentId = (int) ($_POST['document_id'] ?? 0);
        if ($documentId <= 0 || !isset($_FILES['file'])) {
            jsonResponse(['error' => 'Upload inválido.'], 400);
        }
        jsonResponse($approved->uploadCreative($documentId, $_FILES['file'], $userId));
    }

    if ($uploadAction === 'upload_deal_report') {
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $dealDbId = (int) ($_POST['deal_db_id'] ?? 0);
        if ($documentId <= 0 || $dealDbId <= 0 || !isset($_FILES['file'])) {
            jsonResponse(['error' => 'Upload inválido.'], 400);
        }
        $deviceResolutions = null;
        $rawResolutions = trim((string) ($_POST['device_resolutions'] ?? ''));
        if ($rawResolutions !== '') {
            $decoded = json_decode($rawResolutions, true);
            if (!is_array($decoded)) {
                jsonResponse(['error' => 'Resoluções de telas inválidas.'], 400);
            }
            $deviceResolutions = $decoded;
        }
        jsonResponse($approved->uploadDealReport($documentId, $dealDbId, $_FILES['file'], $deviceResolutions));
    }

    $payload = jsonRequestBody();
    if ($payload === [] && $_POST !== []) {
        $payload = $_POST;
    }

    $action = (string) ($payload['action'] ?? $uploadAction);

    if ($action === 'save_review') {
        $documentId = (int) ($payload['document_id'] ?? 0);
        if ($documentId <= 0) {
            jsonResponse(['error' => 'Documento inválido.'], 400);
        }
        jsonResponse($service->saveReview($documentId, $payload, $userId));
    }

    if ($action === 'restore_pending') {
        if (!$isAdmin) {
            jsonResponse(['error' => 'Permissão negada.'], 403);
        }
        $documentId = (int) ($payload['document_id'] ?? 0);
        if ($documentId <= 0) {
            jsonResponse(['error' => 'Documento inválido.'], 400);
        }
        $service->restorePending($documentId, $userId);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'save_deal') {
        $documentId = (int) ($payload['document_id'] ?? 0);
        if ($documentId <= 0) {
            jsonResponse(['error' => 'Documento inválido.'], 400);
        }
        jsonResponse($approved->saveDeal($documentId, $payload));
    }

    if ($action === 'delete_deal') {
        $documentId = (int) ($payload['document_id'] ?? 0);
        $dealDbId = (int) ($payload['deal_db_id'] ?? 0);
        if ($documentId <= 0 || $dealDbId <= 0) {
            jsonResponse(['error' => 'Deal inválido.'], 400);
        }
        jsonResponse($approved->deleteDeal($documentId, $dealDbId));
    }

    if ($action === 'save_campaign_slots') {
        $documentId = (int) ($payload['document_id'] ?? 0);
        if ($documentId <= 0) {
            jsonResponse(['error' => 'Documento inválido.'], 400);
        }
        jsonResponse($approved->saveCampaignSlots($documentId, (int) ($payload['campaign_slots'] ?? 1)));
    }

    if ($action === 'delete_creative') {
        $documentId = (int) ($payload['document_id'] ?? 0);
        $creativeId = (int) ($payload['creative_id'] ?? 0);
        if ($documentId <= 0 || $creativeId <= 0) {
            jsonResponse(['error' => 'Criativo inválido.'], 400);
        }
        jsonResponse($approved->deleteCreative($documentId, $creativeId));
    }

    jsonResponse(['error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 400);
}
