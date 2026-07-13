/**
 * MedConnect message deletion UI + realtime event polling.
 */
(function (window) {
  'use strict';

  const DELETED_TEXT = 'This message was deleted.';

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[char]));
  }

  function closeDialog() {
    document.querySelectorAll('.msg-delete-backdrop').forEach((node) => node.remove());
  }

  function confirmDialog(title, body) {
    if (window.McModal && typeof window.McModal.confirm === 'function') {
      return window.McModal.confirm({
        title: title,
        message: body,
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        showLogo: false,
        icon: 'confirm',
      });
    }

    return new Promise((resolve) => {
      closeDialog();
      const backdrop = document.createElement('div');
      backdrop.className = 'msg-delete-backdrop';
      backdrop.innerHTML = `
        <div class="msg-delete-dialog" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}">
          <h3>${escapeHtml(title)}</h3>
          <p>${escapeHtml(body)}</p>
          <div class="msg-delete-actions">
            <button type="button" data-choice="yes">Confirm</button>
            <button type="button" class="cancel" data-choice="no">Cancel</button>
          </div>
        </div>
      `;
      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
          closeDialog();
          resolve(false);
        }
      });
      backdrop.querySelector('[data-choice="yes"]').addEventListener('click', () => {
        closeDialog();
        resolve(true);
      });
      backdrop.querySelector('[data-choice="no"]').addEventListener('click', () => {
        closeDialog();
        resolve(false);
      });
      document.body.appendChild(backdrop);
    });
  }

  function openOptionsMenu(message, canDeleteForEveryone, canDeleteForMe) {
    if (window.McModal && typeof window.McModal.open === 'function') {
      return new Promise((resolve) => {
        const actions = [];
        if (canDeleteForMe) {
          actions.push({ label: 'Delete for Me', variant: 'secondary', value: 'me' });
        }
        if (canDeleteForEveryone) {
          actions.push({ label: 'Delete for Everyone', variant: 'danger', value: 'everyone' });
        }
        actions.push({ label: 'Cancel', variant: 'secondary', value: null, autoFocus: true });

        window.McModal.open({
          id: 'msg-options-modal',
          title: 'Message options',
          description: 'Choose how you want to remove this message from the conversation.',
          showLogo: false,
          size: 'sm',
          footerClass: 'mc-modal__footer--stack',
          actions: actions,
          resolve: resolve,
        });
      });
    }

    return new Promise((resolve) => {
      closeDialog();
      const backdrop = document.createElement('div');
      backdrop.className = 'msg-delete-backdrop';
      const actions = [];

      if (canDeleteForMe) {
        actions.push('<button type="button" data-action="me">Delete for Me</button>');
      }
      if (canDeleteForEveryone) {
        actions.push('<button type="button" class="danger" data-action="everyone">Delete for Everyone</button>');
      }
      actions.push('<button type="button" class="cancel" data-action="cancel">Cancel</button>');

      backdrop.innerHTML = `
        <div class="msg-delete-dialog" role="dialog" aria-modal="true" aria-label="Message options">
          <h3>Message options</h3>
          <p>Choose how you want to remove this message from the conversation.</p>
          <div class="msg-delete-actions">${actions.join('')}</div>
        </div>
      `;

      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
          closeDialog();
          resolve(null);
        }
      });
      backdrop.querySelectorAll('[data-action]').forEach((button) => {
        button.addEventListener('click', () => {
          const action = button.getAttribute('data-action');
          closeDialog();
          resolve(action === 'cancel' ? null : action);
        });
      });
      document.body.appendChild(backdrop);
    });
  }

  function buildBubbleHtml(message, bubbleClass) {
    const deleted = Boolean(message.is_deleted_for_everyone);
    const text = deleted ? DELETED_TEXT : (message.message || '');
    const deletedClass = deleted ? ' is-deleted' : '';
    return `<div class="bubble-wrap"><div class="bubble ${bubbleClass}${deletedClass}" data-message-id="${message.id}">${escapeHtml(text)}</div><button type="button" class="msg-options-btn" aria-label="Message options" data-message-id="${message.id}">⋯</button></div>`;
  }

  function buildChatBubbleHtml(message, bubbleClass) {
    const deleted = Boolean(message.is_deleted_for_everyone);
    const text = deleted ? DELETED_TEXT : (message.message || '');
    const deletedClass = deleted ? ' is-deleted' : '';
    return `<div class="bubble-wrap"><div class="chat-bubble ${bubbleClass}${deletedClass}" data-message-id="${message.id}">${escapeHtml(text)}</div><button type="button" class="msg-options-btn" aria-label="Message options" data-message-id="${message.id}">⋯</button></div>`;
  }

  async function deleteMessage(assetBase, messageId, mode) {
    const restEndpoint = mode === 'everyone'
      ? `${assetBase}/app/api/messages/${encodeURIComponent(messageId)}/delete-for-everyone`
      : `${assetBase}/app/api/messages/${encodeURIComponent(messageId)}/delete-for-me`;
    const postEndpoint = mode === 'everyone'
      ? `${assetBase}/app/api/messages/delete_for_everyone.php`
      : `${assetBase}/app/api/messages/delete_for_me.php`;
    const token = csrfToken();

    let response = await fetch(restEndpoint, {
      method: 'DELETE',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: token ? { 'X-CSRF-Token': token } : undefined,
    });

    if (!response.ok && response.status === 404) {
      response = await fetch(postEndpoint, {
        method: 'POST',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ message_id: String(messageId), csrf_token: token }),
      });
    }

    const data = await response.json();
    if (!data.success) {
      throw new Error(data.message || 'Could not delete message.');
    }
    return data;
  }

  function applyLocalDeletion(messages, event, currentUserId) {
    if (!Array.isArray(messages)) return messages;

    if (event.event_type === 'deleted_for_me') {
      const hiddenFor = Number(event.payload?.hidden_for_user_id || event.actor_user_id);
      if (hiddenFor === Number(currentUserId)) {
        return messages.filter((msg) => Number(msg.id) !== Number(event.message_id));
      }
      return messages;
    }

    if (event.event_type === 'deleted_for_everyone') {
      return messages.map((msg) => {
        if (Number(msg.id) !== Number(event.message_id)) return msg;
        const updated = event.message ? { ...event.message } : { ...msg };
        updated.is_deleted_for_everyone = true;
        updated.message = DELETED_TEXT;
        updated.can_delete_for_everyone = false;
        return updated;
      });
    }

    return messages;
  }

  function bindMessageInteractions(container, messages, options) {
    if (!container) return;

    const getMessageById = (id) => messages.find((msg) => Number(msg.id) === Number(id));

    const openForMessage = async (messageId) => {
      const message = getMessageById(messageId);
      if (!message) return;

      const canEveryone = Boolean(message.can_delete_for_everyone);
      const canMe = Boolean(message.can_delete_for_me);
      if (!canEveryone && !canMe) return;

      const choice = await openOptionsMenu(message, canEveryone, canMe);
      if (!choice) return;

      const confirmed = await confirmDialog(
        choice === 'everyone' ? 'Delete for everyone?' : 'Delete for you?',
        choice === 'everyone'
          ? 'This message will be removed for both participants. The original text cannot be recovered in chat.'
          : 'This message will be hidden from your chat only. The other participant will still see it.'
      );
      if (!confirmed) return;

      try {
        const result = await deleteMessage(options.assetBase, messageId, choice === 'everyone' ? 'everyone' : 'me');
        if (typeof options.onDeleted === 'function') {
          options.onDeleted(result, choice === 'everyone' ? 'deleted_for_everyone' : 'deleted_for_me', message);
        }
      } catch (error) {
        if (typeof options.onError === 'function') {
          options.onError(error.message || 'Could not delete message.');
        }
      }
    };

    container.querySelectorAll('.bubble[data-message-id], .chat-bubble[data-message-id]').forEach((node) => {
      if (node.dataset.deleteBound === '1') return;
      node.dataset.deleteBound = '1';

      const messageId = Number(node.getAttribute('data-message-id'));
      const wrap = node.closest('.bubble-wrap');
      let pressTimer = null;

      const clearPress = () => {
        if (pressTimer) {
          clearTimeout(pressTimer);
          pressTimer = null;
        }
      };

      node.addEventListener('click', (event) => {
        if (event.target.closest('.msg-options-btn')) return;
        event.preventDefault();
        if (wrap) wrap.classList.add('is-menu-open');
        openForMessage(messageId).finally(() => {
          if (wrap) wrap.classList.remove('is-menu-open');
        });
      });

      node.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        openForMessage(messageId);
      });

      node.addEventListener('touchstart', () => {
        clearPress();
        pressTimer = setTimeout(() => openForMessage(messageId), 550);
      }, { passive: true });
      node.addEventListener('touchend', clearPress);
      node.addEventListener('touchmove', clearPress);
    });

    container.querySelectorAll('.msg-options-btn').forEach((button) => {
      if (button.dataset.deleteBound === '1') return;
      button.dataset.deleteBound = '1';
      button.addEventListener('click', (event) => {
        event.stopPropagation();
        openForMessage(Number(button.getAttribute('data-message-id')));
      });
    });
  }

  function createRealtimePoller(getConsultationId, getLastEventId, setLastEventId, onEvents, options) {
    let timer = null;
    let inFlight = false;

    async function poll() {
      const consultationId = Number(getConsultationId());
      if (!consultationId || inFlight) return;
      inFlight = true;
      try {
        const sinceId = Number(getLastEventId() || 0);
        const url = `${options.assetBase}/app/api/messages/events.php?consultation_id=${encodeURIComponent(consultationId)}&since_id=${encodeURIComponent(sinceId)}&_=${Date.now()}`;
        const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
        const data = await response.json();
        if (!data.success) return;
        if (Number(data.last_event_id) > sinceId) {
          setLastEventId(Number(data.last_event_id));
        }
        if (Array.isArray(data.events) && data.events.length) {
          onEvents(data.events);
        }
      } catch (e) {
        // Quiet polling failures.
      } finally {
        inFlight = false;
      }
    }

    return {
      start(intervalMs) {
        clearInterval(timer);
        timer = setInterval(poll, intervalMs || 2000);
        poll();
      },
      stop() {
        clearInterval(timer);
        timer = null;
      },
    };
  }

  function csrfToken() {
    return document.body?.dataset?.csrf || '';
  }

  async function sendMessage(consultationId, message, options = {}) {
    const assetBase = options.assetBase || document.body?.dataset?.assetBase || '';
    const token = options.csrfToken || csrfToken();
    const response = await fetch(`${assetBase}/app/api/messages/send.php`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        consultation_id: String(consultationId),
        message,
        csrf_token: token,
      }),
    });

    let data;
    try {
      data = await response.json();
    } catch (e) {
      return { success: false, message: 'Unexpected server response.' };
    }

    return data;
  }

  window.MedConnectMessages = {
    DELETED_TEXT,
    escapeHtml,
    buildBubbleHtml,
    buildChatBubbleHtml,
    bindMessageInteractions,
    createRealtimePoller,
    applyLocalDeletion,
    deleteMessage,
    csrfToken,
    sendMessage,
  };
})(window);
