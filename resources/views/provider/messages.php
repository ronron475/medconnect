<?php
$active_page = 'messages';
$page_title  = 'Messages';
require __DIR__ . '/partials/icons.php';
require __DIR__ . '/partials/data.php';
require __DIR__ . '/partials/queue_helpers.php';
require_once BASE_PATH . '/app/includes/message_deletion.php';

$page_styles = ['provider_session_alert.css', 'messages-delete.css'];
$provider_messages_css_ver = (int) @filemtime(ASSETS_PATH . '/css/provider-messages.css');

$provider_id = (int)($_SESSION['user_id'] ?? 0);
$box = strtolower(trim((string) ($_GET['box'] ?? 'inbox'))); // inbox|archived|all
if (!in_array($box, ['inbox', 'archived', 'all'], true)) $box = 'inbox';

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
    consultation_thread_state_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            c.id AS consultation_id,
            c.patient_id,
            c.consult_date,
            c.consult_time,
            c.consult_type,
            c.status,
            c.created_at,
            s.slot_date,
            s.start_time AS slot_start,
            u.first_name,
            u.last_name,
            u.email,
            COALESCE(pr.contact_number, '')                                        AS phone,
            pr.age,
            CONCAT_WS(', ', NULLIF(pr.barangay,''), NULLIF(pr.city_municipality,''), NULLIF(pr.province,'')) AS address,
            tr.chief_complaint,
            tr.symptoms,
            tr.urgency_label,
            vs.room_token,
            COALESCE(ts.is_archived, 0) AS is_archived,
            COALESCE(ts.is_deleted, 0) AS is_deleted,
            COALESCE(unread.cnt, 0) AS unread
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN consultation_thread_state ts ON ts.consultation_id = c.id AND ts.user_id = ?
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
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        LEFT JOIN (
            SELECT consultation_id, COUNT(*) AS cnt
            FROM consultation_messages
            WHERE receiver_id = ? AND is_read = 0 AND is_deleted_for_everyone = 0
            GROUP BY consultation_id
        ) unread ON unread.consultation_id = c.id
        WHERE c.provider_id = ?
          AND (ts.is_deleted IS NULL OR ts.is_deleted = 0)
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
    $stmt->execute([$provider_id, $provider_id, $provider_id]);
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
        $archived = !empty($row['is_archived']);
        if ($box === 'inbox' && $archived) continue;
        if ($box === 'archived' && !$archived) continue;

        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $complaint = $row['chief_complaint'] ?: $row['consult_type'] ?: 'General Consultation';
        $preview = $row['chief_complaint']
            ? 'Chief complaint: ' . $row['chief_complaint']
            : 'Consultation request for ' . ($row['consult_type'] ?: 'General Consultation');

        $session_access = queue_session_access([
            'status'       => $row['status'] ?? 'pending',
            'consult_date' => $row['consult_date'] ?? '',
            'consult_time' => $row['consult_time'] ?? '',
            'slot_date'    => $row['slot_date'] ?? '',
            'slot_start'   => $row['slot_start'] ?? '',
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
            'is_archived'          => (int) ($row['is_archived'] ?? 0),
            'unread'               => (int) ($row['unread'] ?? 0),
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

// Do NOT mark as read on page load. Read state is updated only when the user opens a conversation
// (handled via POST /app/api/messages/mark_read.php with CSRF).

require __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/provider-messages.css?v=<?= $provider_messages_css_ver ?>"/>

<div class="messages-page">
  <header class="messages-page-head">
    <h1 class="messages-page-title">Messages</h1>
    <span class="messages-refresh-badge" id="refreshStatus">Auto-refresh on</span>
  </header>

  <div class="mc-msg-filters" role="tablist" aria-label="Conversation filter">
    <a class="mc-msg-filter <?= $box === 'inbox' ? 'is-active' : '' ?>" href="?box=inbox" role="tab" aria-selected="<?= $box === 'inbox' ? 'true' : 'false' ?>">Inbox</a>
    <a class="mc-msg-filter <?= $box === 'archived' ? 'is-active' : '' ?>" href="?box=archived" role="tab" aria-selected="<?= $box === 'archived' ? 'true' : 'false' ?>">Archived</a>
    <a class="mc-msg-filter <?= $box === 'all' ? 'is-active' : '' ?>" href="?box=all" role="tab" aria-selected="<?= $box === 'all' ? 'true' : 'false' ?>">All</a>
  </div>

  <div class="messages-shell" id="messagesShell">
    <aside class="msg-panel msg-panel--list">
      <div class="msg-sidebar-top">
        <div class="msg-search-wrap">
          <span class="msg-search-icon" aria-hidden="true"><?= icon_sm('search') ?></span>
          <input type="search" id="messageSearch" placeholder="Search patients" aria-label="Search patients">
        </div>
      </div>
      <div class="msg-list" id="messageList">
        <?php foreach ($conversations as $index => $conversation): ?>
          <button
            type="button"
            class="msg-item <?= $index === 0 ? 'active' : '' ?><?= !empty($conversation['unread']) ? ' is-unread' : '' ?>"
            data-index="<?= $index ?>"
            data-search="<?= htmlspecialchars(strtolower($conversation['name'] . ' ' . $conversation['preview'] . ' ' . $conversation['status'])) ?>"
            data-consultation-id="<?= (int) ($conversation['consultation_id'] ?? 0) ?>"
            data-archived="<?= !empty($conversation['is_archived']) ? '1' : '0' ?>"
          >
            <span class="msg-avatar" aria-hidden="true"><?= htmlspecialchars($conversation['initials']) ?></span>
            <span class="msg-item-body">
              <span class="msg-name-row">
                <span class="msg-name"><?= htmlspecialchars($conversation['name']) ?></span>
                <span class="msg-time" data-msg-time="<?= $index ?>"><?= htmlspecialchars($conversation['time']) ?></span>
              </span>
              <span class="msg-preview" data-msg-preview="<?= $index ?>"><?= htmlspecialchars($conversation['preview']) ?></span>
            </span>
            <span class="msg-actions">
              <?php if (!empty($conversation['unread'])): ?>
              <span class="msg-unread-badge" aria-label="<?= (int) $conversation['unread'] ?> unread"><?= (int) $conversation['unread'] ?></span>
              <?php endif; ?>
              <span class="msg-kebab" role="button" tabindex="0" aria-label="Conversation actions" data-thread-menu="1">
                <span class="msg-kebab__dots" aria-hidden="true"></span>
              </span>
            </span>
          </button>
        <?php endforeach; ?>
      </div>
    </aside>

    <section class="msg-panel msg-panel--thread">
      <div class="msg-thread-header">
        <div class="msg-thread-header-left">
          <button type="button" class="msg-back" id="msgBackBtn" aria-label="Back to conversations" title="Back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
          </button>
          <div class="msg-avatar" id="activeInitials" aria-hidden="true"><?= htmlspecialchars($active_msg['initials']) ?></div>
          <div class="msg-thread-head-text">
            <div class="msg-name" id="activeName"><?= htmlspecialchars($active_msg['name']) ?></div>
            <div class="msg-presence" id="activePresence">
              <span class="msg-presence-dot" aria-hidden="true"></span>
              <span id="activeMeta"><?= htmlspecialchars(str_replace('_', ' ', $active_msg['status'])) ?></span>
            </div>
          </div>
        </div>
        <div class="msg-thread-actions">
          <button type="button" class="msg-icon-btn primary" id="videoButton" title="Start video" aria-label="Start video">
            <?= icon_sm('video') ?>
          </button>
          <button type="button" class="msg-icon-btn" id="sessionButton" title="Open consultation" aria-label="Open consultation">
            <?= icon_sm('phone') ?>
          </button>
        </div>
      </div>

      <div id="messageAlert" class="msg-alert"></div>

      <div class="thread-body" id="threadBody">
        <div class="msg-clinical-strip">
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
          <div class="msg-avatar" id="patientBubbleInitials" aria-hidden="true"><?= htmlspecialchars($active_msg['initials']) ?></div>
          <div>
            <div class="bubble patient" id="patientPreview"><?= htmlspecialchars($active_msg['preview']) ?></div>
            <div class="bubble-time" id="patientPreviewTime"><?= htmlspecialchars($active_msg['time']) ?></div>
          </div>
        </div>

        <div class="bubble-row provider seed-message">
          <div class="pd-avatar" style="width:32px;height:32px;font-size:10px"><?= htmlspecialchars($provider['initials']) ?></div>
          <div>
            <div class="bubble provider">
              I can review this from the consultation session. Use the phone icon to open the clinical workspace, or video to start the secure room.
            </div>
            <div class="bubble-time" style="text-align:right">Ready to connect</div>
          </div>
        </div>
      </div>

      <div class="composer">
        <div class="composer-inner">
          <input type="text" id="messageInput" placeholder="Write a message" aria-label="Write a message">
          <button type="button" class="msg-send-btn" id="sendMessageBtn"><?= icon_sm('send') ?> Send</button>
        </div>
      </div>
    </section>
  </div>
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
const messagesShell = document.getElementById('messagesShell');
const msgBackBtn = document.getElementById('msgBackBtn');
const activePresence = document.getElementById('activePresence');
const providerInitials = <?= json_encode($provider['initials'] ?? 'DR') ?>;
const currentUserId = <?= (int)$provider_id ?>;
const assetBase = <?= json_encode(ASSET_BASE) ?>;
const csrfToken = <?= json_encode((string) ($_SESSION['csrf_token'] ?? '')) ?>;
window.MedConnectCsrfToken = csrfToken;
window.MedConnectThreadActionUrl = <?= json_encode(ASSET_BASE . '/app/api/messages/thread_action.php') ?>;
const markReadUrl = <?= json_encode(ASSET_BASE . '/app/api/messages/mark_read.php') ?>;
let refreshTimer = null;
let refreshInFlight = false;
let lastEventId = 0;
let realtimePoller = null;

function presenceForStatus(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'in_consultation') {
    return { label: 'Online', online: true };
  }
  if (normalized === 'scheduled') {
    return { label: 'Scheduled', online: false };
  }
  if (normalized === 'pending') {
    return { label: 'Pending', online: false };
  }
  if (normalized === 'empty' || normalized === 'message only') {
    return { label: 'Unavailable', online: false };
  }
  return { label: normalized.replace(/_/g, ' '), online: false };
}

function isMobileMessages() {
  return window.matchMedia('(max-width: 767.98px)').matches;
}

function updatePresence(item) {
  const presence = presenceForStatus(item?.status);
  if (activeMeta) {
    activeMeta.textContent = presence.label;
  }
  if (activePresence) {
    activePresence.classList.toggle('is-online', presence.online);
  }
}

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
  videoButton.disabled = blocked || !item || !Number(item.consultation_id);
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

function clearConversationUnread(index) {
  const item = conversations[index];
  if (item) item.unread = 0;

  const row = document.querySelector(`.msg-item[data-index="${index}"]`);
  if (!row) return;
  row.classList.remove('is-unread');

  const badge = row.querySelector('.msg-unread-badge');
  if (badge) badge.remove();
}

function setSidebarUnread(count) {
  const n = Math.max(0, parseInt(count, 10) || 0);
  const text = n > 99 ? '99+' : String(n);
  document.querySelectorAll('[data-nav-messages-badge]').forEach((badge) => {
    badge.textContent = text;
    badge.hidden = n <= 0;
    badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
  });
}

// On messages.php we don't render the FAB component (which normally updates the sidebar badge),
// so listen to the global unread event here as well.
window.addEventListener('medconnect:messages-unread', (event) => {
  const d = event && event.detail ? event.detail : null;
  if (d && typeof d.unread_count !== 'undefined') {
    setSidebarUnread(d.unread_count);
  }
});

function setActiveConversation(index) {
  activeIndex = index;
  const item = conversations[index];
  if (!item) return;

  document.querySelectorAll('.msg-item').forEach((button) => {
    button.classList.toggle('active', Number(button.dataset.index) === index);
  });

  activeInitials.textContent = item.initials;
  activeName.textContent = item.name;
  updatePresence(item);
  activeComplaint.textContent = item.complaint;
  activeTriage.textContent = item.triage;
  activeAddress.textContent = item.address;
  patientBubbleInitials.textContent = item.initials;
  patientPreview.textContent = item.preview;
  patientPreviewTime.textContent = item.time;
  renderThread(item);
  startMessageRealtime();
  if (Number(item.consultation_id)) {
    fetch(markReadUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: new URLSearchParams({ consultation_id: String(item.consultation_id), csrf_token: csrfToken }),
    }).then(r => r.json()).then((j) => {
      if (j && j.success && typeof j.unread_count !== 'undefined' && window.MedConnectUnreadService) {
        window.MedConnectUnreadService.setUnread(j.unread_count, 'mark-read');
      }
      if (j && j.success) {
        clearConversationUnread(index);
        if (typeof j.unread_count !== 'undefined') setSidebarUnread(j.unread_count);
      }
    }).catch(() => {});
  }

  updateSessionActions(item);

  if (isMobileMessages() && messagesShell) {
    messagesShell.classList.add('is-thread-open');
  }

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
    if (typeof data.unread_count !== 'undefined') {
      if (window.MedConnectUnreadService) {
        window.MedConnectUnreadService.setUnread(data.unread_count, 'messages-page');
      } else {
        window.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: data.unread_count, source: 'messages-page' } }));
      }
    }
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
  button.addEventListener('click', (e) => {
    // Allow kebab/menu clicks without opening thread.
    if (e && e.target && e.target.closest && e.target.closest('.msg-kebab')) return;
    setActiveConversation(Number(button.dataset.index));
  });
});

// Thread menu (popover)
<?php $threadMenuVer = (int) @filemtime(ASSETS_PATH . '/js/messages-thread-menu.js'); ?>
// loaded below

if (msgBackBtn && messagesShell) {
  msgBackBtn.addEventListener('click', () => {
    messagesShell.classList.remove('is-thread-open');
  });
}

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
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        consultation_id: item.consultation_id,
        message,
        csrf_token: csrfToken
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
      body: new URLSearchParams({
        consultation_id: item.consultation_id,
        csrf_token: document.body.dataset.csrf || ''
      })
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

<script src="<?= ASSET_BASE ?>/assets/js/messages-thread-menu.js?v=<?= $threadMenuVer ?>" defer></script>
<?php require __DIR__ . '/partials/layout_close.php'; ?>
