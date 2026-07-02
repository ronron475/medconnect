<?php
/**
 * medConnect Clinical Portal - Consultation Queue
 */
$active_page = 'queue';
$page_title  = 'Live Queue';
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once __DIR__ . '/partials/icons.php';
require_once __DIR__ . '/partials/data.php';
require_once __DIR__ . '/partials/queue_helpers.php';

$provider_id = (int)($_SESSION['user_id'] ?? 0);

$queue_items = [];
$triage_feed = [];
$queue_stats = [
    'today'     => 0,
    'waiting'   => 0,
    'active'    => 0,
    'urgent'    => 0,
    'completed' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.patient_id,
            c.consult_date,
            c.consult_time,
            c.consult_type,
            c.status,
            u.first_name,
            u.last_name,
            u.email,
            pr.age,
            tr.level,
            tr.urgency_label,
            tr.chief_complaint,
            tr.symptoms,
            vs.room_token
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN patient_registrations pr ON pr.email = u.email
        LEFT JOIN (
            SELECT t1.*
            FROM triage_results t1
            INNER JOIN (
                SELECT patient_id, MAX(assessed_at) AS latest_at
                FROM triage_results
                GROUP BY patient_id
            ) t2 ON t2.patient_id = t1.patient_id AND t2.latest_at = t1.assessed_at
        ) tr ON tr.patient_id = c.patient_id
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        WHERE (c.provider_id = ? OR c.provider_id IS NULL OR c.provider_id = 0)
          AND c.consult_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY
            CASE c.status
                WHEN 'in_consultation' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'pending' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            c.consult_date DESC,
            c.consult_time DESC
    ");
    $stmt->execute([$provider_id]);
    $queue_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT
            tr.id,
            tr.patient_id,
            tr.level,
            tr.symptoms,
            tr.chief_complaint,
            tr.urgency_label,
            tr.status,
            tr.assessed_at,
            u.first_name,
            u.last_name
        FROM triage_results tr
        JOIN users u ON u.id = tr.patient_id
        ORDER BY
            CASE
                WHEN tr.level IN ('1', '2', 'Emergency', 'high') THEN 1
                ELSE 2
            END,
            tr.assessed_at DESC
        LIMIT 8
    ");
    $triage_feed = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Queue page query failed: ' . $e->getMessage());
}

foreach ($queue_items as $item) {
    if (($item['consult_date'] ?? '') === date('Y-m-d')) {
        $queue_stats['today']++;
    }
    if (in_array($item['status'], ['pending', 'scheduled'], true)) {
        $queue_stats['waiting']++;
    }
    if (($item['status'] ?? '') === 'in_consultation') {
        $queue_stats['active']++;
    }
    if (($item['status'] ?? '') === 'completed') {
        $queue_stats['completed']++;
    }
    if (in_array((string)($item['level'] ?? ''), ['1', '2', 'Emergency', 'high'], true)) {
        $queue_stats['urgent']++;
    }
}

if (!$queue_items && !empty($queue)) {
    foreach ($queue as $q) {
        $queue_items[] = [
            'id' => $q['id'] ?? 0,
            'first_name' => explode(' ', $q['patient_name'] ?? 'Patient')[0] ?? 'Patient',
            'last_name' => explode(' ', $q['patient_name'] ?? 'Patient')[1] ?? '',
            'consult_type' => $q['complaint'] ?? 'Consultation',
            'status' => strtolower(str_replace(' ', '_', $q['status'] ?? 'pending')),
            'consult_date' => date('Y-m-d'),
            'consult_time' => date('H:i:s'),
            'age' => 'N/A',
            'urgency_label' => 'Not triaged',
            'chief_complaint' => $q['complaint'] ?? 'Consultation',
            'room_token' => '',
        ];
    }
}

function queue_initials(array $item): string
{
    return strtoupper(substr($item['first_name'] ?? 'P', 0, 1) . substr($item['last_name'] ?? '', 0, 1));
}

function queue_status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

function queue_status_class(string $status): string
{
    return match ($status) {
        'in_consultation' => 'active',
        'completed' => 'done',
        'cancelled' => 'muted',
        default => 'waiting',
    };
}

function queue_is_urgent(?string $level, ?string $label): bool
{
    $value = strtolower((string)$level . ' ' . (string)$label);
    return str_contains($value, '1') || str_contains($value, '2') || str_contains($value, 'emergency') || str_contains($value, 'urgent');
}

function queue_format_symptoms(?string $raw): array
{
    $list = [];
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $symptom) {
            $symptom = trim((string) $symptom);
            if ($symptom !== '') {
                $list[] = ucwords(str_replace('_', ' ', $symptom));
            }
        }
    } elseif ($raw !== null && trim((string) $raw) !== '' && !str_starts_with(trim((string) $raw), '[')) {
        $list[] = trim((string) $raw);
    }
    return $list;
}

$page_styles = ['provider_queue.css', 'provider_session_alert.css'];
require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="queue-page">
    <section class="queue-hero">
        <div>
            <div class="queue-eyebrow">Clinical Portal</div>
            <h1 class="queue-title">Consultation Queue</h1>
            <div class="queue-subtitle">Track assigned patients, live rooms, triage priority, and session status from one workspace.</div>
        </div>
        <div class="queue-date"><?= date('l, F j, Y') ?></div>
    </section>

    <section class="queue-metrics" aria-label="Queue summary">
        <div class="queue-metric teal">
            <div class="queue-metric-label">Today</div>
            <div class="queue-metric-value"><?= (int)$queue_stats['today'] ?></div>
        </div>
        <div class="queue-metric blue">
            <div class="queue-metric-label">Waiting</div>
            <div class="queue-metric-value"><?= (int)$queue_stats['waiting'] ?></div>
        </div>
        <div class="queue-metric red">
            <div class="queue-metric-label">Urgent</div>
            <div class="queue-metric-value"><?= (int)$queue_stats['urgent'] ?></div>
        </div>
        <div class="queue-metric green">
            <div class="queue-metric-label">Active</div>
            <div class="queue-metric-value"><?= (int)$queue_stats['active'] ?></div>
        </div>
        <div class="queue-metric gray">
            <div class="queue-metric-label">Completed</div>
            <div class="queue-metric-value"><?= (int)$queue_stats['completed'] ?></div>
        </div>
    </section>

    <section class="queue-layout">
        <div class="queue-panel">
            <div class="queue-panel-header">
                <div class="queue-panel-title"><?= icon('users') ?> Assigned Consultation Queue</div>
                <a href="schedule.php" class="queue-btn">View Schedule</a>
            </div>
            <div class="queue-table-wrap">
                <table class="queue-table">
                    <thead>
                        <tr>
                            <th class="col-patient">Patient</th>
                            <th class="col-complaint">Chief Complaint</th>
                            <th class="col-triage">Triage</th>
                            <th class="col-schedule">Schedule</th>
                            <th class="col-status">Status</th>
                            <th class="col-action">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$queue_items): ?>
                            <tr><td colspan="6"><div class="queue-empty">No assigned consultations yet.</div></td></tr>
                        <?php else: ?>
                            <?php foreach ($queue_items as $item):
                                $name = trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''));
                                $status = (string)($item['status'] ?? 'pending');
                                $status_class = queue_status_class($status);
                                $is_urgent = queue_is_urgent($item['level'] ?? '', $item['urgency_label'] ?? '');
                                $session_url = 'consultation_session.php?id=' . (int)$item['id'];
                                $session_access = queue_session_access($item);
                                $symptoms = queue_format_symptoms($item['symptoms'] ?? '');
                                $complaint = trim((string) ($item['chief_complaint'] ?? ''));
                                if ($complaint === '') {
                                    $complaint = trim((string) ($item['consult_type'] ?? 'General Consultation'));
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="queue-patient">
                                        <div class="queue-avatar"><?= htmlspecialchars(queue_initials($item)) ?></div>
                                        <div>
                                            <div class="queue-patient-name"><?= htmlspecialchars($name ?: 'Patient') ?></div>
                                            <div class="queue-meta"><?= htmlspecialchars(($item['age'] ?: 'N/A') . ' yrs') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="queue-complaint-main"><?= htmlspecialchars($complaint) ?></div>
                                    <?php if ($symptoms): ?>
                                    <div class="queue-chip-list">
                                        <?php foreach ($symptoms as $symptom): ?>
                                        <span class="queue-chip"><?= htmlspecialchars($symptom) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="queue-badge <?= $is_urgent ? 'urgent' : 'routine' ?>">
                                        <?= htmlspecialchars($item['urgency_label'] ?: ($is_urgent ? 'Urgent' : 'Not triaged')) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight:700;"><?= htmlspecialchars(date('M j, Y', strtotime($item['consult_date']))) ?></div>
                                    <div class="queue-meta"><?= htmlspecialchars(date('g:i A', strtotime($item['consult_time']))) ?></div>
                                </td>
                                <td class="col-status"><span class="queue-badge <?= $status_class ?>"><?= htmlspecialchars(queue_status_label($status)) ?></span></td>
                                <td>
                                    <div class="queue-actions">
                                        <?php if ($session_access['allowed']): ?>
                                            <a href="<?= $session_url ?>" class="queue-btn primary"><?= icon_sm('video') ?> Open Session</a>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="queue-btn primary is-disabled queue-open-session-blocked"
                                                data-reason="<?= htmlspecialchars($session_access['reason'], ENT_QUOTES, 'UTF-8') ?>"
                                                title="<?= htmlspecialchars($session_access['reason'], ENT_QUOTES, 'UTF-8') ?>"
                                            ><?= icon_sm('video') ?> Open Session</button>
                                        <?php endif; ?>
                                        <?php if (!empty($item['room_token']) && $session_access['allowed']): ?>
                                            <a href="<?= ASSET_BASE ?>/views/consultation/video_room.php?token=<?= urlencode($item['room_token']) ?>" class="queue-btn"><?= icon_sm('monitor') ?> Room</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <aside class="queue-sidebar">
            <div class="queue-panel">
                <div class="queue-panel-header">
                    <div class="queue-panel-title"><?= icon('activity') ?> Triage Feed</div>
                    <a href="triage.php" class="queue-btn">Review All</a>
                </div>
                <div class="queue-feed">
                    <?php if (!$triage_feed): ?>
                        <div class="queue-empty">No triage cases recorded.</div>
                    <?php else: ?>
                        <?php foreach ($triage_feed as $case):
                            $urgent = queue_is_urgent($case['level'] ?? '', $case['urgency_label'] ?? '');
                            $case_name = trim(($case['first_name'] ?? '') . ' ' . ($case['last_name'] ?? ''));
                            $feed_symptoms = queue_format_symptoms($case['symptoms'] ?? '');
                            $feed_complaint = trim((string) ($case['chief_complaint'] ?? ''));
                        ?>
                        <div class="queue-feed-card <?= $urgent ? 'urgent' : 'routine' ?>">
                            <div class="queue-feed-top">
                                <div>
                                    <div class="queue-feed-name"><?= htmlspecialchars($case_name ?: 'Patient') ?></div>
                                    <div class="queue-feed-time"><?= htmlspecialchars(date('M j, g:i A', strtotime($case['assessed_at']))) ?></div>
                                </div>
                                <span class="queue-badge <?= $urgent ? 'urgent' : 'routine' ?>"><?= $urgent ? 'Urgent' : 'Routine' ?></span>
                            </div>
                            <?php if ($feed_complaint !== ''): ?>
                            <div class="queue-feed-complaint"><?= htmlspecialchars($feed_complaint) ?></div>
                            <?php endif; ?>
                            <?php if ($feed_symptoms): ?>
                            <div class="queue-chip-list">
                                <?php foreach ($feed_symptoms as $symptom): ?>
                                <span class="queue-chip"><?= htmlspecialchars($symptom) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif ($feed_complaint === ''): ?>
                            <div class="queue-feed-complaint">No complaint recorded.</div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="queue-panel">
                <div class="queue-panel-header">
                    <div class="queue-panel-title"><?= icon('monitor') ?> Queue Monitor</div>
                </div>
                <div class="queue-monitor">
                    <?php
                    $monitor = [
                        ['Waiting', $queue_stats['waiting'], '#f59e0b'],
                        ['In Consultation', $queue_stats['active'], '#2563eb'],
                        ['Urgent Priority', $queue_stats['urgent'], '#dc2626'],
                        ['Completed', $queue_stats['completed'], '#16a34a'],
                    ];
                    foreach ($monitor as [$label, $count, $color]):
                    ?>
                    <div class="queue-monitor-item">
                        <span><span class="queue-monitor-dot" style="background:<?= $color ?>"></span><?= htmlspecialchars($label) ?></span>
                        <span><?= (int)$count ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>
    </section>
</div>

<?php require __DIR__ . '/partials/session_schedule_modal.php'; ?>
<script src="<?= ASSET_BASE ?>/assets/js/provider-session-alert.js"></script>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
