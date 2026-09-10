<?php
$active_page = 'followup_management';
$page_title  = 'Follow-Up Management';
$page_styles = ['provider-followup.css'];
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require __DIR__.'/partials/layout_open.php';

$provider_id = (int)$_SESSION['user_id'];
$status_filter = strtolower(trim((string) ($_GET['status'] ?? 'upcoming')));
if (!in_array($status_filter, ['upcoming', 'completed', 'missed'], true)) {
    $status_filter = 'upcoming';
}
$date_from = trim((string) ($_GET['from'] ?? ''));
$date_to = trim((string) ($_GET['to'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT f.*, u.first_name, u.last_name, u.email
    FROM followups f
    JOIN users u ON u.id = f.patient_id
    WHERE f.provider_id = ?
";
$params = [$provider_id];

if ($status_filter === 'upcoming') {
    $sql .= " AND f.status = 'scheduled' AND f.followup_date >= CURDATE()";
} elseif ($status_filter === 'completed') {
    $sql .= " AND f.status = 'completed'";
} elseif ($status_filter === 'missed') {
    $sql .= " AND f.status IN ('missed','scheduled') AND f.followup_date < CURDATE()";
}

if ($date_from !== '') {
    $sql .= ' AND f.followup_date >= ?';
    $params[] = $date_from;
}
if ($date_to !== '') {
    $sql .= ' AND f.followup_date <= ?';
    $params[] = $date_to;
}
if ($search !== '') {
    $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}
$sql .= ' ORDER BY f.followup_date ASC, f.id DESC';

$followups = [];
$query_error = false;
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $query_error = true;
}

$followup_count = count($followups);

/**
 * @param array<string, string> $overrides
 */
function fu_filter_url(array $overrides = []): string
{
    $params = [
        'status' => (string) ($_GET['status'] ?? 'upcoming'),
        'from' => (string) ($_GET['from'] ?? ''),
        'to' => (string) ($_GET['to'] ?? ''),
        'q' => (string) ($_GET['q'] ?? ''),
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = (string) $value;
    }
    $params = array_filter($params, static fn ($v): bool => $v !== '');
    return $params === [] ? '?' : ('?' . http_build_query($params));
}

/**
 * @param array<string, mixed> $row
 */
function fu_display_status(array $row, string $filter): array
{
    $raw = strtolower(trim((string) ($row['status'] ?? '')));
    $date = (string) ($row['followup_date'] ?? '');
    $isPast = $date !== '' && strtotime($date) < strtotime('today');

    if ($raw === 'completed') {
        return ['label' => 'Completed', 'class' => 'fu-chip--completed'];
    }
    if ($raw === 'missed' || ($raw === 'scheduled' && $isPast) || $filter === 'missed') {
        return ['label' => 'Missed', 'class' => 'fu-chip--missed'];
    }
    if ($raw === 'cancelled') {
        return ['label' => 'Cancelled', 'class' => 'fu-chip--cancelled'];
    }
    if ($raw === 'scheduled') {
        return ['label' => 'Upcoming', 'class' => 'fu-chip--upcoming'];
    }

    return ['label' => ucfirst($raw !== '' ? $raw : 'Unknown'), 'class' => 'fu-chip--neutral'];
}
?>

<div class="fu-page">
  <header class="fu-hero">
    <div class="fu-hero__copy">
      <p class="fu-hero__eyebrow">Patient care</p>
      <div class="fu-hero__title-row">
        <h2 class="fu-title">Follow-Up Management</h2>
        <span class="fu-count"><?= (int) $followup_count ?> result<?= $followup_count === 1 ? '' : 's' ?></span>
      </div>
      <p class="fu-sub">Track scheduled, completed, and missed follow-ups for your patients.</p>
    </div>
    <div class="fu-hero__actions">
      <nav class="fu-filters" aria-label="Follow-up status filters">
        <a href="<?= htmlspecialchars(fu_filter_url(['status' => 'upcoming'])) ?>" class="fu-filter<?= $status_filter === 'upcoming' ? ' is-active' : '' ?>">Upcoming</a>
        <a href="<?= htmlspecialchars(fu_filter_url(['status' => 'completed'])) ?>" class="fu-filter<?= $status_filter === 'completed' ? ' is-active' : '' ?>">Completed</a>
        <a href="<?= htmlspecialchars(fu_filter_url(['status' => 'missed'])) ?>" class="fu-filter<?= $status_filter === 'missed' ? ' is-active' : '' ?>">Missed</a>
      </nav>
    </div>
  </header>

  <form method="get" class="fu-toolbar" action="">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
    <div class="fu-toolbar__dates">
      <label class="fu-field">
        <span>From</span>
        <div class="fu-date">
          <input type="date" name="from" value="<?= htmlspecialchars($date_from) ?>" class="fu-input" aria-label="From date">
        </div>
      </label>
      <label class="fu-field">
        <span>To</span>
        <div class="fu-date">
          <input type="date" name="to" value="<?= htmlspecialchars($date_to) ?>" class="fu-input" aria-label="To date">
        </div>
      </label>
    </div>
    <div class="fu-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or email…" autocomplete="off" aria-label="Search patient">
    </div>
    <button type="submit" class="mc-btn mc-btn--primary mc-btn--sm fu-apply">Apply</button>
  </form>

  <div class="fu-panel">
    <div class="fu-table-wrap">
      <table class="fu-table">
        <thead>
          <tr>
            <th>Patient</th>
            <th>Follow-up date</th>
            <th>Status</th>
            <th>Notes</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($query_error): ?>
          <tr class="fu-empty-row">
            <td colspan="5"><div class="fu-empty"><p>Could not load follow-ups. Please try again.</p></div></td>
          </tr>
          <?php elseif (empty($followups)): ?>
          <tr class="fu-empty-row">
            <td colspan="5">
              <div class="fu-empty">
                <p>No <?= htmlspecialchars($status_filter) ?> follow-ups found<?= ($search !== '' || $date_from !== '' || $date_to !== '') ? ' for this filter' : '' ?>.</p>
                <p class="fu-empty__hint">Schedule follow-ups from a consultation session when care needs a check-in.</p>
              </div>
            </td>
          </tr>
          <?php else: foreach ($followups as $f):
            $statusMeta = fu_display_status($f, $status_filter);
            $canReschedule = strtolower((string) ($f['status'] ?? '')) === 'scheduled'
                && strtotime((string) $f['followup_date']) >= strtotime('today');
            $notes = trim((string) ($f['notes'] ?? $f['message'] ?? ''));
          ?>
          <tr>
            <td class="fu-td--patient" data-label="Patient">
              <span class="fu-patient-name"><?= htmlspecialchars(trim($f['first_name'] . ' ' . $f['last_name'])) ?></span>
              <span class="fu-patient-email"><?= htmlspecialchars((string) $f['email']) ?></span>
            </td>
            <td data-label="Follow-up date" class="fu-td--date"><?= date('M j, Y', strtotime((string) $f['followup_date'])) ?></td>
            <td data-label="Status">
              <span class="fu-chip <?= htmlspecialchars($statusMeta['class']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
            </td>
            <td data-label="Notes" class="fu-td--notes"><?= htmlspecialchars($notes !== '' ? $notes : '—') ?></td>
            <td data-label="Actions" class="fu-td--actions">
              <?php if ($canReschedule): ?>
              <button
                type="button"
                class="mc-btn mc-btn--outline mc-btn--sm"
                data-reschedule="<?= (int) $f['id'] ?>"
                data-date="<?= htmlspecialchars((string) $f['followup_date']) ?>"
                data-patient="<?= htmlspecialchars(trim($f['first_name'] . ' ' . $f['last_name'])) ?>"
              >Reschedule</button>
              <?php else: ?>
              <span class="fu-action-none">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="fu-modal" id="fuRescheduleModal" hidden>
  <div class="fu-modal__backdrop" data-fu-close></div>
  <div class="fu-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="fuRescheduleTitle">
    <div class="fu-modal__header">
      <h3 id="fuRescheduleTitle" class="fu-modal__title">Reschedule follow-up</h3>
      <button type="button" class="fu-modal__close" data-fu-close aria-label="Close">&times;</button>
    </div>
    <form id="fuRescheduleForm" class="fu-modal__body">
      <p class="fu-modal__patient" id="fuReschedulePatient"></p>
      <label class="fu-field">
        <span>New date</span>
        <div class="fu-date">
          <input type="date" id="fuRescheduleDate" name="followup_date" class="fu-input" required>
        </div>
      </label>
      <p class="fu-modal__error" id="fuRescheduleError" hidden></p>
      <div class="fu-modal__footer">
        <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" data-fu-close>Cancel</button>
        <button type="submit" class="mc-btn mc-btn--primary mc-btn--sm" id="fuRescheduleSave">Save date</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('fuRescheduleModal');
  var form = document.getElementById('fuRescheduleForm');
  var dateInput = document.getElementById('fuRescheduleDate');
  var patientEl = document.getElementById('fuReschedulePatient');
  var errorEl = document.getElementById('fuRescheduleError');
  var followupId = 0;

  function closeModal() {
    if (!modal) return;
    modal.hidden = true;
    followupId = 0;
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
  }

  function openModal(btn) {
    followupId = parseInt(btn.getAttribute('data-reschedule') || '0', 10);
    if (!modal || !followupId) return;
    if (dateInput) dateInput.value = btn.getAttribute('data-date') || '';
    if (patientEl) patientEl.textContent = btn.getAttribute('data-patient') || '';
    if (errorEl) {
      errorEl.hidden = true;
      errorEl.textContent = '';
    }
    modal.hidden = false;
    if (dateInput) dateInput.focus();
  }

  document.querySelectorAll('[data-reschedule]').forEach(function (btn) {
    btn.addEventListener('click', function () { openModal(btn); });
  });

  if (modal) {
    modal.querySelectorAll('[data-fu-close]').forEach(function (el) {
      el.addEventListener('click', closeModal);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
  });

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!followupId || !dateInput || !dateInput.value) return;
      var fd = new FormData();
      fd.append('followup_id', String(followupId));
      fd.append('followup_date', dateInput.value);
      fd.append('csrf_token', document.body.dataset.csrf || '');
      var saveBtn = document.getElementById('fuRescheduleSave');
      if (saveBtn) saveBtn.disabled = true;
      fetch('<?= ASSET_BASE ?>/app/api/provider/update_followup.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.success) {
            location.reload();
            return;
          }
          if (errorEl) {
            errorEl.textContent = (j && j.message) ? j.message : 'Could not update follow-up.';
            errorEl.hidden = false;
          }
        })
        .catch(function () {
          if (errorEl) {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.hidden = false;
          }
        })
        .finally(function () {
          if (saveBtn) saveBtn.disabled = false;
        });
    });
  }
})();
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
