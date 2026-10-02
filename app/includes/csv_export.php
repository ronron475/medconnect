<?php
/**
 * Shared CSV download helpers for user-facing exports.
 *
 * Every file starts with a UTF-8 BOM so Excel reads accented names (ñ) and dashes correctly,
 * and text cells beginning with = + - @ (or tab / CR) are prefixed with an apostrophe so
 * spreadsheet apps never evaluate them as formulas.
 */

declare(strict_types=1);

function csv_export_safe_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    $text = (string) $value;
    if ($text === '' || is_int($value) || is_float($value)) {
        return $text;
    }
    // Plain numbers (e.g. negative coordinates) stay numeric.
    if (preg_match('/^-?\d+(\.\d+)?$/', $text)) {
        return $text;
    }
    if (strpbrk($text[0], "=+-@\t\r") !== false) {
        return "'" . $text;
    }

    return $text;
}

/**
 * Send download headers, write the BOM and return the output handle.
 *
 * @return resource
 */
function csv_export_begin(string $filename)
{
    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'export.csv';
    if (!str_ends_with(strtolower($safeName), '.csv')) {
        $safeName .= '.csv';
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    return $out;
}

/**
 * @param resource $out
 * @param list<mixed> $cells
 */
function csv_export_row($out, array $cells): void
{
    fputcsv($out, array_map('csv_export_safe_cell', $cells));
}

/** Readable label for snake_case / lowercase status values (e.g. in_consultation → In Consultation). */
function csv_export_label(?string $value, string $empty = '—'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $empty;
    }
    // Already human-readable (mixed case, no underscores) — keep acronyms like "CTAS" intact.
    if (!str_contains($value, '_') && $value !== strtolower($value)) {
        return $value;
    }

    return ucwords(strtolower(str_replace('_', ' ', $value)));
}

function csv_export_sex(?string $value): string
{
    $key = strtolower(trim((string) $value));

    return match ($key) {
        'male', 'm' => 'Male',
        'female', 'f' => 'Female',
        '' => 'Not specified',
        default => ucfirst($key),
    };
}

function csv_export_date(?string $value, string $empty = '—'): string
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return $empty;
    }
    $ts = strtotime($value);

    return $ts !== false ? date('M j, Y', $ts) : $value;
}

/** "Generated" stamp written as text so Excel does not convert it into a narrow date cell. */
function csv_export_generated_at(): string
{
    return date('M j, Y') . ' at ' . date('g:i A');
}
