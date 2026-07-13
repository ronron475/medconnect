/**
 * medConnect — Unified Premium Modal System
 * McModal.open / confirm / alert / success / error / warning / loading
 */
(function (global) {
  'use strict';

  const DURATION = 360;
  const CLOSE_MS = 300;
  const instances = new Map();
  let scrollLockCount = 0;
  let loadingOverlay = null;
  let lastFocus = null;

  const ICONS = {
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path class="mc-modal__check-path" d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline class="mc-modal__check-path" points="22 4 12 14.01 9 11.01"/></svg>',
    error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
    warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
    logout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    confirm: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
  };

  function assetBase() {
    const root = document.getElementById('medconnectThemeRoot');
    const fromDom = (document.body && document.body.getAttribute('data-asset-base'))
      || (root && root.getAttribute('data-asset-base'))
      || '';
    return (fromDom || global.ASSET_BASE || global.APP_BASE || '').replace(/\/$/, '');
  }

  function logoUrl() {
    return assetBase() + '/assets/img/medcon_logo.png';
  }

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function lockScroll() {
    scrollLockCount += 1;
    document.body.classList.add('mc-modal-open');
  }

  function unlockScroll() {
    scrollLockCount = Math.max(0, scrollLockCount - 1);
    if (scrollLockCount === 0) {
      document.body.classList.remove('mc-modal-open');
    }
  }

  function getFocusable(container) {
    return Array.from(container.querySelectorAll(
      'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter(function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  function trapFocus(overlay, dialog) {
    function onKeyDown(e) {
      if (e.key !== 'Tab') return;
      const nodes = getFocusable(dialog);
      if (!nodes.length) {
        e.preventDefault();
        return;
      }
      const first = nodes[0];
      const last = nodes[nodes.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
    overlay.addEventListener('keydown', onKeyDown);
    return function () { overlay.removeEventListener('keydown', onKeyDown); };
  }

  function buildIcon(variant) {
    if (!variant || variant === 'default') return '';
    const icon = ICONS[variant] || '';
    const animated = variant === 'success' ? ' mc-modal__icon--check-animated' : '';
    return (
      '<div class="mc-modal__icon mc-modal__icon--' + esc(variant) + animated + '" aria-hidden="true">' +
        icon +
      '</div>'
    );
  }

  function buildActions(actions, footerClass) {
    if (!actions || !actions.length) return '';
    const html = actions.map(function (action) {
      const cls = 'mc-modal__btn mc-modal__btn--' + esc(action.variant || 'secondary');
      const attrs = [
        'type="button"',
        'class="' + cls + '"',
        action.id ? 'id="' + esc(action.id) + '"' : '',
        action.dataset ? Object.keys(action.dataset).map(function (k) {
          return 'data-' + k + '="' + esc(action.dataset[k]) + '"';
        }).join(' ') : '',
        action.autoFocus ? 'data-mc-autofocus="1"' : '',
      ].filter(Boolean).join(' ');
      return '<button ' + attrs + '>' + esc(action.label || 'OK') + '</button>';
    }).join('');
    return '<div class="mc-modal__footer ' + esc(footerClass || 'mc-modal__footer--split') + '">' + html + '</div>';
  }

  function renderModalContent(opts) {
    const showLogo = opts.showLogo !== false && !opts.icon;
    const showClose = opts.closable !== false;
    const headerParts = [];

    if (showLogo) {
      headerParts.push('<img class="mc-modal__logo" src="' + esc(logoUrl()) + '" alt="medConnect" width="52" height="52" decoding="async"/>');
    }
    if (opts.icon || opts.variant) {
      headerParts.push(buildIcon(opts.icon || opts.variant));
    }
    if (opts.title) {
      headerParts.push('<h2 class="mc-modal__title" id="' + esc(opts.titleId || 'mc-modal-title') + '">' + esc(opts.title) + '</h2>');
    }
    if (opts.description) {
      headerParts.push('<p class="mc-modal__desc">' + esc(opts.description) + '</p>');
    }

    const bodyHtml = opts.html != null ? opts.html : (opts.body ? '<p>' + esc(opts.body) + '</p>' : '');
    const stepsHtml = opts.steps ? (
      '<div class="mc-modal__steps" aria-hidden="true">' +
        opts.steps.map(function (step, i) {
          const cls = step.done ? 'is-done' : (step.active ? 'is-active' : '');
          return '<div class="mc-modal__step ' + cls + '"></div>';
        }).join('') +
      '</div>'
    ) : '';

    return (
      (showClose ? '<button type="button" class="mc-modal__close" data-mc-modal-close aria-label="Close dialog">' + ICONS.close + '</button>' : '') +
      '<div class="mc-modal__header">' + headerParts.join('') + '</div>' +
      stepsHtml +
      (bodyHtml ? '<div class="mc-modal__body">' + bodyHtml + '</div>' : '') +
      buildActions(opts.actions, opts.footerClass)
    );
  }

  function bindActions(overlay, dialog, opts, inst) {
    const releaseFocus = trapFocus(overlay, dialog);
    const isStatic = Boolean(inst.static);

    function handleClose(result) {
      if (inst.closing) return;
      inst.closing = true;
      inst.result = result;
      overlay.classList.remove('is-open');
      overlay.classList.add('is-closing');
      overlay.setAttribute('aria-hidden', 'true');

      window.setTimeout(function () {
        overlay.hidden = true;
        overlay.setAttribute('hidden', '');
        overlay.classList.remove('is-closing', 'is-open');
        if (!isStatic) {
          overlay.innerHTML = '';
          if (overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
          }
          instances.delete(inst.id);
          if (inst.releaseFocus) inst.releaseFocus();
          document.removeEventListener('keydown', inst.onKey);
        } else {
          inst.closing = false;
        }
        unlockScroll();
        if (lastFocus && typeof lastFocus.focus === 'function') {
          try { lastFocus.focus(); } catch (_) {}
        }
        if (typeof opts.onClose === 'function') opts.onClose(result);
        if (inst.resolve) inst.resolve(result);
      }, CLOSE_MS);
    }

    inst.close = handleClose;
    inst.releaseFocus = releaseFocus;

    inst.onKey = function (e) {
      if (e.key === 'Escape' && opts.closable !== false) {
        e.preventDefault();
        handleClose(false);
      }
    };
    document.addEventListener('keydown', inst.onKey);

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay && opts.backdropClose !== false) {
        handleClose(false);
      }
    });

    dialog.querySelectorAll('[data-mc-modal-close]').forEach(function (btn) {
      btn.addEventListener('click', function () { handleClose(false); });
    });

    (opts.actions || []).forEach(function (action, index) {
      const selector = action.id ? ('#' + action.id) : ('.mc-modal__footer .mc-modal__btn:nth-child(' + (index + 1) + ')');
      const btn = dialog.querySelector(selector);
      if (!btn) return;
      btn.addEventListener('click', function () {
        if (typeof action.onClick === 'function') {
          const ret = action.onClick();
          if (ret === false) return;
        }
        handleClose(action.value !== undefined ? action.value : true);
      });
    });

    const autofocus = dialog.querySelector('[data-mc-autofocus]') || dialog.querySelector('.mc-modal__btn') || dialog.querySelector('.mc-modal__close');
    if (autofocus) window.setTimeout(function () { autofocus.focus(); }, 30);

    return handleClose;
  }

  function open(opts) {
    const options = Object.assign({
      id: 'mc-modal-' + Date.now(),
      size: 'md',
      closable: true,
      showLogo: true,
      backdropClose: true,
    }, opts || {});

    if (options.id && instances.has(options.id)) {
      close(options.id);
    }

    lastFocus = document.activeElement;
    const overlay = document.createElement('div');
    overlay.className = 'mc-modal-overlay';
    overlay.id = options.id + '-overlay';
    overlay.setAttribute('role', 'presentation');
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');

    const dialog = document.createElement('div');
    dialog.className = 'mc-modal mc-modal--' + esc(options.size);
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    if (options.title) {
      dialog.setAttribute('aria-labelledby', options.titleId || 'mc-modal-title');
    }
    if (options.variant === 'error') dialog.classList.add('mc-modal--shake');

    dialog.innerHTML = renderModalContent(options);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);

    const inst = { id: options.id, closing: false, resolve: options.resolve || null };
    instances.set(options.id, inst);
    bindActions(overlay, dialog, options, inst);

    overlay.hidden = false;
    lockScroll();
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
      });
    });

    return {
      id: options.id,
      close: function (result) { inst.close(result); },
      el: dialog,
      overlay: overlay,
    };
  }

  function close(id) {
    const inst = instances.get(id);
    if (inst && inst.close) inst.close(false);
  }

  function closeAll() {
    Array.from(instances.keys()).forEach(close);
  }

  function confirm(opts) {
    const options = Object.assign({
      title: 'Confirm Action',
      message: 'Do you want to continue?',
      confirmLabel: 'Confirm',
      cancelLabel: 'Cancel',
      variant: 'confirm',
      danger: false,
      showLogo: false,
      icon: 'confirm',
    }, opts || {});

    return new Promise(function (resolve) {
      open({
        id: options.id || 'mc-confirm',
        title: options.title,
        description: options.message || options.description,
        body: options.body,
        variant: options.danger ? 'warning' : (options.variant || 'confirm'),
        icon: options.icon || (options.danger ? 'warning' : 'confirm'),
        showLogo: options.showLogo,
        size: options.size || 'sm',
        closable: options.closable !== false,
        resolve: resolve,
        footerClass: 'mc-modal__footer--split',
        actions: [
          { label: options.cancelLabel, variant: 'secondary', value: false, autoFocus: true },
          {
            label: options.confirmLabel,
            variant: options.danger ? 'danger' : 'primary',
            value: true,
          },
        ],
      });
    });
  }

  function alertModal(opts) {
    const options = Object.assign({
      title: 'Notice',
      message: '',
      buttonLabel: 'OK',
      variant: 'default',
    }, opts || {});

    return new Promise(function (resolve) {
      open({
        id: options.id || 'mc-alert',
        title: options.title,
        description: options.message,
        html: options.html,
        variant: options.variant,
        icon: options.icon || options.variant,
        showLogo: false,
        size: 'sm',
        resolve: resolve,
        footerClass: options.footerClass || 'mc-modal__footer--stack',
        actions: [{ label: options.buttonLabel, variant: 'primary', value: true, autoFocus: true }],
      });
    });
  }

  function success(opts) {
    return alertModal(Object.assign({ title: 'Success', variant: 'success', icon: 'success' }, opts));
  }

  function error(opts) {
    return alertModal(Object.assign({ title: 'Error', variant: 'error', icon: 'error', buttonLabel: 'Try Again' }, opts));
  }

  function warning(opts) {
    return confirm(Object.assign({ title: 'Warning', variant: 'warning', icon: 'warning', danger: true }, opts));
  }

  function showLoading(opts) {
    const options = Object.assign({
      brand: 'medConnect',
      status: 'Connecting...',
      substatus: 'Securing your session...',
      hint: 'Please wait...',
    }, opts || {});

    if (global.MedConnectGlobalLoader && typeof global.MedConnectGlobalLoader.showModal === 'function') {
      global.MedConnectGlobalLoader.showModal(options);
      return;
    }

    if (loadingOverlay) hideLoading();

    loadingOverlay = document.createElement('div');
    loadingOverlay.className = 'mc-modal-overlay mc-modal-loader-overlay is-open';
    loadingOverlay.setAttribute('role', 'status');
    loadingOverlay.setAttribute('aria-live', 'polite');
    loadingOverlay.setAttribute('aria-busy', 'true');
    loadingOverlay.innerHTML =
      '<div class="mc-modal mc-modal-loader mc-modal--sm" role="presentation">' +
        '<div class="mc-modal-loader__stage" aria-hidden="true">' +
          '<div class="mc-modal-loader__glow"></div>' +
          '<div class="mc-modal-loader__ring"></div>' +
          '<div class="mc-modal-loader__logo-wrap">' +
            '<img class="mc-modal-loader__logo" src="' + esc(logoUrl()) + '" alt="" width="56" height="56" decoding="async"/>' +
          '</div>' +
        '</div>' +
        '<p class="mc-modal-loader__brand">' + esc(options.brand) + '</p>' +
        '<p class="mc-modal-loader__status">' + esc(options.status) + '</p>' +
        '<p class="mc-modal-loader__hint">' + esc(options.substatus) + '</p>' +
        '<p class="mc-modal-loader__hint">' + esc(options.hint) + '</p>' +
      '</div>';
    document.body.appendChild(loadingOverlay);
    lockScroll();
  }

  function hideLoading() {
    if (global.MedConnectGlobalLoader && typeof global.MedConnectGlobalLoader.hideModal === 'function') {
      global.MedConnectGlobalLoader.hideModal();
    }
    if (!loadingOverlay) return;
    loadingOverlay.classList.remove('is-open');
    loadingOverlay.classList.add('is-closing');
    const node = loadingOverlay;
    loadingOverlay = null;
    window.setTimeout(function () {
      if (node.parentNode) node.parentNode.removeChild(node);
      unlockScroll();
    }, CLOSE_MS);
  }

  function bindStaticModal(overlayId, options) {
    const overlay = document.getElementById(overlayId);
    if (!overlay) return null;

    const dialog = overlay.querySelector('.mc-modal');
    if (!dialog) return null;

    const inst = { id: overlayId, closing: false, static: true };
    instances.set(overlayId, inst);

    function show() {
      lastFocus = document.activeElement;
      overlay.hidden = false;
      overlay.removeAttribute('hidden');
      lockScroll();
      requestAnimationFrame(function () {
        requestAnimationFrame(function () {
          overlay.classList.add('is-open');
          overlay.setAttribute('aria-hidden', 'false');
        });
      });
    }

    bindActions(overlay, dialog, Object.assign({ closable: true, backdropClose: true }, options || {}), inst);

    return {
      show: show,
      hide: function (result) { inst.close(result); },
      overlay: overlay,
      dialog: dialog,
    };
  }

  function initLogoutModal() {
    const bound = bindStaticModal('logout-modal-overlay', {});
    if (!bound) return;

    global.showLogoutModal = bound.show;
    global.hideLogoutModal = function () { bound.hide(false); };
  }

  function init() {
    initLogoutModal();
    if (global.MedConnectLogout && typeof global.MedConnectLogout.init === 'function') {
      global.MedConnectLogout.init();
    }
  }

  const McModal = {
    open: open,
    close: close,
    closeAll: closeAll,
    confirm: confirm,
    alert: alertModal,
    success: success,
    error: error,
    warning: warning,
    loading: showLoading,
    hideLoading: hideLoading,
    bind: bindStaticModal,
    logoUrl: logoUrl,
    assetBase: assetBase,
  };

  global.McModal = McModal;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
