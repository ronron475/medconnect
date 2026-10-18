<?php
/**
 * Structured prescription lines stored in the existing clinical-note text field.
 * Historical free text is left unchanged. Issuing still goes through prescription_issue().
 */

declare(strict_types=1);

require_once __DIR__ . '/prescription_verification.php';

const PRESCRIPTION_LINES_MARKER = 'MC-RX-1';
const PRESCRIPTION_LINES_LEGACY = 'MC-RX-LEGACY';
const PRESCRIPTION_LINE_MAX = 20;

/** @return array{medication:int,dosage:int,frequency:int,duration:int} */
function prescription_line_limits(): array
{
    return [
        'medication' => 150,
        'dosage' => 100,
        'frequency' => 100,
        'duration' => 100,
    ];
}

function prescription_line_clean(string $value): string
{
    $value = trim($value);
    $collapsed = preg_replace('/\s+/u', ' ', $value);

    return $collapsed === null ? $value : $collapsed;
}

function prescription_line_key(string $medication, string $dosage, string $frequency, string $duration): string
{
    $parts = array_map(
        static function (string $value): string {
            return mb_strtolower(prescription_line_clean($value));
        },
        [$medication, $dosage, $frequency, $duration]
    );

    return implode("\n", $parts);
}

/**
 * @param array<string, mixed> $post
 * @return array{provided:bool,rows:list<array{medication:string,dosage:string,frequency:string,duration:string}>,error:?string}
 */
function prescription_lines_from_post(array $post): array
{
    $medications = $post['rx_medication'] ?? null;
    if (!is_array($medications)) {
        return ['provided' => false, 'rows' => [], 'error' => null];
    }

    $dosages = is_array($post['rx_dosage'] ?? null) ? $post['rx_dosage'] : [];
    $frequencies = is_array($post['rx_frequency'] ?? null) ? $post['rx_frequency'] : [];
    $durations = is_array($post['rx_duration'] ?? null) ? $post['rx_duration'] : [];
    if (count($medications) > PRESCRIPTION_LINE_MAX) {
        return ['provided' => true, 'rows' => [], 'error' => 'Too many medications were submitted.'];
    }

    $limits = prescription_line_limits();
    $rows = [];
    $count = count($medications);
    for ($i = 0; $i < $count; $i++) {
        $raw = [
            'medication' => (string) ($medications[$i] ?? ''),
            'dosage' => (string) ($dosages[$i] ?? ''),
            'frequency' => (string) ($frequencies[$i] ?? ''),
            'duration' => (string) ($durations[$i] ?? ''),
        ];
        foreach ($raw as $value) {
            if (preg_match('/[\r\n\t]/', $value)) {
                return ['provided' => true, 'rows' => [], 'error' => 'Prescription fields cannot contain line breaks.'];
            }
        }
        $row = [
            'medication' => prescription_line_clean($raw['medication']),
            'dosage' => prescription_line_clean($raw['dosage']),
            'frequency' => prescription_line_clean($raw['frequency']),
            'duration' => prescription_line_clean($raw['duration']),
        ];
        $filled = 0;
        foreach ($row as $value) {
            if ($value !== '') {
                $filled++;
            }
        }
        if ($filled === 0) {
            continue;
        }
        if ($filled < 4) {
            return ['provided' => true, 'rows' => [], 'error' => 'Each medication needs a dosage, frequency, and duration.'];
        }
        foreach ($limits as $key => $max) {
            if (mb_strlen($row[$key]) > $max) {
                return ['provided' => true, 'rows' => [], 'error' => 'One of the prescription fields is too long.'];
            }
        }
        $rows[] = $row;
    }

    return ['provided' => true, 'rows' => $rows, 'error' => null];
}

/**
 * @param list<array{medication:string,dosage:string,frequency:string,duration:string}> $rows
 */
function prescription_lines_encode(array $rows, string $legacy = ''): string
{
    $legacy = str_replace(["\r\n", "\r"], "\n", $legacy);
    if ($rows === []) {
        return trim($legacy) === '' ? '' : $legacy;
    }

    $lines = [PRESCRIPTION_LINES_MARKER];
    foreach ($rows as $row) {
        $lines[] = $row['medication'] . "\t" . $row['dosage'] . "\t" . $row['frequency'] . "\t" . $row['duration'];
    }
    if (trim($legacy) !== '') {
        $lines[] = PRESCRIPTION_LINES_LEGACY;
        $lines[] = $legacy;
    }

    return implode("\n", $lines);
}

function prescription_lines_is_encoded(string $stored): bool
{
    $stored = str_replace(["\r\n", "\r"], "\n", $stored);

    return $stored === PRESCRIPTION_LINES_MARKER || str_starts_with($stored, PRESCRIPTION_LINES_MARKER . "\n");
}

/**
 * @return array{rows:list<array{medication:string,dosage:string,frequency:string,duration:string}>,legacy:string}
 */
function prescription_lines_decode(string $stored): array
{
    $stored = str_replace(["\r\n", "\r"], "\n", $stored);
    if (!prescription_lines_is_encoded($stored)) {
        return ['rows' => [], 'legacy' => $stored];
    }

    $lines = explode("\n", $stored);
    array_shift($lines);
    $rows = [];
    $legacy = [];
    $inLegacy = false;
    foreach ($lines as $line) {
        if (!$inLegacy && $line === PRESCRIPTION_LINES_LEGACY) {
            $inLegacy = true;
            continue;
        }
        if ($inLegacy) {
            $legacy[] = $line;
            continue;
        }
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) !== 4) {
            continue;
        }
        $rows[] = [
            'medication' => $parts[0],
            'dosage' => $parts[1],
            'frequency' => $parts[2],
            'duration' => $parts[3],
        ];
    }

    return ['rows' => $rows, 'legacy' => implode("\n", $legacy)];
}

function prescription_lines_display(string $stored): string
{
    if (!prescription_lines_is_encoded($stored)) {
        return $stored;
    }

    $decoded = prescription_lines_decode($stored);
    $lines = [];
    foreach ($decoded['rows'] as $row) {
        $lines[] = $row['medication'] . ' | ' . $row['dosage'] . ' | ' . $row['frequency'] . ' | ' . $row['duration'];
    }
    $legacy = trim($decoded['legacy']);
    if ($legacy !== '') {
        $lines[] = $legacy;
    }

    return implode("\n", $lines);
}

function prescription_line_note(array $row): string
{
    return $row['medication'] . ' | ' . $row['dosage'] . ' | ' . $row['frequency'] . ' | ' . $row['duration'];
}

function prescription_active_duplicate_exists(
    PDO $pdo,
    int $consultationId,
    string $medication,
    string $dosage,
    string $frequency,
    string $duration
): bool {
    $stmt = $pdo->prepare('
        SELECT medication_name, dosage, frequency, duration, status
        FROM prescriptions
        WHERE consultation_id = ?
    ');
    $stmt->execute([$consultationId]);
    $want = prescription_line_key($medication, $dosage, $frequency, $duration);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === PRESCRIPTION_STATUS_REVOKED) {
            continue;
        }
        if ($status !== '' && $status !== PRESCRIPTION_STATUS_ACTIVE) {
            continue;
        }
        $have = prescription_line_key(
            (string) ($row['medication_name'] ?? ''),
            (string) ($row['dosage'] ?? ''),
            (string) ($row['frequency'] ?? ''),
            (string) ($row['duration'] ?? '')
        );
        if ($have === $want) {
            return true;
        }
    }

    return false;
}

/**
 * Issue each distinct structured line. An active match for this consultation is skipped.
 * A revoked row does not block a new issue. The caller owns the transaction.
 *
 * @param list<array{medication:string,dosage:string,frequency:string,duration:string}> $rows
 */
function prescription_issue_lines(
    PDO $pdo,
    int $consultationId,
    int $patientId,
    int $providerId,
    array $rows
): int {
    $issued = 0;
    $seen = [];
    foreach ($rows as $row) {
        $key = prescription_line_key($row['medication'], $row['dosage'], $row['frequency'], $row['duration']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        if (prescription_active_duplicate_exists(
            $pdo,
            $consultationId,
            $row['medication'],
            $row['dosage'],
            $row['frequency'],
            $row['duration']
        )) {
            continue;
        }
        prescription_issue(
            $pdo,
            $consultationId,
            $patientId,
            $providerId,
            $row['medication'],
            $row['dosage'],
            $row['frequency'],
            $row['duration'],
            prescription_line_note($row)
        );
        $issued++;
    }

    return $issued;
}

/**
 * A prescription must name an existing consultation for this provider and patient.
 * Cancelled visits are rejected. This never creates or completes a consultation.
 *
 * @return array{ok:bool,message:string}
 */
function prescription_consultation_can_issue(PDO $pdo, int $providerId, int $patientId, int $consultationId): array
{
    if ($providerId <= 0 || $patientId <= 0 || $consultationId <= 0) {
        return ['ok' => false, 'message' => 'Select the consultation this prescription belongs to.'];
    }

    $stmt = $pdo->prepare('
        SELECT id, patient_id, status
        FROM consultations
        WHERE id = ? AND provider_id = ?
        LIMIT 1
    ');
    $stmt->execute([$consultationId, $providerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['patient_id'] ?? 0) !== $patientId) {
        return ['ok' => false, 'message' => 'Access denied.'];
    }

    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if (in_array($status, ['cancelled', 'canceled'], true)) {
        return ['ok' => false, 'message' => 'A prescription cannot be issued for a cancelled consultation.'];
    }

    $note = $pdo->prepare('
        SELECT finalized_at, signature_data
        FROM clinical_notes
        WHERE consultation_id = ?
        LIMIT 1
    ');
    $note->execute([$consultationId]);
    $noteRow = $note->fetch(PDO::FETCH_ASSOC) ?: null;
    $finalizedAt = trim((string) ($noteRow['finalized_at'] ?? ''));
    $signature = trim((string) ($noteRow['signature_data'] ?? ''));
    if ($noteRow && (
        ($finalizedAt !== '' && $finalizedAt !== '0000-00-00 00:00:00')
        || $signature !== ''
    )) {
        return ['ok' => false, 'message' => 'A prescription cannot be issued after the SOAP note is finalized.'];
    }

    if ($status === 'completed') {
        return ['ok' => false, 'message' => 'A prescription cannot be issued for a completed consultation without a finalized SOAP note.'];
    }

    return ['ok' => true, 'message' => 'ok'];
}
