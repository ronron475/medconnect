<?php
/**
 * Multiple recording segments for one consultation (reconnect-safe).
 * video_sessions.recording_path remains a legacy pointer to the latest file.
 */
declare(strict_types=1);

function consultation_recording_segments_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS consultation_recording_segments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                consultation_id INT UNSIGNED NOT NULL,
                video_session_id BIGINT UNSIGNED NOT NULL,
                segment_index INT UNSIGNED NOT NULL DEFAULT 1,
                recording_path VARCHAR(500) NOT NULL,
                started_at DATETIME NULL,
                ended_at DATETIME NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'saved',
                upload_key VARCHAR(80) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_recording_upload_key (upload_key),
                KEY idx_recording_consult (consultation_id, segment_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        error_log('consultation_recording_segments_ensure: ' . $e->getMessage());
    }
    $done = true;
}

function consultation_recording_segment_file_rel(string $recordingPath): string
{
    $path = str_replace('\\', '/', ltrim(trim($recordingPath), '/'));
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    $root = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (is_file($abs)) {
        return $path;
    }
    if (defined('STORAGE_PATH') && str_starts_with($path, 'storage/')) {
        $underStorage = STORAGE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($path, strlen('storage/')));
        if (is_file($underStorage)) {
            return $path;
        }
    }
    return '';
}

function consultation_recording_segment_duration_label(?string $startedAt, ?string $endedAt): string
{
    $start = ($startedAt !== null && $startedAt !== '') ? strtotime($startedAt) : false;
    $end = ($endedAt !== null && $endedAt !== '') ? strtotime($endedAt) : false;
    if ($start === false || $end === false || $end < $start) {
        return '';
    }
    $sec = (int) ($end - $start);
    if ($sec < 60) {
        return $sec . ' second' . ($sec === 1 ? '' : 's');
    }
    $mins = (int) round($sec / 60);
    return $mins . ' minute' . ($mins === 1 ? '' : 's');
}

/**
 * @return list<array<string, mixed>>
 */
function consultation_recording_segments_list(PDO $pdo, int $consultationId): array
{
    if ($consultationId <= 0) {
        return [];
    }
    consultation_recording_segments_ensure($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT id, consultation_id, video_session_id, segment_index, recording_path,
                   started_at, ended_at, status, upload_key, created_at
            FROM consultation_recording_segments
            WHERE consultation_id = ?
            ORDER BY segment_index ASC, id ASC
        ");
        $stmt->execute([$consultationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $rel = consultation_recording_segment_file_rel((string) ($row['recording_path'] ?? ''));
        $status = strtolower(trim((string) ($row['status'] ?? 'saved')));
        if ($rel === '') {
            $status = 'missing';
        }
        $started = trim((string) ($row['started_at'] ?? ''));
        $ended = trim((string) ($row['ended_at'] ?? ''));
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'segment_index' => (int) ($row['segment_index'] ?? 0),
            'recording_path' => $rel,
            'status' => $status,
            'started_at' => $started,
            'ended_at' => $ended,
            'started_label' => ($started !== '' && strtotime($started)) ? date('g:i A', strtotime($started)) : '',
            'ended_label' => ($ended !== '' && strtotime($ended)) ? date('g:i A', strtotime($ended)) : '',
            'duration_label' => consultation_recording_segment_duration_label(
                $started !== '' ? $started : null,
                $ended !== '' ? $ended : null
            ),
            'playable' => $rel !== '' && $status === 'saved',
        ];
    }
    return $out;
}

function consultation_recording_segments_next_index(PDO $pdo, int $consultationId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(segment_index), 0) FROM consultation_recording_segments WHERE consultation_id = ?');
        $stmt->execute([$consultationId]);
        return ((int) $stmt->fetchColumn()) + 1;
    } catch (PDOException $e) {
        return 1;
    }
}

/**
 * @return array{id:int, path:string, duplicate:bool}|null
 */
function consultation_recording_segment_find_by_key(PDO $pdo, string $uploadKey): ?array
{
    $uploadKey = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', $uploadKey) ?: '', 0, 80);
    if ($uploadKey === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT id, recording_path FROM consultation_recording_segments WHERE upload_key = ? LIMIT 1');
        $stmt->execute([$uploadKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'path' => (string) $row['recording_path'],
        'duplicate' => true,
    ];
}

/**
 * @return array{id:int, path:string, duplicate:bool}
 */
function consultation_recording_segment_save(
    PDO $pdo,
    int $consultationId,
    int $videoSessionId,
    string $relativePath,
    string $uploadKey,
    int $segmentIndex,
    ?string $startedAt,
    ?string $endedAt
): array {
    consultation_recording_segments_ensure($pdo);
    $uploadKey = substr(preg_replace('/[^a-zA-Z0-9._-]/', '', $uploadKey) ?: ('seg-' . time()), 0, 80);
    $existing = consultation_recording_segment_find_by_key($pdo, $uploadKey);
    if ($existing) {
        return $existing;
    }

    $segmentIndex = max(1, $segmentIndex);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO consultation_recording_segments
                (consultation_id, video_session_id, segment_index, recording_path, started_at, ended_at, status, upload_key)
            VALUES (?, ?, ?, ?, ?, ?, 'saved', ?)
        ");
        $stmt->execute([
            $consultationId,
            $videoSessionId,
            $segmentIndex,
            $relativePath,
            $startedAt,
            $endedAt,
            $uploadKey,
        ]);
    } catch (PDOException $e) {
        $again = consultation_recording_segment_find_by_key($pdo, $uploadKey);
        if ($again) {
            return $again;
        }
        throw $e;
    }

    return [
        'id' => (int) $pdo->lastInsertId(),
        'path' => $relativePath,
        'duplicate' => false,
    ];
}
