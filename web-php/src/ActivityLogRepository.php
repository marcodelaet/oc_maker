<?php

declare(strict_types=1);

namespace OcMaker;

use PDO;

final class ActivityLogRepository
{
    public const PAGE_MAX = 200;
    public const EXPORT_MAX = 50000;

    /** @return array{entries:list<array<string,mixed>>,total:int} */
    public function list(int $limit, int $offset, ?string $actionFilter = null, ?string $search = null): array
    {
        [$whereSql, $params] = $this->buildFilters($actionFilter, $search);

        $countStmt = Database::connection()->prepare("SELECT COUNT(*) FROM user_activity_log {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $limit = min(max(1, $limit), self::PAGE_MAX);
        $offset = max(0, $offset);

        $stmt = Database::connection()->prepare(
            "SELECT * FROM user_activity_log {$whereSql} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'entries' => array_map([$this, 'publicEntry'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function allForExport(?string $actionFilter = null, ?string $search = null): array
    {
        [$whereSql, $params] = $this->buildFilters($actionFilter, $search);

        $stmt = Database::connection()->prepare(
            'SELECT * FROM user_activity_log ' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . self::EXPORT_MAX
        );
        $stmt->execute($params);

        return array_map([$this, 'publicEntry'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<string> */
    public function distinctActions(): array
    {
        $stmt = Database::connection()->query(
            'SELECT DISTINCT action FROM user_activity_log ORDER BY action ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map('strval', $rows)));
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function publicEntry(array $row): array
    {
        $details = null;
        if (!empty($row['details'])) {
            try {
                $decoded = json_decode((string) $row['details'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $details = $decoded;
                }
            } catch (\JsonException) {
                $details = ['raw' => (string) $row['details']];
            }
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'actor_type' => (string) ($row['actor_type'] ?? 'guest'),
            'actor_label' => (string) ($row['actor_label'] ?? ''),
            'action' => (string) ($row['action'] ?? ''),
            'entity_type' => $row['entity_type'] ?? null,
            'entity_id' => $row['entity_id'] ?? null,
            'message' => $row['message'] ?? null,
            'details' => $details,
            'details_json' => $row['details'] ?? null,
            'ip_address' => $row['ip_address'] ?? null,
            'user_agent' => $row['user_agent'] ?? null,
            'client_os' => $row['client_os'] ?? null,
            'client_browser' => $row['client_browser'] ?? null,
            'client_platform' => $row['client_platform'] ?? null,
            'reverse_hostname' => $row['reverse_hostname'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function buildFilters(?string $actionFilter, ?string $search): array
    {
        $where = [];
        $params = [];

        if ($actionFilter !== null && trim($actionFilter) !== '') {
            $where[] = 'action = ?';
            $params[] = trim($actionFilter);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $where[] = '(actor_label LIKE ? OR message LIKE ? OR action LIKE ? OR entity_id LIKE ? OR ip_address LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$whereSql, $params];
    }
}
