<?php
$active_page = 'triage';
$page_title  = 'Active Triage Review';
$page_styles = ['provider_triage.css'];
require __DIR__ . '/partials/icons.php';
require __DIR__ . '/partials/data.php';
require __DIR__ . '/partials/layout_open.php';

$module_tab = ($_GET['tab'] ?? 'active') === 'history' ? 'history' : 'active';
if ($module_tab === 'active') {
    $display_cases = array_values(array_filter($triage_cases, fn($t) => empty($t['reviewed'])));
} else {
    $display_cases = $triage_cases;
}

$urgent_count     = count(array_filter($display_cases, fn($t) => $t['urgency'] === 'Urgent'));
$non_urgent_count = count(array_filter($display_cases, fn($t) => $t['urgency'] === 'Non-Urgent'));
$reviewed_count   = count(array_filter($display_cases, fn($t) => $t['reviewed']));
$pending_count    = count($display_cases) - $reviewed_count;
?>

<div class="greeting-banner" style="margin-bottom:16px;">
  <div>
    <h2 class="text-h2">Triage</h2>
    <p class="text-muted text-sm" style="margin:0;">Active case review and historical triage records.</p>
  </div>
  <div style="display:flex;gap:8px;">
    <a href="?tab=active" class="mc-btn <?= $module_tab === 'active' ? 'mc-btn--primary' : 'mc-btn--outline' ?>">Active Cases</a>
    <a href="?tab=history" class="mc-btn <?= $module_tab === 'history' ? 'mc-btn--primary' : 'mc-btn--outline' ?>">History</a>
  </div>
</div>

<div class="triage-banner">
  <?= icon_col('alert', '#3b82f6') ?>
  <span>AI triage is for <strong>clinical decision support only</strong>. The healthcare provider makes the final assessment and treatment decision for every case.</span>
</div>

<div class="triage-stats">
  <div class="triage-stat-card triage-stat-card--urgent">
    <div class="triage-stat-icon"><?= icon('activity') ?></div>
    <div>
      <div class="triage-stat-value"><?= $urgent_count ?></div>
      <div class="triage-stat-label">Urgent Cases</div>
    </div>
  </div>
  <div class="triage-stat-card triage-stat-card--routine">
    <div class="triage-stat-icon"><?= icon('check') ?></div>
    <div>
      <div class="triage-stat-value"><?= $non_urgent_count ?></div>
      <div class="triage-stat-label">Non-Urgent Cases</div>
    </div>
  </div>
  <div class="triage-stat-card triage-stat-card--reviewed">
    <div class="triage-stat-icon"><?= icon('eye') ?></div>
    <div>
      <div class="triage-stat-value"><?= $reviewed_count ?></div>
      <div class="triage-stat-label">Reviewed</div>
    </div>
  </div>
</div>

<div class="triage-tabs">
  <button type="button" class="triage-tab active" data-filter="all">
    All Cases <span class="triage-tab-count"><?= count($display_cases) ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="urgent">
    Urgent <span class="triage-tab-count"><?= $urgent_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="non-urgent">
    Non-Urgent <span class="triage-tab-count"><?= $non_urgent_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="pending">
    Pending <span class="triage-tab-count"><?= $pending_count ?></span>
  </button>
  <button type="button" class="triage-tab" data-filter="reviewed">
    Reviewed <span class="triage-tab-count"><?= $reviewed_count ?></span>
  </button>
</div>

<div class="mc-card" style="padding: 0; overflow: hidden;">
  <div class="mc-card-header" style="padding: 16px 20px; border-bottom: 1px solid var(--mc-border-thin);">
    <h3 class="text-h3" style="margin: 0;"><?= icon('activity') ?> AI Triage Case Review</h3>
    <span class="text-xs text-muted"><?= count($display_cases) ?> total · <?= $pending_count ?> pending review</span>
  </div>

  <div class="mc-table-wrap">
    <table class="mc-table" id="triageTable">
      <thead>
        <tr>
          <th>Patient</th>
          <th>Symptoms</th>
          <th>Chief Complaint</th>
          <th>AI Classification</th>
          <th>Submitted</th>
          <th>Workflow</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($display_cases)): ?>
        <tr>
          <td colspan="7">
            <div class="triage-empty">
              <p>No triage cases yet. New patient assessments will appear here.</p>
            </div>
          </td>
        </tr>
      <?php else: foreach ($display_cases as $t):
        $is_urgent = $t['urgency'] === 'Urgent';
        $payload   = htmlspecialchars(json_encode($t, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
      ?>
        <tr
          class="<?= $is_urgent ? 'triage-row-urgent' : '' ?>"
          data-urgency="<?= $is_urgent ? 'urgent' : 'non-urgent' ?>"
          data-reviewed="<?= $t['reviewed'] ? 'true' : 'false' ?>"
          data-pending="<?= $t['reviewed'] ? 'false' : 'true' ?>"
        >
          <td data-label="Patient" style="font-weight: 700; color: var(--mc-navy-dark);">
            <?= htmlspecialchars($t['name']) ?>
          </td>
          <td data-label="Symptoms">
            <div class="triage-symptom-chips">
              <?php if (!empty($t['symptoms_list'])): ?>
                <?php foreach ($t['symptoms_list'] as $symptom): ?>
                <span class="triage-symptom-chip"><?= htmlspecialchars($symptom) ?></span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Complaint">
            <span class="triage-complaint" title="<?= htmlspecialchars($t['complaint'] ?: '—') ?>">
              <?= htmlspecialchars($t['complaint'] ?: '—') ?>
            </span>
          </td>
          <td data-label="Classification">
            <?php if ($is_urgent): ?>
            <span class="triage-badge triage-badge--urgent">Urgent</span>
            <?php else: ?>
            <span class="triage-badge triage-badge--routine">Non-Urgent</span>
            <?php endif; ?>
            <?php if (!empty($t['label'])): ?>
            <div class="text-xs text-muted" style="margin-top: 4px;"><?= htmlspecialchars($t['label']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Submitted" style="white-space: nowrap; font-size: 12px; color: var(--mc-slate-muted);">
            <?= htmlspecialchars($t['date']) ?><br><?= htmlspecialchars($t['time']) ?>
          </td>
          <td data-label="Workflow">
            <?php if ($t['reviewed']): ?>
            <span class="triage-badge triage-badge--reviewed">Reviewed</span>
            <?php else: ?>
            <span class="triage-badge triage-badge--pending">Pending</span>
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <div class="triage-actions">
              <button type="button" class="mc-btn mc-btn--outline triage-view-btn" style="padding: 6px 12px; font-size: 11px;" data-triage="<?= $payload ?>">View Details</button>
              <?php if (!$t['reviewed']): ?>
              <button type="button" class="mc-btn mc-btn--primary triage-accept-btn" style="padding: 6px 12px; font-size: 11px;" data-id="<?= (int) $t['id'] ?>">Accept</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="triageModal" class="triage-modal" aria-hidden="true">
  <div class="triage-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="triageModalTitle">
    <div class="triage-modal__header">
      <h2 id="triageModalTitle" class="triage-modal__title">Clinical Triage Summary</h2>
      <button type="button" class="triage-modal__close" onclick="closeTriageModal()" aria-label="Close">&times;</button>
    </div>

    <div class="triage-modal__body">
      <div class="triage-modal-grid">
        <div>
          <span class="triage-field-label">Patient Name</span>
          <div id="modalName" class="triage-field-value"></div>
        </div>
        <div>
          <span class="triage-field-label">AI Urgency Classification</span>
          <div id="modalUrgency"></div>
        </div>
      </div>

      <div style="margin-bottom: 18px;">
        <span class="triage-field-label">Symptoms Selected</span>
        <div id="modalSymptoms" class="triage-modal-box"></div>
      </div>

      <div style="margin-bottom: 18px;">
        <span class="triage-field-label">Chief Complaint</span>
        <div id="modalComplaint" class="triage-modal-box triage-modal-box--complaint"></div>
      </div>

      <div class="triage-override-box">
        <span class="triage-field-label">Manual Override (Clinical Decision)</span>
        <div class="triage-override-row">
          <select id="overrideLevel">
            <option value="1">Urgent (Priority 1)</option>
            <option value="2">Urgent (Priority 2)</option>
            <option value="3">Non-Urgent (Priority 3)</option>
            <option value="4">Routine (Priority 4)</option>
            <option value="5">Routine (Priority 5)</option>
          </select>
          <button type="button" class="mc-btn mc-btn--primary" style="padding: 8px 16px; font-size: 12px;" onclick="applyOverride()">Apply Override</button>
        </div>
        <p class="text-xs text-muted" style="margin: 8px 0 0;">Changing the priority level will be recorded in the system audit trail.</p>
      </div>
    </div>

    <div class="triage-modal__footer">
      <button type="button" class="mc-btn mc-btn--outline" onclick="closeTriageModal()">Close</button>
      <button type="button" id="modalAcceptBtn" class="mc-btn mc-btn--primary" onclick="acceptTriageFromModal()">Accept Patient &amp; Add to Queue</button>
    </div>
  </div>
</div>

<script>
const TRIAGE_API = <?= json_encode(ASSET_BASE . '/app/api/provider/update_triage.php') ?>;
let currentTriageId = null;

function parseTriagePayload(raw) {
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function viewTriageDetails(t) {
  currentTriageId = t.id;
  document.getElementById('modalName').textContent = t.name || '—';

  const symptoms = Array.isArray(t.symptoms_list) && t.symptoms_list.length
    ? t.symptoms_list.join(', ')
    : (t.symptoms_display || '—');
  document.getElementById('modalSymptoms').textContent = symptoms;
  document.getElementById('modalComplaint').textContent = t.complaint || 'No detailed complaint provided.';
  document.getElementById('overrideLevel').value = t.level || '3';

  const urgencyEl = document.getElementById('modalUrgency');
  urgencyEl.innerHTML = t.urgency === 'Urgent'
    ? '<span class="triage-badge triage-badge--urgent">Urgent</span>'
    : '<span class="triage-badge triage-badge--routine">Non-Urgent</span>';

  document.getElementById('modalAcceptBtn').style.display = t.reviewed ? 'none' : 'inline-flex';

  const modal = document.getElementById('triageModal');
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden', 'false');
}

function closeTriageModal() {
  const modal = document.getElementById('triageModal');
  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden', 'true');
}

async function acceptTriage(id) {
  if (!confirm('Accept this patient and move them to the consultation queue?')) return;
  try {
    const res = await fetch(TRIAGE_API, {
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({ id: String(id), action: 'accept' }),
    });
    const data = await res.json();
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Could not update triage status.');
    }
  } catch {
    alert('Error updating triage status.');
  }
}

async function applyOverride() {
  const level = document.getElementById('overrideLevel').value;
  if (!currentTriageId) return;
  if (!confirm('Are you sure you want to manually override the AI priority level?')) return;
  try {
    const res = await fetch(TRIAGE_API, {
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({
        id: String(currentTriageId),
        action: 'override',
        level: level,
      }),
    });
    const data = await res.json();
    if (data.success) {
      window.location.reload();
    } else {
      alert(data.message || 'Could not update priority.');
    }
  } catch {
    alert('Error updating priority.');
  }
}

function acceptTriageFromModal() {
  if (currentTriageId) {
    acceptTriage(currentTriageId);
  }
}

document.querySelectorAll('.triage-view-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    const payload = parseTriagePayload(btn.dataset.triage || '');
    if (payload) viewTriageDetails(payload);
  });
});

document.querySelectorAll('.triage-accept-btn').forEach((btn) => {
  btn.addEventListener('click', () => acceptTriage(Number(btn.dataset.id || 0)));
});

document.querySelectorAll('.triage-tab[data-filter]').forEach((tab) => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.triage-tab[data-filter]').forEach((t) => t.classList.remove('active'));
    tab.classList.add('active');
    const filter = tab.dataset.filter;
    document.querySelectorAll('#triageTable tbody tr[data-urgency]').forEach((row) => {
      if (filter === 'all') {
        row.style.display = '';
        return;
      }
      if (filter === 'reviewed') {
        row.style.display = row.dataset.reviewed === 'true' ? '' : 'none';
        return;
      }
      if (filter === 'pending') {
        row.style.display = row.dataset.pending === 'true' ? '' : 'none';
        return;
      }
      row.style.display = row.dataset.urgency === filter ? '' : 'none';
    });
  });
});

document.getElementById('triageModal')?.addEventListener('click', (event) => {
  if (event.target.id === 'triageModal') {
    closeTriageModal();
  }
});
</script>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
