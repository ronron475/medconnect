<?php
/**
 * Runtime schema for case / video consultation violation reports.
 */
declare(strict_types=1);

function case_reports_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'case_reports'")->rowCount();
        if ($exists === 0) {
            $pdo->exec("
                CREATE TABLE case_reports (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    source_type VARCHAR(30) NOT NULL DEFAULT 'case',
                    triage_id INT UNSIGNED NULL,
                    consultation_id INT UNSIGNED NULL,
                    appointment_id INT UNSIGNED NULL,
                    patient_id INT UNSIGNED NOT NULL,
                    reported_by INT UNSIGNED NOT NULL,
                    reason VARCHAR(80) NOT NULL,
                    notes TEXT NULL,
                    consultation_status_at_report VARCHAR(30) NULL,
                    status ENUM('pending', 'under_review', 'dismissed', 'confirmed', 'escalated') NOT NULL DEFAULT 'pending',
                    reviewed_by INT UNSIGNED NULL,
                    reviewed_at DATETIME NULL,
                    admin_note TEXT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_case_reports_triage (triage_id),
                    KEY idx_case_reports_consultation (consultation_id),
                    KEY idx_case_reports_patient (patient_id),
                    KEY idx_case_reports_status (status, created_at),
                    KEY idx_case_reports_reporter (reported_by),
                    KEY idx_case_reports_source (source_type, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $done = true;
            return;
        }

        $cols = $pdo->query('SHOW COLUMNS FROM case_reports')->fetchAll(PDO::FETCH_COLUMN);
        $add = static function (string $col, string $def) use ($pdo, &$cols): void {
            if (!in_array($col, $cols, true)) {
                $pdo->exec("ALTER TABLE case_reports ADD COLUMN {$col} {$def}");
                $cols[] = $col;
            }
        };

        $add('source_type', "VARCHAR(30) NOT NULL DEFAULT 'case' AFTER id");
        $add('consultation_id', 'INT UNSIGNED NULL AFTER triage_id');
        $add('appointment_id', 'INT UNSIGNED NULL AFTER consultation_id');
        $add('consultation_status_at_report', 'VARCHAR(30) NULL AFTER notes');

        if (in_array('triage_id', $cols, true)) {
            try {
                $pdo->exec('ALTER TABLE case_reports MODIFY COLUMN triage_id INT UNSIGNED NULL');
            } catch (PDOException $e) {
                // Column may already be nullable.
            }
        }

        try {
            $pdo->exec('CREATE INDEX idx_case_reports_consultation ON case_reports (consultation_id)');
        } catch (PDOException $e) {
        }
        try {
            $pdo->exec('CREATE INDEX idx_case_reports_source ON case_reports (source_type, created_at)');
        } catch (PDOException $e) {
        }
        $done = true;
    } catch (PDOException $e) {
        error_log('case_reports_ensure_schema: ' . $e->getMessage());
    }
}

function case_report_source_case(): string
{
    return 'case';
}

function case_report_source_video(): string
{
    return 'video_consultation';
}

function case_report_source_label(string $source): string
{
    return match (strtolower(trim($source))) {
        'video_consultation', 'video' => 'Video Consultation',
        default => 'Case',
    };
}

/** @return list<string> */
function case_report_valid_case_reasons(): array
{
    return [
        'prank_fake',
        'spam_irrelevant',
        'abusive_inappropriate',
        'false_misleading',
        'repeated_suspicious',
        'other',
    ];
}

/** @return list<string> */
function case_report_valid_video_reasons(): array
{
    return [
        'abusive_language',
        'harassment',
        'threatening_behavior',
        'sexual_inappropriate',
        'inappropriate_gestures',
        'nudity_exposure',
        'unauthorized_recording',
        'sharing_private_info',
        'misuse_personal_info',
        'impersonation',
        'fraud_deceptive',
        'repeated_prank',
        'spam_disruptive',
        'disrupting_consultation',
        'unauthorized_use',
        'suspicious_malicious',
        'platform_rules_violation',
        'other',
    ];
}

/** @return list<string> */
function case_report_valid_reasons(): array
{
    return array_values(array_unique(array_merge(
        case_report_valid_case_reasons(),
        case_report_valid_video_reasons()
    )));
}

function case_report_reason_label(string $reason): string
{
    return match ($reason) {
        'prank_fake' => 'Suspected prank / fake submission',
        'spam_irrelevant' => 'Spam / irrelevant submission',
        'abusive_inappropriate' => 'Abusive / inappropriate content',
        'false_misleading' => 'False or misleading information',
        'repeated_suspicious' => 'Repeated suspicious submission',
        'abusive_language' => 'Abusive or offensive language',
        'harassment' => 'Harassment',
        'threatening_behavior' => 'Threatening behavior',
        'sexual_inappropriate' => 'Sexual or inappropriate behavior',
        'inappropriate_gestures' => 'Inappropriate gestures or actions',
        'nudity_exposure' => 'Nudity or inappropriate exposure',
        'unauthorized_recording' => 'Recording or photographing without authorization',
        'sharing_private_info' => "Sharing another person's private information",
        'misuse_personal_info' => "Attempting to obtain or misuse another person's information",
        'impersonation' => 'Impersonation / pretending to be another person',
        'fraud_deceptive' => 'Fraudulent or deceptive behavior',
        'repeated_prank' => 'Repeated prank behavior',
        'spam_disruptive' => 'Spam or disruptive behavior',
        'disrupting_consultation' => 'Intentionally disrupting the consultation',
        'unauthorized_use' => 'Unauthorized use of the video consultation',
        'suspicious_malicious' => 'Suspicious or potentially malicious behavior',
        'platform_rules_violation' => 'Violation of platform rules',
        'other' => 'Other',
        default => ucfirst(str_replace('_', ' ', $reason)),
    };
}

function case_report_status_label(string $status): string
{
    return match (strtolower(trim($status))) {
        'pending'      => 'Pending Review',
        'under_review' => 'Under Review',
        'dismissed'    => 'Dismissed',
        'confirmed'    => 'Confirmed',
        'escalated'    => 'Escalated',
        default        => ucfirst(str_replace('_', ' ', $status)),
    };
}

function case_report_consultation_ref(?int $consultationId): string
{
    if (!$consultationId || $consultationId <= 0) {
        return '';
    }

    return 'CONS-' . str_pad((string) $consultationId, 6, '0', STR_PAD_LEFT);
}

/** @return list<string> */
function case_report_active_statuses(): array
{
    return ['pending', 'under_review', 'escalated'];
}
