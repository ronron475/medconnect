<?php
/**
 * Format triage symptoms for display (JSON array or plain text).
 */
function mc_format_triage_symptoms(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '—';
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return htmlspecialchars(implode(', ', array_map('strval', $decoded)));
    }
    return htmlspecialchars($raw);
}

function mc_triage_risk_class(string $level): string
{
    if (in_array($level, ['1', 'high', 'emergency', 'urgent', 'EMERGENCY'], true)) {
        return 'badge-risk--high';
    }
    if (in_array($level, ['2', 'moderate', 'non-urgent', 'URGENT'], true)) {
        return 'badge-risk--moderate';
    }
    return 'badge-risk--low';
}

function mc_triage_level_label(string $level, ?string $urgency_label = null): string
{
    if ($urgency_label) {
        return htmlspecialchars($urgency_label);
    }
    $map = [
        '1' => 'Emergency',
        '2' => 'Urgent',
        '3' => 'Non-Urgent',
        'EMERGENCY' => 'Emergency',
        'URGENT' => 'Urgent',
        'NON_URGENT' => 'Non-Urgent',
    ];
    return htmlspecialchars($map[$level] ?? strtoupper($level));
}
