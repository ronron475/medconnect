<?php
/**
 * Search the local ICD-10-CM order file. Billable codes only.
 */
declare(strict_types=1);

function icd10_order_file(): string
{
    return BASE_PATH . '/data/nlp/source/icd10cm_order_2025.txt';
}

function icd10_format_code(string $raw): string
{
    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $raw) ?? '');
    if (strlen($raw) <= 3) {
        return $raw;
    }
    return substr($raw, 0, 3) . '.' . substr($raw, 3);
}

function icd10_label(string $code, string $description): string
{
    return $code . ' – ' . trim($description);
}

/**
 * @return array{code: string, description: string, label: string}|null
 */
function icd10_parse_order_line(string $line): ?array
{
    if (strlen($line) < 16) {
        return null;
    }
    $code = icd10_format_code(substr($line, 6, 7));
    $billable = substr($line, 14, 1) === '1';
    if ($code === '' || !$billable) {
        return null;
    }
    $description = trim(substr($line, 16, 60));
    if ($description === '') {
        $description = trim(substr($line, 77));
    }
    if ($description === '') {
        return null;
    }
    return [
        'code' => $code,
        'description' => $description,
        'label' => icd10_label($code, $description),
    ];
}

/**
 * @return list<array{code: string, description: string, label: string}>
 */
function icd10_search(string $query, int $limit = 12): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2) {
        return [];
    }
    $file = icd10_order_file();
    if (!is_readable($file)) {
        return [];
    }
    $needle = mb_strtolower($query);
    $codeNeedle = strtoupper(preg_replace('/[^A-Z0-9.]/', '', $query) ?? '');
    $limit = max(1, min(20, $limit));
    $out = [];
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return [];
    }
    while (($line = fgets($handle)) !== false) {
        $row = icd10_parse_order_line($line);
        if ($row === null) {
            continue;
        }
        $codeHit = $codeNeedle !== '' && str_starts_with(str_replace('.', '', $row['code']), str_replace('.', '', $codeNeedle));
        $textHit = str_contains(mb_strtolower($row['description']), $needle)
            || str_contains(mb_strtolower($row['code']), $needle);
        if (!$codeHit && !$textHit) {
            continue;
        }
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }
    fclose($handle);
    return $out;
}

/**
 * Accept only a label produced by this lookup.
 *
 * @return array{code: string, description: string, label: string}|null
 */
function icd10_match_label(string $label): ?array
{
    $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
    if (!preg_match('/^([A-Z][0-9][0-9A-Z.]{1,8})\s+[–-]\s+(.+)$/u', $label, $m)) {
        return null;
    }
    $wanted = strtoupper($m[1]);
    $file = icd10_order_file();
    if (!is_readable($file)) {
        return null;
    }
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        return null;
    }
    $found = null;
    while (($line = fgets($handle)) !== false) {
        $row = icd10_parse_order_line($line);
        if ($row === null || $row['code'] !== $wanted) {
            continue;
        }
        $found = $row;
        break;
    }
    fclose($handle);
    if ($found === null) {
        return null;
    }
    return $found['label'] === $label ? $found : null;
}
