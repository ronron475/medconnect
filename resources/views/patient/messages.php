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
require_once BASE_PATH . '/app/includes/message_deletion.php';

if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    require_once BASE_PATH . '/app/includes/auth_guard.php';
    header('Location: ' . auth_signin_required_url());
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$patient_id = (int)$_SESSION['user_id'];
$page_title = 'Messages';
require_once BASE_PATH . '/app/includes/message_deletion.php';

$box = strtolower(trim((string) ($_GET['box'] ?? 'inbox'))); // inbox|archived|all
if (!in_array($box, ['inbox', 'archived', 'all'], true)) $box = 'inbox';

consultation_messages_ensure_schema($pdo);
consultation_thread_state_ensure_schema($pdo);

$stmt = $pdo->prepare("
    SELECT c.id AS consultation_id, c.provider_id, c.provider_name, c.consult_date, c.consult_time, c.consult_type, c.status,
           u.first_name AS provider_first, u.last_name AS provider_last,
           COALESCE(ts.is_archived, 0) AS is_archived,
           COALESCE(ts.is_deleted, 0) AS is_deleted,
           COALESCE(unread.cnt, 0) AS unread
    FROM consultations c
    LEFT JOIN users u ON u.id = c.provider_id
    LEFT JOIN consultation_thread_state ts ON ts.consultation_id = c.id AND ts.user_id = ?
    LEFT JOIN (
        SELECT consultation_id, COUNT(*) AS cnt
        FROM consultation_messages
        WHERE receiver_id = ? AND is_read = 0 AND is_deleted_for_everyone = 0
        GROUP BY consultation_id
    ) unread ON unread.consultation_id = c.id
    WHERE c.patient_id = ?
      AND (ts.is_deleted IS NULL OR ts.is_deleted = 0)
    ORDER BY c.consult_date DESC, c.consult_time DESC
");
$stmt->execute([$patient_id, $patient_id, $patient_id]);
$consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$messages_by_consultation = [];
if ($consultations) {
    $ids = array_map(fn($c) => (int)$c['consultation_id'], $consultations);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT cm.*, u.first_name, u.last_name, u.role
        FROM consultation_messages cm
        JOIN users u ON u.id = cm.sender_id
        WHERE cm.consultation_id IN ($placeholders)
        ORDER BY cm.created_at ASC, cm.id ASC
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $formatted = message_format_for_viewer($row, $patient_id);
        if ($formatted === null) {
            continue;
        }
        $messages_by_consultation[(int)$row['consultation_id']][] = $formatted;
    }
}

$conversations = [];
foreach ($consultations as $c) {
    $archived = !empty($c['is_archived']);
    if ($box === 'inbox' && $archived) continue;
    if ($box === 'archived' && !$archived) continue;

    $doctor_name = trim(($c['provider_first'] ?? '') . ' ' . ($c['provider_last'] ?? ''));
    if ($doctor_name === '') {
        $doctor_name = $c['provider_name'] ?: 'Healthcare Provider';
    }
    $parts = preg_split('/\s+/', trim($doctor_name));
    $initials = strtoupper(substr($parts[0] ?? 'D', 0, 1) . substr($parts[count($parts) - 1] ?? '', 0, 1));
    $msgs = $messages_by_consultation[(int)$c['consultation_id']] ?? [];
    $last_msg = $msgs !== [] ? $msgs[array_key_last($msgs)] : null;
    $consult_label = $c['consult_type'] ?: 'General consultation';
    $fallback_time = date('M j, g:i A', strtotime($c['consult_date'] . ' ' . $c['consult_time']));
    $preview = $last_msg
        ? mb_strimwidth((string) ($last_msg['message'] ?? ''), 0, 72, '…')
        : $consult_label;
    $list_time = $last_msg && !empty($last_msg['time'])
        ? (string) $last_msg['time']
        : $fallback_time;
    $status = $c['status'] ?: 'pending';
    $conversations[] = [
        'consultation_id' => (int)$c['consultation_id'],
        'name' => 'Dr. ' . preg_replace('/^Dr\.\s*/i', '', $doctor_name),
        'initials' => $initials,
        'preview' => $preview,
        'time' => $list_time,
        'fallback_time' => $fallback_time,
        'fallback_preview' => $consult_label,
        'status' => $status,
        'status_label' => ucwords(str_replace('_', ' ', $status)),
        'consult_type' => $consult_label,
        'is_archived' => (int) ($c['is_archived'] ?? 0),
        'unread' => (int) ($c['unread'] ?? 0),
        'messages' => $msgs,
    ];
}

$active = $conversations[0] ?? [
    'consultation_id' => 0,
    'name' => 'No conversations',
    'initials' => 'MC',
    'preview' => 'Messages from your healthcare provider will appear here.',
    'time' => '',
    'fallback_time' => '',
    'fallback_preview' => 'No consultation',
    'status' => 'empty',
    'status_label' => 'No conversations',
    'consult_type' => 'No consultation',
    'messages' => [],
];

// Do NOT mark as read on page load. Read state is updated only when the user opens a conversation
// (handled via POST /app/api/messages/mark_read.php with CSRF).

$patient_initials = strtoupper(
    substr($_SESSION['first_name'] ?? 'P', 0, 1) . substr($_SESSION['last_name'] ?? '', 0, 1)
);
$patientMessagesCssVer = (int) @filemtime(ASSETS_PATH . '/css/patient-messages.css');
$patient_page_stylesheets = [
    ASSET_BASE . '/assets/css/messages-delete.css?v=2',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
<style>.msg-alert{display:none;margin:0 18px 14px;padding:10px 12px;border-radius:9px;font-size:12.5px;font-weight:800}.msg-alert.show{display:block}.msg-alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.msg-alert.success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}</style>
</head>
<body class="patient-portal" data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/patient-messages.css?v=<?= $patientMessagesCssVer ?>"/>

    <div class="messages-page">
      <header class="messages-page-head">
        <span class="messages-page-count" role="status"><?= count($conversations) ?> conversation<?= count($conversations) === 1 ? '' : 's' ?></span>
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
              <span class="msg-search-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
              </span>
              <input type="search" id="messageSearch" placeholder="Search" aria-label="Search conversations">
            </div>
          </div>
          <div class="msg-list">
            <?php if (empty($conversations)): ?>
            <div class="msg-list-empty">
              <p>No provider conversations yet.</p>
              <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="mc-btn mc-btn--outline" style="margin-top:10px;text-decoration:none;display:inline-flex;font-size:12px;">Book Consultation</a>
            </div>
            <?php endif; ?>
            <?php foreach ($conversations as $i => $c): ?>
            <button
              type="button"
              class="msg-item <?= $i === 0 ? 'active' : '' ?><?= !empty($c['unread']) ? ' is-unread' : '' ?>"
              data-index="<?= $i ?>"
              data-search="<?= htmlspecialchars(strtolower($c['name'] . ' ' . $c['preview'] . ' ' . $c['status'])) ?>"
              data-consultation-id="<?= (int) ($c['consultation_id'] ?? 0) ?>"
              data-archived="<?= !empty($c['is_archived']) ? '1' : '0' ?>"
            >
              <span class="msg-avatar" aria-hidden="true"><?= htmlspecialchars($c['initials']) ?></span>
              <span class="msg-item-body">
                <span class="msg-name-row">
                  <span class="msg-name"><?= htmlspecialchars($c['name']) ?></span>
                  <span class="msg-time" data-msg-time="<?= $i ?>"><?= htmlspecialchars($c['time']) ?></span>
                </span>
                <span class="msg-preview" data-msg-preview="<?= $i ?>"><?= htmlspecialchars($c['preview']) ?></span>
              </span>
              <span class="msg-actions">
                <?php if (!empty($c['unread'])): ?>
                <span class="msg-unread-badge" aria-label="<?= (int) $c['unread'] ?> unread"><?= (int) $c['unread'] ?></span>
                <?php endif; ?>
                <span class="msg-kebab" role="button" tabindex="0" aria-label="Conversation actions" data-thread-menu="1">
                  <span class="msg-kebab__dots" aria-hidden="true"></span>
                </span>
              </span>
              <span class="msg-status-pill msg-status-pill--<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', strtolower($c['status']))) ?>"><?= htmlspecialchars($c['status_label']) ?></span>
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
              <div class="msg-avatar" id="activeInitials" aria-hidden="true"><?= htmlspecialchars($active['initials']) ?></div>
              <div class="msg-thread-head-text">
                <div class="msg-name" id="activeName"><?= htmlspecialchars($active['name']) ?></div>
                <div class="msg-presence" id="activePresence">
                  <span class="msg-presence-dot" aria-hidden="true"></span>
                  <span id="activeConsult"><?= htmlspecialchars($active['consult_type']) ?></span>
                </div>
              </div>
            </div>
            <span class="msg-status-pill msg-status-pill--<?= htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', strtolower($active['status']))) ?>" id="activeStatus"><?= htmlspecialchars($active['status_label'] ?? ucwords(str_replace('_', ' ', $active['status']))) ?></span>
          </div>

          <div id="messageAlert" class="msg-alert"></div>

          <div class="thread-body" id="threadBody">
            <div class="msg-thread-empty" id="threadEmpty"<?= !empty($active['messages']) ? ' hidden' : '' ?>>
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
              <p>No messages yet</p>
              <span>Send a reply below to start the conversation.</span>
            </div>
          </div>

          <div class="composer">
            <div class="composer-inner">
              <input type="text" id="messageInput" placeholder="Write a message" aria-label="Write a message" autocomplete="off">
              <button type="button" class="msg-send-btn" id="sendMessageBtn">Send</button>
            </div>
          </div>
        </section>
      </div>
    </div>
<script src="<?= ASSET_BASE ?>/assets/js/messages-delete.js?v=2"></script>
<script>
const conversations = <?= json_encode($conversations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const currentUserId = <?= $patient_id ?>;
const myInitials = <?= json_encode($patient_initials) ?>;
const assetBase = <?= json_encode(ASSET_BASE) ?>;
const csrfToken = <?= json_encode((string) ($_SESSION['csrf_token'] ?? '')) ?>;
window.MedConnectCsrfToken = csrfToken;
window.MedConnectThreadActionUrl = <?= json_encode(ASSET_BASE . '/app/api/messages/thread_action.php') ?>;
const markReadUrl = <?= json_encode(ASSET_BASE . '/app/api/messages/mark_read.php') ?>;
let activeIndex = 0;
let refreshTimer = null;
let lastEventId = 0;
let realtimePoller = null;
const threadBody = document.getElementById('threadBody');
const messageInput = document.getElementById('messageInput');
const sendMessageBtn = document.getElementById('sendMessageBtn');
const messageAlert = document.getElementById('messageAlert');
const messagesShell = document.querySelector('.messages-shell');
const backBtn = document.getElementById('msgBackBtn');

function isMobileMessages() {
  return window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
}

function openThreadOnMobile() {
  if (!messagesShell) return;
  if (isMobileMessages()) {
    messagesShell.classList.add('is-thread-open');
  }
}

function openListOnMobile() {
  if (!messagesShell) return;
  messagesShell.classList.remove('is-thread-open');
}

// Ensure we never start in "shrunk two-panel" mode on phones.
document.addEventListener('DOMContentLoaded', () => {
  if (isMobileMessages()) {
    openListOnMobile();
  }
});

function escapeHtml(v){return MedConnectMessages.escapeHtml(v);}
function setAlert(m,t='success'){messageAlert.textContent=m;messageAlert.className='msg-alert show '+t;}
function clearAlert(){messageAlert.className='msg-alert';messageAlert.textContent='';}
const threadEmpty = document.getElementById('threadEmpty');
const activeStatus = document.getElementById('activeStatus');

function statusClass(status) {
  return 'msg-status-pill msg-status-pill--' + String(status || 'pending').toLowerCase().replace(/[^a-z0-9_-]/g, '');
}

function trimPreview(text, max = 72) {
  const value = String(text ?? '').trim();
  if (value.length <= max) return value;
  return value.slice(0, max - 1) + '…';
}

function updateConversationPreview(index, item) {
  const conv = item || conversations[index];
  if (!conv) return;

  const messages = conv.messages || [];
  const previewEl = document.querySelector(`[data-msg-preview="${index}"]`);
  const timeEl = document.querySelector(`[data-msg-time="${index}"]`);
  const listItem = document.querySelector(`.msg-item[data-index="${index}"]`);

  let previewText = conv.fallback_preview || conv.consult_type || conv.preview || '';
  let timeText = conv.fallback_time || conv.time || '';

  if (messages.length) {
    const latest = messages[messages.length - 1];
    const deleted = latest.deleted_for_everyone || latest.is_deleted;
    previewText = deleted ? 'This message was deleted.' : trimPreview(latest.message || '');
    timeText = latest.time || timeText;
    conv.preview = previewText;
    conv.time = timeText;
  } else {
    conv.preview = previewText;
    conv.time = timeText;
  }

  if (previewEl) previewEl.textContent = previewText;
  if (timeEl) timeEl.textContent = timeText;
  if (listItem) {
    listItem.dataset.search = `${conv.name} ${previewText} ${conv.status}`.toLowerCase();
  }
}

function renderThread(item){
  document.querySelectorAll('.dynamic-message').forEach(n=>n.remove());
  const hasMessages = !!(item.messages && item.messages.length);
  if (threadEmpty) threadEmpty.hidden = hasMessages;
  (item.messages||[]).forEach(msg=>{
    const mine = Number(msg.sender_id) === currentUserId;
    const row=document.createElement('div');
    row.className='bubble-row dynamic-message'+(mine?' mine':'');
    row.innerHTML=`<div class="msg-avatar">${escapeHtml(mine?myInitials:item.initials)}</div><div>${MedConnectMessages.buildBubbleHtml(msg, mine?'mine':'provider')}<div class="bubble-time" style="${mine?'text-align:right':''}">${escapeHtml(msg.time)}</div></div>`;
    threadBody.appendChild(row);
  });
  MedConnectMessages.bindMessageInteractions(threadBody, item.messages || [], {
    assetBase,
    onDeleted(result, eventType) {
      const item = conversations[activeIndex];
      if (!item) return;
      if (eventType === 'deleted_for_me') {
        item.messages = (item.messages || []).filter((msg) => Number(msg.id) !== Number(result.data.message_id));
      } else if (result.data?.message) {
        item.messages = (item.messages || []).map((msg) => Number(msg.id) === Number(result.data.message_id) ? result.data.message : msg);
      }
      renderThread(item);
      updateConversationPreview(activeIndex, item);
      setAlert(eventType === 'deleted_for_everyone' ? 'Message deleted for everyone.' : 'Message deleted for you.');
      setTimeout(clearAlert, 1600);
    },
    onError(message) { setAlert(message, 'error'); }
  });
  threadBody.scrollTop=threadBody.scrollHeight;
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

function setActiveConversation(i){
  activeIndex=i; const item=conversations[i]; if(!item)return;
  document.querySelectorAll('.msg-item').forEach(b=>b.classList.toggle('active',Number(b.dataset.index)===i));
  document.getElementById('activeInitials').textContent=item.initials;
  document.getElementById('activeName').textContent=item.name;
  document.getElementById('activeConsult').textContent=item.consult_type;
  const activePresence = document.getElementById('activePresence');
  if (activePresence) {
    activePresence.classList.toggle('is-online', String(item.status || '').toLowerCase() === 'in_consultation');
  }
  if (activeStatus) {
    activeStatus.textContent = item.status_label || String(item.status || '').replace('_', ' ');
    activeStatus.className = statusClass(item.status);
  }
  renderThread(item);
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
        clearConversationUnread(i);
        if (typeof j.unread_count !== 'undefined') setSidebarUnread(j.unread_count);
      }
    }).catch(() => {});
  }
  startMessageAutoRefresh();
  openThreadOnMobile();
}
async function refreshActiveMessages(silent=true){
  const item=conversations[activeIndex];
  if(!item || !Number(item.consultation_id))return;
  try{
    const res=await fetch(`<?= ASSET_BASE ?>/app/api/messages/list.php?consultation_id=${encodeURIComponent(item.consultation_id)}`,{cache:'no-store'});
    const data=await res.json();
    if(!data.success){if(!silent)setAlert(data.message||'Could not refresh messages.','error');return;}
    const oldCount=item.messages?item.messages.length:0;
    item.messages=data.messages||[];
    renderThread(item);
    updateConversationPreview(activeIndex, item);
    if (typeof data.unread_count !== 'undefined') {
      if (window.MedConnectUnreadService) {
        window.MedConnectUnreadService.setUnread(data.unread_count, 'messages-page');
      } else {
        window.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: data.unread_count, source: 'messages-page' } }));
      }
    }
    if(!silent && item.messages.length>oldCount){setAlert('New message loaded.');setTimeout(clearAlert,1200);}
  }catch(e){if(!silent)setAlert('Could not refresh messages.','error');}
}
function startMessageAutoRefresh(){
  clearInterval(refreshTimer);
  refreshTimer=setInterval(()=>refreshActiveMessages(true),5000);
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
        const next = MedConnectMessages.applyLocalDeletion(item.messages || [], event, currentUserId);
        if (next.length !== (item.messages || []).length || JSON.stringify(next) !== JSON.stringify(item.messages || [])) {
          item.messages = next;
          changed = true;
        }
      });
      if (changed) {
        renderThread(item);
        updateConversationPreview(activeIndex, item);
      }
    },
    { assetBase }
  );
  realtimePoller.start(2000);
}
async function sendMessage(){
  const item=conversations[activeIndex]; const message=messageInput.value.trim();
  if(!item || !Number(item.consultation_id)){setAlert('Select a consultation before sending.','error');return;}
  if(!message){setAlert('Type a message first.','error');return;}
  sendMessageBtn.disabled=true; sendMessageBtn.textContent='Sending...';
  try{
    const res=await fetch('<?= ASSET_BASE ?>/app/api/messages/send.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({consultation_id:item.consultation_id,message,csrf_token:csrfToken})});
    const data=await res.json();
    if(!data.success){setAlert(data.message||'Could not send message.','error');return;}
    item.messages=item.messages||[]; item.messages.push(data.data); messageInput.value=''; renderThread(item);
    updateConversationPreview(activeIndex, item);
    setAlert('Message sent.'); setTimeout(clearAlert,1600);
  }catch(e){setAlert('Could not reach the message service.','error');}
  finally{sendMessageBtn.disabled=false; sendMessageBtn.textContent='Send';}
}
document.querySelectorAll('.msg-item').forEach((button) => {
  button.addEventListener('click', (e) => {
    if (e && e.target && e.target.closest && e.target.closest('.msg-kebab')) return;
    setActiveConversation(Number(button.dataset.index));
  });
});

<?php $threadMenuVer = (int) @filemtime(ASSETS_PATH . '/js/messages-thread-menu.js'); ?>
const messageSearch = document.getElementById('messageSearch');
if (messageSearch) {
  messageSearch.addEventListener('input', (event) => {
    const query = event.target.value.trim().toLowerCase();
    document.querySelectorAll('.msg-item').forEach((button) => {
      button.style.display = (button.dataset.search || '').includes(query) ? 'grid' : 'none';
    });
  });
}
sendMessageBtn.addEventListener('click',sendMessage);
messageInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();sendMessage();}});
if (backBtn) backBtn.addEventListener('click', openListOnMobile);
window.addEventListener('resize', () => {
  // If user rotates / grows to tablet+ while thread is open, show both panels again
  if (!isMobileMessages()) {
    openListOnMobile();
  }
});
setActiveConversation(0);
conversations.forEach((conv, index) => updateConversationPreview(index, conv));
</script>
<script src="<?= ASSET_BASE ?>/assets/js/messages-thread-menu.js?v=<?= $threadMenuVer ?>" defer></script>
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>
