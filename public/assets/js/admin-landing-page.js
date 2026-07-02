(function () {
  'use strict';

  var api = document.body.dataset.lpApi;
  if (!api) return;

  var toastEl = document.getElementById('lpToast');
  var previewHero = document.getElementById('lpPreviewHero');
  var previewTitle = document.getElementById('lpPreviewTitle');
  var previewSub = document.getElementById('lpPreviewSub');
  var previewAnn = document.getElementById('lpPreviewAnn');
  var previewUpdated = document.getElementById('lpPreviewUpdated');

  function qs(id) { return document.getElementById(id); }

  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('is-visible');
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { toastEl.classList.remove('is-visible'); }, 2800);
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function fmtDate(s) {
    if (!s) return '—';
    var d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d.getTime()) ? s : d.toLocaleString();
  }

  function setStat(id, val) {
    var el = qs(id);
    if (el) el.textContent = val == null ? '—' : String(val);
  }

  function updatePreview(data) {
    var hero = data.hero || {};
    var cfg = data.config || {};
    if (previewHero) previewHero.style.backgroundImage = "url('" + esc(hero.bg_image || '') + "')";
    if (previewTitle) {
      previewTitle.innerHTML = '<span class="accent">' + esc(hero.accent || cfg.LANDING_HERO_ACCENT) + '</span> '
        + esc(hero.line1 || cfg.LANDING_HERO_LINE1) + '<br>'
        + esc(hero.line2 || cfg.LANDING_HERO_LINE2);
    }
    if (previewSub) previewSub.textContent = hero.subheading || cfg.LANDING_HERO_SUBHEADING || '';
    if (previewUpdated) {
      previewUpdated.textContent = data.stats && data.stats.last_updated
        ? 'Last updated ' + fmtDate(data.stats.last_updated) + (data.stats.last_updated_by ? ' by ' + data.stats.last_updated_by : '')
        : 'Not updated yet';
    }
    if (previewAnn) {
      var items = data.recent || [];
      var published = items.filter(function (r) { return r.status === 'published'; }).slice(0, 2);
      if (!published.length) {
        previewAnn.innerHTML = '<p class="lp-mgmt__preview-ann-item">No published announcements yet.</p>';
      } else {
        previewAnn.innerHTML = published.map(function (r) {
          return '<div class="lp-mgmt__preview-ann-item"><strong>' + esc(r.title) + '</strong><br>'
            + esc(fmtDate(r.publish_at || r.updated_at)) + '</div>';
        }).join('');
      }
    }
  }

  function fillForms(data) {
    var cfg = data.config || {};
    if (qs('heroAccent')) qs('heroAccent').value = cfg.LANDING_HERO_ACCENT || '';
    if (qs('heroLine1')) qs('heroLine1').value = cfg.LANDING_HERO_LINE1 || '';
    if (qs('heroLine2')) qs('heroLine2').value = cfg.LANDING_HERO_LINE2 || '';
    if (qs('heroSubheading')) qs('heroSubheading').value = cfg.LANDING_HERO_SUBHEADING || '';
    if (qs('heroBgImage')) qs('heroBgImage').value = cfg.LANDING_HERO_BG_IMAGE || '';
    if (qs('heroAnimation')) qs('heroAnimation').checked = cfg.LANDING_HERO_ANIMATION === '1';
    if (qs('sectionAnnouncements')) qs('sectionAnnouncements').checked = cfg.LANDING_SECTION_ANNOUNCEMENTS === '1';
    if (qs('sectionServices')) qs('sectionServices').checked = cfg.LANDING_SECTION_SERVICES === '1';
    if (qs('sectionHowItWorks')) qs('sectionHowItWorks').checked = cfg.LANDING_SECTION_HOW_IT_WORKS === '1';
    if (qs('sectionContact')) qs('sectionContact').checked = cfg.LANDING_SECTION_CONTACT === '1';
    if (qs('maintenanceBanner')) qs('maintenanceBanner').checked = cfg.LANDING_MAINTENANCE_BANNER === '1';
    if (qs('maintenanceMessage')) qs('maintenanceMessage').value = cfg.LANDING_MAINTENANCE_MESSAGE || '';
  }

  function renderActivity(items) {
    var tbody = qs('lpActivityBody');
    if (!tbody) return;
    if (!items || !items.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="mc-table-empty">No recent announcement activity.</td></tr>';
      return;
    }
    tbody.innerHTML = items.map(function (row) {
      return '<tr>'
        + '<td><strong>' + esc(row.title) + '</strong></td>'
        + '<td>' + esc(row.author_name || '—') + '</td>'
        + '<td>' + esc(fmtDate(row.updated_at || row.created_at)) + '</td>'
        + '<td><span class="lp-mgmt__status is-' + esc(row.status) + '">' + esc(row.status) + '</span></td>'
        + '</tr>';
    }).join('');
  }

  function loadDashboard() {
    return fetch(api + '?action=stats', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.success) throw new Error(j.message || 'Failed to load dashboard.');
        var s = j.stats || {};
        setStat('statTotal', s.announcements_total);
        setStat('statPublished', s.announcements_published);
        setStat('statDrafts', s.announcements_drafts);
        setStat('statFeatured', s.announcements_featured);
        setStat('statActive', s.announcements_active);
        setStat('statMedia', s.media_count);
        setStat('statVisits', s.homepage_visits == null ? 'N/A' : s.homepage_visits);
        fillForms(j);
        updatePreview(j);
        renderActivity(j.recent);
        return j;
      });
  }

  function postForm(action, form) {
    var fd = new FormData(form);
    fd.append('action', action);
    return fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.success) throw new Error(j.message || 'Save failed.');
        toast(j.message || 'Saved.');
        return loadDashboard();
      })
      .catch(function (err) {
        toast(err.message || 'Request failed.');
      });
  }

  var heroForm = qs('lpHeroForm');
  if (heroForm) {
    heroForm.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('save_hero', heroForm);
    });
    heroForm.querySelectorAll('input, textarea').forEach(function (el) {
      el.addEventListener('input', function () {
        updatePreview({
          config: {
            LANDING_HERO_ACCENT: qs('heroAccent').value,
            LANDING_HERO_LINE1: qs('heroLine1').value,
            LANDING_HERO_LINE2: qs('heroLine2').value,
            LANDING_HERO_SUBHEADING: qs('heroSubheading').value,
            LANDING_HERO_BG_IMAGE: qs('heroBgImage').value,
          },
          hero: {
            accent: qs('heroAccent').value,
            line1: qs('heroLine1').value,
            line2: qs('heroLine2').value,
            subheading: qs('heroSubheading').value,
            bg_image: (document.body.dataset.assetBase || '') + '/' + qs('heroBgImage').value.replace(/^\//, ''),
          },
          recent: [],
          stats: {},
        });
      });
    });
  }

  var settingsForm = qs('lpSettingsForm');
  if (settingsForm) {
    settingsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm('save_settings', settingsForm);
    });
  }

  var refreshBtn = qs('lpRefreshCache');
  if (refreshBtn) {
    refreshBtn.addEventListener('click', function () {
      var base = document.body.dataset.publicLanding || '/index.php';
      var iframe = document.createElement('iframe');
      iframe.style.display = 'none';
      iframe.src = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'v=' + Date.now();
      document.body.appendChild(iframe);
      setTimeout(function () { iframe.remove(); }, 3000);
      toast('Landing page cache refreshed.');
    });
  }

  loadDashboard().catch(function (err) { toast(err.message); });
})();
