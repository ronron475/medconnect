(function () {
  'use strict';

  const root = document.getElementById('gis-dashboard');
  if (!root) return;

  const apiUrl = root.dataset.api || '';
  const exportUrl = root.dataset.export || '';
  const userRole = root.dataset.userRole || '';
  const VIEW_KEY = 'medconnect_gis_view';
  const BASE_LAYER_KEY = 'medconnect_gis_base_layer';
  const PAGE_SIZE = 15;

  const SEVERITY = {
    NON_URGENT: 'non_urgent',
    URGENT: 'urgent',
    EMERGENCY: 'emergency',
  };

  const SEVERITY_COLORS = {
    non_urgent: '#22C55E',
    urgent: '#F59E0B',
    emergency: '#EF4444',
  };

  const SEVERITY_LABELS = {
    non_urgent: 'Non-Urgent',
    urgent: 'Urgent',
    emergency: 'Emergency',
  };

  const HEAT_GRADIENTS = {
    non_urgent: { 0.25: '#bbf7d0', 0.55: '#22c55e', 0.85: '#15803d' },
    urgent: { 0.25: '#fef08a', 0.55: '#f59e0b', 0.85: '#ea580c' },
    emergency: { 0.25: '#fdba74', 0.55: '#ef4444', 0.85: '#b91c1c' },
    all: { 0.2: '#22c55e', 0.45: '#facc15', 0.65: '#f59e0b', 0.85: '#ef4444' },
  };

  const HEAT_INTENSITY = {
    non_urgent: 0.35,
    urgent: 0.65,
    emergency: 1.0,
    all: 0.5,
  };

  const POLL_INTERVAL_MS = 15000;

  /** Read canonical triage_level from API — GIS never classifies locally. */
  const GisTriage = {
    levels: SEVERITY,
    read(row) {
      const level = String(row?.triage_level || '').toLowerCase();
      if (level === SEVERITY.EMERGENCY || level === SEVERITY.URGENT || level === SEVERITY.NON_URGENT) {
        return level;
      }
      return SEVERITY.NON_URGENT;
    },
    label(level) {
      return SEVERITY_LABELS[level] || SEVERITY_LABELS.non_urgent;
    },
    badgeClass(level) {
      if (level === SEVERITY.EMERGENCY) return 'gis-badge--emergency';
      if (level === SEVERITY.URGENT) return 'gis-badge--urgent';
      return 'gis-badge--non-urgent';
    },
    filterPatients(patients, layer) {
      if (layer === 'all') return patients || [];
      return (patients || []).filter(function (row) {
        return GisTriage.read(row) === layer;
      });
    },
  };

  const GisData = {
    filtersQuery() {
      return filtersQuery();
    },
    async fetchBundle() {
      return fetchBundle();
    },
    async fetchSync() {
      const params = new URLSearchParams(filtersQuery());
      params.set('action', 'sync');
      params.set('since', state.lastSync);
      const res = await fetch(apiUrl + '?' + params.toString());
      const json = await res.json();
      if (!json.success) return null;
      return json.data || {};
    },
    mergePatients(changed) {
      if (!Array.isArray(changed) || !changed.length) return false;
      const map = new Map(
        state.patients.map(function (p) {
          return [String(p.patient_id), p];
        })
      );
      changed.forEach(function (row) {
        map.set(String(row.patient_id), row);
      });
      state.patients = Array.from(map.values()).sort(function (a, b) {
        const av = new Date(a.registration_date || 0).getTime();
        const bv = new Date(b.registration_date || 0).getTime();
        return bv - av;
      });
      return true;
    },
  };

  const state = {
    view: localStorage.getItem(VIEW_KEY) || 'map',
    patients: [],
    analytics: null,
    triageStats: null,
    sortKey: 'registration_date',
    sortDir: 'desc',
    page: 1,
    heatmapLayer: 'all',
    lastSync: new Date().toISOString(),
    map: null,
    cluster: null,
    heat: null,
    streetLayer: null,
    satelliteLayer: null,
    baseLayer: localStorage.getItem(BASE_LAYER_KEY) === 'satellite' ? 'satellite' : 'street',
    pollTimer: null,
  };

  const els = {
    mapPanel: document.getElementById('gis-map-panel'),
    tablePanel: document.getElementById('gis-table-panel'),
    toggleBtns: root.querySelectorAll('.gis-toggle-btn'),
    tableBody: document.getElementById('gis-table-body'),
    pagination: document.getElementById('gis-pagination'),
    search: document.getElementById('gis-search'),
    province: document.getElementById('gis-filter-province'),
    municipality: document.getElementById('gis-filter-municipality'),
    barangay: document.getElementById('gis-filter-barangay'),
    status: document.getElementById('gis-filter-status'),
    dateFrom: document.getElementById('gis-filter-from'),
    dateTo: document.getElementById('gis-filter-to'),
    exportCsv: document.getElementById('gis-export-csv'),
    exportExcel: document.getElementById('gis-export-excel'),
    printBtn: document.getElementById('gis-print'),
    analyticsBarangay: document.getElementById('analytics-barangay'),
    analyticsEmergency: document.getElementById('analytics-emergency'),
    analyticsConsultations: document.getElementById('analytics-consultations'),
    analyticsSymptoms: document.getElementById('analytics-symptoms'),
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function readTriageLevel(row) {
    return GisTriage.read(row);
  }

  function severityCounts(patients) {
    if (state.triageStats && state.triageStats.counts) {
      return state.triageStats.counts;
    }
    const counts = { non_urgent: 0, urgent: 0, emergency: 0 };
    (patients || []).forEach(function (row) {
      counts[readTriageLevel(row)] += 1;
    });
    return counts;
  }

  function patientsForLayer(layer) {
    return GisTriage.filterPatients(state.patients, layer);
  }

  function topBarangayForLayer(layer) {
    if (state.triageStats && state.triageStats.top_barangay && state.triageStats.top_barangay[layer]) {
      return state.triageStats.top_barangay[layer].display || '—';
    }
    const rows = patientsForLayer(layer);
    const counts = {};
    rows.forEach(function (row) {
      const name = (row.barangay || '').trim() || 'Unknown';
      counts[name] = (counts[name] || 0) + 1;
    });
    let top = '';
    let max = 0;
    Object.keys(counts).forEach(function (name) {
      if (counts[name] > max) {
        max = counts[name];
        top = name;
      }
    });
    if (!top) return '—';
    return top + ' (' + max.toLocaleString() + ')';
  }

  function filtersQuery() {
    const params = new URLSearchParams();
    if (els.search && els.search.value.trim()) params.set('search', els.search.value.trim());
    if (els.province && els.province.value) params.set('province', els.province.value);
    if (els.municipality && els.municipality.value) params.set('municipality', els.municipality.value);
    if (els.barangay && els.barangay.value) params.set('barangay', els.barangay.value);
    if (els.status && els.status.value) params.set('status', els.status.value);
    if (els.dateFrom && els.dateFrom.value) params.set('date_from', els.dateFrom.value);
    if (els.dateTo && els.dateTo.value) params.set('date_to', els.dateTo.value);
    return params.toString();
  }

  async function fetchBundle() {
    const qs = filtersQuery();
    const url = apiUrl + (qs ? '?' + qs : '');
    const res = await fetch(url);
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed to load GIS data');
    return json.data || {};
  }

  async function fetchUpdates() {
    return GisData.fetchSync();
  }

  function pulseLiveIndicator() {
    const el = document.getElementById('gis-live-indicator');
    if (!el) return;
    el.classList.add('is-pulse');
    setTimeout(function () {
      el.classList.remove('is-pulse');
    }, 1200);
  }

  function setView(view) {
    state.view = view;
    localStorage.setItem(VIEW_KEY, view);
    els.toggleBtns.forEach(function (btn) {
      const active = btn.dataset.view === view;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    els.mapPanel.classList.toggle('is-active', view === 'map');
    els.tablePanel.classList.toggle('is-active', view === 'table');
    els.mapPanel.hidden = view !== 'map';
    els.tablePanel.hidden = view !== 'table';
    if (view === 'map' && state.map) {
      setTimeout(function () {
        state.map.invalidateSize();
      }, 120);
    }
  }

  function renderSeverityStats() {
    const counts = severityCounts(state.patients);
    const layer = state.heatmapLayer;

    const nonEl = document.getElementById('stat-non_urgent');
    const urgEl = document.getElementById('stat-urgent');
    const emEl = document.getElementById('stat-emergency');
    const barEl = document.getElementById('stat-top_barangay');

    if (nonEl) nonEl.textContent = counts.non_urgent.toLocaleString();
    if (urgEl) urgEl.textContent = counts.urgent.toLocaleString();
    if (emEl) emEl.textContent = counts.emergency.toLocaleString();
    if (barEl) barEl.textContent = topBarangayForLayer(layer);

    root.querySelectorAll('[data-severity-stat]').forEach(function (card) {
      const key = card.getAttribute('data-severity-stat');
      const highlight =
        (layer === 'all' && key === 'barangay') ||
        (layer !== 'all' && key === layer);
      card.classList.toggle('is-highlight', highlight);
    });
  }

  function fillFilterOptions(patients) {
    const provinces = uniqueValues(patients, 'province');
    const municipalities = uniqueValues(patients, 'municipality');
    const barangays = uniqueValues(patients, 'barangay');
    fillSelect(els.province, provinces, 'All provinces');
    fillSelect(els.municipality, municipalities, 'All municipalities');
    fillSelect(els.barangay, barangays, 'All barangays');
  }

  function uniqueValues(rows, key) {
    return Array.from(
      new Set(
        rows
          .map(function (row) {
            return (row[key] || '').trim();
          })
          .filter(Boolean)
      )
    ).sort();
  }

  function fillSelect(select, values, placeholder) {
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">' + escapeHtml(placeholder) + '</option>';
    values.forEach(function (value) {
      const opt = document.createElement('option');
      opt.value = value;
      opt.textContent = value;
      select.appendChild(opt);
    });
    if (values.includes(current)) select.value = current;
  }

  function sortedPatients() {
    const rows = state.patients.slice();
    const key = state.sortKey;
    const dir = state.sortDir === 'asc' ? 1 : -1;
    rows.sort(function (a, b) {
      const av = (a[key] ?? '').toString().toLowerCase();
      const bv = (b[key] ?? '').toString().toLowerCase();
      if (av < bv) return -1 * dir;
      if (av > bv) return 1 * dir;
      return 0;
    });
    return rows;
  }

  function severityBadgeClass(severity) {
    return GisTriage.badgeClass(severity);
  }

  function renderTable() {
    const rows = sortedPatients();
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    if (state.page > totalPages) state.page = totalPages;
    const start = (state.page - 1) * PAGE_SIZE;
    const pageRows = rows.slice(start, start + PAGE_SIZE);

    if (!pageRows.length) {
      els.tableBody.innerHTML =
        '<tr><td colspan="6" class="gis-table-empty">No patient location records match the current filters.</td></tr>';
    } else {
      els.tableBody.innerHTML = pageRows
        .map(function (row) {
          const statusClass =
            row.patient_status === 'Active' ? 'gis-badge--active' : 'gis-badge--inactive';
          const severity = readTriageLevel(row);
          const severityBadge =
            ' <span class="gis-badge ' +
            severityBadgeClass(severity) +
            '">' +
            escapeHtml(SEVERITY_LABELS[severity]) +
            '</span>';
          return (
            '<tr>' +
            '<td><strong>' +
            escapeHtml(row.patient_name) +
            '</strong><br><span class="text-xs text-muted">ID ' +
            escapeHtml(row.patient_id) +
            '</span>' +
            severityBadge +
            '</td>' +
            '<td>' +
            escapeHtml(row.barangay) +
            '</td>' +
            '<td>' +
            escapeHtml(row.municipality) +
            '</td>' +
            '<td>' +
            escapeHtml(row.province) +
            '</td>' +
            '<td>' +
            escapeHtml(row.registration_date_display || row.registration_date) +
            '</td>' +
            '<td><span class="gis-badge ' +
            statusClass +
            '">' +
            escapeHtml(row.patient_status) +
            '</span></td>' +
            '</tr>'
          );
        })
        .join('');
    }

    els.pagination.innerHTML =
      '<span>Showing ' +
      (rows.length ? start + 1 : 0) +
      '–' +
      Math.min(start + PAGE_SIZE, rows.length) +
      ' of ' +
      rows.length +
      '</span>' +
      '<div><button type="button" id="gis-page-prev"' +
      (state.page <= 1 ? ' disabled' : '') +
      '>Previous</button> ' +
      '<button type="button" id="gis-page-next"' +
      (state.page >= totalPages ? ' disabled' : '') +
      '>Next</button></div>';

    const prev = document.getElementById('gis-page-prev');
    const next = document.getElementById('gis-page-next');
    if (prev) {
      prev.onclick = function () {
        state.page -= 1;
        renderTable();
      };
    }
    if (next) {
      next.onclick = function () {
        state.page += 1;
        renderTable();
      };
    }
  }

  function canShowPatientName() {
    return userRole === 'admin' || userRole === 'superadmin' || userRole === 'provider';
  }

  function canShowCoordinates() {
    return userRole === 'admin' || userRole === 'superadmin';
  }

  function consultationStatusLabel(row) {
    const active = Number(row.active_consultations || 0);
    if (active > 0) {
      return 'Active consultation (' + active + ')';
    }
    return 'No active consultation';
  }

  function popupHtml(row) {
    const severity = readTriageLevel(row);
    const severityLabel = SEVERITY_LABELS[severity];
    const lat = Number(row.latitude);
    const lng = Number(row.longitude);
    let html = '<div class="gis-popup">';

    if (canShowPatientName()) {
      html += '<strong>' + escapeHtml(row.patient_name) + '</strong>';
    } else {
      html += '<strong>Patient #' + escapeHtml(row.patient_id) + '</strong>';
    }

    html +=
      '<p><span class="gis-badge ' +
      severityBadgeClass(severity) +
      '">' +
      escapeHtml(severityLabel) +
      '</span></p>' +
      '<p><strong>Barangay:</strong> ' +
      escapeHtml(row.barangay || '—') +
      '</p>' +
      '<p><strong>Consultation status:</strong> ' +
      escapeHtml(consultationStatusLabel(row)) +
      '</p>' +
      '<p><strong>Date created:</strong> ' +
      escapeHtml(row.registration_date_display || row.registration_date || '—') +
      '</p>' +
      '<p><strong>Assigned BHW:</strong> ' +
      escapeHtml(row.assigned_bhw || 'Not assigned') +
      '</p>' +
      '<p><strong>Assigned doctor:</strong> ' +
      escapeHtml(row.assigned_doctor || 'Not assigned') +
      '</p>';

    if (canShowCoordinates() && isValidCoord(lat, lng)) {
      html +=
        '<p><strong>Coordinates:</strong> ' +
        escapeHtml(lat.toFixed(6) + ', ' + lng.toFixed(6)) +
        '</p>';
    }

    html += '</div>';
    return html;
  }

  function latLngToTile(lat, lng, zoom) {
    const z = zoom;
    const n = Math.pow(2, z);
    const x = Math.floor(((lng + 180) / 360) * n);
    const latRad = (lat * Math.PI) / 180;
    const y = Math.floor(
      ((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * n
    );
    return { x: x, y: y, z: z };
  }

  function previewTileUrl(layerType) {
    const center = { lat: 10.5378, lng: 122.8383 };
    const tile = latLngToTile(center.lat, center.lng, 12);
    if (layerType === 'satellite') {
      return (
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/' +
        tile.z +
        '/' +
        tile.y +
        '/' +
        tile.x
      );
    }
    return 'https://tile.openstreetmap.org/' + tile.z + '/' + tile.x + '/' + tile.y + '.png';
  }

  function updateLayerSwitchPreview() {
    const btn = document.getElementById('gisMapLayerSwitch');
    const thumb = document.getElementById('gisMapLayerThumb');
    const label = document.getElementById('gisMapLayerLabel');
    if (!btn || !thumb) return;

    const nextLayer = state.baseLayer === 'street' ? 'satellite' : 'street';
    const nextLabel = nextLayer === 'satellite' ? 'Satellite' : 'Map';
    thumb.style.backgroundImage = "url('" + previewTileUrl(nextLayer) + "')";
    if (label) label.textContent = nextLabel;
    btn.setAttribute('aria-label', 'Switch to ' + nextLabel + ' view');
    btn.setAttribute('title', 'Switch to ' + nextLabel);
  }

  function applyBaseLayer(layerName) {
    if (!state.map || !state.streetLayer || !state.satelliteLayer) return;

    const target = layerName === 'satellite' ? 'satellite' : 'street';

    if (state.map.hasLayer(state.streetLayer)) {
      state.map.removeLayer(state.streetLayer);
    }
    if (state.map.hasLayer(state.satelliteLayer)) {
      state.map.removeLayer(state.satelliteLayer);
    }

    if (target === 'satellite') {
      state.satelliteLayer.addTo(state.map);
    } else {
      state.streetLayer.addTo(state.map);
    }

    state.baseLayer = target;
    localStorage.setItem(BASE_LAYER_KEY, target);
    updateLayerSwitchPreview();
    ensureOverlayOrder();
  }

  function toggleBaseLayer() {
    applyBaseLayer(state.baseLayer === 'street' ? 'satellite' : 'street');
  }

  function ensureOverlayOrder() {
    if (state.heat && state.map.hasLayer(state.heat)) {
      state.heat.bringToFront();
    }
    if (state.cluster) {
      state.cluster.bringToFront();
    }
  }

  function initLayerSwitch() {
    const btn = document.getElementById('gisMapLayerSwitch');
    if (!btn || btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', toggleBaseLayer);
    updateLayerSwitchPreview();
  }

  function severityMarkerIcon(severity) {
    const color = SEVERITY_COLORS[severity] || SEVERITY_COLORS.non_urgent;
    return L.divIcon({
      className: 'gis-severity-marker',
      html:
        '<div style="background:' +
        color +
        ';width:14px;height:14px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 2px ' +
        color +
        '99"></div>',
      iconSize: [14, 14],
      iconAnchor: [7, 7],
    });
  }

  function initMap() {
    if (state.map || typeof L === 'undefined') return;
    state.map = L.map('gis-map', { scrollWheelZoom: true }).setView([10.5378, 122.8383], 12);

    state.streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    });

    state.satelliteLayer = L.tileLayer(
      'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics',
      }
    );

    applyBaseLayer(state.baseLayer);

    state.cluster = L.markerClusterGroup({
      showCoverageOnHover: false,
      maxClusterRadius: 48,
    });
    state.map.addLayer(state.cluster);
    initLayerSwitch();
  }

  function isValidCoord(lat, lng) {
    return (
      Number.isFinite(lat) &&
      Number.isFinite(lng) &&
      lat >= -90 &&
      lat <= 90 &&
      lng >= -180 &&
      lng <= 180 &&
      !(lat === 0 && lng === 0)
    );
  }

  function heatPoints(layerName) {
    const rows = patientsForLayer(layerName);
    const baseIntensity = HEAT_INTENSITY[layerName] || HEAT_INTENSITY.all;

    if (layerName === 'all') {
      return rows
        .map(function (row) {
          const severity = readTriageLevel(row);
          let weight = baseIntensity;
          if (severity === SEVERITY.URGENT) weight = 0.65;
          if (severity === SEVERITY.EMERGENCY) weight = 1.0;
          return [Number(row.latitude), Number(row.longitude), weight];
        })
        .filter(function (point) {
          return isValidCoord(point[0], point[1]);
        });
    }

    return rows
      .map(function (row) {
        return [Number(row.latitude), Number(row.longitude), baseIntensity];
      })
      .filter(function (point) {
        return isValidCoord(point[0], point[1]);
      });
  }

  function renderMap() {
    initMap();
    if (!state.map || !state.cluster) return;

    state.cluster.clearLayers();
    const bounds = [];
    const layerName = state.heatmapLayer;
    const visiblePatients = patientsForLayer(layerName);

    visiblePatients.forEach(function (row) {
      const lat = Number(row.latitude);
      const lng = Number(row.longitude);
      if (!isValidCoord(lat, lng)) return;
      bounds.push([lat, lng]);
      const severity = readTriageLevel(row);
      const marker = L.marker([lat, lng], { icon: severityMarkerIcon(severity) });
      marker.bindPopup(popupHtml(row));
      state.cluster.addLayer(marker);
    });

    if (state.heat) {
      state.map.removeLayer(state.heat);
      state.heat = null;
    }

    const points = heatPoints(layerName);
    if (points.length && typeof L.heatLayer === 'function') {
      const gradient = HEAT_GRADIENTS[layerName] || HEAT_GRADIENTS.all;
      state.heat = L.heatLayer(points, {
        radius: layerName === 'emergency' ? 32 : 28,
        blur: layerName === 'non_urgent' ? 22 : 18,
        maxZoom: 14,
        gradient: gradient,
      });
      state.map.addLayer(state.heat);
    }

    ensureOverlayOrder();

    if (bounds.length) {
      state.map.fitBounds(bounds, { padding: [36, 36], maxZoom: 14 });
    } else {
      state.map.setView([10.5378, 122.8383], 12);
    }

    setTimeout(function () {
      state.map.invalidateSize();
    }, 120);
  }

  function aggregateByBarangay(rows, predicate) {
    const counts = {};
    (rows || []).forEach(function (row) {
      if (predicate && !predicate(row)) return;
      const name = (row.barangay || '').trim() || 'Unknown';
      counts[name] = (counts[name] || 0) + 1;
    });
    return Object.keys(counts)
      .map(function (name) {
        return { label: name, barangay: name, count: counts[name] };
      })
      .sort(function (a, b) {
        return b.count - a.count;
      });
  }

  function buildSymptomsByBarangay(rows) {
    const map = {};
    (rows || []).forEach(function (row) {
      const barangay = (row.barangay || '').trim() || 'Unknown';
      const symptom = (row.triage_label || 'Not triaged').trim() || 'Not triaged';
      const key = barangay + '|' + symptom;
      if (!map[key]) {
        map[key] = { barangay: barangay, label: symptom, count: 0 };
      }
      map[key].count += 1;
    });
    return Object.values(map).sort(function (a, b) {
      return b.count - a.count;
    });
  }

  function layerCaption() {
    const layer = state.heatmapLayer;
    if (layer === 'non_urgent') return 'Showing Non-Urgent cases';
    if (layer === 'urgent') return 'Showing Urgent cases';
    if (layer === 'emergency') return 'Showing Emergency cases';
    return 'Showing all severity levels';
  }

  function buildClientAnalytics() {
    const visible = patientsForLayer(state.heatmapLayer);
    return {
      by_barangay: aggregateByBarangay(visible),
      emergencies: aggregateByBarangay(state.patients, function (row) {
        return readTriageLevel(row) === SEVERITY.EMERGENCY;
      }),
      consultations: aggregateByBarangay(state.patients, function (row) {
        return Number(row.active_consultations || 0) > 0;
      }),
      symptoms: buildSymptomsByBarangay(visible),
    };
  }

  function updateAnalyticsCaptions() {
    const caption = layerCaption();
    root.querySelectorAll('[data-analytics-caption]').forEach(function (el) {
      el.textContent = caption;
    });
  }

  function renderClientAnalytics() {
    const analytics = buildClientAnalytics();
    updateAnalyticsCaptions();
    renderRankList(els.analyticsBarangay, analytics.by_barangay || [], 'label');
    renderRankList(els.analyticsEmergency, analytics.emergencies || [], 'barangay');
    renderRankList(els.analyticsConsultations, analytics.consultations || [], 'barangay');
    renderRankList(
      els.analyticsSymptoms,
      (analytics.symptoms || []).map(function (item) {
        return {
          label: (item.barangay || 'Unknown') + ' — ' + (item.label || 'Not triaged'),
          count: item.count,
        };
      }),
      'label'
    );
  }

  function renderRankList(container, rows, labelKey) {
    if (!container) return;
    if (!rows.length) {
      container.innerHTML = '<li class="gis-rank-list__empty">No records for the current filter.</li>';
      return;
    }
    container.innerHTML = rows
      .slice(0, 8)
      .map(function (row, index) {
        return (
          '<li><span><span class="gis-rank-list__rank">' +
          (index + 1) +
          '.</span> ' +
          escapeHtml(row[labelKey] || row.label || '—') +
          '</span><strong>' +
          Number(row.count || 0).toLocaleString() +
          '</strong></li>'
        );
      })
      .join('');
  }

  function refreshDashboard() {
    renderSeverityStats();
    renderTable();
    try {
      renderMap();
    } catch (mapErr) {
      console.error('GIS map render failed:', mapErr);
    }
    renderClientAnalytics();
  }

  async function loadAll() {
    try {
      const data = await fetchBundle();
      state.patients = data.patients || [];
      state.analytics = data.analytics || null;
      state.triageStats = data.triage_stats || data.summary?.triage_stats || null;
      state.lastSync = data.server_ts || new Date().toISOString();
      fillFilterOptions(state.patients);
      refreshDashboard();
    } catch (err) {
      console.error(err);
      if (els.tableBody) {
        els.tableBody.innerHTML =
          '<tr><td colspan="6" class="gis-table-empty">Unable to load GIS dashboard data.</td></tr>';
      }
    }
  }

  async function pollUpdates() {
    try {
      const data = await fetchUpdates();
      if (!data) return;

      let changed = false;
      const updates = data.changed || data.patients || [];
      if (GisData.mergePatients(updates)) {
        changed = true;
        fillFilterOptions(state.patients);
      }

      if (data.triage_stats) {
        state.triageStats = data.triage_stats;
        changed = true;
      }

      if (changed) {
        refreshDashboard();
        pulseLiveIndicator();
      }

      state.lastSync = data.server_ts || new Date().toISOString();
    } catch (e) {
      /* silent poll failure */
    }
  }

  function bindEvents() {
    els.toggleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setView(btn.dataset.view || 'map');
      });
    });

    [els.search, els.province, els.municipality, els.barangay, els.status, els.dateFrom, els.dateTo].forEach(
      function (el) {
        if (!el) return;
        el.addEventListener('change', function () {
          state.page = 1;
          loadAll();
        });
        if (el === els.search) {
          el.addEventListener(
            'input',
            debounce(function () {
              state.page = 1;
              loadAll();
            }, 350)
          );
        }
      }
    );

    root.querySelectorAll('.gis-table th[data-sort]').forEach(function (th) {
      th.addEventListener('click', function () {
        const key = th.dataset.sort;
        if (state.sortKey === key) {
          state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
          state.sortKey = key;
          state.sortDir = 'asc';
        }
        renderTable();
      });
    });

    root.querySelectorAll('input[name="heatmap-layer"]').forEach(function (input) {
      input.addEventListener('change', function () {
        state.heatmapLayer = input.value;
        renderSeverityStats();
        try {
          renderMap();
        } catch (mapErr) {
          console.error('GIS map render failed:', mapErr);
        }
        renderClientAnalytics();
      });
    });

    if (els.exportCsv) {
      els.exportCsv.addEventListener('click', function () {
        window.location.href = exportUrl + '?' + filtersQuery();
      });
    }
    if (els.exportExcel) {
      els.exportExcel.addEventListener('click', function () {
        window.location.href = exportUrl + '?format=excel&' + filtersQuery();
      });
    }
    if (els.printBtn) {
      els.printBtn.addEventListener('click', function () {
        setView('table');
        window.print();
      });
    }
  }

  function debounce(fn, wait) {
    let timer;
    return function () {
      clearTimeout(timer);
      timer = setTimeout(fn, wait);
    };
  }

  setView(state.view);
  bindEvents();
  loadAll();
  state.pollTimer = setInterval(pollUpdates, POLL_INTERVAL_MS);
})();
