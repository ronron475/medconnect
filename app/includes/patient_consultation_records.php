<?php
/**
 * Patient-visible consultation records — one consultation row, finalized SOAP only.
 */

function patient_consultation_records_schema_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('completed_at', $cols, true)) {
            $pdo->exec('ALTER TABLE consultations ADD COLUMN completed_at DATETIME NULL DEFAULT NULL AFTER status');
        }
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM clinical_notes')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('finalized_at', $cols, true)) {
            $pdo->exec('ALTER TABLE clinical_notes ADD COLUMN finalized_at DATETIME NULL DEFAULT NULL AFTER signature_data');
        }
    } catch (PDOException $e) { /* non-fatal */ }

    $done = true;
}

function patient_consultation_is_finalized(string $consultationStatus, ?string $signatureData): bool
{
    return strtolower(trim($consultationStatus)) === 'completed'
        && trim((string) $signatureData) !== '';
}

function patient_consultation_record_visible_sql(string $consultAlias = 'c', string $noteAlias = 'cn'): string
{
    return "{$consultAlias}.status = 'completed'
        AND {$noteAlias}.signature_data IS NOT NULL
        AND TRIM({$noteAlias}.signature_data) <> ''";
}

function patient_consultation_detail_url(int $consultationId): string
{
    $base = defined('ASSET_BASE') ? (string) ASSET_BASE : '';
    return $base . '/views/patient/consultation_detail.php?id=' . $consultationId;
}
