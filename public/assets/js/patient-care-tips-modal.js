/**
 * My Health — open doctor-approved care tips in a modal (one triage case per view).
 */
(function (window, document) {
  'use strict';

  var modal = null;
  var titleEl = null;
  var dateEl = null;
  var statusEl = null;
  var providerEl = null;
  var listEl = null;
  var contentEl = null;
  var tipsById = {};
  var lastFocus = null;

  function cacheEls() {
    if (modal) {
      return true;
    }
    modal = document.getElementById('pmhCareTipsModal');
    if (!modal) {
      return false;
    }
    titleEl = document.getElementById('pmhCareTipsModalTitle');
    dateEl = document.getElementById('pmhCareTipsModalDate');
    statusEl = document.getElementById('pmhCareTipsModalStatus');
    providerEl = document.getElementById('pmhCareTipsModalProvider');
    listEl = document.getElementById('pmhCareTipsModalList');
    contentEl = modal.querySelector('.pmh-care-modal__content');
    return true;
  }

  function loadTipsMap() {
    var node = document.getElementById('pmhCareTipsData');
    if (!node || !node.textContent) {
      return;
    }
    try {
      var parsed = JSON.parse(node.textContent);
      tipsById = parsed && typeof parsed === 'object' ? parsed : {};
    } catch (_) {
      tipsById = {};
    }
  }

  function setStatusClass(className) {
    if (!statusEl) {
      return;
    }
    statusEl.className = 'pmh-care-modal__status pmh-care-card__status ' + (className || 'pmh-care-card__status--default');
  }

  function openModal(triageId) {
    if (!cacheEls()) {
      return;
    }
    var entry = tipsById[String(triageId)] || tipsById[triageId];
    if (!entry) {
      return;
    }

    lastFocus = document.activeElement;

    if (titleEl) {
      titleEl.textContent = entry.complaint || 'Health concern';
    }
    if (dateEl) {
      dateEl.textContent = entry.datetimeLabel || '—';
      if (entry.datetimeIso) {
        dateEl.setAttribute('datetime', entry.datetimeIso);
      } else {
        dateEl.removeAttribute('datetime');
      }
    }
    if (statusEl) {
      statusEl.textContent = entry.statusLabel || 'Recorded';
      setStatusClass(entry.statusClass || '');
    }
    if (providerEl) {
      if (entry.providerName) {
        providerEl.hidden = false;
        providerEl.textContent = 'Reviewed by ' + entry.providerName;
      } else {
        providerEl.hidden = true;
        providerEl.textContent = '';
      }
    }
    if (listEl) {
      listEl.innerHTML = '';
      (entry.tips || []).forEach(function (tip) {
        var li = document.createElement('li');
        li.className = 'pmh-care-modal__item';
        li.textContent = tip;
        listEl.appendChild(li);
      });
    }

    modal.hidden = false;
    document.body.classList.add('pmh-care-modal-open');
    if (contentEl) {
      contentEl.scrollTop = 0;
    }
    var closeBtn = modal.querySelector('.pmh-care-modal__close');
    if (closeBtn) {
      closeBtn.focus();
    }
  }

  function closeModal() {
    if (!modal || modal.hidden) {
      return;
    }
    modal.hidden = true;
    document.body.classList.remove('pmh-care-modal-open');
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
    lastFocus = null;
  }

  function onDocumentClick(event) {
    var trigger = event.target.closest('[data-pmh-care-tips-open]');
    if (trigger) {
      event.preventDefault();
      var triageId = trigger.getAttribute('data-triage-id');
      if (triageId) {
        openModal(triageId);
      }
      return;
    }
    if (event.target.closest('[data-pmh-care-tips-close]')) {
      event.preventDefault();
      closeModal();
    }
  }

  function onDocumentKeydown(event) {
    if (event.key === 'Escape' && modal && !modal.hidden) {
      event.preventDefault();
      closeModal();
    }
  }

  function init() {
    loadTipsMap();
    if (!cacheEls()) {
      return;
    }
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onDocumentKeydown);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.MedConnectCareTipsModal = {
    open: openModal,
    close: closeModal,
  };
})(window, document);
