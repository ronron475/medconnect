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
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$uid = (int) $_SESSION['user_id'];

// ── Fetch chronologically ───────────────────────────────────
// 1. Consultations
$consults = $pdo->prepare("
    SELECT c.*, u.first_name, u.last_name 
    FROM consultations c
    JOIN users u ON c.provider_id = u.id
    WHERE c.patient_id = ?
    ORDER BY c.consult_date DESC
");
$consults->execute([$uid]);
$history = $consults->fetchAll();

// 2. Prescriptions
$meds = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC");
$meds->execute([$uid]);
$prescriptions = $meds->fetchAll();

$page_title = 'My Medical History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php
$page_title = 'Medical History';
require_once VIEWS_PATH . '/patient/partials/layout_head.php';
?>
  <style>
    .history-main { padding: 28px 32px; }
    .timeline { position: relative; padding-left: 36px; }
    .timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #C8EEF2; }
    .event { position: relative; margin-bottom: 32px; }
    .event-dot { position: absolute; left: -32px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #00B4D8; border: 3px solid #fff; box-shadow: 0 0 0 2px #B2EBF2; }
    .event-card { background: #fff; border-radius: 12px; padding: 22px; box-shadow: 0 2px 12px rgba(0,180,216,0.07); border: 1px solid #C8EEF2; }
    .event-date { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
    .event-title { font-size: 16px; font-weight: 800; color: #0d1b3e; margin-bottom: 12px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 640px) {
      .grid { grid-template-columns: 1fr; }
      .history-main { padding: 16px !important; }
    }
    .label { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 3px; }
    .val { font-size: 13.5px; color: #334155; line-height: 1.5; }
  </style>
</head>
<body class="patient-portal">
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>
    <div class="patient-page history-main">
  <h2 class="text-h2">Full Medical Record History</h2>
  <p style="color:#64748b; margin-bottom:40px;">Your chronological history of visits, diagnoses, and prescriptions.</p>

  <div class="timeline">
    <?php foreach($history as $h): ?>
    <div class="event">
      <div class="event-dot"></div>
      <div class="event-date"><?= date('F j, Y', strtotime($h['consult_date'])) ?></div>
      <div class="event-card">
        <div class="event-title">Consultation with Dr. <?= htmlspecialchars(trim($h['first_name'] . ' ' . $h['last_name'])) ?></div>
        <div class="grid">
          <div>
            <span class="label">Diagnosis</span>
            <p class="val"><?= htmlspecialchars($h['diagnosis'] ?: 'No diagnosis recorded.') ?></p>
          </div>
          <div>
            <span class="label">Recommendations</span>
            <p class="val"><?= htmlspecialchars($h['recommendation'] ?: 'Routine checkup.') ?></p>
          </div>
        </div>
        
        <!-- Nested Prescriptions if any -->
        <?php 
          $p_stmt = $pdo->prepare("SELECT * FROM prescriptions WHERE consultation_id = ?");
          $p_stmt->execute([$h['id']]);
          $p_list = $p_stmt->fetchAll();
          if($p_list):
        ?>
        <div style="margin-top:20px; padding-top:20px; border-top:1px solid #f1f5f9;">
          <span class="label">Prescribed Medications</span>
          <ul style="margin:8px 0; padding-left:20px; font-size:14px; color:#1a6db5; font-weight:600;">
            <?php foreach($p_list as $med): ?>
              <li><?= htmlspecialchars($med['medication_name']) ?> — <?= $med['dosage'] ?> (<?= $med['frequency'] ?>)</li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
    </div>
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>
</body>
</html>
