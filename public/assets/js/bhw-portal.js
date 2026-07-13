(function () {
  var base = document.body.dataset.assetBase || '';
  var api = base + '/app/api/bhw';

  var overlay = null;
  var dialog = null;
  var iconWrap = null;
  var iconSuccess = null;
  var iconError = null;
  var iconWarn = null;
  var titleEl = null;
  var messageEl = null;
  var actionsEl = null;
  var onCloseCallback = null;

  function ensureDom() {
    if (overlay) return;
    overlay = document.getElementById('bhw-feedback-overlay');
    if (!overlay) return;
    dialog = document.getElementById('bhw-feedback-dialog');
    iconWrap = document.getElementById('bhw-feedback-icon');
    iconSuccess = document.getElementById('bhw-feedback-icon-success');
    iconError = document.getElementById('bhw-feedback-icon-error');
    iconWarn = document.getElementById('bhw-feedback-icon-warn');
    titleEl = document.getElementById('bhw-feedback-title');
    messageEl = document.getElementById('bhw-feedback-message');
    actionsEl = document.getElementById('bhw-feedback-actions');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay && overlay.dataset.dismissible === 'true') {
        hideFeedback();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('is-visible') && overlay.dataset.dismissible !== 'false') {
        hideFeedback();
      }
    });
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function patientDisplayName(p) {
    return ((p.last_name || '') + ', ' + (p.first_name || '')).replace(/^,\s*|,\s*$/g, '').trim();
  }

  function patientMetaText(p) {
    var parts = [];
    if (p.email) parts.push(String(p.email).trim());
    if (p.contact_number) parts.push(String(p.contact_number).trim());
    return parts.join(' · ');
  }

  function patientMetaHtml(p) {
    var parts = [];
    if (p.email) {
      parts.push('<span class="bhw-picker-meta-item">' + escapeHtml(p.email) + '</span>');
    }
    if (p.contact_number) {
      parts.push('<span class="bhw-picker-meta-item">' + escapeHtml(p.contact_number) + '</span>');
    }
    return parts.join('<span class="bhw-picker-meta-sep" aria-hidden="true">·</span>');
  }

  function makeButton(label, className, handler) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'bhw-feedback-btn ' + className;
    btn.textContent = label;
    btn.addEventListener('click', handler);
    return btn;
  }

  function makeLink(label, className, href) {
    var a = document.createElement('a');
    a.className = 'bhw-feedback-btn ' + className;
    a.textContent = label;
    a.href = href;
    return a;
  }

  function feedbackKind(opts) {
    var type = opts.type || 'success';
    if (type === 'error') return 'error';
    if (type === 'warn' || type === 'warning' || type === 'confirm') return 'warn';
    return 'success';
  }

  function applyFeedbackVariant(kind) {
    if (!dialog || !iconWrap) return;
    dialog.classList.remove('bhw-feedback-dialog--success', 'bhw-feedback-dialog--error', 'bhw-feedback-dialog--warn', 'bhw-feedback-dialog--rich');
    dialog.classList.add('bhw-feedback-dialog--' + kind);
    iconWrap.className = 'bhw-feedback-icon bhw-feedback-icon--' + kind;
    if (iconSuccess) iconSuccess.style.display = kind === 'success' ? '' : 'none';
    if (iconError) iconError.style.display = kind === 'error' ? '' : 'none';
    if (iconWarn) iconWarn.style.display = kind === 'warn' ? '' : 'none';
  }

  function defaultFeedbackTitle(kind) {
    if (kind === 'error') return 'Something went wrong';
    if (kind === 'warn') return 'Please confirm';
    return 'Success';
  }

  function actionButtonClass(action, kind) {
    if (!action.primary) return 'bhw-feedback-btn--secondary';
    if (action.danger) return 'bhw-feedback-btn--danger';
    if (kind === 'error') return 'bhw-feedback-btn--danger';
    return 'bhw-feedback-btn--primary';
  }

  function renderFeedbackActions(opts, kind) {
    actionsEl.innerHTML = '';
    actionsEl.className = 'bhw-feedback-actions';

    if (opts.actions && opts.actions.length) {
      if (opts.actions.length > 1) actionsEl.classList.add('bhw-feedback-actions--dual');
      opts.actions.forEach(function (action) {
        var cls = actionButtonClass(action, kind);
        if (action.href) {
          actionsEl.appendChild(makeLink(action.label || 'Continue', cls, action.href));
          return;
        }
        actionsEl.appendChild(makeButton(action.label || 'OK', cls, function () {
          if (typeof action.onClick === 'function') action.onClick();
          hideFeedback();
        }));
      });
      return;
    }

    var primary = opts.primary;
    var secondary = opts.secondary;

    if (secondary) {
      actionsEl.classList.add('bhw-feedback-actions--dual');
      actionsEl.appendChild(makeButton(secondary.label || 'Close', 'bhw-feedback-btn--secondary', function () {
        if (typeof secondary.action === 'function') secondary.action();
        hideFeedback();
      }));
    }

    if (primary) {
      if (secondary) actionsEl.classList.add('bhw-feedback-actions--dual');
      if (primary.href) {
        actionsEl.appendChild(makeLink(primary.label || 'Continue', 'bhw-feedback-btn--primary', primary.href));
      } else {
        actionsEl.appendChild(makeButton(primary.label || 'OK', actionButtonClass({ primary: true, danger: primary.danger }, kind), function () {
          if (typeof primary.action === 'function') primary.action();
          hideFeedback();
        }));
      }
    }

    if (!primary && !secondary) {
      actionsEl.appendChild(makeButton('OK', kind === 'error' ? 'bhw-feedback-btn--danger' : 'bhw-feedback-btn--primary', hideFeedback));
    }
  }

  function showFeedback(options) {
    ensureDom();
    var opts = options || {};
    var kind = feedbackKind(opts);
    var isSuccess = kind === 'success';

    if (!opts.actions && (opts.primary || opts.secondary)) {
      opts.actions = [];
      if (opts.secondary) {
        opts.actions.push({
          label: opts.secondary.label || 'Close',
          primary: false,
          onClick: opts.secondary.action
        });
      }
      if (opts.primary) {
        opts.actions.push({
          label: opts.primary.label || 'Continue',
          primary: true,
          href: opts.primary.href,
          onClick: opts.primary.action
        });
      }
    }

    if (window.McModal) {
      var modalFn = isSuccess ? window.McModal.success : window.McModal.error;
      if (typeof modalFn === 'function') {
        var actions = [];
        (opts.actions || []).forEach(function (action) {
          actions.push({
            label: action.label,
            variant: action.primary ? 'primary' : 'secondary',
            onClick: function () {
              if (action.href) {
                window.location.href = action.href;
                return true;
              }
              if (typeof action.onClick === 'function') action.onClick();
              if (action.close !== false) hideFeedback();
              return false;
            },
          });
        });
        if (!actions.length) {
          actions.push({
            label: opts.buttonLabel || 'OK',
            variant: 'primary',
            onClick: function () {
              if (onCloseCallback) onCloseCallback();
              return true;
            },
          });
        }
        if (actions.length > 1 && typeof window.McModal.open === 'function') {
          window.McModal.open({
            title: opts.title || defaultFeedbackTitle(kind),
            description: opts.html ? undefined : (opts.message || ''),
            html: opts.html ? opts.message : undefined,
            variant: kind === 'warn' ? 'warning' : (isSuccess ? 'success' : 'error'),
            icon: kind === 'warn' ? 'warning' : (isSuccess ? 'success' : 'error'),
            showLogo: false,
            size: opts.html ? 'md' : 'sm',
            footerClass: 'mc-modal__footer--split',
            actions: actions,
            backdropClose: opts.dismissible !== false,
            closable: opts.dismissible !== false,
            onClose: function () {
              releaseUiBlockers();
              if (onCloseCallback) onCloseCallback();
            },
          });
          return;
        }

        modalFn.call(window.McModal, {
          title: opts.title || (isSuccess ? 'Success' : 'Something went wrong'),
          message: opts.html ? undefined : (opts.message || ''),
          html: opts.html ? opts.message : undefined,
          buttonLabel: opts.buttonLabel || 'OK',
        }).then(function () {
          releaseUiBlockers();
          if (onCloseCallback) onCloseCallback();
        });
        return;
      }
    }

    if (!overlay) {
      if (typeof alert !== 'undefined') alert(opts.message || opts.title || 'Done');
      return;
    }
    onCloseCallback = typeof opts.onClose === 'function' ? opts.onClose : null;

    if (dialog) {
      applyFeedbackVariant(kind);
      dialog.classList.toggle('bhw-feedback-dialog--rich', !!opts.html);
    }

    titleEl.textContent = opts.title || defaultFeedbackTitle(kind);
    if (opts.html) {
      messageEl.innerHTML = opts.message || '';
    } else {
      messageEl.textContent = opts.message || '';
    }

    renderFeedbackActions(opts, kind);

    overlay.dataset.dismissible = opts.dismissible === false ? 'false' : 'true';
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('bhw-feedback-open');

    var focusBtn = actionsEl.querySelector('button, a');
    if (focusBtn) focusBtn.focus();
  }

  function releaseUiBlockers() {
    var L = window.MedConnectGlobalLoader || window.MedConnectLoader;
    if (L && typeof L.forceHide === 'function') {
      L.forceHide();
    }
    document.body.classList.remove(
      'mc-global-loader-active',
      'mc-loader-active',
      'mc-login-loading-active',
      'mc-global-loader--boot-active',
      'mc-global-loader--modal-active',
      'bhw-feedback-open'
    );
    ['mc-loader-boot', 'mc-global-loader'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.classList.remove(
        'mc-global-loader--visible',
        'mc-loader--visible',
        'mc-global-loader--modal',
        'mc-global-loader--exit',
        'mc-loader--exit'
      );
      el.setAttribute('hidden', '');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('aria-busy', 'false');
    });
    if (window.McModal && typeof window.McModal.hideLoading === 'function') {
      window.McModal.hideLoading();
    }
  }

  function hideFeedback() {
    if (!overlay) {
      releaseUiBlockers();
      return;
    }
    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('bhw-feedback-open');
    releaseUiBlockers();
    if (onCloseCallback) {
      var cb = onCloseCallback;
      onCloseCallback = null;
      cb();
    }
  }

  function confirm(options) {
    var opts = options || {};
    if (window.McModal && typeof window.McModal.confirm === 'function') {
      return window.McModal.confirm({
        title: opts.title || 'Please confirm',
        message: opts.message || 'Are you sure you want to continue?',
        confirmLabel: opts.confirmLabel || 'Confirm',
        cancelLabel: opts.cancelLabel || 'Cancel',
        danger: !!opts.danger,
        backdropClose: opts.dismissible === true,
        closable: opts.dismissible === true,
      });
    }

    return new Promise(function (resolve) {
      var settled = false;
      function finish(value) {
        if (settled) return;
        settled = true;
        resolve(value);
      }

      showFeedback({
        type: 'warn',
        title: opts.title || 'Please confirm',
        message: opts.message || 'Are you sure you want to continue?',
        dismissible: opts.dismissible === true,
        actions: [
          {
            label: opts.cancelLabel || 'Cancel',
            onClick: function () { finish(false); }
          },
          {
            label: opts.confirmLabel || 'Confirm',
            primary: true,
            danger: !!opts.danger,
            onClick: function () { finish(true); }
          }
        ],
        onClose: function () { finish(false); }
      });
    });
  }

  function mcLoader() {
    return window.MedConnectGlobalLoader || window.MedConnectLoader || null;
  }

  function inlineLoadingHtml(message, options) {
    var L = mcLoader();
    if (L && typeof L.inlineHtml === 'function') {
      return L.inlineHtml(message, options);
    }
    return '<div class="mc-inline-loading" role="status"><span>' + (message || 'Loading…') + '</span></div>';
  }

  function inlineLoadingRow(colspan, message, cellClass) {
    var L = mcLoader();
    if (L && typeof L.inlineRow === 'function') {
      return L.inlineRow(colspan, message, cellClass);
    }
    return '<tr><td colspan="' + colspan + '">' + inlineLoadingHtml(message) + '</td></tr>';
  }

  function parseJsonResponse(r) {
    return r.text().then(function (text) {
      if (!text) {
        return { success: false, message: 'Empty server response. Please refresh and try again.' };
      }
      try {
        return JSON.parse(text);
      } catch (e) {
        return {
          success: false,
          message: 'Unexpected server response (HTTP ' + r.status + '). Please refresh and try again.',
        };
      }
    });
  }

  window.BhwPortal = {
    loader: {
      showFormal: function (options) {
        var L = mcLoader();
        if (L && typeof L.showFormal === 'function') return L.showFormal(options || {});
        if (L) L.show(Object.assign({ modal: true }, options || {}));
      },
      hideFormal: function () {
        var L = mcLoader();
        if (L && typeof L.hideFormal === 'function') return L.hideFormal();
        if (L) L.hide();
      },
      show: function (options) {
        var L = mcLoader();
        if (L && typeof L.showFormal === 'function') return L.showFormal(options || {});
        if (L) L.show(options || {});
      },
      hide: function () {
        var L = mcLoader();
        if (L && typeof L.hideFormal === 'function') return L.hideFormal();
        if (L) L.hide();
      },
      inlineHtml: inlineLoadingHtml,
      inlineRow: inlineLoadingRow
    },
    get: function (path, params) {
      var q = new URLSearchParams(params || {}).toString();
      return fetch(api + '/' + path + (q ? '?' + q : ''), {
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      }).then(parseJsonResponse);
    },
    post: function (path, data) {
      var fd = data instanceof FormData ? data : new FormData();
      if (!(data instanceof FormData)) {
        Object.keys(data || {}).forEach(function (k) {
          if (Array.isArray(data[k])) {
            data[k].forEach(function (v) { fd.append(k + '[]', v); });
          } else if (data[k] !== undefined && data[k] !== null) {
            fd.append(k, data[k]);
          }
        });
      }
      fd.append('csrf_token', document.body.dataset.csrf || '');
      return fetch(api + '/' + path, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-MC-No-Loader': '1' },
      }).then(parseJsonResponse);
    },
    loadPatients: function (selectEl, q) {
      return this.get('patients.php', { action: 'list', q: q || '' }).then(function (res) {
        if (!selectEl || !res.success) return res;
        selectEl.innerHTML = '<option value="">Select patient…</option>';
        (res.patients || []).forEach(function (p) {
          var o = document.createElement('option');
          o.value = p.id;
          o.textContent = p.last_name + ', ' + p.first_name + ' (' + p.email + ')';
          selectEl.appendChild(o);
        });
        return res;
      });
    },
    mountPatientPicker: function (container, options) {
      if (!container) return null;
      var opts = options || {};
      var debounceTimer = null;
      var selectedId = opts.preselect ? parseInt(opts.preselect, 10) : 0;
      var patients = [];
      var highlightIdx = -1;

      container.innerHTML =
        '<label class="form-label" for="bhwPickerSearch">' + (opts.label || 'Search patient') + '</label>' +
        '<div class="bhw-patient-picker">' +
        '  <input type="search" id="bhwPickerSearch" class="bhw-patient-picker-search" autocomplete="off"' +
        '    placeholder="' + (opts.placeholder || 'Search by name, email, or contact…') + '" aria-autocomplete="list" aria-controls="bhwPickerList">' +
        '  <div id="bhwPickerList" class="bhw-patient-picker-list" role="listbox"></div>' +
        '</div>' +
        '<div id="bhwPickerSelected" class="bhw-selected-patient" style="display:none;">' +
        '  <div class="bhw-selected-patient-avatar" id="bhwPickerAvatar" aria-hidden="true"></div>' +
        '<div class="bhw-selected-patient-info"><strong id="bhwPickerSelectedName"></strong><div class="bhw-selected-patient-meta-row" id="bhwPickerSelectedMeta"></div></div>' +
        '  <button type="button" class="bhw-selected-patient-clear" id="bhwPickerClear">Change Patient</button>' +
        '</div>';

      var searchEl = container.querySelector('#bhwPickerSearch');
      var listEl = container.querySelector('#bhwPickerList');
      var selectedBar = container.querySelector('#bhwPickerSelected');
      var selectedName = container.querySelector('#bhwPickerSelectedName');
      var selectedMeta = container.querySelector('#bhwPickerSelectedMeta');
      var selectedAvatar = container.querySelector('#bhwPickerAvatar');
      var clearBtn = container.querySelector('#bhwPickerClear');

      if (opts.hideSelectedBar && selectedBar) {
        selectedBar.style.display = 'none';
        selectedBar.classList.add('bhw-selected-patient--hidden');
      }

      function patientInitials(p) {
        return ((p.first_name || '?').charAt(0) + (p.last_name || '').charAt(0)).toUpperCase();
      }

      function openList() { listEl.classList.add('is-open'); }
      function closeList() { listEl.classList.remove('is-open'); highlightIdx = -1; }

      function renderList(rows) {
        patients = rows || [];
        if (!patients.length) {
          listEl.innerHTML = '<div class="bhw-patient-picker-empty">No patients found in your barangay.</div>';
          openList();
          return;
        }
        listEl.innerHTML = patients.map(function (p, i) {
          var name = escapeHtml(patientDisplayName(p));
          var meta = escapeHtml(patientMetaText(p));
          var sel = selectedId === parseInt(p.id, 10) ? ' is-selected' : '';
          return '<button type="button" class="bhw-patient-picker-item' + sel + '" data-idx="' + i + '" role="option">' +
            '<span class="bhw-patient-picker-item-avatar" aria-hidden="true">' + escapeHtml(patientInitials(p)) + '</span>' +
            '<span class="bhw-patient-picker-item-body">' +
            '<span class="bhw-patient-picker-name">' + name + '</span>' +
            '<span class="bhw-patient-picker-meta">' + meta + '</span></span></button>';
        }).join('');
        if (!selectedId && opts.openOnLoad) openList();
      }

      function selectPatient(p) {
        if (!p) return;
        selectedId = parseInt(p.id, 10);
        searchEl.value = patientDisplayName(p);
        selectedName.textContent = patientDisplayName(p);
        if (selectedMeta) {
          selectedMeta.innerHTML = patientMetaHtml(p);
        }
        if (selectedAvatar) selectedAvatar.textContent = patientInitials(p);
        if (!opts.hideSelectedBar) selectedBar.style.display = 'flex';
        closeList();
        if (typeof opts.onSelect === 'function') opts.onSelect(selectedId, p);
      }

      function clearSelection() {
        selectedId = 0;
        searchEl.value = '';
        if (!opts.hideSelectedBar) selectedBar.style.display = 'none';
        searchEl.focus();
        fetchPatients('');
        if (typeof opts.onClear === 'function') opts.onClear();
      }

      function fetchPatients(q) {
        listEl.innerHTML = inlineLoadingHtml('Loading patients…', { className: 'bhw-patient-picker-loading' });
        openList();
        return BhwPortal.get('patients.php', { action: 'list', q: q || '' }).then(function (res) {
          if (res.success) renderList(res.patients || []);
          else listEl.innerHTML = '<div class="bhw-patient-picker-empty">' + (res.message || 'Could not load patients.') + '</div>';
          return res;
        });
      }

      function findPatientById(id) {
        for (var i = 0; i < patients.length; i++) {
          if (parseInt(patients[i].id, 10) === id) return patients[i];
        }
        return null;
      }

      searchEl.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { fetchPatients(searchEl.value.trim()); }, 280);
      });

      searchEl.addEventListener('focus', function () {
        if (!selectedId) fetchPatients(searchEl.value.trim());
      });

      listEl.addEventListener('click', function (e) {
        var btn = e.target.closest('.bhw-patient-picker-item');
        if (!btn) return;
        var p = patients[parseInt(btn.dataset.idx, 10)];
        selectPatient(p);
      });

      clearBtn.addEventListener('click', clearSelection);

      searchEl.addEventListener('keydown', function (e) {
        var items = listEl.querySelectorAll('.bhw-patient-picker-item');
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          highlightIdx = Math.min(highlightIdx + 1, items.length - 1);
          items.forEach(function (el, i) { el.classList.toggle('is-highlighted', i === highlightIdx); });
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          highlightIdx = Math.max(highlightIdx - 1, 0);
          items.forEach(function (el, i) { el.classList.toggle('is-highlighted', i === highlightIdx); });
        } else if (e.key === 'Enter' && highlightIdx >= 0 && patients[highlightIdx]) {
          e.preventDefault();
          selectPatient(patients[highlightIdx]);
        } else if (e.key === 'Escape') {
          closeList();
        }
      });

      fetchPatients('').then(function () {
        if (selectedId > 0) {
          var found = findPatientById(selectedId);
          if (found) {
            selectPatient(found);
          } else {
            BhwPortal.get('patients.php', { action: 'get', patient_id: selectedId }).then(function (r) {
              if (r.success && r.patient) {
                selectPatient({
                  id: r.patient.id,
                  first_name: r.patient.first_name,
                  last_name: r.patient.last_name,
                  email: r.patient.email,
                  contact_number: r.patient.contact_number
                });
              }
            });
          }
        }
      });

      return { clear: clearSelection, getSelectedId: function () { return selectedId; } };
    },
    showFeedback: showFeedback,
    hideFeedback: hideFeedback,
    confirm: confirm,
    toast: function (msg, ok, options) {
      var extra = options || {};
      showFeedback({
        type: ok ? 'success' : 'error',
        title: extra.title || (ok ? 'Success' : 'Error'),
        message: msg,
        primary: extra.primary,
        secondary: extra.secondary,
        dismissible: extra.dismissible,
        onClose: function () {
          releaseUiBlockers();
          if (typeof extra.onClose === 'function') extra.onClose();
        },
      });
    },
    releaseUiBlockers: releaseUiBlockers,
  };

  document.addEventListener('DOMContentLoaded', ensureDom);
})();
