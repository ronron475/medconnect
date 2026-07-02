<?php
/**
 * MedConnect Notification Service
 * Centralized notification creation, delivery, and retrieval.
 */

final class NotificationManager
{
    public const TYPE_SUCCESS      = 'success';
    public const TYPE_INFORMATION  = 'information';
    public const TYPE_WARNING      = 'warning';
    public const TYPE_CRITICAL     = 'critical';
    public const TYPE_EMERGENCY    = 'emergency';
    public const TYPE_REMINDER     = 'reminder';
    public const TYPE_SYSTEM       = 'system';
    public const TYPE_SECURITY     = 'security';
    public const TYPE_MEDICAL      = 'medical';
    public const TYPE_APPOINTMENT  = 'appointment';
    public const TYPE_CONSULTATION = 'consultation';
    public const TYPE_REFERRAL     = 'referral';
    public const TYPE_GIS          = 'gis';
    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DELETED  = 'deleted';

    private static bool $schemaReady = false;

    /** @var array<string, string> */
    private static array $roleDashboardPaths = [
        'admin'       => '/views/admin/dashboard.php',
        'superadmin'  => '/views/superadmin/dashboard.php',
        'provider' => '/views/provider/dashboard.php',
        'patient'  => '/views/patient/dashboard.php',
        'bhw'      => '/views/bhw/dashboard.php',
    ];

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaReady) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            sender_id       INT UNSIGNED NULL,
            receiver_role   VARCHAR(20)  NULL,
            type            VARCHAR(50)  NOT NULL DEFAULT 'information',
            title           VARCHAR(255) NOT NULL,
            message         TEXT         NOT NULL,
            priority        ENUM('low','normal','high','critical','emergency') NOT NULL DEFAULT 'normal',
            related_table   VARCHAR(80)  NULL,
            related_id      BIGINT UNSIGNED NULL,
            status          ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
            is_read         TINYINT(1)   NOT NULL DEFAULT 0,
            link            VARCHAR(512) NULL,
            icon            VARCHAR(50)  NULL,
            expires_at      DATETIME     NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_unread (user_id, is_read, status),
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_receiver_role (receiver_role, created_at),
            INDEX idx_related (related_table, related_id),
            INDEX idx_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = $pdo->query('SHOW COLUMNS FROM notifications')->fetchAll(PDO::FETCH_COLUMN);
        $additions = [
            'sender_id'     => "ALTER TABLE notifications ADD COLUMN sender_id INT UNSIGNED NULL AFTER user_id",
            'receiver_role' => "ALTER TABLE notifications ADD COLUMN receiver_role VARCHAR(20) NULL AFTER sender_id",
            'priority'      => "ALTER TABLE notifications ADD COLUMN priority ENUM('low','normal','high','critical','emergency') NOT NULL DEFAULT 'normal' AFTER message",
            'related_table' => "ALTER TABLE notifications ADD COLUMN related_table VARCHAR(80) NULL AFTER priority",
            'related_id'    => "ALTER TABLE notifications ADD COLUMN related_id BIGINT UNSIGNED NULL AFTER related_table",
            'status'        => "ALTER TABLE notifications ADD COLUMN status ENUM('active','archived','deleted') NOT NULL DEFAULT 'active' AFTER related_id",
            'icon'          => "ALTER TABLE notifications ADD COLUMN icon VARCHAR(50) NULL AFTER link",
            'expires_at'    => "ALTER TABLE notifications ADD COLUMN expires_at DATETIME NULL AFTER icon",
            'updated_at'    => "ALTER TABLE notifications ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($additions as $col => $sql) {
            if (!in_array($col, $columns, true)) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    error_log('Notification schema migration: ' . $e->getMessage());
                }
            }
        }

        // Widen link column if needed
        try {
            $pdo->exec('ALTER TABLE notifications MODIFY link VARCHAR(512) NULL');
        } catch (PDOException $e) { /* non-fatal */ }

        self::$schemaReady = true;
    }

    /**
     * Legacy wrapper — backward compatible with existing call sites.
     */
    public static function notify(PDO $pdo, $user_id, $type, $title, $message, $link = null): bool
    {
        return self::create($pdo, (int) $user_id, [
            'type'       => (string) $type,
            'title'      => (string) $title,
            'message'    => (string) $message,
            'action_url' => $link,
        ]) !== null;
    }

    /**
     * Create a notification for a single receiver.
     *
     * @param array{
     *   sender_id?: int|null,
     *   receiver_role?: string|null,
     *   type?: string,
     *   title: string,
     *   message: string,
     *   priority?: string,
     *   related_table?: string|null,
     *   related_id?: int|null,
     *   action_url?: string|null,
     *   icon?: string|null,
     *   expires_at?: string|null,
     *   email?: bool,
     *   audit?: bool
     * } $options
     */
    public static function create(PDO $pdo, int $receiverId, array $options): ?int
    {
        self::ensureSchema($pdo);

        $title   = trim($options['title'] ?? '');
        $message = trim($options['message'] ?? '');
        if ($title === '' || $message === '') {
            return null;
        }

        $type     = $options['type'] ?? self::TYPE_INFORMATION;
        $priority = $options['priority'] ?? self::mapPriorityFromType($type);
        $actionUrl = self::normalizeUrl($options['action_url'] ?? null);
        $senderId  = isset($options['sender_id']) ? (int) $options['sender_id'] : null;
        $receiverRole = $options['receiver_role'] ?? self::resolveUserRole($pdo, $receiverId);
        $icon = $options['icon'] ?? self::iconForType($type);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, sender_id, receiver_role, type, title, message, priority,
                     related_table, related_id, status, is_read, link, icon, expires_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 0, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $receiverId,
                $senderId ?: null,
                $receiverRole,
                $type,
                $title,
                $message,
                $priority,
                $options['related_table'] ?? null,
                isset($options['related_id']) ? (int) $options['related_id'] : null,
                $actionUrl,
                $icon,
                $options['expires_at'] ?? null,
            ]);
            $notificationId = (int) $pdo->lastInsertId();

            self::auditNotification($pdo, $receiverId, $title, $type, $notificationId);

            if (!empty($options['email'])) {
                self::sendEmailIfConfigured($pdo, $receiverId, $title, $message, $actionUrl);
            }

            return $notificationId;
        } catch (Exception $e) {
            error_log('Notification Error: ' . $e->getMessage());
            return null;
        }
    }

    /** Notify all active users with a given role. */
    public static function notifyRole(PDO $pdo, string $role, array $options): int
    {
        self::ensureSchema($pdo);
        $options['receiver_role'] = $role;
        $count = 0;
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role = ? AND is_active = 1");
        $stmt->execute([$role]);
        while ($uid = $stmt->fetchColumn()) {
            if (self::create($pdo, (int) $uid, $options)) {
                $count++;
            }
        }
        return $count;
    }

    public static function notifyAdmins(PDO $pdo, array $options): int
    {
        return self::notifyRole($pdo, 'admin', $options);
    }

    public static function notifySuperadmins(PDO $pdo, array $options): int
    {
        return self::notifyRole($pdo, 'superadmin', $options);
    }

    public static function notifyProvider(PDO $pdo, int $providerId, array $options): ?int
    {
        $options['receiver_role'] = 'provider';
        return self::create($pdo, $providerId, $options);
    }

    public static function notifyPatient(PDO $pdo, int $patientId, array $options): ?int
    {
        $options['receiver_role'] = 'patient';
        return self::create($pdo, $patientId, $options);
    }

    public static function notifyBhw(PDO $pdo, int $bhwId, array $options): ?int
    {
        $options['receiver_role'] = 'bhw';
        return self::create($pdo, $bhwId, $options);
    }

    /** Notify BHW users assigned to a patient's barangay. */
    public static function notifyBhwForPatient(PDO $pdo, int $patientId, array $options): int
    {
        self::ensureSchema($pdo);
        $count = 0;
        $stmt = $pdo->prepare("
            SELECT u.id FROM users u
            INNER JOIN patient_registrations pr ON pr.email = (SELECT email FROM users WHERE id = ? LIMIT 1)
            WHERE u.role = 'bhw' AND u.is_active = 1
              AND u.barangay_id = (SELECT b.id FROM barangays b WHERE b.name = pr.barangay LIMIT 1)
        ");
        try {
            $stmt->execute([$patientId]);
            while ($bhwId = $stmt->fetchColumn()) {
                if (self::notifyBhw($pdo, (int) $bhwId, $options)) {
                    $count++;
                }
            }
        } catch (PDOException $e) {
            // Fallback: notify all BHW if barangay join fails
            $fallback = $pdo->query("SELECT id FROM users WHERE role = 'bhw' AND is_active = 1");
            while ($bhwId = $fallback->fetchColumn()) {
                if (self::notifyBhw($pdo, (int) $bhwId, $options)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function getUnread(PDO $pdo, int $userId): array
    {
        return self::list($pdo, $userId, ['unread_only' => true, 'limit' => 50])['items'];
    }

    public static function getUnreadCount(PDO $pdo, int $userId): int
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE user_id = ? AND is_read = 0 AND status = 'active'
              AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{
     *   unread_only?: bool,
     *   status?: string,
     *   type?: string,
     *   priority?: string,
     *   search?: string,
     *   page?: int,
     *   limit?: int,
     *   since_id?: int
     * } $filters
     */
    public static function list(PDO $pdo, int $userId, array $filters = []): array
    {
        self::ensureSchema($pdo);

        $page  = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ['user_id = ?', "status != 'deleted'", '(expires_at IS NULL OR expires_at > NOW())'];
        $params = [$userId];

        if (!empty($filters['unread_only'])) {
            $where[] = 'is_read = 0';
        }
        if (!empty($filters['status']) && in_array($filters['status'], [self::STATUS_ACTIVE, self::STATUS_ARCHIVED], true)) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        } else {
            $where[] = "status = 'active'";
        }
        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['priority'])) {
            $where[] = 'priority = ?';
            $params[] = $filters['priority'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(title LIKE ? OR message LIKE ?)';
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }
        if (!empty($filters['since_id'])) {
            $where[] = 'id > ?';
            $params[] = (int) $filters['since_id'];
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM notifications WHERE {$whereSql} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items'       => array_map([self::class, 'formatRow'], $rows),
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ];
    }

    public static function markRead(PDO $pdo, int $userId, int $notificationId): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    }

    public static function markUnread(PDO $pdo, int $userId, int $notificationId): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 0, updated_at = NOW() WHERE id = ? AND user_id = ? AND status = 'active'");
        return $stmt->execute([$notificationId, $userId]);
    }

    public static function markAllRead(PDO $pdo, int $userId): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, updated_at = NOW() WHERE user_id = ? AND is_read = 0 AND status = 'active'");
        return $stmt->execute([$userId]);
    }

    public static function archive(PDO $pdo, int $userId, int $notificationId): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'archived', updated_at = NOW() WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    }

    public static function delete(PDO $pdo, int $userId, int $notificationId): bool
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare("UPDATE notifications SET status = 'deleted', updated_at = NOW() WHERE id = ? AND user_id = ?");
        return $stmt->execute([$notificationId, $userId]);
    }

    /** Dashboard widget data for the current user role. */
    public static function getDashboardWidgets(PDO $pdo, int $userId, string $role): array
    {
        self::ensureSchema($pdo);

        $recent = self::list($pdo, $userId, ['limit' => 5]);
        $unreadCount = self::getUnreadCount($pdo, $userId);

        $widgets = [
            'recent_notifications' => $recent['items'],
            'unread_count'         => $unreadCount,
            'today_appointments'   => 0,
            'upcoming_consultations' => 0,
            'pending_referrals'    => 0,
            'emergency_alerts'     => 0,
        ];

        try {
            if ($role === 'provider') {
                $s = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE provider_id = ? AND consult_date = CURDATE() AND status NOT IN ('cancelled','completed')");
                $s->execute([$userId]);
                $widgets['today_appointments'] = (int) $s->fetchColumn();
                $s = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE provider_id = ? AND consult_date >= CURDATE() AND status IN ('scheduled','waiting','in_consultation')");
                $s->execute([$userId]);
                $widgets['upcoming_consultations'] = (int) $s->fetchColumn();
                $s = $pdo->prepare("SELECT COUNT(*) FROM digital_referrals WHERE provider_id = ? AND status = 'pending'");
                $s->execute([$userId]);
                $widgets['pending_referrals'] = (int) $s->fetchColumn();
            } elseif ($role === 'patient') {
                $s = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE patient_id = ? AND consult_date = CURDATE() AND status NOT IN ('cancelled','completed')");
                $s->execute([$userId]);
                $widgets['today_appointments'] = (int) $s->fetchColumn();
                $s = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE patient_id = ? AND consult_date >= CURDATE() AND status IN ('scheduled','waiting')");
                $s->execute([$userId]);
                $widgets['upcoming_consultations'] = (int) $s->fetchColumn();
                if ($pdo->query("SHOW TABLES LIKE 'digital_referrals'")->rowCount()) {
                    $s = $pdo->prepare("SELECT COUNT(*) FROM digital_referrals WHERE patient_id = ? AND status = 'pending'");
                    $s->execute([$userId]);
                    $widgets['pending_referrals'] = (int) $s->fetchColumn();
                }
            } elseif ($role === 'bhw') {
                $s = $pdo->prepare("SELECT COUNT(*) FROM consultations c JOIN users u ON u.id = c.patient_id WHERE c.consult_date = CURDATE() AND c.status NOT IN ('cancelled','completed')");
                $s->execute();
                $widgets['today_appointments'] = (int) $s->fetchColumn();
                $s = $pdo->query("SELECT COUNT(*) FROM digital_referrals WHERE status = 'pending'");
                $widgets['pending_referrals'] = (int) $s->fetchColumn();
            } elseif ($role === 'admin' || $role === 'superadmin') {
                $widgets['pending_referrals'] = (int) $pdo->query("SELECT COUNT(*) FROM digital_referrals WHERE status = 'pending'")->fetchColumn();
                $widgets['today_appointments'] = (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE consult_date = CURDATE()")->fetchColumn();
            }

            $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND status = 'active' AND priority IN ('critical','emergency')");
            $s->execute([$userId]);
            $widgets['emergency_alerts'] = (int) $s->fetchColumn();
        } catch (PDOException $e) {
            error_log('Notification widgets error: ' . $e->getMessage());
        }

        return $widgets;
    }

    public static function dashboardPathForRole(string $role): string
    {
        return self::$roleDashboardPaths[$role] ?? '/views/patient/dashboard.php';
    }

    public static function formatRow(array $row): array
    {
        return [
            'notification_id' => (int) $row['id'],
            'id'              => (int) $row['id'],
            'sender_id'       => isset($row['sender_id']) ? (int) $row['sender_id'] : null,
            'receiver_id'     => (int) $row['user_id'],
            'receiver_role'   => $row['receiver_role'] ?? null,
            'type'            => $row['type'],
            'title'           => $row['title'],
            'message'         => $row['message'],
            'priority'        => $row['priority'] ?? 'normal',
            'related_table'   => $row['related_table'] ?? null,
            'related_id'      => isset($row['related_id']) ? (int) $row['related_id'] : null,
            'status'          => $row['status'] ?? 'active',
            'is_read'         => (bool) ($row['is_read'] ?? false),
            'action_url'      => self::normalizeUrl($row['link'] ?? null),
            'link'            => self::normalizeUrl($row['link'] ?? null),
            'icon'            => $row['icon'] ?? self::iconForType($row['type'] ?? 'information'),
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'] ?? $row['created_at'],
            'expires_at'      => $row['expires_at'] ?? null,
            'time_ago'        => self::timeAgo($row['created_at']),
            'date_label'      => date('M j, Y', strtotime($row['created_at'])),
            'time_label'      => date('g:i A', strtotime($row['created_at'])),
        ];
    }

    private static function mapPriorityFromType(string $type): string
    {
        return match ($type) {
            self::TYPE_EMERGENCY, self::TYPE_CRITICAL => 'emergency',
            self::TYPE_WARNING => 'high',
            self::TYPE_SECURITY => 'critical',
            self::TYPE_REMINDER => 'normal',
            default => 'normal',
        };
    }

    public static function iconForType(string $type): string
    {
        return match ($type) {
            self::TYPE_SUCCESS      => 'check-circle',
            self::TYPE_WARNING      => 'alert-triangle',
            self::TYPE_CRITICAL,
            self::TYPE_EMERGENCY    => 'alert-octagon',
            self::TYPE_REMINDER     => 'clock',
            self::TYPE_SECURITY     => 'shield',
            self::TYPE_MEDICAL      => 'heart-pulse',
            self::TYPE_APPOINTMENT  => 'calendar',
            self::TYPE_CONSULTATION => 'video',
            self::TYPE_REFERRAL     => 'share-2',
            self::TYPE_GIS          => 'map-pin',
            self::TYPE_SYSTEM       => 'settings',
            default                 => 'bell',
        };
    }

    private static function normalizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = defined('ASSET_BASE') ? ASSET_BASE : '';
        $url = '/' . ltrim($url, '/');

        // Repair links saved with a duplicated ASSET_BASE prefix.
        if ($base !== '') {
            $doublePrefix = $base . $base;
            if (str_starts_with($url, $doublePrefix . '/') || $url === $doublePrefix) {
                $url = substr($url, strlen($base));
            }
            if ($url === $base || str_starts_with($url, $base . '/')) {
                return $url;
            }
        }

        return $base . $url;
    }

    private static function resolveUserRole(PDO $pdo, int $userId): ?string
    {
        try {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $role = $stmt->fetchColumn();
            return $role ? (string) $role : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    private static function auditNotification(PDO $pdo, int $receiverId, string $title, string $type, int $notificationId): void
    {
        try {
            $auditPath = BASE_PATH . '/app/includes/audit_log.php';
            if (!class_exists('AuditAction', false) && file_exists($auditPath)) {
                require_once $auditPath;
            }
            if (function_exists('audit_log')) {
                audit_log($pdo, [
                    'patient_id'  => $receiverId,
                    'action_type' => 'notification_created',
                    'description' => "Notification sent: {$title}",
                    'meta'        => ['notification_id' => $notificationId, 'type' => $type],
                ]);
            }
        } catch (Exception $e) {
            error_log('Notification audit error: ' . $e->getMessage());
        }
    }

    private static function sendEmailIfConfigured(PDO $pdo, int $receiverId, string $title, string $message, ?string $actionUrl): void
    {
        try {
            $mailerPath = BASE_PATH . '/app/includes/mailer.php';
            if (!function_exists('initMailer') && file_exists($mailerPath)) {
                require_once $mailerPath;
            }
            if (!function_exists('initMailer')) {
                return;
            }

            $stmt = $pdo->prepare('SELECT email, first_name, role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$receiverId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['email'])) {
                return;
            }

            // Respect provider notification preferences
            if ($user['role'] === 'provider') {
                $prefPath = BASE_PATH . '/app/includes/provider_settings.php';
                if (file_exists($prefPath)) {
                    require_once $prefPath;
                    if (function_exists('provider_notification_prefs')) {
                        $prefs = provider_notification_prefs($pdo, $receiverId);
                        if (empty($prefs['email_notifications'])) {
                            return;
                        }
                    }
                }
            }

            $mail = initMailer();
            if (!$mail) {
                return;
            }
            $mail->addAddress($user['email'], $user['first_name'] ?? '');
            $mail->Subject = '[MedConnect] ' . $title;
            $body = '<p>' . htmlspecialchars($message) . '</p>';
            if ($actionUrl) {
                $body .= '<p><a href="' . htmlspecialchars($actionUrl) . '">View in MedConnect</a></p>';
            }
            $mail->Body = $body;
            $mail->AltBody = $message . ($actionUrl ? "\n\n" . $actionUrl : '');
            $mail->send();
        } catch (Exception $e) {
            error_log('Notification email error: ' . $e->getMessage());
        }
    }

    private static function timeAgo(string $datetime): string
    {
        $ts = strtotime($datetime);
        $diff = time() - $ts;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return (int) floor($diff / 60) . 'm ago';
        if ($diff < 86400) return (int) floor($diff / 3600) . 'h ago';
        if ($diff < 604800) return (int) floor($diff / 86400) . 'd ago';
        return date('M j, Y', $ts);
    }
}
