(function () {
  'use strict';

  var cfg = window.ANN_CONFIG || {};
  var api = cfg.api;
  var mediaApi = cfg.mediaApi;
  var csrf = cfg.csrf;

  var tableBody = document.getElementById('annTableBody');
  var emptyEl = document.getElementById('annEmpty');
  var countEl = document.getElementById('annCount');
  var skeleton = document.getElementById('annSkeleton');
  var editorModal = document.getElementById('annEditorModal');
  var previewModal = document.getElementById('annPreviewModal');
  var mediaModal = document.getElementById('annMediaModal');
  var form = document.getElementById('annForm');
  var feedbackEl = document.getElementById('annFeedback');
  var feedbackOnClose = null;

  function qs(id) { return document.getElementById(id); }

  function showFeedback(opts) {
    if (!feedbackEl) {
      if (opts.message) window.alert(opts.message);
      if (typeof opts.onClose === 'function') opts.onClose();
      return;
    }

    var type = opts.type || 'success';
    var iconWrap = qs('annFeedbackIcon');
    var titleEl = qs('annFeedbackTitle');
    var msgEl = qs('annFeedbackMessage');
    var actionsEl = qs('annFeedbackActions');

    feedbackOnClose = typeof opts.onClose === 'function' ? opts.onClose : null;

    iconWrap.className = 'ann-feedback__icon is-' + (type === 'error' ? 'error' : type === 'warn' ? 'warn' : 'success');
    feedbackEl.querySelector('.ann-feedback__icon-svg--success').hidden = type !== 'success';
    feedbackEl.querySelector('.ann-feedback__icon-svg--error').hidden = type !== 'error';
    feedbackEl.querySelector('.ann-feedback__icon-svg--warn').hidden = type !== 'warn';

    titleEl.textContent = opts.title || (type === 'error' ? 'Something went wrong' : 'Success');
    msgEl.textContent = opts.message || '';

    actionsEl.innerHTML = '';
    actionsEl.className = 'ann-feedback__actions' + ((opts.actions || []).length > 1 ? ' ann-feedback__actions--dual' : '');

    (opts.actions || [{ label: 'Done', primary: true }]).forEach(function (act) {
      var btn = document.createElement(act.href ? 'a' : 'button');
      btn.type = act.href ? undefined : 'button';
      btn.className = 'ann-feedback__btn ann-feedback__btn--' + (act.danger ? 'danger' : act.primary ? 'primary' : 'secondary');
      btn.textContent = act.label;
      if (act.href) {
        btn.href = act.href;
        btn.target = '_blank';
        btn.rel = 'noopener';
      }
      btn.addEventListener('click', function (e) {
        if (!act.href) e.preventDefault();
        hideFeedback();
        if (typeof act.onClick === 'function') act.onClick();
      });
      actionsEl.appendChild(btn);
    });

    feedbackEl.hidden = false;
    feedbackEl.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function hideFeedback() {
    if (!feedbackEl) return;
    feedbackEl.hidden = true;
    feedbackEl.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (feedbackOnClose) {
      var cb = feedbackOnClose;
      feedbackOnClose = null;
      cb();
    }
  }

  function confirmAction(opts) {
    return new Promise(function (resolve) {
      showFeedback({
        type: 'warn',
        title: opts.title || 'Confirm action',
        message: opts.message || 'Are you sure?',
        actions: [
          { label: opts.cancelLabel || 'Cancel', onClick: function () { resolve(false); } },
          { label: opts.confirmLabel || 'Confirm', primary: true, danger: !!opts.danger, onClick: function () { resolve(true); } }
        ],
        onClose: function () { resolve(false); }
      });
    });
  }

  function notifyResult(j, context) {
    var ok = !!j.success;
    var saveAction = context && context.saveAction;
    var action = context && context.action;
    var title = 'Done';
    var message = j.message || (ok ? 'Saved successfully.' : 'Please try again.');
    var actions = [{ label: 'Done', primary: true, onClick: context && context.onDone }];
    var type = ok ? 'success' : 'error';

    if (ok && saveAction === 'publish') {
      title = 'Announcement Published';
      message = 'Your announcement is now live on the landing page. Notifications have been sent to the selected audience.';
      actions = [
        { label: 'View Landing Page', href: cfg.landingUrl || '/index.php' },
        { label: 'Done', primary: true, onClick: context && context.onDone }
      ];
    } else if (ok && saveAction === 'schedule') {
      title = 'Announcement Scheduled';
      message = 'Your announcement will publish automatically on the scheduled date and time.';
    } else if (ok && saveAction === 'draft') {
      title = 'Draft Saved';
      message = 'Your announcement has been saved as a draft. Publish it when you are ready.';
    } else if (ok && action === 'publish') {
      title = 'Announcement Published';
      message = 'This announcement is now visible on the landing page and notifications have been sent.';
      actions = [
        { label: 'View Landing Page', href: cfg.landingUrl || '/index.php' },
        { label: 'Done', primary: true, onClick: context && context.onDone }
      ];
    } else if (ok && action === 'unpublish') {
      title = 'Unpublished';
      message = 'The announcement has been removed from the public landing page.';
    } else if (ok && action === 'archive') {
      title = 'Archived';
      message = 'The announcement has been moved to the archive.';
    } else if (ok && action === 'restore') {
      title = 'Restored';
      message = 'The announcement has been restored successfully.';
    } else if (ok && action === 'delete') {
      title = 'Deleted';
      message = 'The announcement has been deleted.';
    } else if (ok && action === 'toggle_pin') {
      title = 'Pin Updated';
      message = j.message || 'Pin status updated.';
    } else if (!ok) {
      title = 'Something went wrong';
      type = 'error';
    }

    showFeedback({ type: type, title: title, message: message, actions: actions });
  }

  document.querySelectorAll('[data-ann-feedback-close]').forEach(function (el) {
    el.addEventListener('click', hideFeedback);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && feedbackEl && !feedbackEl.hidden) hideFeedback();
  });

  function filters() {
    return {
      search: qs('annSearch').value.trim(),
      status: qs('annFilterStatus').value,
      category: qs('annFilterCategory').value,
      audience: qs('annFilterAudience').value,
      author_id: qs('annFilterAuthor').value,
      is_pinned: qs('annFilterPinned').value,
      date_from: qs('annFilterDateFrom').value,
      date_to: qs('annFilterDateTo').value,
    };
  }

  function buildQuery(params) {
    var q = new URLSearchParams({ action: 'list' });
    Object.keys(params).forEach(function (k) {
      if (params[k] !== '' && params[k] != null) q.set(k, params[k]);
    });
    return q.toString();
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(s.replace(' ', 'T'));
    return isNaN(d) ? s : d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function showSkeleton(on) {
    skeleton.hidden = !on;
    qs('annTableWrap').style.opacity = on ? '0.5' : '1';
  }

  function loadList() {
    showSkeleton(true);
    fetch(api + '?' + buildQuery(filters()), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        showSkeleton(false);
        if (!j.success) return;
        renderTable(j.data.items || [], j.data.total || 0);
      })
      .catch(function () { showSkeleton(false); });
  }

  function renderTable(items, total) {
    tableBody.innerHTML = '';
    emptyEl.hidden = items.length > 0;
    countEl.textContent = total + ' announcement' + (total === 1 ? '' : 's');

    items.forEach(function (row) {
      var tr = document.createElement('tr');
      var audiences = (row.target_audience || row.target_roles || []).join(', ');
      tr.innerHTML =
        '<td><strong>' + esc(row.title) + '</strong>' +
          (row.is_pinned ? '<span class="ann-pin">PINNED</span>' : '') + '</td>' +
        '<td>' + esc(row.category_label || row.category) + '</td>' +
        '<td class="text-xs">' + esc(audiences) + '</td>' +
        '<td><span class="mc-badge is-' + esc(row.status) + '">' + esc(row.status) + '</span></td>' +
        '<td class="text-xs">' + fmtDate(row.publish_at) + '</td>' +
        '<td>' + (row.view_count || 0) + '</td>' +
        '<td><div class="ann-actions" data-id="' + row.id + '"></div></td>';
      var actions = tr.querySelector('.ann-actions');
      addAction(actions, 'Edit', 'edit');
      if (row.status !== 'published') addAction(actions, 'Publish', 'publish');
      if (row.status === 'published') addAction(actions, 'Unpublish', 'unpublish');
      addAction(actions, row.is_pinned ? 'Unpin' : 'Pin', 'toggle_pin');
      if (row.status !== 'archived') addAction(actions, 'Archive', 'archive');
      else addAction(actions, 'Restore', 'restore');
      addAction(actions, 'Delete', 'delete', true);
      tableBody.appendChild(tr);
    });

    tableBody.querySelectorAll('.ann-actions').forEach(function (wrap) {
      var id = wrap.dataset.id;
      wrap.querySelectorAll('button').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var act = btn.dataset.act;
          if (act === 'edit') return openEditor(id);
          if (act === 'delete') {
            confirmAction({
              title: 'Delete announcement?',
              message: 'This will remove the announcement from the admin list and landing page. You can restore it later if needed.',
              confirmLabel: 'Delete',
              danger: true
            }).then(function (ok) { if (ok) postAction(act, id); });
            return;
          }
          postAction(act, id);
        });
      });
    });
  }

  function addAction(wrap, label, act, danger) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'mc-btn mc-btn--outline mc-btn--sm' + (danger ? ' ann-btn-danger' : '');
    b.textContent = label;
    b.dataset.act = act;
    wrap.appendChild(b);
  }

  function postAction(action, id) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('id', id);
    fd.append('csrf_token', csrf);
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        notifyResult(j, { action: action, onDone: function () { if (j.success) loadList(); } });
        if (j.success) loadList();
      });
  }

  function openEditor(id) {
    form.reset();
    qs('annId').value = '';
    qs('annBannerPreview').hidden = true;
    qs('annAttachmentName').textContent = '';
    qs('annModalTitle').textContent = id ? 'Edit Announcement' : 'Create Announcement';

    if (!id) {
      editorModal.hidden = false;
      return;
    }

    fetch(api + '?action=get&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.success) return;
        fillForm(j.data);
        editorModal.hidden = false;
      });
  }

  function fillForm(d) {
    qs('annId').value = d.id;
    qs('annTitle').value = d.title || '';
    qs('annSubtitle').value = d.subtitle || '';
    qs('annCategory').value = d.category || 'general';
    qs('annPriority').value = d.priority || 'normal';
    qs('annShortDesc').value = d.short_description || '';
    qs('annContent').value = d.content || '';
    qs('annBannerPath').value = d.banner_image || '';
    qs('annAttachmentPath').value = d.attachment || '';
    qs('annPublishAt').value = toLocalInput(d.publish_at);
    qs('annExpireAt').value = toLocalInput(d.expire_at);
    qs('annPinned').checked = !!+d.is_pinned;
    qs('annFeatured').checked = !!+d.is_featured;

    form.querySelectorAll('input[name="target_audience[]"]').forEach(function (cb) {
      cb.checked = (d.target_audience || d.target_roles || []).indexOf(cb.value) >= 0;
    });
    form.querySelectorAll('input[name="barangay_ids[]"]').forEach(function (cb) {
      cb.checked = (d.barangay_ids || []).indexOf(+cb.value) >= 0;
    });

    if (d.banner_url) {
      qs('annBannerPreview').src = d.banner_url;
      qs('annBannerPreview').hidden = false;
    }
    if (d.attachment) qs('annAttachmentName').textContent = d.attachment.split('/').pop();
  }

  function toLocalInput(s) {
    if (!s) return '';
    return s.replace(' ', 'T').slice(0, 16);
  }

  function closeModals(sel) {
    document.querySelectorAll(sel).forEach(function (el) { el.hidden = true; });
  }

  function uploadFile(input, action, pathField, previewEl) {
    if (!input.files || !input.files[0]) return;
    var fd = new FormData();
    fd.append('action', action);
    fd.append('file', input.files[0]);
    fd.append('csrf_token', csrf);
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.success) {
          showFeedback({ type: 'error', title: 'Upload failed', message: j.message || 'Could not upload file.' });
          return;
        }
        qs(pathField).value = j.path;
        if (previewEl && j.url) {
          previewEl.src = j.url;
          previewEl.hidden = false;
        }
        if (pathField === 'annAttachmentPath') {
          qs('annAttachmentName').textContent = j.path.split('/').pop();
        }
      });
  }

  function renderPreview() {
    var cat = qs('annCategory').value;
    var html =
      (qs('annBannerPreview').src && !qs('annBannerPreview').hidden
        ? '<img class="ann-preview__banner" src="' + esc(qs('annBannerPreview').src) + '" alt="">' : '') +
      '<span class="ann-preview__badge">' + esc((cfg.categories || {})[cat] || cat) + '</span>' +
      '<h2>' + esc(qs('annTitle').value) + '</h2>' +
      (qs('annSubtitle').value ? '<h3>' + esc(qs('annSubtitle').value) + '</h3>' : '') +
      (qs('annShortDesc').value ? '<p>' + esc(qs('annShortDesc').value) + '</p>' : '') +
      '<div class="ann-preview__body">' + esc(qs('annContent').value) + '</div>';
    qs('annPreviewContent').innerHTML = html;
    previewModal.hidden = false;
  }

  function loadMediaPicker() {
    fetch(mediaApi + '?action=list&type=image', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var grid = qs('annMediaGrid');
        grid.innerHTML = '';
        (j.data || []).forEach(function (m) {
          var div = document.createElement('div');
          div.className = 'ann-media-item';
          div.innerHTML = '<img src="' + esc(m.url) + '" alt="' + esc(m.alt_text || m.file_name) + '">';
          div.addEventListener('click', function () {
            qs('annBannerPath').value = m.file_path;
            qs('annBannerPreview').src = m.url;
            qs('annBannerPreview').hidden = false;
            mediaModal.hidden = true;
          });
          grid.appendChild(div);
        });
        mediaModal.hidden = false;
      });
  }

  // Events
  qs('annCreateBtn').addEventListener('click', function () { openEditor(null); });
  ['annSearch','annFilterStatus','annFilterCategory','annFilterAudience','annFilterAuthor','annFilterPinned','annFilterDateFrom','annFilterDateTo']
    .forEach(function (id) {
      qs(id).addEventListener('change', loadList);
      if (id === 'annSearch') qs(id).addEventListener('input', debounce(loadList, 350));
    });
  qs('annFilterReset').addEventListener('click', function () {
    qs('annSearch').value = '';
    ['annFilterStatus','annFilterCategory','annFilterAudience','annFilterAuthor','annFilterPinned','annFilterDateFrom','annFilterDateTo']
      .forEach(function (id) { qs(id).value = ''; });
    loadList();
  });

  document.querySelectorAll('[data-close-modal]').forEach(function (el) {
    el.addEventListener('click', function () { editorModal.hidden = true; });
  });
  document.querySelectorAll('[data-close-preview]').forEach(function (el) {
    el.addEventListener('click', function () { previewModal.hidden = true; });
  });
  document.querySelectorAll('[data-close-media]').forEach(function (el) {
    el.addEventListener('click', function () { mediaModal.hidden = true; });
  });

  qs('annPreviewBtn').addEventListener('click', renderPreview);
  qs('annPickBanner').addEventListener('click', loadMediaPicker);
  qs('annBannerFile').addEventListener('change', function () {
    uploadFile(this, 'upload_banner', 'annBannerPath', qs('annBannerPreview'));
  });
  qs('annAttachmentFile').addEventListener('change', function () {
    uploadFile(this, 'upload_attachment', 'annAttachmentPath', null);
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.submitter;
    var saveAction = btn && btn.dataset.saveAction ? btn.dataset.saveAction : 'draft';
    var fd = new FormData(form);
    var id = fd.get('id');
    fd.append('action', id ? 'update' : 'create');
    fd.append('save_action', saveAction);
    fd.append('csrf_token', csrf);
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        notifyResult(j, {
          saveAction: saveAction,
          onDone: function () {
            if (j.success) {
              editorModal.hidden = true;
              loadList();
            }
          }
        });
        if (j.success) {
          editorModal.hidden = true;
          loadList();
        }
      });
  });

  function debounce(fn, ms) {
    var t;
    return function () {
      clearTimeout(t);
      var args = arguments;
      var self = this;
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  loadList();
})();
