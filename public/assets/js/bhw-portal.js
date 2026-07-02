(function () {
  var base = document.body.dataset.assetBase || '';
  var api = base + '/app/api/bhw';

  var overlay = null;
  var dialog = null;
  var iconWrap = null;
  var iconSuccess = null;
  var iconError = null;
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
    titleEl = document.getElementById('bhw-feedback-title');
    messageEl = document.getElementById('bhw-feedback-message');
    actionsEl = document.getElementById('bhw-feedback-actions');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay && overlay.dataset.dismissible === 'true') {
        hideFeedback();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlay.classList.contains('is-visible')) {
        hideFeedback();
      }
    });
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

  function showFeedback(options) {
    ensureDom();
    if (!overlay) {
      if (typeof alert !== 'undefined') alert(options.message || options.title || 'Done');
      return;
    }

    var opts = options || {};
    var isSuccess = opts.type !== 'error';
    onCloseCallback = typeof opts.onClose === 'function' ? opts.onClose : null;

    iconWrap.className = 'bhw-feedback-icon ' + (isSuccess ? 'bhw-feedback-icon--success' : 'bhw-feedback-icon--error');
    if (iconSuccess) iconSuccess.style.display = isSuccess ? '' : 'none';
    if (iconError) iconError.style.display = isSuccess ? 'none' : '';

    titleEl.textContent = opts.title || (isSuccess ? 'Success' : 'Something went wrong');
    if (opts.html) {
      messageEl.innerHTML = opts.message || '';
    } else {
      messageEl.textContent = opts.message || '';
    }

    actionsEl.innerHTML = '';
    actionsEl.className = 'bhw-feedback-actions';

    var primary = opts.primary;
    var secondary = opts.secondary;

    if (primary) {
      if (primary.href) {
        actionsEl.appendChild(makeLink(primary.label || 'Continue', 'bhw-feedback-btn--primary', primary.href));
      } else {
        actionsEl.appendChild(makeButton(primary.label || 'OK', isSuccess ? 'bhw-feedback-btn--primary' : 'bhw-feedback-btn--danger', function () {
          hideFeedback();
          if (typeof primary.action === 'function') primary.action();
        }));
      }
    }

    if (secondary) {
      actionsEl.classList.add('bhw-feedback-actions--dual');
      actionsEl.appendChild(makeButton(secondary.label || 'Close', 'bhw-feedback-btn--secondary', function () {
        hideFeedback();
        if (typeof secondary.action === 'function') secondary.action();
      }));
    }

    if (!primary && !secondary) {
      actionsEl.appendChild(makeButton('OK', isSuccess ? 'bhw-feedback-btn--primary' : 'bhw-feedback-btn--danger', hideFeedback));
    }

    overlay.dataset.dismissible = opts.dismissible === false ? 'false' : 'true';
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');

    var focusBtn = actionsEl.querySelector('button, a');
    if (focusBtn) focusBtn.focus();
  }

  function hideFeedback() {
    if (!overlay) return;
    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
    if (onCloseCallback) {
      var cb = onCloseCallback;
      onCloseCallback = null;
      cb();
    }
  }

  window.BhwPortal = {
    get: function (path, params) {
      var q = new URLSearchParams(params || {}).toString();
      return fetch(api + '/' + path + (q ? '?' + q : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
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
      return fetch(api + '/' + path, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
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
        '  <div class="bhw-selected-patient-info"><strong id="bhwPickerSelectedName"></strong><span id="bhwPickerSelectedMeta"></span></div>' +
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
          var name = (p.last_name || '') + ', ' + (p.first_name || '');
          var meta = (p.email || '') + (p.contact_number ? ' · ' + p.contact_number : '');
          var sel = selectedId === parseInt(p.id, 10) ? ' is-selected' : '';
          return '<button type="button" class="bhw-patient-picker-item' + sel + '" data-idx="' + i + '" role="option">' +
            '<div class="bhw-patient-picker-name">' + name + '</div>' +
            '<div class="bhw-patient-picker-meta">' + meta + '</div></button>';
        }).join('');
        if (!selectedId) openList();
      }

      function selectPatient(p) {
        if (!p) return;
        selectedId = parseInt(p.id, 10);
        searchEl.value = (p.last_name || '') + ', ' + (p.first_name || '');
        selectedName.textContent = (p.last_name || '') + ', ' + (p.first_name || '');
        selectedMeta.textContent = (p.email || '') + (p.contact_number ? ' · ' + p.contact_number : '');
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
        listEl.innerHTML = '<div class="bhw-patient-picker-loading">Loading patients…</div>';
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
    toast: function (msg, ok, options) {
      var extra = options || {};
      showFeedback({
        type: ok ? 'success' : 'error',
        title: extra.title || (ok ? 'Success' : 'Error'),
        message: msg,
        primary: extra.primary,
        secondary: extra.secondary,
        dismissible: extra.dismissible
      });
    }
  };

  document.addEventListener('DOMContentLoaded', ensureDom);
})();
