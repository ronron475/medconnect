<?php
$active_page = 'consultation_history';
$page_title  = 'Consultation History';
$page_styles = ['provider-consultation-history.css'];
require __DIR__ . '/partials/icons.php';
require __DIR__ . '/partials/data.php';
require_once BASE_PATH . '/app/includes/provider_consultation_history.php';
$GLOBALS['pdo'] = $pdo;
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
patient_consultation_records_schema_ensure($pdo);
require __DIR__ . '/partials/layout_open.php';

$provider_id = (int) ($_SESSION['user_id'] ?? 0);
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
if (!in_array($filter, provider_consultation_history_allowed_filters(), true)) {
    $filter = 'all';
}

$detail_patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$patient_detail = null;
$patient_consultations = [];

if ($detail_patient_id > 0) {
    $detail = provider_consultation_history_patient_detail($pdo, $provider_id, $detail_patient_id);
    $patient_detail = $detail['patient'];
    $patient_consultations = $detail['consultations'];
    if (!$patient_detail) {
        $detail_patient_id = 0;
    }
}

$history_patients = $detail_patient_id > 0 ? [] : provider_consultation_history_patients($pdo, $provider_id, ['filter' => $filter]);

function pch_filter_url(string $filter): string
{
    return '?filter=' . urlencode($filter);
}
?>

<div class="pch-page">

  <?php if ($detail_patient_id > 0 && $patient_detail): ?>

  <div class="pch-panel">
    <div class="pch-detail-head">
      <a href="<?= htmlspecialchars(pch_filter_url($filter)) ?>" class="pch-back" aria-label="Back to patient list">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
        Back to patient list
      </a>
      <h2 class="pch-detail-name"><?= htmlspecialchars((string) $patient_detail['patient_name']) ?></h2>
      <div class="pch-detail-meta">
        Patient ID: <?= htmlspecialchars((string) $patient_detail['patient_number']) ?>
        · Total consultations: <?= count($patient_consultations) ?>
      </div>
      <div class="pch-detail-grid">
        <div>
          <label>Age</label>
          <span><?= htmlspecialchars((string) ($patient_detail['age'] ?: '—')) ?></span>
        </div>
        <div>
          <label>Sex</label>
          <span><?= htmlspecialchars((string) ($patient_detail['sex'] ?: '—')) ?></span>
        </div>
        <div>
          <label>Contact</label>
          <span><?= htmlspecialchars((string) ($patient_detail['contact'] ?: '—')) ?></span>
        </div>
        <div>
          <label>Address</label>
          <span><?= htmlspecialchars((string) ($patient_detail['address'] ?: '—')) ?></span>
        </div>
      </div>
    </div>

    <div class="pch-consult-list">
      <h3 style="margin:0 0 4px;font-size:14px;font-weight:800;">Consultation history</h3>
      <?php if (empty($patient_consultations)): ?>
      <div class="pch-empty"><p>No consultations found for this patient.</p></div>
      <?php else: ?>
        <?php foreach ($patient_consultations as $idx => $row):
          $status = (string) ($row['status'] ?? '');
          $dateLabel = !empty($row['consult_date'])
              ? date('M j, Y', strtotime((string) $row['consult_date']))
              : '—';
          $timeLabel = !empty($row['consult_time'])
              ? date('g:i A', strtotime((string) $row['consult_time']))
              : '';
          $visitNum = count($patient_consultations) - (int) $idx;
          $complaint = trim((string) ($row['chief_complaint'] ?? '')) ?: '—';
          $sessionUrl = ASSET_BASE . '/views/provider/consultation_session.php?id=' . (int) $row['id'];
        ?>
        <article class="pch-consult-card">
          <div class="pch-consult-card__head">
            <div>
              <div class="pch-consult-card__title">Consultation #<?= (int) $row['id'] ?></div>
              <div class="pch-consult-card__date"><?= htmlspecialchars($dateLabel) ?><?= $timeLabel ? ' · ' . htmlspecialchars($timeLabel) : '' ?></div>
            </div>
            <span class="pch-chip <?= htmlspecialchars(provider_consultation_status_chip_class($status)) ?>">
              <?= htmlspecialchars(provider_consultation_status_label($status)) ?>
            </span>
          </div>
          <div class="pch-consult-card__row"><strong>Patient complaint:</strong> <?= htmlspecialchars($complaint) ?></div>
          <div class="pch-consult-card__row"><strong>Doctor:</strong> <?= htmlspecialchars((string) ($row['doctor_name'] ?? '—')) ?></div>
          <?php if (!empty($row['ai_classification'])): ?>
          <div class="pch-consult-card__row"><strong>AI classification:</strong> <?= htmlspecialchars((string) $row['ai_classification']) ?></div>
          <?php endif; ?>
          <?php if (!empty($row['final_classification'])): ?>
          <div class="pch-consult-card__row"><strong>Final doctor classification:</strong> <?= htmlspecialchars((string) $row['final_classification']) ?></div>
          <?php endif; ?>
          <?php if (!empty($row['diagnosis'])): ?>
          <div class="pch-consult-card__row"><strong>Diagnosis:</strong> <?= htmlspecialchars((string) $row['diagnosis']) ?></div>
          <?php endif; ?>
          <?php
            $vh = is_array($row['video_history'] ?? null) ? $row['video_history'] : [];
            $vhLabel = (string) ($vh['video_status_label'] ?? 'Not started');
            $vhCompleted = !empty($vh['show_completed_details']);
          ?>
          <div class="pch-video-block">
            <div class="pch-video-block__title">VIDEO CONSULTATION</div>
            <?php if ($vhCompleted): ?>
            <div class="pch-video-block__status pch-video-block__status--done">&#10003; Completed</div>
            <div class="pch-consult-card__row"><strong>Video call date:</strong> <?= htmlspecialchars((string) ($vh['date_label'] ?? '—')) ?></div>
            <div class="pch-consult-card__row"><strong>Started:</strong> <?= htmlspecialchars((string) ($vh['started_label'] ?? '—')) ?></div>
            <div class="pch-consult-card__row"><strong>Ended:</strong> <?= htmlspecialchars((string) ($vh['ended_label'] ?? '—')) ?></div>
            <div class="pch-consult-card__row"><strong>Duration:</strong> <?= htmlspecialchars((string) ($vh['duration_label'] ?? '—')) ?></div>
            <?php
              $recUrl = consultation_video_recording_view_url((int) ($row['id'] ?? 0));
            ?>
            <?php if ($recUrl !== ''): ?>
            <div class="pch-consult-card__actions" style="margin-top:8px;">
              <a href="<?= htmlspecialchars($recUrl) ?>" target="_blank" rel="noopener" class="mc-btn mc-btn--outline" style="padding:6px 12px;font-size:11px;">View Recording</a>
            </div>
            <?php else: ?>
            <div class="pch-consult-card__row"><strong>Video recording:</strong> Not available</div>
            <?php endif; ?>
            <?php else: ?>
            <div class="pch-consult-card__row"><strong>Video consultation:</strong> <?= htmlspecialchars($vhLabel) ?></div>
            <?php endif; ?>
          </div>
          <div class="pch-consult-card__actions">
            <a href="<?= htmlspecialchars($sessionUrl) ?>" class="mc-btn mc-btn--outline" style="padding:6px 12px;font-size:11px;">View History</a>
            <?php if ($status === 'completed' && !empty($row['clinical_note_finalized'])): ?>
            <a href="<?= htmlspecialchars(ASSET_BASE) ?>/views/provider/medical_records.php?view=patients&amp;patient_id=<?= (int) $patient_detail['id'] ?>&amp;tab=clinical_notes" class="mc-btn mc-btn--outline" style="padding:6px 12px;font-size:11px;">View SOAP</a>
            <?php elseif ($status === 'completed'): ?>
            <span class="pch-doc-pending">Provider documentation is still in progress.</span>
            <?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php else: ?>

  <div class="pch-toolbar">
    <div>
      <h2 class="pch-toolbar__title">Consultation History</h2>
      <p class="pch-toolbar__sub">Patients grouped by account — each visit is a separate consultation record.</p>
    </div>
    <div class="pch-toolbar__actions">
      <nav class="pch-filters" aria-label="History filters">
        <?php foreach (provider_consultation_history_allowed_filters() as $f):
          $label = match ($f) {
              'all' => 'All',
              'completed' => 'Completed',
              'scheduled' => 'Scheduled',
              'cancelled' => 'Cancelled',
              'active' => 'Active',
              default => ucfirst($f),
          };
        ?>
        <a href="<?= htmlspecialchars(pch_filter_url($f)) ?>" class="pch-filter <?= $filter === $f ? 'is-active' : '' ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="pch-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="pchSearch" type="search" placeholder="Search name or patient ID…" autocomplete="off" aria-label="Search consultation history">
      </div>
    </div>
  </div>

  <div class="pch-panel">
    <div class="pch-table-wrap">
      <table class="pch-table">
        <thead>
          <tr>
            <th>Patient</th>
            <th>Last consultation</th>
            <th>Total visits</th>
            <th>Last patient complaint</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="pchHistoryBody">
          <?php if (empty($history_patients)): ?>
          <tr><td colspan="6"><div class="pch-empty"><p>No patients match this history filter.</p></div></td></tr>
          <?php else: foreach ($history_patients as $row):
            $name = trim((string) ($row['patient_name'] ?? ''));
            $pid = (string) ($row['patient_number'] ?? '');
            $status = (string) ($row['latest_status'] ?? '');
            $lastDate = !empty($row['consult_date']) ? date('M j, Y', strtotime((string) $row['consult_date'])) : '—';
            $complaint = trim((string) ($row['last_complaint'] ?? '')) ?: '—';
            $searchBlob = strtolower($name . ' ' . $pid . ' ' . $complaint);
          ?>
          <tr data-pch-row data-search="<?= htmlspecialchars($searchBlob) ?>">
            <td>
              <span class="pch-patient-name"><?= htmlspecialchars($name) ?></span>
              <span class="pch-patient-id"><?= htmlspecialchars($pid) ?></span>
            </td>
            <td style="white-space:nowrap;"><?= htmlspecialchars($lastDate) ?></td>
            <td><?= (int) ($row['total_visits'] ?? 0) ?> visit<?= (int) ($row['total_visits'] ?? 0) === 1 ? '' : 's' ?></td>
            <td><?= htmlspecialchars($complaint) ?></td>
            <td>
              <span class="pch-chip <?= htmlspecialchars(provider_consultation_status_chip_class($status)) ?>">
                <?= htmlspecialchars(provider_consultation_status_label($status)) ?>
              </span>
            </td>
            <td>
              <a href="?patient_id=<?= (int) $row['patient_id'] ?>&amp;filter=<?= urlencode($filter) ?>" class="mc-btn mc-btn--outline" style="padding:6px 12px;font-size:11px;white-space:nowrap;">View history</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
  (function () {
    var input = document.getElementById('pchSearch');
    if (!input) return;
    input.addEventListener('input', function () {
      var q = (input.value || '').toLowerCase().trim();
      document.querySelectorAll('#pchHistoryBody [data-pch-row]').forEach(function (row) {
        var blob = row.getAttribute('data-search') || '';
        row.style.display = !q || blob.indexOf(q) >= 0 ? '' : 'none';
      });
    });
  })();
  </script>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
