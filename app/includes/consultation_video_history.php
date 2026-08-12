<?php
/**
 * Shared video-session summary for Provider History and Patient Past Sessions.
 * Reads existing video_sessions rows — does not invent timestamps or recordings.
 */
declare(strict_types=1);

/**
 * Human duration from real started_at / ended_at (empty when either is missing).
 */
function consultation_format_video_duration(?string $startedAt, ?string $endedAt): string
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
    if ($mins < 60) {
        return $mins . ' minute' . ($mins === 1 ? '' : 's');
    }

    $hours = intdiv($mins, 60);
    $rem = $mins % 60;
    $label = $hours . ' hour' . ($hours === 1 ? '' : 's');
    if ($rem > 0) {
        $label .= ' ' . $rem . ' minute' . ($rem === 1 ? '' : 's');
    }
    return $label;
}

/**
 * Resolve a stored recording path only when the file actually exists on disk.
 */
function consultation_video_recording_public_path(?string $recordingPath, ?string $recordingUrl = null): string
{
    $candidates = [];
    foreach ([$recordingPath, $recordingUrl] as $raw) {
        $path = trim((string) $raw);
        if ($path === '') {
            continue;
        }
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        if (str_contains($path, '..')) {
            continue;
        }
        $candidates[] = $path;
    }

    $root = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
    foreach ($candidates as $rel) {
        $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs)) {
            return $rel;
        }
    }

    return '';
}

/**
 * Authorized player URL for a stored consultation recording.
 */
function consultation_video_recording_view_url(int $consultationId): string
{
    $base = defined('ASSET_BASE') ? (string) ASSET_BASE : '';
    return $base . '/app/api/consultations/view_recording.php?consultation_id=' . $consultationId;
}

/**
 * Latest video_sessions row for a consultation (real DB data only).
 *
 * @return array<string, mixed>|null
 */
function consultation_video_session_row(PDO $pdo, int $consultationId): ?array
{
    if ($consultationId <= 0) {
        return null;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM video_sessions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($cols) || $cols === []) {
        return null;
    }

    $hasUrl = in_array('recording_url', $cols, true);
    $urlSelect = $hasUrl ? 'recording_url' : 'NULL AS recording_url';

    try {
        $stmt = $pdo->prepare("
            SELECT id, consultation_id, room_token, status, started_at, ended_at,
                   recording_path, {$urlSelect}
            FROM video_sessions
            WHERE consultation_id = ?
            ORDER BY
                CASE WHEN status = 'active' THEN 0 ELSE 1 END,
                id DESC
            LIMIT 1
        ");
        $stmt->execute([$consultationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('consultation_video_session_row: ' . $e->getMessage());
        return null;
    }
}

/**
 * Build display summary for history cards / detail panels.
 *
 * @param array<string, mixed>|null $videoRow
 * @return array{
 *   has_session: bool,
 *   video_status: string,
 *   video_status_label: string,
 *   show_completed_details: bool,
 *   date_label: string,
 *   started_label: string,
 *   ended_label: string,
 *   duration_label: string,
 *   recording_path: string,
 *   has_recording: bool,
 *   timeline: list<array{label:string,time_label:string}>
 * }
 */
function consultation_video_history_summary(
    string $consultationStatus,
    ?array $videoRow,
    ?string $consultationCompletedAt = null,
    string $doctorDisplayName = '',
    string $patientDisplayName = ''
): array {
    $status = strtolower(trim($consultationStatus));
    $status = str_replace(' ', '_', $status);

    $empty = [
        'has_session' => false,
        'video_status' => '',
        'video_status_label' => 'Not started',
        'show_completed_details' => false,
        'date_label' => '',
        'started_label' => '',
        'ended_label' => '',
        'duration_label' => '',
        'recording_path' => '',
        'has_recording' => false,
        'timeline' => [],
        'participants_label' => '',
        'session_outcome_label' => '',
    ];

    if (in_array($status, ['scheduled', 'pending'], true)) {
        $empty['video_status_label'] = 'Not started';
        return $empty;
    }

    if ($status === 'cancelled') {
        $empty['video_status_label'] = 'Not started';
        return $empty;
    }

    if ($status === 'in_consultation') {
        $vsStatus = strtolower(trim((string) ($videoRow['status'] ?? '')));
        if ($videoRow && $vsStatus === 'active') {
            $empty['has_session'] = true;
            $empty['video_status'] = 'active';
            $empty['video_status_label'] = 'In progress';
            $started = (string) ($videoRow['started_at'] ?? '');
            if ($started !== '' && strtotime($started)) {
                $empty['started_label'] = date('g:i A', strtotime($started));
                $empty['date_label'] = date('M j, Y', strtotime($started));
            }
            return $empty;
        }
        $empty['has_session'] = (bool) $videoRow;
        $empty['video_status_label'] = 'In progress';
        return $empty;
    }

    // completed (and any other terminal-like status): only show completed video
    // details when real start+end exist on an ended (or any) session row.
    if (!$videoRow) {
        $empty['video_status_label'] = 'Not started';
        return $empty;
    }

    $startedAt = trim((string) ($videoRow['started_at'] ?? ''));
    $endedAt = trim((string) ($videoRow['ended_at'] ?? ''));
    $vsStatus = strtolower(trim((string) ($videoRow['status'] ?? '')));
    $duration = consultation_format_video_duration($startedAt, $endedAt);
    $recordingRel = consultation_video_recording_public_path(
        (string) ($videoRow['recording_path'] ?? ''),
        (string) ($videoRow['recording_url'] ?? '')
    );

    $summary = $empty;
    $summary['has_session'] = true;
    $summary['video_status'] = $vsStatus;
    $summary['recording_path'] = $recordingRel;
    $summary['has_recording'] = $recordingRel !== '';

    $doctor = trim($doctorDisplayName);
    $patient = trim($patientDisplayName);
    if ($doctor !== '' || $patient !== '') {
        $parts = array_values(array_filter([
            $patient !== '' ? $patient : null,
            $doctor !== '' ? $doctor : null,
        ]));
        $summary['participants_label'] = implode(' + ', $parts);
    }

    if ($status === 'completed' && $startedAt !== '' && $endedAt !== '' && $duration !== '') {
        $summary['show_completed_details'] = true;
        $summary['video_status_label'] = 'Completed';
        $summary['duration_label'] = $duration;
        $summary['date_label'] = date('M j, Y', strtotime($startedAt) ?: time());
        $summary['started_label'] = date('g:i A', strtotime($startedAt));
        $summary['ended_label'] = date('g:i A', strtotime($endedAt));
        $summary['session_outcome_label'] = 'Successfully completed';

        $timeline = [];
        $timeline[] = [
            'label' => 'Video consultation started',
            'time_label' => $summary['started_label'],
        ];
        $timeline[] = [
            'label' => 'Video consultation ended',
            'time_label' => $summary['ended_label'],
        ];
        $completedAt = trim((string) ($consultationCompletedAt ?? ''));
        if ($completedAt !== '' && strtotime($completedAt)) {
            $timeline[] = [
                'label' => 'Session completed',
                'time_label' => date('g:i A', strtotime($completedAt)),
            ];
        } else {
            $timeline[] = [
                'label' => 'Session completed',
                'time_label' => $summary['ended_label'],
            ];
        }
        $summary['timeline'] = $timeline;
        return $summary;
    }

    if ($vsStatus === 'active') {
        $summary['video_status_label'] = 'In progress';
        return $summary;
    }

    if ($startedAt !== '' && $endedAt === '') {
        $summary['video_status_label'] = 'Ended without recorded end time';
        $summary['started_label'] = date('g:i A', strtotime($startedAt));
        $summary['date_label'] = date('M j, Y', strtotime($startedAt));
        return $summary;
    }

    $summary['video_status_label'] = 'Not started';
    $summary['has_session'] = false;
    return $summary;
}

/**
 * Attach video history summary onto consultation rows (mutates by reference).
 *
 * @param list<array<string,mixed>> $consultations
 */
function consultation_video_history_enrich_rows(
    PDO $pdo,
    array &$consultations,
    string $doctorNameKey = 'doctor_name',
    string $patientName = ''
): void {
    foreach ($consultations as &$row) {
        $cid = (int) ($row['id'] ?? 0);
        $videoRow = $cid > 0 ? consultation_video_session_row($pdo, $cid) : null;
        $row['video_session'] = $videoRow;
        $row['video_history'] = consultation_video_history_summary(
            (string) ($row['status'] ?? ''),
            $videoRow,
            isset($row['completed_at']) ? (string) $row['completed_at'] : null,
            (string) ($row[$doctorNameKey] ?? ''),
            $patientName
        );
    }
    unset($row);
}
