<?php
/**
 * SOAP electronic signature helpers — typed name or drawn canvas.
 * Identity always comes from the authenticated provider, never from client IDs.
 */

function clinical_note_signature_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM clinical_notes')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $done = true;
        return;
    }

    $byName = [];
    foreach ($cols as $col) {
        $byName[(string) ($col['Field'] ?? '')] = $col;
    }

    $alters = [];
    if (!isset($byName['signature_method'])) {
        $alters[] = "ADD COLUMN signature_method VARCHAR(20) NULL DEFAULT NULL AFTER signature_data";
    }
    if (!isset($byName['signature_name'])) {
        $alters[] = "ADD COLUMN signature_name VARCHAR(255) NULL DEFAULT NULL AFTER signature_method";
    }
    if (!isset($byName['signed_at'])) {
        $alters[] = "ADD COLUMN signed_at DATETIME NULL DEFAULT NULL AFTER signature_name";
    }
    if (!isset($byName['finalized_at'])) {
        $alters[] = "ADD COLUMN finalized_at DATETIME NULL DEFAULT NULL AFTER signed_at";
    }

    if ($alters) {
        try {
            $pdo->exec('ALTER TABLE clinical_notes ' . implode(', ', $alters));
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $sigType = strtolower((string) ($byName['signature_data']['Type'] ?? 'text'));
    if ($sigType !== '' && strpos($sigType, 'mediumtext') === false && strpos($sigType, 'longtext') === false) {
        try {
            $pdo->exec('ALTER TABLE clinical_notes MODIFY signature_data MEDIUMTEXT NULL');
        } catch (PDOException $e) { /* non-fatal */ }
    }

    $done = true;
}

/**
 * @return array{first_name:string,middle_name:string,last_name:string,full_name:string,legal_name:string,display_name:string}
 */
function clinical_note_provider_identity(PDO $pdo, int $providerId): array
{
    $empty = [
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'full_name' => '',
        'legal_name' => '',
        'display_name' => '',
    ];
    if ($providerId <= 0) {
        return $empty;
    }

    $row = false;
    try {
        $stmt = $pdo->prepare('
            SELECT u.first_name, u.last_name,
                   COALESCE(pp.middle_name, \'\') AS middle_name
            FROM users u
            LEFT JOIN provider_profiles pp ON pp.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ');
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['middle_name'] = '';
        }
    }
    if (!$row) {
        return $empty;
    }

    $first = trim((string) ($row['first_name'] ?? ''));
    $middle = trim((string) ($row['middle_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $legal = trim(preg_replace('/\s+/', ' ', $first . ' ' . ($middle !== '' ? $middle . ' ' : '') . $last));
    $full = trim(preg_replace('/\s+/', ' ', $first . ' ' . $last));
    $display = $full !== '' ? (preg_match('/^dr\.?\s+/i', $full) ? $full : 'Dr. ' . $full) : 'Healthcare Provider';

    return [
        'first_name' => $first,
        'middle_name' => $middle,
        'last_name' => $last,
        'full_name' => $full,
        'legal_name' => $legal !== '' ? $legal : $full,
        'display_name' => $display,
    ];
}

function clinical_note_normalize_person_name(string $name): string
{
    $n = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $n = preg_replace('/^(dr\.?|dra\.?|doctor)\s+/iu', '', $n) ?? $n;
    $n = str_replace(['Ñ', 'ñ'], 'n', $n);
    $n = mb_strtolower($n, 'UTF-8');
    $n = preg_replace('/[^\p{L}\s]/u', '', $n) ?? $n;
    return trim(preg_replace('/\s+/u', ' ', $n) ?? '');
}

function clinical_note_typed_name_candidates(array $identity): array
{
    $first = trim((string) ($identity['first_name'] ?? ''));
    $middle = trim((string) ($identity['middle_name'] ?? ''));
    $last = trim((string) ($identity['last_name'] ?? ''));
    $mi = $middle !== '' ? mb_substr($middle, 0, 1, 'UTF-8') : '';

    $candidates = [
        $first . ' ' . $last,
        $first . ' ' . $middle . ' ' . $last,
        $first . ' ' . $mi . ' ' . $last,
        $first . ' ' . $mi . '. ' . $last,
        (string) ($identity['full_name'] ?? ''),
        (string) ($identity['legal_name'] ?? ''),
        (string) ($identity['display_name'] ?? ''),
    ];

    $out = [];
    foreach ($candidates as $candidate) {
        $norm = clinical_note_normalize_person_name($candidate);
        if ($norm !== '') {
            $out[$norm] = $norm;
        }
    }
    return array_values($out);
}

function clinical_note_typed_name_matches(string $typed, array $identity): bool
{
    $typedN = clinical_note_normalize_person_name($typed);
    if ($typedN === '' || mb_strlen($typedN) < 3) {
        return false;
    }
    return in_array($typedN, clinical_note_typed_name_candidates($identity), true);
}

/**
 * @return array{ok:bool,message:string}
 */
function clinical_note_drawn_signature_valid(string $dataUrl): array
{
    $dataUrl = trim($dataUrl);
    if ($dataUrl === '' || strncmp($dataUrl, 'data:image/', 11) !== 0) {
        return [
            'ok' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ];
    }

    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+/=\s]+)$#i', $dataUrl, $m)) {
        return [
            'ok' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ];
    }

    $bin = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
    if ($bin === false || strlen($bin) < 400) {
        return [
            'ok' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ];
    }
    if (strlen($bin) > 450000) {
        return ['ok' => false, 'message' => 'Signature image is too large. Please clear and sign again.'];
    }

    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => true, 'message' => ''];
    }

    $img = @imagecreatefromstring($bin);
    if (!$img) {
        return [
            'ok' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 80 || $h < 40) {
        imagedestroy($img);
        return [
            'ok' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ];
    }

    $ink = 0;
    $stepX = max(1, (int) floor($w / 90));
    $stepY = max(1, (int) floor($h / 50));
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            $rgba = imagecolorat($img, $x, $y);
            $a = ($rgba & 0x7F000000) >> 24;
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;
            if ($a < 110 && ($r < 242 || $g < 242 || $b < 242)) {
                $ink++;
            }
        }
    }
    imagedestroy($img);

    if ($ink < 10) {
        return [
            'ok' => false,
            'message' => 'Please provide your electronic signature before finalizing the SOAP note.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

function clinical_note_is_image_payload(?string $value): bool
{
    return strncmp(trim((string) $value), 'data:image/', 11) === 0;
}

function clinical_note_signed_by_label(array $note, string $fallbackName = ''): string
{
    $name = trim((string) ($note['signature_name'] ?? ''));
    if ($name === '') {
        $raw = trim((string) ($note['signature_data'] ?? ''));
        if ($raw !== '' && !clinical_note_is_image_payload($raw)) {
            $name = $raw;
        }
    }
    if ($name === '') {
        $name = trim($fallbackName);
    }
    if ($name === '') {
        return '';
    }
    if (!preg_match('/^dr\.?\s+/i', $name)) {
        $name = 'Dr. ' . $name;
    }
    return 'Electronically signed by ' . $name;
}

function clinical_note_signed_at_label(array $note): string
{
    $at = trim((string) ($note['signed_at'] ?? $note['finalized_at'] ?? ''));
    if ($at === '' || strtotime($at) === false) {
        return '';
    }
    return 'Signed: ' . date('F j, Y', strtotime($at));
}
