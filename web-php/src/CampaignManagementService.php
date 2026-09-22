<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignManagementService
{
    public const CMS_OPTIONS = ['Invian', 'Onsign', 'Xibo'];

    public const OS_OPTIONS = [
        'Windows x64',
        'Windows x32',
        'SSSP 6',
        'WebOS 3',
        'WebOS 4',
        'WebOS 5',
        'WebOS 6',
        'Android',
        'Tizen 6.5 ou +',
    ];

    public const OFFLINE_UNITS = ['horas', 'dias', 'meses', 'anos', 'nunca'];

    public const WORKFLOW_STATUSES = [
        'aguardando_aprovacao',
        'aprovada',
        'rejeitada',
        'finalizada_pausada',
    ];

    private DocumentRepository $documents;
    private InventoryRepository $inventory;
    private InventoryScreenRepository $screens;
    private CampaignReviewRepository $reviews;

    public function __construct()
    {
        $this->documents = new DocumentRepository();
        $this->inventory = new InventoryRepository();
        $this->screens = new InventoryScreenRepository();
        $this->reviews = new CampaignReviewRepository();
    }

    /** Move campanhas aprovadas encerradas (com relatório) para finalizada_pausada. */
    public function finalizeEndedApprovedCampaigns(): int
    {
        $stmt = Database::connection()->query(
            "SELECT d.id
             FROM documents d
             WHERE d.campaign_workflow_status = 'aprovada'
               AND d.termino IS NOT NULL
               AND DATE(d.termino) < CURDATE()
               AND EXISTS (
                 SELECT 1
                 FROM campaign_deals cd
                 INNER JOIN campaign_deal_daily_reports r ON r.deal_db_id = cd.id
                 WHERE cd.document_id = d.id
                 LIMIT 1
               )"
        );
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($ids as $documentId) {
            $this->documents->setWorkflowStatus($documentId, 'finalizada_pausada', null, null);
        }

        return count($ids);
    }

    public function finalizeDocumentIfEligible(int $documentId): bool
    {
        $doc = $this->documents->findById($documentId);
        if ($doc === null || ($doc['campaign_workflow_status'] ?? '') !== 'aprovada') {
            return false;
        }

        $termino = $doc['termino'] ?? null;
        if ($termino === null || $termino === '') {
            return false;
        }

        $end = \DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $termino, 0, 10));
        if ($end === false || $end >= new \DateTimeImmutable('today')) {
            return false;
        }

        $stmt = Database::connection()->prepare(
            'SELECT 1
             FROM campaign_deals cd
             INNER JOIN campaign_deal_daily_reports r ON r.deal_db_id = cd.id
             WHERE cd.document_id = ?
             LIMIT 1'
        );
        $stmt->execute([$documentId]);
        if (!$stmt->fetchColumn()) {
            return false;
        }

        $this->documents->setWorkflowStatus($documentId, 'finalizada_pausada', null, null);

        return true;
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $this->finalizeEndedApprovedCampaigns();
        $counts = array_fill_keys(self::WORKFLOW_STATUSES, 0);
        $stmt = Database::connection()->query(
            'SELECT campaign_workflow_status, COUNT(*) AS total
             FROM documents
             GROUP BY campaign_workflow_status'
        );
        foreach ($stmt->fetchAll() as $row) {
            $status = (string) ($row['campaign_workflow_status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function listCampaigns(string $status, string $query, int $page, int $perPage): array
    {
        if (!in_array($status, self::WORKFLOW_STATUSES, true)) {
            throw new \InvalidArgumentException('Status de campanha inválido.');
        }

        $this->finalizeEndedApprovedCampaigns();

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $query = trim($query);

        $where = 'd.campaign_workflow_status = :status';
        $params = ['status' => $status];
        if ($query !== '') {
            $where .= ' AND (d.campanha LIKE :q OR d.ads_id LIKE :q OR d.document_id LIKE :q OR d.anunciante LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $countStmt = Database::connection()->prepare("SELECT COUNT(*) FROM documents d WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT d.id, d.document_id, d.ads_id, d.campanha, d.anunciante, d.inicio, d.termino,
                       d.campaign_workflow_status, d.created_at,
                       (SELECT COUNT(*)
                        FROM campaign_screen_reviews csr
                        WHERE csr.document_id = d.id AND csr.is_online = 0) AS offline_screen_count
                FROM documents d
                WHERE {$where}
                ORDER BY d.created_at DESC
                LIMIT :lim OFFSET :off";
        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed> */
    public function documentDetail(int $documentId): array
    {
        $doc = $this->documents->findById($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento não encontrado.');
        }

        $inventoryRows = $this->inventory->listByDocument($documentId);
        $unitReviews = $this->reviews->unitReviewsByDocument($documentId);
        $screenReviews = $this->reviews->screenReviewsByDocument($documentId);

        $units = [];
        foreach ($inventoryRows as $row) {
            $itemId = (int) $row['inventory_item_id'];
            $faces = max(1, (int) ($row['faces'] ?? 1));
            $review = $unitReviews[$itemId] ?? null;
            $existingScreens = $this->screens->listByInventoryItem($itemId);
            $existingByFace = [];
            foreach ($existingScreens as $screen) {
                $existingByFace[(int) $screen['face_number']] = $screen;
            }

            $faceRows = [];
            for ($face = 1; $face <= $faces; $face++) {
                $key = $itemId . ':' . $face;
                $saved = $screenReviews[$key] ?? null;
                $global = $existingByFace[$face] ?? null;
                $screenCode = trim((string) ($global['screen_code'] ?? $saved['screen_code'] ?? ''));
                if ($screenCode === '' && !empty($row['codigo'])) {
                    $screenCode = ScreenCodeHelper::buildDefaultScreenCode(
                        (string) $row['codigo'],
                        $face,
                        (string) ($row['denominacao'] ?? ''),
                    );
                } elseif ($screenCode !== '') {
                    $screenCode = ScreenCodeHelper::ensureLocationSuffix(
                        $screenCode,
                        (string) ($row['denominacao'] ?? ''),
                    );
                }

                $cms = $saved['cms'] ?? $global['cms'] ?? null;
                $knownScreen = $screenCode !== '' ? $this->screens->findByCode($screenCode) : null;
                if ($knownScreen !== null && !empty($knownScreen['cms'])) {
                    $cms = $knownScreen['cms'];
                }

                $faceRows[] = [
                    'face_number' => $face,
                    'screen_code' => $screenCode,
                    'cms' => $cms,
                    'os_name' => $saved['os_name'] ?? $global['os_name'] ?? null,
                    'is_online' => (bool) ($saved['is_online'] ?? $global['is_online'] ?? 1),
                    'offline_duration' => (int) ($saved['offline_duration'] ?? $global['offline_duration'] ?? 0),
                    'offline_unit' => $saved['offline_unit'] ?? $global['offline_unit'] ?? 'horas',
                    'known_screen' => $knownScreen !== null,
                ];
            }

            $units[] = [
                'inventory_item_id' => $itemId,
                'codigo' => $row['codigo'] ?? '',
                'denominacao' => $row['denominacao'] ?? '',
                'faces' => $faces,
                'rede_name' => $row['rede_name'] ?? '',
                'status' => $review['status'] ?? 'pending',
                'rejection_reason' => $review['rejection_reason'] ?? null,
                'replacement_codigo' => $review['replacement_codigo'] ?? null,
                'face_rows' => $faceRows,
            ];
        }

        return [
            'document' => [
                'id' => (int) $doc['id'],
                'document_id' => $doc['document_id'],
                'ads_id' => $doc['ads_id'],
                'campanha' => $doc['campanha'],
                'anunciante' => $doc['anunciante'],
                'inicio' => $doc['inicio'],
                'termino' => $doc['termino'],
                'campaign_workflow_status' => $doc['campaign_workflow_status'] ?? 'aguardando_aprovacao',
                'campaign_rejection_reason' => $doc['campaign_rejection_reason'] ?? null,
            ],
            'units' => $units,
            'offline_screens' => $this->reviews->offlineScreensForDocument($documentId),
            'options' => [
                'cms' => self::CMS_OPTIONS,
                'os' => self::OS_OPTIONS,
                'offline_units' => self::OFFLINE_UNITS,
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    public function saveReview(int $documentId, array $payload, int $userId): array
    {
        $doc = $this->documents->findById($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento não encontrado.');
        }

        $units = $payload['units'] ?? [];
        if (!is_array($units)) {
            throw new \InvalidArgumentException('Dados de unidades inválidos.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            foreach ($units as $unit) {
                if (!is_array($unit)) {
                    continue;
                }
                $itemId = (int) ($unit['inventory_item_id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }

                $this->reviews->saveUnitReview($documentId, $itemId, $unit, $userId);

                $faces = $unit['faces'] ?? [];
                if (!is_array($faces)) {
                    continue;
                }
                foreach ($faces as $face) {
                    if (!is_array($face)) {
                        continue;
                    }
                    $this->reviews->saveScreenReview($documentId, $itemId, $face);
                    $this->screens->upsert([
                        'inventory_item_id' => $itemId,
                        'face_number' => (int) ($face['face_number'] ?? 1),
                        'screen_code' => $face['screen_code'] ?? '',
                        'cms' => $face['cms'] ?? null,
                        'os_name' => $face['os_name'] ?? null,
                        'is_online' => $face['is_online'] ?? true,
                        'offline_duration' => $face['offline_duration'] ?? 0,
                        'offline_unit' => $face['offline_unit'] ?? 'horas',
                    ]);
                }
            }

            $campaignAction = (string) ($payload['campaign_action'] ?? '');
            if ($campaignAction === 'approve') {
                $this->assertAllUnitsReviewed($documentId);
                $this->documents->setWorkflowStatus($documentId, 'aprovada', null, $userId);
            } elseif ($campaignAction === 'reject') {
                $reason = trim((string) ($payload['campaign_rejection_reason'] ?? ''));
                if ($reason === '') {
                    throw new \InvalidArgumentException('Informe o motivo da reprovação da campanha.');
                }
                $this->documents->setWorkflowStatus($documentId, 'rejeitada', $reason, $userId);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        auditLog(
            'campaign.review_save',
            'document',
            (string) ($doc['document_id'] ?? $documentId),
            'Revisão de inventário salva',
            [
                'db_id' => $documentId,
                'campaign_action' => $payload['campaign_action'] ?? null,
            ],
        );

        return $this->documentDetail($documentId);
    }

    public function restorePending(int $documentId, int $userId): void
    {
        $doc = $this->documents->findById($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento não encontrado.');
        }
        if (($doc['campaign_workflow_status'] ?? '') !== 'rejeitada') {
            throw new \InvalidArgumentException('Somente campanhas rejeitadas podem retornar para aguardando aprovação.');
        }

        $this->documents->setWorkflowStatus($documentId, 'aguardando_aprovacao', null, $userId);

        auditLog(
            'campaign.restore_pending',
            'document',
            (string) ($doc['document_id'] ?? $documentId),
            'Campanha retornou para aguardando aprovação',
            ['db_id' => $documentId],
        );
    }

    /** @return array{document: array<string, mixed>, offline_screens: list<array<string, mixed>>} */
    public function offlineScreensPayload(int $documentId): array
    {
        $doc = $this->documents->findById($documentId);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento não encontrado.');
        }

        return [
            'document' => [
                'campanha' => $doc['campanha'] ?? '',
                'anunciante' => $doc['anunciante'] ?? '',
                'ads_id' => $doc['ads_id'] ?? '',
                'document_id' => $doc['document_id'] ?? '',
                'inicio' => $doc['inicio'] ?? null,
                'termino' => $doc['termino'] ?? null,
            ],
            'offline_screens' => array_map(
                fn(array $row): array => [
                    'screen_code' => (string) ($row['screen_code'] ?? ''),
                    'rede_name' => (string) ($row['rede_name'] ?? ''),
                    'offline_duration' => (int) ($row['offline_duration'] ?? 0),
                    'offline_unit' => (string) ($row['offline_unit'] ?? 'horas'),
                ],
                $this->reviews->offlineScreensForDocument($documentId),
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    public function lookupScreen(string $screenCode): ?array
    {
        $screen = $this->screens->findByCode($screenCode);
        if ($screen === null) {
            return null;
        }

        return [
            'screen_code' => $screen['screen_code'],
            'cms' => $screen['cms'],
            'os_name' => $screen['os_name'],
            'is_online' => (bool) ($screen['is_online'] ?? 1),
            'offline_duration' => (int) ($screen['offline_duration'] ?? 0),
            'offline_unit' => $screen['offline_unit'] ?? 'horas',
        ];
    }

    private function assertAllUnitsReviewed(int $documentId): void
    {
        $inventoryCount = $this->inventory->countByDocument($documentId);
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM campaign_unit_reviews
             WHERE document_id = ? AND status IN ('approved', 'rejected')"
        );
        $stmt->execute([$documentId]);
        $reviewed = (int) $stmt->fetchColumn();
        if ($reviewed < $inventoryCount) {
            throw new \InvalidArgumentException('Aprove ou reprove todas as unidades antes de aprovar a campanha.');
        }

        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM campaign_unit_reviews
             WHERE document_id = ? AND status = 'approved'"
        );
        $stmt->execute([$documentId]);
        $approved = (int) $stmt->fetchColumn();
        if ($approved <= 0) {
            throw new \InvalidArgumentException('É necessário aprovar ao menos uma unidade para aprovar a campanha.');
        }
    }
}
