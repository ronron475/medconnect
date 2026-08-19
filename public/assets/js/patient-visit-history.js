/**
 * Client-side Visit History filters on Book Consultation.
 */
(function () {
  'use strict';

  var filters = document.getElementById('visitHistoryFilters');
  var table = document.getElementById('visitHistoryTable');
  if (!filters || !table) return;

  var searchEl = document.getElementById('visitHistorySearch');
  var statusEl = document.getElementById('visitHistoryStatus');
  var decisionEl = document.getElementById('visitHistoryDecision');
  var dateEl = document.getElementById('visitHistoryDate');
  var countEl = document.getElementById('visitHistoryCount');
  var clearEl = document.getElementById('visitHistoryClear');
  var noneEl = document.getElementById('visitHistoryNone');
  var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-status]'));

  function daysAgo(n) {
    var d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() - n);
    return d;
  }

  function parseYmd(value) {
    if (!value) return null;
    var parts = String(value).split('-');
    if (parts.length !== 3) return null;
    return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
  }

  function applyFilters() {
    var query = (searchEl && searchEl.value ? searchEl.value : '').trim().toLowerCase();
    var status = statusEl ? statusEl.value : 'all';
    var decision = decisionEl ? decisionEl.value : 'all';
    var dateMode = dateEl ? dateEl.value : 'all';
    var year = new Date().getFullYear();
    var cutoff7 = daysAgo(7);
    var cutoff30 = daysAgo(30);
    var shown = 0;

    rows.forEach(function (row) {
      var concern = row.getAttribute('data-concern') || '';
      var rowStatus = row.getAttribute('data-status') || '';
      var rowDecision = row.getAttribute('data-decision') || '';
      var rowDate = parseYmd(row.getAttribute('data-date') || '');
      var ok = true;

      if (query && concern.indexOf(query) === -1) ok = false;

      if (ok && status !== 'all') {
        if (status === 'open') {
          ok = rowStatus !== 'booked' && rowStatus !== 'completed' && rowStatus !== 'emergency';
        } else {
          ok = rowStatus === status;
        }
      }

      if (ok && decision !== 'all') {
        ok = rowDecision === decision;
      }

      if (ok && dateMode !== 'all' && rowDate) {
        if (dateMode === '7') ok = rowDate >= cutoff7;
        else if (dateMode === '30') ok = rowDate >= cutoff30;
        else if (dateMode === 'year') ok = rowDate.getFullYear() === year;
      } else if (ok && dateMode !== 'all' && !rowDate) {
        ok = false;
      }

      row.hidden = !ok;
      if (ok) shown += 1;
    });

    if (noneEl) noneEl.hidden = shown !== 0;
    if (countEl) {
      countEl.textContent = shown === rows.length
        ? shown + ' visit' + (shown === 1 ? '' : 's')
        : 'Showing ' + shown + ' of ' + rows.length;
    }

    var active = query !== '' || status !== 'all' || decision !== 'all' || dateMode !== 'all';
    if (clearEl) clearEl.hidden = !active;
  }

  function clearFilters() {
    if (searchEl) searchEl.value = '';
    if (statusEl) statusEl.value = 'all';
    if (decisionEl) decisionEl.value = 'all';
    if (dateEl) dateEl.value = 'all';
    applyFilters();
    if (searchEl) searchEl.focus();
  }

  [searchEl, statusEl, decisionEl, dateEl].forEach(function (el) {
    if (!el) return;
    el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', applyFilters);
  });
  if (clearEl) clearEl.addEventListener('click', clearFilters);

  applyFilters();
})();
