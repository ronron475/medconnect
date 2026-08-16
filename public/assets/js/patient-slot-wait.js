/**
 * Patient dashboard — live NON-URGENT slot waitlist card (no page refresh).
 */
(function (global) {
  'use strict';

  if (global.MedConnectPatientSlotWait) return;

    var inFlight = false;

  function base() {
    if (typeof global.APP_BASE === 'string' && global.APP_BASE) {
      return String(global.APP_BASE).replace(/\/$/, '');
    }
    if (document.body && document.body.dataset && document.body.dataset.assetBase) {
      return String(document.body.dataset.assetBase).replace(/\/$/, '');
    }
    return '';
  }

  function esc(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function text(el, value) {
    if (el) el.textContent = value == null ? '' : String(value);
  }

  function initials(name) {
    var clean = String(name || '').replace(/^dr\.?\s*/i, '').trim();
    if (!clean) return 'DR';
    var parts = clean.split(/\s+/);
    var first = (parts[0] || '').charAt(0);
    var last = (parts[parts.length - 1] || '').charAt(0);
    return (first + last).toUpperCase() || 'DR';
  }

  function applyHero(wait) {
    var hero = document.getElementById('pdashHeroPrimary');
    if (!hero) return;
    var available = !!(wait && wait.active && wait.status === 'slot_available');
    var waiting = !!(wait && wait.active && !available);
    if (available) {
      hero.textContent = 'Book Consultation';
      hero.setAttribute('href', '#pdashSlotWait');
    } else if (waiting) {
      hero.textContent = 'View waiting status';
      hero.setAttribute('href', '#pdashSlotWait');
    }
  }

  function careHtml(wait, available) {
    var rec = String((wait && wait.care_tips_status) || '');
    var html = '';
    if (rec === 'approved') {
      var reviewed = String((wait && wait.care_tips_label) || 'Reviewed by your provider');
      html += '<p class="pdash-care-hint pdash-care-hint--success">' + esc(reviewed) + '. You may view the approved care guidance.</p>';
    } else if (rec === 'pending_approval' || rec === 'hidden') {
      html += '<p class="pdash-care-hint pdash-care-hint--warn">Care Tips: Pending Provider Review. AI-generated guidance is not final medical advice until a provider reviews it.</p>';
    } else if (wait && wait.care_tips_label) {
      html += '<p class="pdash-care-hint pdash-care-hint--muted">' + esc(wait.care_tips_label) + '</p>';
    }
    if (!available) {
      html += '<p class="pdash-care-hint pdash-care-hint--muted" id="pdashSlotWaitFifo">When a provider opens a real slot, patients are offered a chance to book in waiting order. Appointments are not created automatically.</p>';
    }
    return html;
  }

  function buildCard(wait) {
    var available = String(wait.status || 'waiting') === 'slot_available';
    var provider = String(wait.eligible_provider_name || wait.assigned_provider_name || '').trim();
    var complaint = String(wait.complaint || 'Your complaint is on file.');
    var bookUrl = String(wait.book_url || (base() + '/views/patient/triage.php'));
    var careUrl = String(wait.care_tips_url || (base() + '/views/patient/my_health.php?tab=care-tips'));
    var rec = String(wait.care_tips_status || '');
    var careApproved = rec === 'approved';
    var meta = [];
    if (wait.waiting_since_label) meta.push('Waiting since ' + wait.waiting_since_label);
    else meta.push('Waiting for Provider Availability');
    if (!available && wait.queue_position > 0) {
      meta.push('Queue position ' + wait.queue_position + (wait.waiting_count ? (' of ' + wait.waiting_count) : ''));
    }
    var waitingLead = 'There is currently no available provider consultation slot. Your case is safely in the waiting queue. We will notify you when a provider opens an available consultation schedule.';

    return ''
      + '<section class="pdash-card pdash-card--review pdash-care pdash-wait" id="pdashSlotWait" aria-labelledby="pdashSlotWaitTitle" data-wait-status="' + esc(wait.status || 'waiting') + '">'
      + '  <div class="pdash-care__top">'
      + '    <div class="pdash-care__title-wrap">'
      + '      <span class="pdash-care__icon" aria-hidden="true">'
      + '        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
      + '      </span>'
      + '      <div>'
      + '        <h2 class="pdash-card__title pdash-care__title" id="pdashSlotWaitTitle">' + (available ? 'Consultation Slot Available' : 'Waiting for Provider Availability') + '</h2>'
      + '        <p class="pdash-care__lead" id="pdashSlotWaitLead">' + esc(available
        ? (provider ? (provider + ' has an available consultation schedule.') : 'A consultation slot is now available.')
        : waitingLead) + '</p>'
      + '      </div>'
      + '    </div>'
      + '    <span class="pdash-care__status-chip ' + (available ? 'pdash-care__status-chip--ready' : 'pdash-care__status-chip--wait') + '" id="pdashSlotWaitChip">'
      + (available ? 'Consultation Slot Available' : 'Waiting for Provider Availability')
      + '    </span>'
      + '  </div>'
      + '  <div class="pdash-wait__badge" id="pdashSlotWaitTriageBadge">NON-URGENT — ' + (available ? 'CONSULTATION SLOT AVAILABLE' : 'WAITING FOR PROVIDER AVAILABILITY') + '</div>'
      + '  <div class="pdash-care-panel" role="status">'
      + '    <div class="pdash-care-panel__grid">'
      + '      <div class="pdash-care-concern">'
      + '        <span class="pdash-care-concern__label">Patient complaint</span>'
      + '        <p class="pdash-care-concern__text" id="pdashSlotWaitComplaint">' + esc(complaint) + '</p>'
      + '      </div>'
      + '      <div class="pdash-care-doctor">'
      + '        <span class="pdash-care-doctor__avatar" aria-hidden="true" id="pdashSlotWaitInitials">' + esc(initials(provider)) + '</span>'
      + '        <div class="pdash-care-doctor__body">'
      + '          <span class="pdash-care-doctor__eyebrow" id="pdashSlotWaitProviderEyebrow">' + (available ? 'Available provider' : 'Consultation status') + '</span>'
      + '          <strong class="pdash-care-doctor__name" id="pdashSlotWaitProvider">' + esc(available ? (provider || 'A healthcare provider') : 'Waiting for Provider Availability') + '</strong>'
      + '          <p class="pdash-care-doctor__note" id="pdashSlotWaitMeta">' + esc(meta.join(' · ')) + '</p>'
      + '        </div>'
      + '      </div>'
      + '    </div>'
      + '    <div class="pdash-wait__care" id="pdashSlotWaitCare">' + careHtml(wait, available) + '</div>'
      + '    <div class="pdash-care-actions">'
      + '      <a href="' + esc(careUrl) + '" class="pdash-btn pdash-btn--outline pdash-care-actions__btn" id="pdashSlotWaitCareLink">'
      + (careApproved ? 'View Care Guidance' : 'Track care tips')
      + '      </a>'
      + '      <a href="' + esc(bookUrl) + '" class="pdash-btn pdash-btn--primary pdash-care-actions__btn" id="pdashSlotWaitBook"' + (available ? '' : ' hidden') + '>Book Consultation</a>'
      + '    </div>'
      + '  </div>'
      + '</section>';
  }

  function ensureCard(wait) {
    var card = document.getElementById('pdashSlotWait');
    if (card) return card;
    var mount = document.getElementById('pdashPrimaryCard');
    if (!mount) return null;
    mount.innerHTML = buildCard(wait);
    return document.getElementById('pdashSlotWait');
  }

  function applyState(wait, hasOpenConsultation) {
    if (hasOpenConsultation) {
      if (document.getElementById('pdashSlotWait')) {
        global.location.reload();
      }
      return;
    }

    if (!wait || !wait.active) {
      if (document.getElementById('pdashSlotWait')) {
        global.location.reload();
      }
      return;
    }

    var card = ensureCard(wait);
    if (!card) {
      return;
    }

    var status = String(wait.status || 'waiting');
    var available = status === 'slot_available';
    var provider = String(wait.eligible_provider_name || wait.assigned_provider_name || '').trim();
    card.setAttribute('data-wait-status', status);

    text(document.getElementById('pdashSlotWaitTitle'), available
      ? 'Consultation Slot Available'
      : 'Waiting for Provider Availability');
    text(document.getElementById('pdashSlotWaitLead'), available
      ? (provider ? (provider + ' has an available consultation schedule.') : 'A consultation slot is now available.')
      : 'There is currently no available provider consultation slot. Your case is safely in the waiting queue. We will notify you when a provider opens an available consultation schedule.');

    var chip = document.getElementById('pdashSlotWaitChip');
    if (chip) {
      chip.textContent = available ? 'Consultation Slot Available' : 'Waiting for Provider Availability';
      chip.classList.toggle('pdash-care__status-chip--ready', available);
      chip.classList.toggle('pdash-care__status-chip--wait', !available);
    }

    var badge = document.getElementById('pdashSlotWaitTriageBadge');
    if (badge) {
      badge.textContent = available
        ? 'NON-URGENT — CONSULTATION SLOT AVAILABLE'
        : 'NON-URGENT — WAITING FOR PROVIDER AVAILABILITY';
    }

    text(document.getElementById('pdashSlotWaitProviderEyebrow'), available ? 'Available provider' : 'Consultation status');
    if (wait.complaint) {
      text(document.getElementById('pdashSlotWaitComplaint'), wait.complaint);
    }
    text(document.getElementById('pdashSlotWaitProvider'), available
      ? (provider || 'A healthcare provider')
      : 'Waiting for Provider Availability');
    var avatar = document.getElementById('pdashSlotWaitInitials');
    if (avatar && provider) avatar.textContent = initials(provider);

    var meta = document.getElementById('pdashSlotWaitMeta');
    if (meta) {
      var parts = [];
      if (wait.waiting_since_label) parts.push('Waiting since ' + wait.waiting_since_label);
      else parts.push('Waiting for Provider Availability');
      if (!available && wait.queue_position > 0) {
        parts.push('Queue position ' + wait.queue_position + (wait.waiting_count ? (' of ' + wait.waiting_count) : ''));
      }
      meta.textContent = parts.join(' · ');
    }

    var book = document.getElementById('pdashSlotWaitBook');
    if (book) {
      if (wait.book_url) book.setAttribute('href', wait.book_url);
      book.hidden = !available;
    }

    var careLink = document.getElementById('pdashSlotWaitCareLink');
    if (careLink) {
      if (wait.care_tips_url) careLink.setAttribute('href', wait.care_tips_url);
      careLink.textContent = String(wait.care_tips_status || '') === 'approved'
        ? 'View Care Guidance'
        : 'Track care tips';
    }

    var care = document.getElementById('pdashSlotWaitCare');
    if (care) {
      care.innerHTML = careHtml(wait, available);
    }

    applyHero(wait);
  }

  async function refresh() {
    if (inFlight) return;
    if (
      !document.getElementById('pdashSlotWait')
      && !document.getElementById('pdashPrimaryCard')
      && !document.getElementById('pdashSymptomsReview')
      && !document.getElementById('pdashCareTipsReady')
    ) {
      return;
    }
    inFlight = true;
    try {
      var res = await fetch(base() + '/app/api/patient/booking_state.php?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
      });
      var json = await res.json().catch(function () { return null; });
      if (!json || !json.success) return;
      var payload = json.data || json;
      var wait = payload.waitlist || json.waitlist || payload;
      var hasOpen = !!(payload.has_open_consultation || json.has_open_consultation);
      if (wait && typeof wait.active !== 'undefined') {
        applyState(wait, hasOpen);
      }
    } catch (_) {
      /* ignore transient poll errors */
    } finally {
      inFlight = false;
    }
  }

  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (
      changed.indexOf('slots') !== -1
      || changed.indexOf('schedule') !== -1
      || changed.indexOf('triage') !== -1
      || changed.indexOf('booking_state') !== -1
      || changed.indexOf('appointments') !== -1
    ) {
      refresh();
    }
  });

  global.MedConnectPatientSlotWait = { refresh: refresh };
})(window);
