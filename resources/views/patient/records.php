<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
// ── Auth: patients only ───────────────────────────────────────────────────────
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$uid        = (int) $_SESSION['user_id'];
$page_title = 'Health Files';

// ── Fetch this patient's own profile (name + number only) ────────────────────
$stmt = $pdo->prepare("
    SELECT u.first_name, u.last_name,
           CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number
    FROM users u
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$uid]);
$pt = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Fetch prescriptions (this patient only) ───────────────────────────────────
$prescriptions = [];
try {
    $s = $pdo->prepare("
        SELECT
            CONCAT(pr.medication_name, ' ', pr.dosage)  AS record_name,
            pr.frequency,
            pr.duration,
            COALESCE(pr.notes, '')                       AS detail,
            DATE(pr.created_at)                          AS record_date,
            CONCAT(u.first_name, ' ', u.last_name)       AS provider_name
        FROM prescriptions pr
        JOIN users u ON u.id = pr.provider_id
        WHERE pr.patient_id = ?
        ORDER BY pr.created_at DESC
    ");
    $s->execute([$uid]);
    $prescriptions = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist yet */ }

// ── Fetch clinical notes (this patient only) ──────────────────────────────────
$clinical_notes = [];
try {
    $s = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(cn.diagnosis, ''), 'Clinical Note') AS record_name,
            cn.assessment                                        AS frequency,
            cn.plan                                             AS duration,
            COALESCE(cn.treatment_plan, '')                     AS detail,
            DATE(cn.created_at)                                 AS record_date,
            CONCAT(u.first_name, ' ', u.last_name)              AS provider_name
        FROM clinical_notes cn
        JOIN consultations c ON c.id = cn.consultation_id
        JOIN users u ON u.id = cn.provider_id
        WHERE cn.patient_id = ?
        ORDER BY cn.created_at DESC
    ");
    $s->execute([$uid]);
    $clinical_notes = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist yet */ }

// ── Fetch referrals (this patient only) ───────────────────────────────────────
$referrals = [];
try {
    $s = $pdo->prepare("
        SELECT
            CONCAT(dr.referral_type, ' Referral')      AS record_name,
            dr.reason                                   AS frequency,
            COALESCE(dr.destination_facility, '')       AS duration,
            dr.status                                   AS detail,
            DATE(dr.created_at)                         AS record_date,
            CONCAT(u.first_name, ' ', u.last_name)      AS provider_name
        FROM digital_referrals dr
        JOIN users u ON u.id = dr.provider_id
        WHERE dr.patient_id = ?
        ORDER BY dr.created_at DESC
    ");
    $s->execute([$uid]);
    $referrals = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* table may not exist yet */ }

// ── Merge and sort all records newest-first ───────────────────────────────────
$all_records = [];
foreach ($prescriptions  as $r) { $r['record_type'] = 'Prescription';  $all_records[] = $r; }
foreach ($clinical_notes as $r) { $r['record_type'] = 'Clinical Note'; $all_records[] = $r; }
foreach ($referrals      as $r) { $r['record_type'] = 'Referral';      $all_records[] = $r; }
usort($all_records, fn($a, $b) => strcmp($b['record_date'] ?? '', $a['record_date'] ?? ''));

$counts = [
    'Prescription'  => count($prescriptions),
    'Clinical Note' => count($clinical_notes),
    'Referral'      => count($referrals),
    'all'           => count($all_records),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$page_title = 'Health Files';
require_once VIEWS_PATH . '/patient/partials/layout_head.php';
?>
</head>
<body class="patient-portal">
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
    <!-- Page header -->
    <div style="background:#fff;border-radius:12px;border:1px solid var(--mc-border-thin);
                border-left:5px solid #069396;padding:20px 28px;
                display:flex;align-items:center;justify-content:space-between;
                box-shadow:var(--mc-shadow-micro);margin-bottom:24px;">
      <div>
        <div style="font-size:11px;color:var(--mc-slate-muted);font-weight:700;
                    text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px;">
          My Records
        </div>
        <h2 class="text-h2" style="color:var(--mc-navy-dark);line-height:1;">Health Files</h2>
        <div style="font-size:12px;color:var(--mc-slate-muted);margin-top:4px;">
          <?= htmlspecialchars(($pt['first_name'] ?? '') . ' ' . ($pt['last_name'] ?? '')) ?>
          &nbsp;&bull;&nbsp;
          <span style="font-weight:700;color:var(--mc-aqua-medium);">
            <?= htmlspecialchars($pt['patient_number'] ?? '') ?>
          </span>
        </div>
      </div>
      <span class="mc-badge" style="background:#e0f7fa;color:var(--mc-aqua-medium);
                                     border-color:#b2ebf2;font-weight:700;">
        <?= $counts['all'] ?> record<?= $counts['all'] !== 1 ? 's' : '' ?>
      </span>
    </div>

    <!-- Summary folder cards -->
    <div class="folder-grid rec-filter-bar" style="margin-bottom:24px;">
      <?php
      $folders = [
        'all'          => ['Prescription','Clinical Note','Referral'],
        'Prescription' => [],
        'Clinical Note'=> [],
        'Referral'     => [],
      ];
      $folder_meta = [
        'all'          => ['label'=>'All Records',   'color'=>'#069396','bg'=>'#e0f7fa',
          'icon'=>'<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>'],
        'Prescription' => ['label'=>'Prescriptions', 'color'=>'#1d4ed8','bg'=>'#dbeafe',
          'icon'=>'<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>'],
        'Clinical Note'=> ['label'=>'Clinical Notes','color'=>'#166534','bg'=>'#dcfce7',
          'icon'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
        'Referral'     => ['label'=>'Referrals',     'color'=>'#92400e','bg'=>'#fef3c7',
          'icon'=>'<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'],
      ];
      foreach ($folder_meta as $key => $m):
        $cnt = $key === 'all' ? $counts['all'] : ($counts[$key] ?? 0);
      ?>
      <button onclick="filterTable('<?= $key ?>')"
              class="rec-filter-btn" id="btn-<?= htmlspecialchars($key) ?>"
              style="display:flex;flex-direction:column;align-items:center;gap:8px;
                     padding:16px 12px;border-radius:12px;height:auto;">
        <div style="width:40px;height:40px;border-radius:10px;
                    background:<?= $m['bg'] ?>;display:flex;align-items:center;justify-content:center;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none"
               stroke="<?= $m['color'] ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <?= $m['icon'] ?>
          </svg>
        </div>
        <div style="font-size:13px;font-weight:700;"><?= $m['label'] ?></div>
        <div style="font-size:11px;opacity:.7;"><?= $cnt ?> record<?= $cnt !== 1 ? 's' : '' ?></div>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Records table -->
    <div class="mc-card" style="padding:0;overflow:hidden;">
      <div class="mc-table-wrap">
      <table class="mc-table" id="records-table">
        <thead>
          <tr>
            <th>Record</th>
            <th>Type</th>
            <th>Date</th>
            <th>Provider</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($all_records)): ?>
            <tr><td colspan="5">
              <div class="mc-table-empty">
                <p>No records found. Your prescriptions, clinical notes, and referrals will appear here after consultations.</p>
              </div>
            </td></tr>
          <?php else: foreach ($all_records as $r):
            $type_key = strtolower(str_replace(' ', '-', $r['record_type']));
          ?>
            <tr data-type="<?= htmlspecialchars($r['record_type']) ?>">
              <td data-label="Record" style="font-weight:700;color:var(--mc-navy-dark);
                          max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars($r['record_name'] ?? '—') ?>
              </td>
              <td data-label="Type">
                <span class="rec-type-badge rec-type--<?= $type_key ?>">
                  <?= htmlspecialchars($r['record_type']) ?>
                </span>
              </td>
              <td data-label="Date" style="white-space:nowrap;font-size:13px;">
                <?= !empty($r['record_date'])
                    ? htmlspecialchars(date('M j, Y', strtotime($r['record_date'])))
                    : '—' ?>
              </td>
              <td data-label="Provider" style="font-size:13px;color:var(--mc-slate-muted);">
                <?= htmlspecialchars($r['provider_name'] ?? '—') ?>
              </td>
              <td data-label="Detail" class="rec-detail" title="<?= htmlspecialchars($r['detail'] ?? '') ?>">
                <?= htmlspecialchars($r['detail'] ?? '—') ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    </div><!-- .patient-page -->
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

<script>
function filterTable(type) {
  // Update button states
  document.querySelectorAll('.rec-filter-btn').forEach(btn => btn.classList.remove('active'));
  const activeBtn = document.getElementById('btn-' + type);
  if (activeBtn) activeBtn.classList.add('active');

  // Filter rows
  document.querySelectorAll('#records-table tbody tr[data-type]').forEach(row => {
    row.style.display = (type === 'all' || row.dataset.type === type) ? '' : 'none';
  });
}
// Default: show all
filterTable('all');
</script>
</body>
</html>
