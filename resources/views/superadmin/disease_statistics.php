<?php
require_once __DIR__ . '/_bootstrap.php';
$page_title = 'Disease & Triage Statistics';

$triageStats = [];
$diagnosisStats = [];
$complaintStats = [];

if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
    $triageStats = $pdo->query("
        SELECT COALESCE(urgency_label, CONCAT('Level ', level)) AS label, COUNT(*) AS cnt
        FROM triage_results
        GROUP BY label
        ORDER BY cnt DESC
        LIMIT 15
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($pdo->query("SHOW TABLES LIKE 'clinical_notes'")->rowCount()) {
    $diagnosisStats = $pdo->query("
        SELECT TRIM(diagnosis) AS label, COUNT(*) AS cnt
        FROM clinical_notes
        WHERE diagnosis IS NOT NULL AND TRIM(diagnosis) <> ''
        GROUP BY TRIM(diagnosis)
        ORDER BY cnt DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $hasDiagnosis = $pdo->query("SHOW COLUMNS FROM consultations LIKE 'diagnosis'")->rowCount() > 0;
    if ($hasDiagnosis) {
        $fromConsults = $pdo->query("
            SELECT TRIM(diagnosis) AS label, COUNT(*) AS cnt
            FROM consultations
            WHERE diagnosis IS NOT NULL AND TRIM(diagnosis) <> ''
            GROUP BY TRIM(diagnosis)
            ORDER BY cnt DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fromConsults as $row) {
            $found = false;
            foreach ($diagnosisStats as &$existing) {
                if (strcasecmp($existing['label'], $row['label']) === 0) {
                    $existing['cnt'] += $row['cnt'];
                    $found = true;
                    break;
                }
            }
            unset($existing);
            if (!$found) {
                $diagnosisStats[] = $row;
            }
        }
        usort($diagnosisStats, fn($a, $b) => $b['cnt'] <=> $a['cnt']);
        $diagnosisStats = array_slice($diagnosisStats, 0, 20);
    }

    $hasComplaint = $pdo->query("SHOW COLUMNS FROM triage_results LIKE 'chief_complaint'")->rowCount() > 0;
    if ($hasComplaint) {
        $complaintStats = $pdo->query("
            SELECT TRIM(chief_complaint) AS label, COUNT(*) AS cnt
            FROM triage_results
            WHERE chief_complaint IS NOT NULL AND TRIM(chief_complaint) <> ''
            GROUP BY TRIM(chief_complaint)
            ORDER BY cnt DESC
            LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

$totalTriage = array_sum(array_column($triageStats, 'cnt'));
$totalDiagnosis = array_sum(array_column($diagnosisStats, 'cnt'));

require_once __DIR__ . '/partials/layout_open.php';
?>
<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2">Disease & Triage Statistics</h2>
  <p class="text-muted">Live aggregates from triage assessments and recorded clinical diagnoses.</p>
</div>

<div class="superadmin-stat-grid" style="margin-bottom:24px;">
  <div class="mc-card"><div class="text-h1"><?= number_format($totalTriage) ?></div><div class="text-xs text-muted">Triage assessments</div></div>
  <div class="mc-card"><div class="text-h1"><?= number_format($totalDiagnosis) ?></div><div class="text-xs text-muted">Recorded diagnoses</div></div>
  <div class="mc-card"><div class="text-h1"><?= count($diagnosisStats) ?></div><div class="text-xs text-muted">Unique diagnoses</div></div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">
  <div class="mc-card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 16px 8px;"><h3 class="text-h3">Triage urgency distribution</h3></div>
    <table class="mc-table">
      <thead><tr><th>Urgency</th><th>Cases</th><th>%</th></tr></thead>
      <tbody>
        <?php if (!$triageStats): ?>
        <tr><td colspan="3"><div class="mc-table-empty"><p>No triage data available.</p></div></td></tr>
        <?php else: foreach ($triageStats as $s):
          $pct = $totalTriage > 0 ? round(($s['cnt'] / $totalTriage) * 100, 1) : 0;
        ?>
        <tr>
          <td><?= htmlspecialchars($s['label']) ?></td>
          <td><strong><?= (int) $s['cnt'] ?></strong></td>
          <td class="text-muted"><?= $pct ?>%</td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mc-card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 16px 8px;"><h3 class="text-h3">Top recorded diagnoses</h3></div>
    <table class="mc-table">
      <thead><tr><th>Diagnosis</th><th>Cases</th></tr></thead>
      <tbody>
        <?php if (!$diagnosisStats): ?>
        <tr><td colspan="2"><div class="mc-table-empty"><p>No diagnosis data recorded yet.</p></div></td></tr>
        <?php else: foreach ($diagnosisStats as $s): ?>
        <tr><td><?= htmlspecialchars($s['label']) ?></td><td><strong><?= (int) $s['cnt'] ?></strong></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($complaintStats): ?>
  <div class="mc-card" style="padding:0;overflow:hidden;">
    <div style="padding:16px 16px 8px;"><h3 class="text-h3">Common chief complaints</h3></div>
    <table class="mc-table">
      <thead><tr><th>Complaint</th><th>Cases</th></tr></thead>
      <tbody>
        <?php foreach ($complaintStats as $s): ?>
        <tr><td><?= htmlspecialchars($s['label']) ?></td><td><strong><?= (int) $s['cnt'] ?></strong></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
