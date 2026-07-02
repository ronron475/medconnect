<?php
/**
 * audit_log.php — medConnect Patient Profile Audit Logger
 *
 * Provides a single function: audit_log()
 * Call it after any successful profile action to record what changed,
 * who did it, and when.
 *
 * Usage (in any controller, after a successful DB write):
 *
 *   require_once BASE_PATH . '/app/includes/audit_log.php';
 *
 *   audit_log($pdo, [
 *       'patient_id'  => $user_id,
 *       'action_type' => AuditAction::CONTACT_UPDATED,
 *       'description' => 'Patient updated contact number and barangay.',
 *       'meta'        => ['fields_changed' => ['contact_number', 'barangay']],
 *   ]);
 *
 * The $pdo parameter is optional — if null, the entry is written to a
 * fallback flat-file log so no audit event is silently lost.
 *
 * [DB_HOOK] — MySQL insert is commented in audit_log_to_db().
 *             Uncomment once the patient_audit_logs table exists.
 */

// ── Action type constants ─────────────────────────────────────────────────────

/**
 * Centralised list of all auditable action types.
 * Add new constants here as the system grows.
 */
final class AuditAction
{
    // Profile module
    const CONTACT_UPDATED          = 'contact_updated';
    const MEDICAL_PROFILE_UPDATED  = 'medical_profile_updated';
    const RESIDENCY_DOC_UPLOADED   = 'residency_doc_uploaded';
    const RESIDENCY_DOC_VERIFIED   = 'residency_doc_verified';
    const RESIDENCY_DOC_MISMATCH   = 'residency_doc_mismatch';
    const PASSWORD_CHANGED         = 'password_changed';
    const PROFILE_VIEWED           = 'profile_viewed';

    // Auth module (for future use)
    const LOGIN_SUCCESS            = 'login_success';
    const LOGIN_FAILED             = 'login_failed';
    const LOGOUT                   = 'logout';
    const OTP_SENT                 = 'otp_sent';
    const OTP_VERIFIED             = 'otp_verified';
    const PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    const PASSWORD_RESET_COMPLETED = 'password_reset_completed';
    const PATIENT_REGISTERED       = 'patient_registered';
    const ACCOUNT_SETUP_COMPLETED  = 'account_setup_completed';

    // Admin / CHO actions (for future use)
    const ADMIN_DOC_APPROVED       = 'admin_doc_approved';
    const ADMIN_DOC_REJECTED       = 'admin_doc_rejected';
    const ADMIN_ACCOUNT_RESTRICTED = 'admin_account_restricted';
    const ACCOUNT_UPDATE           = 'account_update';
    const ACCOUNT_STATUS_CHANGED   = 'account_status_changed';
    const ACCOUNT_RESTORED           = 'account_restored';
    const REPORT_EXPORT            = 'report_export';

    // Messaging module
    const MESSAGE_DELETED_FOR_ME       = 'message_deleted_for_me';
    const MESSAGE_DELETED_FOR_EVERYONE = 'message_deleted_for_everyone';

    // Announcement module
    const ANNOUNCEMENT_CREATED     = 'announcement_created';
    const ANNOUNCEMENT_EDITED      = 'announcement_edited';
    const ANNOUNCEMENT_PUBLISHED   = 'announcement_published';
    const ANNOUNCEMENT_UNPUBLISHED = 'announcement_unpublished';
    const ANNOUNCEMENT_DELETED     = 'announcement_deleted';
    const ANNOUNCEMENT_ARCHIVED    = 'announcement_archived';
    const ANNOUNCEMENT_RESTORED    = 'announcement_restored';
}

// ── Suggested DDL ─────────────────────────────────────────────────────────────
//
// Run this once when the database is ready:
//
//   CREATE TABLE IF NOT EXISTS patient_audit_logs (
//       id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
//       patient_id   INT UNSIGNED     NOT NULL,
//       action_type  VARCHAR(80)      NOT NULL,
//       description  TEXT             NOT NULL,
//       meta         JSON             NULL,        -- optional structured detail
//       ip_address   VARCHAR(45)      NULL,        -- supports IPv4 + IPv6
//       user_agent   VARCHAR(255)     NULL,
//       created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
//       INDEX idx_patient    (patient_id),
//       INDEX idx_action     (action_type),
//       INDEX idx_created_at (created_at)
//   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
//
// ─────────────────────────────────────────────────────────────────────────────

// ── Public API ────────────────────────────────────────────────────────────────

/**
 * Record an audit event.
 *
 * @param PDO|null $pdo        Active PDO connection, or null to use fallback file log.
 * @param array    $entry {
 *     @type int         $patient_id   Required. The user/patient ID.
 *     @type string      $action_type  Required. One of the AuditAction constants.
 *     @type string      $description  Required. Human-readable summary of the action.
 *     @type array|null  $meta         Optional. Structured detail (fields changed, file names, etc.).
 * }
 * @return bool  True on success, false if both DB and file log failed.
 */
function audit_log(?PDO $pdo, array $entry): bool
{
    // ── Build the normalised log record ──────────────────────────────────────

    $record = [
        'patient_id'  => (int)($entry['patient_id']  ?? 0),
        'action_type' => trim((string)($entry['action_type'] ?? 'unknown')),
        'description' => trim((string)($entry['description'] ?? '')),
        'meta'        => $entry['meta'] ?? null,
        'ip_address'  => _audit_get_ip(),
        'user_agent'  => _audit_get_ua(),
        'created_at'  => date('Y-m-d H:i:s'),
    ];

    // Guard: patient_id and action_type are mandatory
    if ($record['patient_id'] === 0 || $record['action_type'] === '') {
        _audit_file_log('INVALID_ENTRY', $record);
        return false;
    }

    // ── Try DB first ─────────────────────────────────────────────────────────

    if ($pdo !== null) {
        $db_ok = audit_log_to_db($pdo, $record);
        if ($db_ok) {
            return true;
        }
        // DB write failed — fall through to file log so nothing is lost
        _audit_file_log('DB_WRITE_FAILED', $record);
    }

    // ── Fallback: flat-file log ───────────────────────────────────────────────

    return _audit_file_log('OK', $record);
}

// ── DB writer ─────────────────────────────────────────────────────────────────

/**
 * Write the audit record to the patient_audit_logs table.
 *
 * [DB_HOOK] — Uncomment the PDO block once the table exists.
 *
 * @param PDO   $pdo
 * @param array $record  Normalised record from audit_log().
 * @return bool
 */
function audit_log_to_db(PDO $pdo, array $record): bool
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO patient_audit_logs
                (patient_id, action_type, description, meta, ip_address, user_agent, created_at)
            VALUES
                (:patient_id, :action_type, :description, :meta, :ip_address, :user_agent, :created_at)
        ");

        return $stmt->execute([
            ':patient_id'  => $record['patient_id'],
            ':action_type' => $record['action_type'],
            ':description' => $record['description'],
            ':meta'        => $record['meta'] !== null
                                  ? json_encode($record['meta'], JSON_UNESCAPED_UNICODE)
                                  : null,
            ':ip_address'  => $record['ip_address'],
            ':user_agent'  => $record['user_agent'],
            ':created_at'  => $record['created_at'],
        ]);

    } catch (PDOException $e) {
        // Log the PDO error to the file log for diagnosis
        _audit_file_log('PDO_EXCEPTION:' . $e->getMessage(), $record);
        return false;
    }
}

// ── Fallback flat-file logger ─────────────────────────────────────────────────

/**
 * Write a JSON-encoded audit line to a date-rotated flat file.
 * Used when the DB is unavailable or not yet set up.
 *
 * Log location: storage/logs/audit/audit_YYYY-MM-DD.log
 * Each line is a self-contained JSON object for easy parsing later.
 *
 * @param string $note    Short status note prepended to the record (e.g. 'OK', 'DB_WRITE_FAILED').
 * @param array  $record  Normalised audit record.
 * @return bool
 */
function _audit_file_log(string $note, array $record): bool
{
    $log_dir = (defined('STORAGE_PATH') ? STORAGE_PATH : dirname(__DIR__, 2) . '/storage') . '/logs/audit/';

    // Create directory if it doesn't exist
    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0750, true)) {
            return false; // Can't create log dir — silent fail, don't crash the app
        }
        // Protect the log directory from direct web access
        @file_put_contents($log_dir . '.htaccess', "Deny from all\n");
    }

    $log_file = $log_dir . 'audit_' . date('Y-m-d') . '.log';

    $line = json_encode([
        '_note'       => $note,
        'patient_id'  => $record['patient_id'],
        'action_type' => $record['action_type'],
        'description' => $record['description'],
        'meta'        => $record['meta'],
        'ip_address'  => $record['ip_address'],
        'user_agent'  => $record['user_agent'],
        'created_at'  => $record['created_at'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // FILE_APPEND + LOCK_EX — safe for concurrent writes
    $written = @file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    return $written !== false;
}

// ── Internal helpers ──────────────────────────────────────────────────────────

/**
 * Resolve the real client IP address.
 * Checks common proxy headers but always validates format.
 * Never trusts a header blindly — falls back to REMOTE_ADDR.
 */
function _audit_get_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP']  ?? '',   // Cloudflare
        $_SERVER['HTTP_X_REAL_IP']         ?? '',   // Nginx proxy
        $_SERVER['HTTP_X_FORWARDED_FOR']   ?? '',   // Standard proxy (may be comma-list)
        $_SERVER['REMOTE_ADDR']            ?? '',
    ];

    foreach ($candidates as $raw) {
        // X-Forwarded-For can be "client, proxy1, proxy2" — take the first
        $ip = trim(explode(',', $raw)[0]);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return 'unknown';
}

/**
 * Return a truncated User-Agent string (max 255 chars).
 */
function _audit_get_ua(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return mb_substr(trim($ua), 0, 255);
}
