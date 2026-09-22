<?php

declare(strict_types=1);

namespace OcMaker;

final class CampaignCreativeRepository
{
    /** @return list<array<string, mixed>> */
    public function listByDocument(int $documentId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, document_id, file_name, file_path, mime_type, width, height,
                    duration_seconds, frame_rate, file_size, created_at
             FROM campaign_creatives
             WHERE document_id = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$documentId]);

        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $data */
    public function save(int $documentId, array $data, ?int $uploadedBy): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO campaign_creatives
             (document_id, file_name, file_path, mime_type, width, height,
              duration_seconds, frame_rate, file_size, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $documentId,
            $data['file_name'],
            $data['file_path'],
            $data['mime_type'] ?? null,
            $data['width'] ?? null,
            $data['height'] ?? null,
            $data['duration_seconds'] ?? null,
            $data['frame_rate'] ?? null,
            $data['file_size'] ?? null,
            $uploadedBy,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function updateMetadata(int $id, array $data): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE campaign_creatives SET
               width = :width, height = :height,
               duration_seconds = :duration_seconds, frame_rate = :frame_rate
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'frame_rate' => $data['frame_rate'] ?? null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listMissingMetadata(): array
    {
        $stmt = Database::connection()->query(
            "SELECT id, file_path, mime_type, width, height, duration_seconds, frame_rate
             FROM campaign_creatives
             WHERE file_path IS NOT NULL AND file_path <> ''
               AND (width IS NULL OR height IS NULL
                    OR (mime_type LIKE 'video/%' AND (duration_seconds IS NULL OR frame_rate IS NULL)))"
        );

        return $stmt ? $stmt->fetchAll() : [];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM campaign_creatives WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    public function delete(int $id, int $documentId): void
    {
        $row = $this->findById($id);
        if ($row === null || (int) $row['document_id'] !== $documentId) {
            throw new \InvalidArgumentException('Criativo não encontrado.');
        }

        if (!empty($row['file_path']) && is_file((string) $row['file_path'])) {
            @unlink((string) $row['file_path']);
        }

        $stmt = Database::connection()->prepare('DELETE FROM campaign_creatives WHERE id = ? AND document_id = ?');
        $stmt->execute([$id, $documentId]);
    }
}
