<?php
$active_page = 'messages';
$page_title  = 'Messages';
require __DIR__ . '/partials/icons.php';
require __DIR__ . '/partials/data.php';
require __DIR__ . '/partials/queue_helpers.php';
require_once BASE_PATH . '/app/includes/message_deletion.php';

$page_styles = ['provider_session_alert.css', 'messages-delete.css'];

$provider_id = (int)($_SESSION['user_id'] ?? 0);

function provider_message_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first = $parts[0] ?? 'P';
    $last = $parts[count($parts) - 1] ?? '';
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
}

$conversations = [];

try {
    consultation_messages_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            c.id AS consultation_id,
            c.patient_id,
            c.consult_date,
            c.consult_time,
            c.consult_type,
            c.status,
            c.created_at,
            u.first_name,
            u.last_name,
            u.email,
            COALESCE(pr.contact_number, '')                                        AS phone,
            pr.age,
            CONCAT_WS(', ', NULLIF(pr.barangay,''), NULLIF(pr.city_municipality,''), NULLIF(pr.province,'')) AS address,
            tr.chief_complaint,
            tr.symptoms,
            tr.urgency_label,
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
        WHERE c.provider_id = ?
        ORDER BY
            CASE c.status
                WHEN 'in_consultation' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'pending' THEN 3
                ELSE 4
            END,
            c.consult_date DESC,
            c.consult_time DESC
    ");
    $stmt->execute([$provider_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $consultation_ids = array_map(fn($row) => (int)$row['consultation_id'], $rows);
    $messages_by_consultation = [];

    if ($consultation_ids) {
        $placeholders = implode(',', array_fill(0, count($consultation_ids), '?'));
        $msg_stmt = $pdo->prepare("
            SELECT cm.id, cm.consultation_id, cm.sender_id, cm.receiver_id, cm.message, cm.created_at,
                   u.first_name, u.last_name, u.role
            FROM consultation_messages cm
            JOIN users u ON u.id = cm.sender_id
            WHERE cm.consultation_id IN ($placeholders)
            ORDER BY cm.created_at ASC, cm.id ASC
        ");
        $msg_stmt->execute($consultation_ids);
        foreach ($msg_stmt->fetchAll(PDO::FETCH_ASSOC) as $message_row) {
            $cid = (int)$message_row['consultation_id'];
            $formatted = message_format_for_viewer($message_row, $provider_id);
            if ($formatted === null) {
                continue;
            }
            $messages_by_consultation[$cid][] = $formatted;
        }
    }

    foreach ($rows as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $complaint = $row['chief_complaint'] ?: $row['consult_type'] ?: 'General Consultation';
        $preview = $row['chief_complaint']
            ? 'Chief complaint: ' . $row['chief_complaint']
            : 'Consultation request for ' . ($row['consult_type'] ?: 'General Consultation');

        $session_access = queue_session_access([
            'status'       => $row['status'] ?? 'pending',
            'consult_date' => $row['consult_date'] ?? '',
            'consult_time' => $row['consult_time'] ?? '',
        ]);

        $conversations[] = [
            'consultation_id'      => (int)$row['consultation_id'],
            'patient_id'           => (int)$row['patient_id'],
            'name'                 => $name,
            'initials'             => provider_message_initials($name),
            'email'                => $row['email'] ?? '',
            'phone'                => $row['phone'] ?? '',
            'age'                  => $row['age'] ?: 'N/A',
            'address'              => $row['address'] ?: 'No address on file',
            'time'                 => date('M j, g:i A', strtotime($row['consult_date'] . ' ' . $row['consult_time'])),
            'preview'              => $preview,
            'complaint'            => $complaint,
            'triage'               => $row['urgency_label'] ?: 'Not triaged',
            'status'               => $row['status'] ?: 'pending',
            'consult_date'         => $row['consult_date'] ?? '',
            'consult_time'         => $row['consult_time'] ?? '',
            'session_allowed'      => $session_access['allowed'],
            'session_block_reason' => $session_access['reason'],
            'scheduled_label'      => $session_access['scheduled_label'],
            'room_token'           => $row['room_token'] ?: '',
            'messages'             => $messages_by_consultation[(int)$row['consultation_id']] ?? [],
        ];
    }
} catch (Exception $e) {
    error_log('Provider messages query failed: ' . $e->getMessage());
}

if (!$conversations) {
    foreach (($messages ?? []) as $message) {
        $conversations[] = [
            'consultation_id' => 0,
            'patient_id'      => 0,
            'name'            => $message['from'],
            'initials'        => $message['initials'],
            'email'           => '',
            'phone'           => '',
            'age'             => 'N/A',
            'address'         => 'No active consultation linked',
            'time'            => $message['time'],
            'preview'         => $message['preview'],
            'complaint'       => 'No consultation selected',
            'triage'          => 'Not triaged',
            'status'               => 'message only',
            'consult_date'         => '',
            'consult_time'         => '',
            'session_allowed'      => false,
            'session_block_reason' => 'This message is not linked to a consultation yet.',
            'scheduled_label'      => '',
            'room_token'           => '',
            'messages'             => [],
        ];
    }
}

$active_msg = $conversations[0] ?? [
    'consultation_id' => 0,
    'name' => 'No conversations',
    'initials' => 'MC',
    'time' => '',
    'preview' => 'Consultations will appear here once assigned to you.',
    'complaint' => 'No active consultation',
    'triage' => 'N/A',
    'status' => 'empty',
    'age' => 'N/A',
    'address' => '',
    'consult_date' => '',
    'consult_time' => '',
    'session_allowed' => false,
    'session_block_reason' => 'No consultation selected.',
    'scheduled_label' => '',
    'room_token' => '',
    'messages' => [],
];

require __DIR__ . '/partials/layout_open.php';
?>

<style>
  .messages-shell {
    display: grid;
    grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
    gap: 20px;
    height: calc(100vh - 150px);
    min-height: 620px;
  }
  .msg-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
    overflow: hidden;
  }
  .msg-sidebar, .msg-thread { display: flex; flex-direction: column; min-height: 0; }
  .msg-header {
    min-height: 72px;
    padding: 16px 18px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
  }
  .msg-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #0f172a;
    font-size: 15px;
    font-weight: 800;
  }
  .msg-search { padding: 12px 14px; border-bottom: 1px solid #e2e8f0; }
  .msg-search input {
    width: 100%;
    height: 38px;
    border: 1px solid #dbe4ea;
    border-radius: 9px;
    background: #fff;
    color: #0f172a;
    padding: 0 12px 0 36px;
    font: inherit;
    font-size: 13px;
    outline: none;
  }
  .msg-search-wrap { position: relative; }
  .msg-search-wrap span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #64748b; }
  .msg-list { overflow-y: auto; flex: 1; }
  .msg-item {
    width: 100%;
    border: 0;
    border-bottom: 1px solid #edf2f7;
    background: #fff;
    padding: 14px 16px;
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr);
    gap: 12px;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s, box-shadow 0.15s;
  }
  .msg-item:hover, .msg-item.active { background: #f0fdfa; box-shadow: inset 3px 0 0 #0d9488; }
  .msg-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0d9488, #1d4ed8);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
    flex-shrink: 0;
  }
  .msg-name-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 3px; }
  .msg-name { color: #0f172a; font-size: 13.5px; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .msg-time { color: #64748b; font-size: 11px; white-space: nowrap; }
  .msg-preview { color: #64748b; font-size: 12.5px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .msg-status {
    display: inline-flex;
    width: fit-content;
    margin-top: 8px;
    padding: 3px 8px;
    border-radius: 999px;
    background: #e0f2fe;
    color: #075985;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
  }
  .msg-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
  .msg-action {
    height: 38px;
    border-radius: 9px;
    border: 1px solid #dbe4ea;
    background: #fff;
    color: #0f172a;
    padding: 0 13px;
    font-size: 12.5px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    cursor: pointer;
  }
  .msg-action:hover { background: #f8fafc; }
  .msg-action.primary { background: #0d9488; border-color: #0d9488; color: #fff; }
  .msg-action.primary:hover { background: #0f766e; }
  .msg-action:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    pointer-events: none;
  }
  .msg-action.is-schedule-blocked {
    opacity: 0.55;
    cursor: not-allowed;
    background: #94a3b8;
    border-color: #94a3b8;
    color: #fff;
  }
  .msg-action.primary.is-schedule-blocked {
    background: #94a3b8;
    border-color: #94a3b8;
  }
  .msg-action.is-schedule-blocked:hover {
    background: #94a3b8;
    border-color: #94a3b8;
    color: #fff;
  }
  .patient-strip {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }
  .patient-meta { color: #64748b; font-size: 12px; margin-top: 2px; }
  .thread-body {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 22px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  }
  .msg-thread .thread-body .bubble-wrap {
    max-width: 100%;
  }
  .clinical-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    padding: 16px;
    margin-bottom: 18px;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
  }
  .clinical-label { color: #64748b; font-size: 11px; font-weight: 800; text-transform: uppercase; margin-bottom: 5px; }
  .clinical-value { color: #0f172a; font-size: 13px; font-weight: 700; line-height: 1.4; }
  .bubble-row { display: flex; gap: 10px; align-items: flex-end; margin-bottom: 14px; }
  .bubble-row.provider { flex-direction: row-reverse; }
  .bubble { max-width: 68%; border-radius: 14px; padding: 12px 14px; font-size: 13.5px; line-height: 1.55; }
  .bubble.patient { background: #fff; border: 1px solid #e2e8f0; color: #334155; border-bottom-left-radius: 4px; }
  .bubble.provider { background: #ccfbf1; border: 1px solid #99f6e4; color: #134e4a; border-bottom-right-radius: 4px; }
  .bubble-time { color: #64748b; font-size: 11px; margin-top: 4px; }
  .composer {
    padding: 14px 16px;
    border-top: 1px solid #e2e8f0;
    background: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .composer input {
    flex: 1;
    height: 42px;
    border: 1px solid #dbe4ea;
    border-radius: 10px;
    padding: 0 14px;
    font: inherit;
    color: #0f172a;
    outline: none;
  }
  .msg-alert {
    display: none;
    margin: 0 18px 14px;
    padding: 10px 12px;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 700;
  }
  .msg-alert.show { display: block; }
  .msg-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .msg-alert.info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
  .msg-alert.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
  @media (max-width: 980px) {
    .messages-shell { grid-template-columns: 1fr; height: auto; }
    .msg-sidebar { max-height: 420px; }
    .clinical-card { grid-template-columns: 1fr; }
    .bubble { max-width: 82%; }
  }
</style>

<div class="messages-shell">
  <aside class="msg-panel msg-sidebar">
    <div class="msg-header">
      <div class="msg-title"><?= icon('message') ?> Patient Messages</div>
      <span class="msg-status" id="refreshStatus">Auto-refresh on</span>
    </div>
    <div class="msg-search">
      <div class="msg-search-wrap">
        <span><?= icon_sm('search') ?></span>
        <input type="search" id="messageSearch" placeholder="Search patients">
      </div>
    </div>
    <div class="msg-list" id="messageList">
      <?php foreach ($conversations as $index => $conversation): ?>
        <button
          type="button"
          class="msg-item <?= $index === 0 ? 'active' : '' ?>"
          data-index="<?= $index ?>"
          data-search="<?= htmlspecialchars(strtolower($conversation['name'] . ' ' . $conversation['preview'] . ' ' . $conversation['status'])) ?>"
        >
          <span class="msg-avatar"><?= htmlspecialchars($conversation['initials']) ?></span>
          <span style="min-width:0">
            <span class="msg-name-row">
              <span class="msg-name"><?= htmlspecialchars($conversation['name']) ?></span>
              <span class="msg-time" data-msg-time="<?= $index ?>"><?= htmlspecialchars($conversation['time']) ?></span>
            </span>
            <span class="msg-preview" data-msg-preview="<?= $index ?>"><?= htmlspecialchars($conversation['preview']) ?></span>
            <span class="msg-status"><?= htmlspecialchars(str_replace('_', ' ', $conversation['status'])) ?></span>
          </span>
        </button>
      <?php endforeach; ?>
    </div>
  </aside>

  <section class="msg-panel msg-thread">
    <div class="msg-header">
      <div class="patient-strip">
        <div class="msg-avatar" id="activeInitials"><?= htmlspecialchars($active_msg['initials']) ?></div>
        <div style="min-width:0">
          <div class="msg-name" id="activeName"><?= htmlspecialchars($active_msg['name']) ?></div>
          <div class="patient-meta" id="activeMeta">
            <?= htmlspecialchars($active_msg['age']) ?> years old &bull; <?= htmlspecialchars($active_msg['status']) ?>
          </div>
        </div>
      </div>
      <div class="msg-actions">
        <button type="button" class="msg-action" id="sessionButton">
          <?= icon_sm('phone') ?> Call
        </button>
        <button type="button" class="msg-action primary" id="videoButton">
          <?= icon_sm('video') ?> Video
        </button>
      </div>
    </div>

    <div id="messageAlert" class="msg-alert"></div>

    <div class="thread-body" id="threadBody">
      <div class="clinical-card">
        <div>
          <div class="clinical-label">Consultation</div>
          <div class="clinical-value" id="activeComplaint"><?= htmlspecialchars($active_msg['complaint']) ?></div>
        </div>
        <div>
          <div class="clinical-label">AI Triage</div>
          <div class="clinical-value" id="activeTriage"><?= htmlspecialchars($active_msg['triage']) ?></div>
        </div>
        <div>
          <div class="clinical-label">Address</div>
          <div class="clinical-value" id="activeAddress"><?= htmlspecialchars($active_msg['address']) ?></div>
        </div>
      </div>

      <div class="bubble-row seed-message">
        <div class="msg-avatar" id="patientBubbleInitials"><?= htmlspecialchars($active_msg['initials']) ?></div>
        <div>
          <div class="bubble patient" id="patientPreview"><?= htmlspecialchars($active_msg['preview']) ?></div>
          <div class="bubble-time" id="patientPreviewTime"><?= htmlspecialchars($active_msg['time']) ?></div>
        </div>
      </div>

      <div class="bubble-row provider seed-message">
        <div class="pd-avatar" style="width:36px;height:36px;font-size:12px"><?= htmlspecialchars($provider['initials']) ?></div>
        <div>
          <div class="bubble provider">
            I can review this from the consultation session. Use Call to open the clinical workspace, or Video to start the secure room for this consultation.
          </div>
          <div class="bubble-time" style="text-align:right">Ready to connect</div>
        </div>
      </div>
    </div>

    <div class="composer">
      <input type="text" id="messageInput" placeholder="Type a message note..." aria-label="Type a message note">
      <button type="button" class="msg-action primary" id="sendMessageBtn"><?= icon_sm('send') ?> Send</button>
    </div>
  </section>
</div>

<script src="<?= ASSET_BASE ?>/assets/js/messages-delete.js?v=2"></script>
<script>
const conversations = <?= json_encode($conversations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
let activeIndex = 0;

const activeInitials = document.getElementById('activeInitials');
const activeName = document.getElementById('activeName');
const activeMeta = document.getElementById('activeMeta');
const activeComplaint = document.getElementById('activeComplaint');
const activeTriage = document.getElementById('activeTriage');
const activeAddress = document.getElementById('activeAddress');
const patientBubbleInitials = document.getElementById('patientBubbleInitials');
const patientPreview = document.getElementById('patientPreview');
const patientPreviewTime = document.getElementById('patientPreviewTime');
const sessionButton = document.getElementById('sessionButton');
const videoButton = document.getElementById('videoButton');
const messageAlert = document.getElementById('messageAlert');
const threadBody = document.getElementById('threadBody');
const messageInput = document.getElementById('messageInput');
const sendMessageBtn = document.getElementById('sendMessageBtn');
const refreshStatus = document.getElementById('refreshStatus');
const providerInitials = <?= json_encode($provider['initials'] ?? 'DR') ?>;
const currentUserId = <?= (int)$provider_id ?>;
const assetBase = <?= json_encode(ASSET_BASE) ?>;
let refreshTimer = null;
let refreshInFlight = false;
let lastEventId = 0;
let realtimePoller = null;

function setAlert(message, type = 'info') {
  messageAlert.textContent = message;
  messageAlert.className = 'msg-alert show ' + type;
}

function clearAlert() {
  messageAlert.textContent = '';
  messageAlert.className = 'msg-alert';
}

function getSessionAccess(item) {
  if (!item || !Number(item.consultation_id)) {
    return {
      allowed: false,
      reason: item?.session_block_reason || 'This message is not linked to a consultation yet.'
    };
  }
  if (item.session_allowed) {
    return { allowed: true, reason: '' };
  }
  return {
    allowed: false,
    reason: item.session_block_reason || 'This session cannot be opened right now.'
  };
}

function updateSessionActions(item) {
  const access = getSessionAccess(item);
  const blocked = !access.allowed;

  sessionButton.classList.toggle('is-schedule-blocked', blocked);
  videoButton.classList.toggle('is-schedule-blocked', blocked);
  videoButton.disabled = !item || !Number(item.consultation_id);
  sessionButton.dataset.blockReason = access.reason || '';
  videoButton.dataset.blockReason = access.reason || '';
}

function escapeHtml(value) {
  return MedConnectMessages.escapeHtml(value);
}

function renderThread(item) {
  document.querySelectorAll('.dynamic-message').forEach((node) => node.remove());
  document.querySelectorAll('.seed-message').forEach((node) => {
    node.style.display = item.messages && item.messages.length ? 'none' : '';
  });

  if (!item.messages || !item.messages.length) {
    threadBody.scrollTop = threadBody.scrollHeight;
    return;
  }

  const fragment = document.createDocumentFragment();
  item.messages.forEach((message) => {
    const isMine = Number(message.sender_id) === currentUserId;
    const row = document.createElement('div');
    row.className = 'bubble-row dynamic-message' + (isMine ? ' provider' : '');
    row.innerHTML = `
      <div class="${isMine ? 'pd-avatar' : 'msg-avatar'}" style="width:36px;height:36px;font-size:12px">${escapeHtml(isMine ? providerInitials : item.initials)}</div>
      <div>
        ${MedConnectMessages.buildBubbleHtml(message, isMine ? 'provider' : 'patient')}
        <div class="bubble-time" style="${isMine ? 'text-align:right' : ''}">${escapeHtml(message.time || '')}</div>
      </div>
    `;
    fragment.appendChild(row);
  });
  threadBody.appendChild(fragment);
  MedConnectMessages.bindMessageInteractions(threadBody, item.messages || [], {
    assetBase,
    onDeleted(result, eventType) {
      const active = conversations[activeIndex];
      if (!active) return;
      if (eventType === 'deleted_for_me') {
        active.messages = (active.messages || []).filter((msg) => Number(msg.id) !== Number(result.data.message_id));
      } else if (result.data?.message) {
        active.messages = (active.messages || []).map((msg) => Number(msg.id) === Number(result.data.message_id) ? result.data.message : msg);
      }
      renderThread(active);
      updateConversationPreview(activeIndex, active.messages);
      setAlert(eventType === 'deleted_for_everyone' ? 'Message deleted for everyone.' : 'Message deleted for you.', 'success');
      setTimeout(clearAlert, 1600);
    },
    onError(message) { setAlert(message, 'error'); }
  });
  threadBody.scrollTop = threadBody.scrollHeight;
}

function updateConversationPreview(index, messages) {
  if (!messages || !messages.length) return;
  const latest = messages[messages.length - 1];
  const preview = document.querySelector(`[data-msg-preview="${index}"]`);
  const time = document.querySelector(`[data-msg-time="${index}"]`);
  if (preview) preview.textContent = latest.message;
  if (time) time.textContent = latest.time || '';
}

function setActiveConversation(index) {
  activeIndex = index;
  const item = conversations[index];
  if (!item) return;

  document.querySelectorAll('.msg-item').forEach((button) => {
    button.classList.toggle('active', Number(button.dataset.index) === index);
  });

  activeInitials.textContent = item.initials;
  activeName.textContent = item.name;
  activeMeta.textContent = `${item.age} years old • ${item.status.replace('_', ' ')}`;
  activeComplaint.textContent = item.complaint;
  activeTriage.textContent = item.triage;
  activeAddress.textContent = item.address;
  patientBubbleInitials.textContent = item.initials;
  patientPreview.textContent = item.preview;
  patientPreviewTime.textContent = item.time;
  renderThread(item);
  startMessageRealtime();

  updateSessionActions(item);

  if (!Number(item.consultation_id)) {
    setAlert('This message is not linked to a consultation yet, so calls cannot start from here.', 'error');
  } else if (!item.session_allowed) {
    setAlert(item.session_block_reason || 'This session is not available on today\'s schedule.', 'info');
  } else {
    clearAlert();
  }
}

async function refreshActiveMessages(silent = true) {
  if (refreshInFlight) return;
  const item = conversations[activeIndex];
  if (!item || !Number(item.consultation_id)) return;

  refreshInFlight = true;
  try {
    const response = await fetch(`<?= ASSET_BASE ?>/app/api/messages/list.php?consultation_id=${encodeURIComponent(item.consultation_id)}&_=${Date.now()}`, {
      cache: 'no-store'
    });
    const data = await response.json();
    if (!data.success) {
      if (!silent) setAlert(data.message || 'Could not refresh messages.', 'error');
      return;
    }

    const oldCount = item.messages ? item.messages.length : 0;
    item.messages = data.messages || [];
    renderThread(item);
    updateConversationPreview(activeIndex, item.messages);
    if (refreshStatus) refreshStatus.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (!silent && item.messages.length > oldCount) {
      setAlert('New message loaded.', 'success');
      setTimeout(clearAlert, 1200);
    }
  } catch (error) {
    if (!silent) setAlert('Could not refresh messages.', 'error');
    if (refreshStatus) refreshStatus.textContent = 'Refresh paused';
  } finally {
    refreshInFlight = false;
  }
}

function startMessageRealtime() {
  if (realtimePoller) realtimePoller.stop();
  lastEventId = 0;
  realtimePoller = MedConnectMessages.createRealtimePoller(
    () => conversations[activeIndex]?.consultation_id || 0,
    () => lastEventId,
    (id) => { lastEventId = id; },
    (events) => {
      const item = conversations[activeIndex];
      if (!item) return;
      let changed = false;
      events.forEach((event) => {
        const before = (item.messages || []).length;
        item.messages = MedConnectMessages.applyLocalDeletion(item.messages || [], event, currentUserId);
        if (item.messages.length !== before) changed = true;
        if (event.event_type === 'deleted_for_everyone') changed = true;
      });
      if (changed) {
        renderThread(item);
        updateConversationPreview(activeIndex, item.messages);
      }
    },
    { assetBase }
  );
  realtimePoller.start(2000);
}

function startMessageAutoRefresh() {
  clearInterval(refreshTimer);
  refreshTimer = setInterval(() => refreshActiveMessages(true), 2000);
}

document.querySelectorAll('.msg-item').forEach((button) => {
  button.addEventListener('click', () => setActiveConversation(Number(button.dataset.index)));
});

document.getElementById('messageSearch').addEventListener('input', (event) => {
  const query = event.target.value.trim().toLowerCase();
  document.querySelectorAll('.msg-item').forEach((button) => {
    button.style.display = button.dataset.search.includes(query) ? 'grid' : 'none';
  });
});

async function sendMessage() {
  const item = conversations[activeIndex];
  const message = messageInput.value.trim();
  if (!item || !Number(item.consultation_id)) {
    setAlert('Select a consultation before sending a message.', 'error');
    return;
  }
  if (!message) {
    setAlert('Type a message first.', 'error');
    return;
  }

  sendMessageBtn.disabled = true;
  const originalLabel = sendMessageBtn.innerHTML;
  sendMessageBtn.textContent = 'Sending...';

  try {
    const response = await fetch('<?= ASSET_BASE ?>/app/api/messages/send.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        consultation_id: item.consultation_id,
        message
      })
    });
    const data = await response.json();

    if (!data.success) {
      setAlert(data.message || 'Could not send message.', 'error');
      return;
    }

    item.messages = item.messages || [];
    item.messages.push(data.data);
    messageInput.value = '';
    renderThread(item);
    updateConversationPreview(activeIndex, item.messages);
    setAlert('Message sent to patient.', 'success');
    setTimeout(clearAlert, 1800);
  } catch (error) {
    setAlert('Could not reach the message service. Please try again.', 'error');
  } finally {
    sendMessageBtn.disabled = false;
    sendMessageBtn.innerHTML = originalLabel;
  }
}

sendMessageBtn.addEventListener('click', sendMessage);
messageInput.addEventListener('keydown', (event) => {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault();
    sendMessage();
  }
});

sessionButton.addEventListener('click', () => {
  const item = conversations[activeIndex];
  const access = getSessionAccess(item);
  if (!access.allowed) {
    if (typeof window.openProviderSessionAlert === 'function') {
      window.openProviderSessionAlert(access.reason);
    } else {
      setAlert(access.reason, 'error');
    }
    return;
  }
  window.location.href = `consultation_session.php?id=${item.consultation_id}`;
});

videoButton.addEventListener('click', async () => {
  const item = conversations[activeIndex];
  if (!item || !Number(item.consultation_id)) return;

  const access = getSessionAccess(item);
  if (!access.allowed) {
    if (typeof window.openProviderSessionAlert === 'function') {
      window.openProviderSessionAlert(access.reason);
    } else {
      setAlert(access.reason, 'error');
    }
    return;
  }

  videoButton.disabled = true;
  setAlert('Starting secure video room...', 'info');

  try {
    const response = await fetch('<?= ASSET_BASE ?>/app/api/consultations/start_video.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ consultation_id: item.consultation_id })
    });
    const data = await response.json();

    if (!data.success) {
      setAlert(data.message || 'Could not start video session.', 'error');
      return;
    }

    window.location.href = `consultation_session.php?id=${item.consultation_id}`;
  } catch (error) {
    setAlert('Could not reach the video service. Please try again.', 'error');
  } finally {
    updateSessionActions(conversations[activeIndex]);
  }
});

document.addEventListener('visibilitychange', () => {
  if (!document.hidden) refreshActiveMessages(true);
});

setActiveConversation(0);
refreshActiveMessages(true);
startMessageAutoRefresh();
</script>

<?php require __DIR__ . '/partials/session_schedule_modal.php'; ?>
<script src="<?= ASSET_BASE ?>/assets/js/provider-session-alert.js"></script>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
