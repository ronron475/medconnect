(function () {
  var form = document.getElementById('bhwRegForm');
  var statusEl = document.getElementById('bhwRegStatus');
  if (!form || !statusEl || !window.BhwPortal) return;

  var submitBtn = document.getElementById('bhwRegSubmit');
  var errorEl = document.getElementById('bhwRegError');
  var whoEl = document.getElementById('bhwRegStatusWho');
  var labelEl = document.getElementById('bhwRegStatusLabel');
  var detailEl = document.getElementById('bhwRegStatusDetail');
  var anotherBtn = document.getElementById('bhwRegAnother');
  var storageKey = 'bhwPatientReg';
  var timer = 0;
  var lastFp = '';
  var current = null;

  function showError(msg) {
    if (!errorEl) return;
    errorEl.hidden = !msg;
    errorEl.textContent = msg || '';
  }

  function remember(row) {
    current = row;
    try {
      sessionStorage.setItem(storageKey, JSON.stringify({
        patient_id: row.patient_id,
        full_name: row.full_name || '',
        email: row.email || ''
      }));
    } catch (e) {}
  }

  function forget() {
    current = null;
    lastFp = '';
    try { sessionStorage.removeItem(storageKey); } catch (e) {}
  }

  function paint(row) {
    var fp = [row.status, row.label, row.detail, row.email].join('|');
    if (fp === lastFp) return;
    lastFp = fp;
    form.hidden = true;
    statusEl.hidden = false;
    statusEl.classList.toggle('is-done', row.status === 'completed');
    statusEl.classList.toggle('is-wait', row.status !== 'completed');
    if (whoEl) {
      whoEl.textContent = (row.full_name ? row.full_name + ' · ' : '') + (row.email || '');
    }
    if (labelEl) labelEl.textContent = row.label || '';
    if (detailEl) {
      var detail = row.detail && row.detail !== row.label ? row.detail : '';
      detailEl.hidden = !detail;
      detailEl.textContent = detail;
    }
    var stepTitle = statusEl.querySelector('.bhw-form-card-title');
    if (stepTitle) {
      stepTitle.lastChild.textContent = row.status === 'awaiting_gmail'
        ? ' Step 2 — Gmail Verification'
        : ' Step 3 — Patient Password';
    }
  }

  function poll() {
    if (!current || !current.patient_id) return;
    BhwPortal.get('patients.php', {
      action: 'register_status',
      patient_id: current.patient_id
    }).then(function (r) {
      if (!r || !r.success) return;
      remember(r);
      paint(r);
      if (r.status === 'completed' && timer) {
        window.clearInterval(timer);
        timer = 0;
      }
    }).catch(function () {});
  }

  function startPolling() {
    if (timer) window.clearInterval(timer);
    poll();
    timer = window.setInterval(function () {
      if (document.hidden) return;
      poll();
    }, 4000);
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    showError('');
    if (submitBtn) submitBtn.disabled = true;
    var data = {
      action: 'create',
      full_name: document.getElementById('bhwRegName').value,
      contact_number: document.getElementById('bhwRegContact').value,
      email: document.getElementById('bhwRegEmail').value,
      date_of_birth: document.getElementById('bhwRegDob').value,
      gender: document.getElementById('bhwRegSex').value,
      purok: document.getElementById('bhwRegPurok').value,
      allergies: document.getElementById('bhwRegAllergies').value,
      existing_conditions: document.getElementById('bhwRegConditions').value,
      current_medications: document.getElementById('bhwRegMeds').value,
      blood_type: document.getElementById('bhwRegBlood').value
    };
    BhwPortal.post('patients.php', data).then(function (r) {
      if (submitBtn) submitBtn.disabled = false;
      if (!r || !r.success) {
        showError((r && r.message) || 'Registration could not be saved.');
        return;
      }
      remember(r);
      paint(r);
      statusEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      startPolling();
    }).catch(function () {
      if (submitBtn) submitBtn.disabled = false;
      showError('Registration could not be saved.');
    });
  });

  if (anotherBtn) {
    anotherBtn.addEventListener('click', function () {
      if (timer) window.clearInterval(timer);
      timer = 0;
      forget();
      form.reset();
      form.hidden = false;
      statusEl.hidden = true;
      showError('');
      var name = document.getElementById('bhwRegName');
      if (name) name.focus();
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll();
  });

  var purokList = document.getElementById('bhwRegPurokList');
  if (purokList) {
    BhwPortal.get('puroks.php', {}).then(function (r) {
      if (!r || !r.success || !Array.isArray(r.puroks)) return;
      r.puroks.forEach(function (row) {
        var label = row && row.purok ? String(row.purok) : '';
        if (!label) return;
        var opt = document.createElement('option');
        opt.value = label;
        purokList.appendChild(opt);
      });
    }).catch(function () {});
  }

  try {
    var saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
    if (saved && saved.patient_id) {
      current = saved;
      paint({
        patient_id: saved.patient_id,
        full_name: saved.full_name || '',
        email: saved.email || '',
        status: 'awaiting_gmail',
        label: 'Waiting for Gmail verification',
        detail: ''
      });
      startPolling();
    }
  } catch (e) {}
})();
