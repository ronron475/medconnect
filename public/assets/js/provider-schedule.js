/**
 * Provider Schedule & Availability — multi-session editor
 */
(function () {
  'use strict';

  const SCHEDULE_TODAY = window.SCHEDULE_CONFIG?.today || '';
  const SCHEDULE_API = window.SCHEDULE_CONFIG?.api || '';
  const LOGIN_URL = window.SCHEDULE_CONFIG?.loginUrl || '/';

  const DURATION_OPTIONS = [
    { v: 15, l: '15 min' },
    { v: 30, l: '30 min' },
    { v: 45, l: '45 min' },
    { v: 60, l: '1 hour' },
  ];

  function timeToMinutes(t) {
    if (!t) return -1;
    const p = t.split(':');
    return parseInt(p[0], 10) * 60 + parseInt(p[1], 10);
  }

  function validateSessions(cards, requireAtLeastOne) {
    const errors = [];
    const ranges = [];

    if (requireAtLeastOne && !cards.length) {
      return ['Add at least one availability session.'];
    }

    if (!cards.length) {
      return [];
    }

    cards.forEach((card, idx) => {
      const label = 'Session ' + (idx + 1);
      const start = card.querySelector('.schedule-start')?.value || '';
      const end = card.querySelector('.schedule-end')?.value || '';
      const dur = parseInt(card.querySelector('.schedule-duration')?.value || '30', 10);

      if (!start || !end) {
        errors.push(label + ': start and end times are required.');
        return;
      }
      const sm = timeToMinutes(start);
      const em = timeToMinutes(end);
      if (em <= sm) {
        errors.push(label + ': end time must be later than start time.');
        return;
      }
      if (em - sm < dur) {
        errors.push(label + ': session must be at least as long as the slot length (' + dur + ' min).');
        return;
      }

      const key = start + '|' + end + '|' + dur;
      const dup = ranges.find((r) => r.key === key);
      if (dup) {
        errors.push(label + ': duplicate time range (same as Session ' + dup.num + ').');
        return;
      }

      ranges.push({ num: idx + 1, key, start: sm, end: em, label });
      card.classList.toggle('is-invalid', false);
    });

    ranges.sort((a, b) => a.start - b.start);
    for (let i = 1; i < ranges.length; i++) {
      if (ranges[i].start < ranges[i - 1].end) {
        errors.push('Sessions overlap between ' + ranges[i - 1].label + ' and ' + ranges[i].label + '.');
        break;
      }
    }

    return errors;
  }

  function showValidation(box, errors) {
    if (!box) return;
    if (!errors.length) {
      box.hidden = true;
      box.textContent = '';
      return;
    }
    box.hidden = false;
    box.innerHTML = '<strong>Please fix the following:</strong><ul>'
      + errors.map((e) => '<li>' + escapeHtml(e) + '</li>').join('')
      + '</ul>';
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function renumberSessions(list) {
    list.querySelectorAll('[data-session-card]').forEach((card, i) => {
      const num = card.querySelector('[data-session-num]');
      if (num) num.textContent = String(i + 1);
    });
  }

  function buildSessionCard() {
    const wrap = document.createElement('div');
    wrap.className = 'sched-session-card';
    wrap.setAttribute('data-session-card', '');
    wrap.setAttribute('data-session-id', '');

    const durOpts = DURATION_OPTIONS.map(
      (o) => '<option value="' + o.v + '">' + o.l + '</option>'
    ).join('');

    wrap.innerHTML =
      '<div class="sched-session-card__head">'
      + '<span class="sched-session-card__label">Session <span data-session-num>1</span></span>'
      + '<button type="button" class="sched-session-remove" data-remove-session title="Remove session" aria-label="Remove session">'
      + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Remove'
      + '</button></div>'
      + '<div class="sched-session-card__grid">'
      + '<div class="sched-session-field"><label>Start time</label><input type="time" class="sched-field schedule-start" value="09:00" required></div>'
      + '<div class="sched-session-field"><label>End time</label><input type="time" class="sched-field schedule-end" value="12:00" required></div>'
      + '<div class="sched-session-field"><label>Slot length</label><select class="sched-field schedule-duration">' + durOpts + '</select></div>'
      + '</div>';

    return wrap;
  }

  function collectSessions(dayBlock) {
    const list = dayBlock.querySelector('[data-sessions-list]');
    if (!list) return [];
    return Array.from(list.querySelectorAll('[data-session-card]')).map((card) => {
      const id = card.getAttribute('data-session-id');
      return {
        id: id ? parseInt(id, 10) : null,
        start_time: card.querySelector('.schedule-start')?.value || '',
        end_time: card.querySelector('.schedule-end')?.value || '',
        duration: parseInt(card.querySelector('.schedule-duration')?.value || '30', 10),
      };
    });
  }

  const todayBlock = document.querySelector('.sched-day-block--today');
  if (!todayBlock) return;

  const sessionsList = todayBlock.querySelector('[data-sessions-list]');
  const validationBox = todayBlock.querySelector('[data-sched-validation]');
  const addBtn = todayBlock.querySelector('[data-add-session]');
  const saveBtn = todayBlock.querySelector('.schedule-save-btn');

  if (addBtn && sessionsList) {
    addBtn.addEventListener('click', () => {
      const card = buildSessionCard();
      sessionsList.appendChild(card);
      renumberSessions(sessionsList);
      showValidation(validationBox, []);
      card.querySelector('.schedule-start')?.focus();
    });

    sessionsList.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-remove-session]');
      if (!btn) return;
      const card = btn.closest('[data-session-card]');
      const cards = sessionsList.querySelectorAll('[data-session-card]');
      if (cards.length <= 1) {
        showValidation(validationBox, ['You must keep at least one session, or turn off bookings for today.']);
        return;
      }
      card?.remove();
      renumberSessions(sessionsList);
      showValidation(validationBox, validateSessions(sessionsList.querySelectorAll('[data-session-card]'), true));
    });

    sessionsList.addEventListener('change', () => {
      showValidation(validationBox, validateSessions(sessionsList.querySelectorAll('[data-session-card]'), true));
    });
  }

  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      const day = todayBlock.dataset.day || SCHEDULE_TODAY;
      if (todayBlock.dataset.editable !== '1' || day !== SCHEDULE_TODAY) {
        alert('You can only update today\'s schedule (' + SCHEDULE_TODAY + ').');
        return;
      }

      const cards = sessionsList?.querySelectorAll('[data-session-card]') || [];
      const dayActive = todayBlock.querySelector('.schedule-day-active')?.checked ?? false;
      const errors = validateSessions(cards, dayActive);

      if (errors.length) {
        showValidation(validationBox, errors);
        return;
      }

      showValidation(validationBox, []);

      const originalText = saveBtn.textContent;
      saveBtn.disabled = true;
      saveBtn.classList.add('is-saving');
      saveBtn.textContent = 'Saving…';

      const fd = new FormData();
      fd.append('day', day);
      if (dayActive) fd.append('is_active', '1');
      fd.append('sessions', JSON.stringify(collectSessions(todayBlock)));
      const csrf = (document.body && document.body.dataset.csrf) || '';
      if (csrf) fd.append('csrf_token', csrf);

      try {
        const res = await fetch(SCHEDULE_API, {
          method: 'POST',
          body: fd,
          credentials: 'include',
          cache: 'no-store',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        const raw = await res.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch {
          throw new Error(raw || 'Invalid server response.');
        }

        if (data.success) {
          saveBtn.classList.remove('is-saving');
          saveBtn.classList.add('is-success');
          saveBtn.textContent = 'Saved';
          if (window.mcToast) {
            window.mcToast(data.message || 'Schedule saved.');
          }
          setTimeout(() => window.location.reload(), 700);
          return;
        }

        if (data.errors?.length) {
          showValidation(validationBox, data.errors);
        } else if (data.message === 'Unauthorized.') {
          alert('Your session expired. Please log in again.');
          window.location.href = LOGIN_URL;
          return;
        } else {
          alert(data.message || 'Could not save schedule.');
        }
      } catch (err) {
        alert(err.message || 'Error saving schedule.');
      }

      saveBtn.disabled = false;
      saveBtn.classList.remove('is-saving');
      saveBtn.textContent = originalText;
    });
  }
})();
