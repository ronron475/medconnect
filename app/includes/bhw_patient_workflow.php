<?php
/**
 * BHW patient facilitator workflow — status tracking (non-clinical).
 */
require_once dirname(__DIR__) . '/core/TriageLevelService.php';

final class BhwPatientWorkflow
{
    public const REGISTERED = 'registered';
    public const AWAITING_COMPLAINT = 'awaiting_complaint';
    public const AI_PROCESSING = 'ai_processing';
    public const EMERGENCY = 'emergency';
    public const URGENT = 'urgent';
    public const NON_URGENT = 'non_urgent';
    public const APPOINTMENT_SCHEDULED = 'appointment_scheduled';
    public const REFERRAL_GENERATED = 'referral_generated';
    public const CONSULTATION_COMPLETED = 'consultation_completed';
    public const FOLLOW_UP = 'follow_up_monitoring';

    public static function ensure_schema(PDO $pdo): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $cols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('workflow_status', $cols, true)) {
            try {
                $pdo->exec("
                    ALTER TABLE patient_registrations
                    ADD COLUMN workflow_status VARCHAR(40) NOT NULL DEFAULT 'registered'
                    COMMENT 'BHW facilitator workflow state' AFTER status
                ");
            } catch (PDOException $e) { /* non-fatal */ }
        }

        $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('consult_priority', $consultCols, true)) {
            try {
                $pdo->exec("
                    ALTER TABLE consultations
                    ADD COLUMN consult_priority ENUM('standard','urgent','emergency') NOT NULL DEFAULT 'standard'
                    AFTER status
                ");
            } catch (PDOException $e) { /* non-fatal */ }
        }

        $ready = true;
    }

    /** @return list<string> */
    public static function validStatuses(): array
    {
        return [
            self::REGISTERED,
            self::AWAITING_COMPLAINT,
            self::AI_PROCESSING,
            self::EMERGENCY,
            self::URGENT,
            self::NON_URGENT,
            self::APPOINTMENT_SCHEDULED,
            self::REFERRAL_GENERATED,
            self::CONSULTATION_COMPLETED,
            self::FOLLOW_UP,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::REGISTERED             => 'Registered',
            self::AWAITING_COMPLAINT     => 'Waiting for Chief Complaint',
            self::AI_PROCESSING          => 'AI Triage Processing',
            self::EMERGENCY              => 'Emergency',
            self::URGENT                 => 'Urgent',
            self::NON_URGENT             => 'Non-Urgent',
            self::APPOINTMENT_SCHEDULED  => 'Appointment Scheduled',
            self::REFERRAL_GENERATED     => 'Referral Generated',
            self::CONSULTATION_COMPLETED => 'Consultation Completed',
            self::FOLLOW_UP              => 'Follow-up Monitoring',
            default                      => ucwords(str_replace('_', ' ', $status)),
        };
    }

    public static function fromTriageTier(string $tier): string
    {
        return match (TriageLevelService::fromDbLevel($tier)) {
            TriageLevelService::EMERGENCY  => self::EMERGENCY,
            TriageLevelService::URGENT     => self::URGENT,
            default                        => self::NON_URGENT,
        };
    }

    public static function getStatus(PDO $pdo, int $patientId): string
    {
        self::ensure_schema($pdo);
        $stmt = $pdo->prepare('
            SELECT pr.workflow_status
            FROM patient_registrations pr
            JOIN users u ON u.email = pr.email
            WHERE u.id = ? LIMIT 1
        ');
        $stmt->execute([$patientId]);
        $status = (string) ($stmt->fetchColumn() ?: self::REGISTERED);

        return in_array($status, self::validStatuses(), true) ? $status : self::REGISTERED;
    }

    public static function setStatus(PDO $pdo, int $patientId, string $status, array $meta = []): void
    {
        if (!in_array($status, self::validStatuses(), true)) {
            return;
        }
        self::ensure_schema($pdo);
        $stmt = $pdo->prepare('
            UPDATE patient_registrations pr
            JOIN users u ON u.email = pr.email
            SET pr.workflow_status = ?
            WHERE u.id = ?
        ');
        $stmt->execute([$status, $patientId]);

        if (function_exists('bhw_audit')) {
            bhw_audit($pdo, $patientId, 'bhw_workflow_status', 'Patient workflow status → ' . self::label($status), array_merge([
                'workflow_status' => $status,
            ], $meta));
        }
    }

    /** Self-registration: patient account exists but chief complaint not yet recorded. */
    public static function onSelfRegistration(PDO $pdo, int $patientId): void
    {
        self::setStatus($pdo, $patientId, self::AWAITING_COMPLAINT, [
            'source' => 'self_registration',
        ]);
    }

    /** Patient portal triage + booking completed by the resident. */
    public static function onPatientPortalBooking(PDO $pdo, int $patientId, string $triageTier): void
    {
        self::setStatus($pdo, $patientId, self::APPOINTMENT_SCHEDULED, [
            'source'       => 'patient_portal',
            'triage_tier'  => $triageTier,
        ]);
    }

    /** Provider finalized consultation or session auto-completed. */
    public static function onConsultationCompleted(PDO $pdo, int $patientId, string $source = 'provider'): void
    {
        self::setStatus($pdo, $patientId, self::CONSULTATION_COMPLETED, [
            'source' => $source,
        ]);
    }

    /** BHW logged a follow-up home visit. */
    public static function onFollowUpMonitoring(PDO $pdo, int $patientId): void
    {
        self::setStatus($pdo, $patientId, self::FOLLOW_UP, [
            'source' => 'home_visit',
        ]);
    }
}
