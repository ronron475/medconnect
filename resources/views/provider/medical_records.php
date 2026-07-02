<?php
$active_page = 'medical_records';
$page_title  = 'Medical Records';
$page_styles = ['provider-medical-records.css'];
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require __DIR__.'/partials/layout_open.php';

$requested_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$tab = $_GET['tab'] ?? 'overview';
$view = ($_GET['view'] ?? 'patients') === 'history' ? 'history' : 'patients';
$provider_id = (int) ($_SESSION['user_id'] ?? 0);

$consultation_history = [];
if ($view === 'history') {
    try {
        $hist_stmt = $pdo->prepare("
            SELECT c.*, u.first_name, u.last_name, cn.diagnosis
            FROM consultations c
            JOIN users u ON c.patient_id = u.id
            LEFT JOIN clinical_notes cn ON c.id = cn.consultation_id
            WHERE c.provider_id = ? AND c.status = 'completed'
            ORDER BY c.consult_date DESC, c.consult_time DESC
        ");
        $hist_stmt->execute([$provider_id]);
        $consultation_history = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $consultation_history = [];
    }
}

function mr_fetch_patient(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name,
            CONCAT(u.first_name,' ',u.last_name) AS name,
            CONCAT(UPPER(LEFT(u.first_name,1)),UPPER(LEFT(u.last_name,1))) AS initials,
            COALESCE(pr.age,'') AS age, COALESCE(pr.gender,'') AS sex,
            COALESCE(pr.contact_number,'') AS contact,
            COALESCE(CONCAT_WS(', ',NULLIF(pr.barangay,''),NULLIF(pr.city_municipality,''),NULLIF(pr.province,'')),'') AS address,
            COALESCE(pr.blood_type,'') AS blood_type,
            COALESCE(pr.existing_conditions,'') AS history,
            COALESCE(pr.allergies,'') AS allergies,
            COALESCE(pr.current_medications,'') AS medications,
            COALESCE((SELECT MAX(c2.consult_date) FROM consultations c2 WHERE c2.patient_id=u.id),'') AS last_consult,
            CASE WHEN u.is_active=1 THEN 'Active' ELSE 'Inactive' END AS status,
            CONCAT('MC-',LPAD(u.id,6,'0')) AS patient_number
        FROM users u
        LEFT JOIN patient_registrations pr ON pr.email=u.email
        WHERE u.id=? AND u.role='patient' LIMIT 1
    ");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$selected = null;
if ($requested_id > 0) {
    $access = provider_patient_assert_access($pdo, (int) ($_SESSION['user_id'] ?? 0), $requested_id, 0);
    if ($access['allowed']) {
        $selected = mr_fetch_patient($pdo, $requested_id);
    } else {
        $requested_id = 0;
    }
}
if (!$selected && !empty($patients) && $view === 'patients') {
    $requested_id = (int)($patients[0]['id'] ?? 0);
    $selected = $requested_id > 0 ? mr_fetch_patient($pdo, $requested_id) : null;
}

$sel_records = [];
$attachments = [];

if ($selected) {
    $pid = (int)$selected['id'];
    $queries = [
        "SELECT 'Consultation' AS rec_type, c.consult_type AS rec_name, DATE(c.consult_date) AS rec_date,
                COALESCE(c.provider_name,'—') AS provider, COALESCE(c.status,'') AS detail, COALESCE(c.diagnosis,'') AS extra, c.id AS rec_id
         FROM consultations c WHERE c.patient_id = ? ORDER BY c.consult_date DESC",
        "SELECT 'Prescription' AS rec_type, CONCAT(pr.medication_name,' ',pr.dosage) AS rec_name, DATE(pr.created_at) AS rec_date,
                CONCAT(u.first_name,' ',u.last_name) AS provider, CONCAT(pr.frequency,', ',pr.duration) AS detail, COALESCE(pr.notes,'') AS extra, pr.id AS rec_id
         FROM prescriptions pr JOIN users u ON u.id=pr.provider_id WHERE pr.patient_id = ? ORDER BY pr.created_at DESC",
        "SELECT 'Referral' AS rec_type, CONCAT(dr.referral_type,' Referral') AS rec_name, DATE(dr.created_at) AS rec_date,
                CONCAT(u.first_name,' ',u.last_name) AS provider, dr.reason AS detail, COALESCE(dr.facility_name, dr.destination_facility, '') AS extra, dr.id AS rec_id
         FROM digital_referrals dr JOIN users u ON u.id=dr.provider_id WHERE dr.patient_id = ? ORDER BY dr.created_at DESC",
    ];
    foreach ($queries as $sql) {
        try {
            $s = $pdo->prepare($sql);
            $s->execute([$pid]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sel_records[] = $r;
            }
        } catch (PDOException $e) {}
    }
    usort($sel_records, fn($a, $b) => strcmp($b['rec_date'] ?? '', $a['rec_date'] ?? ''));

    try {
        $s = $pdo->prepare("SELECT id, original_name, file_name, status, uploaded_at FROM residency_documents WHERE patient_id=? ORDER BY uploaded_at DESC");
        $s->execute([$pid]);
        $attachments = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

$patient_count = count($patients ?? []);
$tabs_list = ['overview' => 'Overview', 'consultations' => 'Consultations', 'prescriptions' => 'Prescriptions', 'referrals' => 'Referrals', 'attachments' => 'Attachments'];
?>

<div class="mr-page">

  <div class="mr-toolbar">
    <div class="mr-toolbar__left">
      <h2 class="mr-toolbar__title">Medical Records</h2>
      <span class="mr-badge"><?= $view === 'history' ? count($consultation_history) . ' completed' : $patient_count . ' patients' ?></span>
      <nav class="mr-tabs" aria-label="Records view">
        <a href="?view=patients" class="<?= $view === 'patients' ? 'is-active' : '' ?>">Patient Directory</a>
        <a href="?view=history" class="<?= $view === 'history' ? 'is-active' : '' ?>">Consultation History</a>
      </nav>
    </div>
    <?php if ($view === 'patients'): ?>
    <div class="mr-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input id="mrSearch" type="search" placeholder="Search patients…" oninput="mrFilterPatients(this.value)" autocomplete="off">
    </div>
    <?php endif; ?>
  </div>

  <?php if ($view === 'history'): ?>

  <div class="mr-history-panel">
    <div class="mr-panel__head">
      <span>Finalized consultation records</span>
      <div class="mr-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="mrHistorySearch" type="search" placeholder="Search history…" oninput="mrFilterHistory(this.value)" autocomplete="off">
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table class="mc-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Patient</th>
            <th>Type</th>
            <th>Diagnosis</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="mrHistoryBody">
          <?php if (empty($consultation_history)): ?>
          <tr><td colspan="6"><div class="mr-empty"><p>No finalized consultations in your history.</p></div></td></tr>
          <?php else: foreach ($consultation_history as $h):
            $patient_name = trim(($h['first_name'] ?? '') . ' ' . ($h['last_name'] ?? ''));
            $search_blob = strtolower($patient_name . ' ' . ($h['diagnosis'] ?? '') . ' ' . ($h['consult_type'] ?? ''));
          ?>
          <tr data-history-row data-search="<?= htmlspecialchars($search_blob) ?>">
            <td style="white-space:nowrap;">
              <strong><?= date('M j, Y', strtotime($h['consult_date'])) ?></strong><br>
              <span class="text-xs text-muted"><?= date('g:i A', strtotime($h['consult_time'])) ?></span>
            </td>
            <td>
              <strong><?= htmlspecialchars($patient_name) ?></strong><br>
              <span class="text-xs text-muted">#<?= (int) $h['patient_id'] ?></span>
            </td>
            <td><?= htmlspecialchars($h['consult_type'] ?? '—') ?></td>
            <td><?= htmlspecialchars($h['diagnosis'] ?? '—') ?></td>
            <td><span class="mr-chip mr-chip--active">Completed</span></td>
            <td>
              <a href="?view=patients&amp;patient_id=<?= (int) $h['patient_id'] ?>&amp;tab=consultations" class="mc-btn mc-btn--outline" style="padding:4px 10px;font-size:11px;">Open</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php else: ?>

  <div class="mr-split">
    <div class="mr-panel">
      <div class="mr-panel__head">Patient directory</div>
      <div id="mrPatientList" class="mr-patient-list">
        <?php if (empty($patients)): ?>
        <div class="mr-empty"><p>No patients assigned yet.</p></div>
        <?php else: foreach ($patients as $p):
          $pid = (int)($p['id'] ?? 0);
          $is_active = $selected && (int)$selected['id'] === $pid;
        ?>
        <a href="?view=patients&amp;patient_id=<?= $pid ?>"
           class="mr-patient-row <?= $is_active ? 'is-active' : '' ?>"
           data-name="<?= htmlspecialchars(strtolower(($p['name'] ?? '') . ' ' . ($p['contact'] ?? ''))) ?>">
          <span class="mr-patient-row__avatar"><?= htmlspecialchars($p['initials'] ?? '?') ?></span>
          <span class="mr-patient-row__info">
            <span class="mr-patient-row__name"><?= htmlspecialchars($p['name'] ?? '') ?></span>
            <span class="mr-patient-row__meta"><?= htmlspecialchars($p['contact'] ?: 'No contact') ?> · <?= htmlspecialchars($p['last_consult'] ? date('M j, Y', strtotime($p['last_consult'])) : 'No visits') ?></span>
          </span>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="mr-panel">
      <?php if (!$selected): ?>
      <div class="mr-empty">
        <?= icon('users') ?>
        <p>Select a patient to view their medical record.</p>
      </div>
      <?php else: ?>
      <div class="mr-detail-head">
        <h3 class="mr-detail-name"><?= htmlspecialchars($selected['name']) ?></h3>
        <div class="mr-detail-meta"><?= htmlspecialchars($selected['patient_number']) ?> · <?= htmlspecialchars($selected['age']) ?> yrs · <?= htmlspecialchars($selected['sex']) ?></div>
        <div class="mr-detail-chips">
          <span class="mr-chip <?= ($selected['status'] ?? '') === 'Active' ? 'mr-chip--active' : '' ?>"><?= htmlspecialchars($selected['status']) ?></span>
          <?php if (!empty($selected['blood_type'])): ?>
          <span class="mr-chip mr-chip--teal"><?= htmlspecialchars($selected['blood_type']) ?></span>
          <?php endif; ?>
          <?php if (!empty($selected['last_consult'])): ?>
          <span class="mr-chip">Last visit <?= date('M j, Y', strtotime($selected['last_consult'])) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <nav class="mr-subtabs" aria-label="Record sections">
        <?php foreach ($tabs_list as $k => $label): ?>
        <a href="?view=patients&amp;patient_id=<?= (int)$selected['id'] ?>&amp;tab=<?= $k ?>"
           class="<?= $tab === $k ? 'is-active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </nav>

      <div class="mr-detail-body">
        <?php if ($tab === 'overview'): ?>
        <div class="mr-info-grid">
          <div class="mr-info-item">
            <label>Contact</label>
            <span><?= htmlspecialchars($selected['contact'] ?: '—') ?></span>
          </div>
          <div class="mr-info-item">
            <label>Address</label>
            <span><?= htmlspecialchars($selected['address'] ?: '—') ?></span>
          </div>
          <div class="mr-info-item">
            <label>Allergies</label>
            <span><?= htmlspecialchars($selected['allergies'] ?: 'None recorded') ?></span>
          </div>
          <div class="mr-info-item">
            <label>Medications</label>
            <span><?= htmlspecialchars($selected['medications'] ?: 'None recorded') ?></span>
          </div>
          <div class="mr-info-item" style="grid-column:1/-1;">
            <label>Medical history</label>
            <span><?= htmlspecialchars($selected['history'] ?: 'None recorded') ?></span>
          </div>
        </div>
        <?php else:
          $filtered = array_filter($sel_records, function ($r) use ($tab) {
              if ($tab === 'consultations') return $r['rec_type'] === 'Consultation';
              if ($tab === 'prescriptions') return $r['rec_type'] === 'Prescription';
              if ($tab === 'referrals') return $r['rec_type'] === 'Referral';
              return false;
          });
          if ($tab === 'attachments'):
            if (empty($attachments)): ?>
              <p class="text-muted text-sm">No attachments on file.</p>
            <?php else: foreach ($attachments as $doc): ?>
              <div class="mr-record-item">
                <div class="mr-record-item__title"><?= htmlspecialchars($doc['original_name']) ?></div>
                <div class="mr-record-item__meta"><?= htmlspecialchars($doc['status']) ?> · <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></div>
              </div>
            <?php endforeach; endif;
          elseif (empty($filtered)): ?>
            <p class="text-muted text-sm">No records in this category.</p>
          <?php else: foreach ($filtered as $r): ?>
            <div class="mr-record-item">
              <div class="mr-record-item__title"><?= htmlspecialchars($r['rec_name']) ?></div>
              <div class="mr-record-item__meta"><?= htmlspecialchars($r['rec_date']) ?> · <?= htmlspecialchars($r['provider']) ?></div>
              <?php if (!empty($r['extra'])): ?>
              <div class="mr-record-item__extra"><?= htmlspecialchars($r['extra']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; endif;
        endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>
</div>

<script>
function mrFilterPatients(q) {
  q = (q || '').toLowerCase().trim();
  document.querySelectorAll('#mrPatientList .mr-patient-row').forEach(function (el) {
    el.style.display = !q || (el.getAttribute('data-name') || '').includes(q) ? '' : 'none';
  });
}
function mrFilterHistory(q) {
  q = (q || '').toLowerCase().trim();
  document.querySelectorAll('#mrHistoryBody [data-history-row]').forEach(function (el) {
    var blob = el.getAttribute('data-search') || '';
    el.style.display = !q || blob.includes(q) ? '' : 'none';
  });
}
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
