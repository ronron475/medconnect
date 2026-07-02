(function () {
  'use strict';

  var root = document.getElementById('mlMgmt');
  if (!root) return;

  var api = root.dataset.api;
  var csrf = document.body.dataset.csrf || '';
  var items = [];
  try {
    items = JSON.parse(root.dataset.media || '[]');
  } catch (e) {
    items = [];
  }

  var filter = 'all';
  var query = '';
  var pendingDeleteId = null;

  var grid = document.getElementById('mlGrid');
  var toastEl = document.getElementById('mlToast');
  var uploadPanel = document.getElementById('mlUploadPanel');
  var altModal = document.getElementById('mlAltModal');
  var deleteModal = document.getElementById('mlDeleteModal');
  var altInput = document.getElementById('mlAltInput');
  var pendingAltId = null;

  function qs(sel) { return root.querySelector(sel); }

  function toast(msg, isError) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-error', !!isError);
    toastEl.classList.add('is-visible');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 3000);
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function fmtSize(bytes) {
    var n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(1) + ' MB';
  }

  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? s : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function filtered() {
    return items.filter(function (m) {
      if (filter === 'image' && m.file_type !== 'image') return false;
      if (filter === 'document' && m.file_type !== 'document') return false;
      if (query) {
        var q = query.toLowerCase();
        var name = (m.file_name || '').toLowerCase();
        var alt = (m.alt_text || '').toLowerCase();
        if (name.indexOf(q) === -1 && alt.indexOf(q) === -1) return false;
      }
      return true;
    });
  }

  function updateStats() {
    var total = items.length;
    var images = items.filter(function (m) { return m.file_type === 'image'; }).length;
    var docs = items.filter(function (m) { return m.file_type === 'document'; }).length;
    var elTotal = document.getElementById('mlStatTotal');
    var elImages = document.getElementById('mlStatImages');
    var elDocs = document.getElementById('mlStatDocs');
    if (elTotal) elTotal.textContent = String(total);
    if (elImages) elImages.textContent = String(images);
    if (elDocs) elDocs.textContent = String(docs);
  }

  function renderCard(m) {
    var isImage = m.file_type === 'image';
    var thumb = isImage
      ? '<img src="' + esc(m.url) + '" alt="' + esc(m.alt_text || m.file_name) + '" loading="lazy">'
      : '<span class="ml-mgmt__doc-icon" aria-hidden="true">📄</span>';
    var altLine = m.alt_text
      ? '<div class="ml-mgmt__alt" title="' + esc(m.alt_text) + '">' + esc(m.alt_text) + '</div>'
      : '';

    return (
      '<article class="ml-mgmt__card" data-id="' + esc(String(m.id)) + '">' +
        '<div class="ml-mgmt__thumb">' + thumb + '</div>' +
        '<div class="ml-mgmt__body">' +
          '<div class="ml-mgmt__name" title="' + esc(m.file_name) + '">' + esc(m.file_name) + '</div>' +
          altLine +
          '<div class="ml-mgmt__meta">' + esc(fmtSize(m.file_size)) + ' · ' + esc(fmtDate(m.created_at)) + '</div>' +
          (m.uploader_name ? '<div class="ml-mgmt__meta">By ' + esc(m.uploader_name) + '</div>' : '') +
          '<div class="ml-mgmt__actions">' +
            '<button type="button" class="mc-btn mc-btn--outline ml-copy" data-url="' + esc(m.url) + '">Copy URL</button>' +
            '<button type="button" class="mc-btn mc-btn--outline ml-alt" data-id="' + esc(String(m.id)) + '" data-alt="' + esc(m.alt_text || '') + '">Alt text</button>' +
            '<button type="button" class="mc-btn mc-btn--outline ml-delete" data-id="' + esc(String(m.id)) + '" data-name="' + esc(m.file_name) + '">Delete</button>' +
          '</div>' +
        '</div>' +
      '</article>'
    );
  }

  function render() {
    updateStats();
    if (!grid) return;
    var list = filtered();
    if (!list.length) {
      grid.innerHTML = (
        '<div class="mc-card ml-mgmt__empty">' +
          '<p class="text-muted" style="margin:0;">' +
            (items.length ? 'No files match your filter.' : 'No media files yet. Upload images or PDFs to reuse in announcements and the website.') +
          '</p>' +
        '</div>'
      );
      return;
    }
    grid.innerHTML = list.map(renderCard).join('');
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrf);
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] != null) fd.append(k, data[k]);
    });
    return fetch(api, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
  }

  function refreshList() {
    return fetch(api + '?action=list', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.success && Array.isArray(j.data)) {
          items = j.data;
          render();
        }
      });
  }

  function copyUrl(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () { toast('URL copied to clipboard.'); });
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = url;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      toast('URL copied to clipboard.');
    } catch (e) {
      toast('Could not copy URL.', true);
    }
    document.body.removeChild(ta);
  }

  root.addEventListener('click', function (e) {
    var t = e.target;

    if (t.id === 'mlToggleUpload') {
      uploadPanel.classList.toggle('is-open');
      return;
    }

    if (t.classList.contains('ml-mgmt__filter')) {
      filter = t.dataset.filter || 'all';
      root.querySelectorAll('.ml-mgmt__filter').forEach(function (btn) {
        btn.classList.toggle('is-active', btn === t);
      });
      render();
      return;
    }

    if (t.classList.contains('ml-copy')) {
      copyUrl(t.dataset.url || '');
      return;
    }

    if (t.classList.contains('ml-alt')) {
      pendingAltId = parseInt(t.dataset.id, 10);
      if (altInput) altInput.value = t.dataset.alt || '';
      if (altModal) altModal.classList.add('is-open');
      return;
    }

    if (t.classList.contains('ml-delete')) {
      pendingDeleteId = parseInt(t.dataset.id, 10);
      var nameEl = document.getElementById('mlDeleteName');
      if (nameEl) nameEl.textContent = t.dataset.name || 'this file';
      if (deleteModal) deleteModal.classList.add('is-open');
      return;
    }

    if (t.id === 'mlAltCancel' || t.id === 'mlDeleteCancel') {
      if (altModal) altModal.classList.remove('is-open');
      if (deleteModal) deleteModal.classList.remove('is-open');
      pendingAltId = null;
      pendingDeleteId = null;
      return;
    }

    if (t.id === 'mlAltSave') {
      if (!pendingAltId) return;
      var altVal = altInput ? altInput.value.trim() : '';
      post('update_alt', { id: pendingAltId, alt_text: altVal }).then(function (j) {
        toast(j.message || (j.success ? 'Saved.' : 'Failed.'), !j.success);
        if (j.success) {
          var item = items.find(function (m) { return Number(m.id) === pendingAltId; });
          if (item) item.alt_text = altVal;
          if (altModal) altModal.classList.remove('is-open');
          pendingAltId = null;
          render();
        }
      });
      return;
    }

    if (t.id === 'mlDeleteConfirm') {
      if (!pendingDeleteId) return;
      post('delete', { id: pendingDeleteId }).then(function (j) {
        toast(j.message || (j.success ? 'Deleted.' : 'Failed.'), !j.success);
        if (j.success) {
          items = items.filter(function (m) { return Number(m.id) !== pendingDeleteId; });
          if (deleteModal) deleteModal.classList.remove('is-open');
          pendingDeleteId = null;
          render();
        }
      });
    }
  });

  var searchEl = document.getElementById('mlSearch');
  if (searchEl) {
    searchEl.addEventListener('input', function () {
      query = this.value.trim();
      render();
    });
  }

  var uploadForm = document.getElementById('mlUploadForm');
  if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fileInput = document.getElementById('mlFileInput');
      var altField = document.getElementById('mlUploadAlt');
      if (!fileInput || !fileInput.files[0]) {
        toast('Choose a file to upload.', true);
        return;
      }
      var btn = document.getElementById('mlUploadSubmit');
      if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }

      var fd = new FormData();
      fd.append('action', 'upload');
      fd.append('csrf_token', csrf);
      fd.append('file', fileInput.files[0]);
      if (altField && altField.value.trim()) fd.append('alt_text', altField.value.trim());

      fetch(api, { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          toast(j.message || (j.success ? 'Uploaded.' : 'Upload failed.'), !j.success);
          if (j.success) {
            fileInput.value = '';
            if (altField) altField.value = '';
            uploadPanel.classList.remove('is-open');
            return refreshList();
          }
        })
        .catch(function () { toast('Upload failed.', true); })
        .finally(function () {
          if (btn) { btn.disabled = false; btn.textContent = 'Upload file'; }
        });
    });
  }

  [altModal, deleteModal].forEach(function (modal) {
    if (!modal) return;
    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        modal.classList.remove('is-open');
        pendingAltId = null;
        pendingDeleteId = null;
      }
    });
  });

  render();
})();
