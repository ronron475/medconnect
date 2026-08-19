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

  const LOCATION_SOURCE = {
    gps: {
      label: 'GPS (Exact)',
      hint: 'Verified device GPS coordinates',
      badgeClass: 'gis-loc-badge--gps',
      markerStyle: 'exact',
    },
    address_geocoded: {
      label: 'Address (Geocoded)',
      hint: 'Derived from the registered address — not GPS',
      badgeClass: 'gis-loc-badge--geocoded',
      markerStyle: 'geocoded',
    },
    barangay_center: {
      label: 'Approximate (Barangay)',
      hint: 'Verified barangay center — not the exact patient address',
      badgeClass: 'gis-loc-badge--approx',
      markerStyle: 'approx',
    },
    barangay_centroid: {
      label: 'Approximate (Barangay)',
      hint: 'Verified barangay center — not the exact patient address',
      badgeClass: 'gis-loc-badge--approx',
      markerStyle: 'approx',
    },
    manual: {
      label: 'GPS (Exact)',
      hint: 'Manually verified coordinates',
      badgeClass: 'gis-loc-badge--gps',
      markerStyle: 'exact',
    },
    imported: {
      label: 'GPS (Exact)',
      hint: 'Imported verified coordinates',
      badgeClass: 'gis-loc-badge--gps',
      markerStyle: 'exact',
    },
    unavailable: {
      label: 'Location unavailable',
      hint: 'No verified patient location could be determined',
      badgeClass: 'gis-loc-badge--unavailable',
      markerStyle: 'unavailable',
    },
  };

  const POLL_INTERVAL_MS = 15000;

  const DEFAULT_MAP_CONFIG = {
    center: { lat: 10.538797, lng: 122.838447 },
    bounds: { south: 10.478, west: 122.748, north: 10.598, east: 122.898 },
    default_zoom: 12,
    min_zoom: 11,
    city: 'Bago City',
    province: 'Negros Occidental',
  };

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
    selectedBarangay: '',
    barangaySort: 'total',
    barangaySearch: '',
    lastSync: new Date().toISOString(),
    map: null,
    cluster: null,
    heat: null,
    streetLayer: null,
    satelliteLayer: null,
    baseLayer: localStorage.getItem(BASE_LAYER_KEY) === 'satellite' ? 'satellite' : 'street',
    pollTimer: null,
    mapConfig: null,
    satelliteTileErrors: 0,
    monitoring: null,
    populationByBarangay: {},
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
    mapBarangay: document.getElementById('gis-map-barangay'),
    brgySearch: document.getElementById('gis-brgy-search'),
    brgySort: document.getElementById('gis-brgy-sort'),
    brgyBody: document.getElementById('gis-brgy-body'),
    brgyDetail: document.getElementById('gis-brgy-detail'),
    status: document.getElementById('gis-filter-status'),
    dateFrom: document.getElementById('gis-filter-from'),
    dateTo: document.getElementById('gis-filter-to'),
    exportCsv: document.getElementById('gis-export-csv'),
    exportExcel: document.getElementById('gis-export-excel'),
    printBtn: document.getElementById('gis-print'),
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

  function severityCounts() {
    if (!state.selectedBarangay && state.heatmapLayer === 'all' && state.triageStats && state.triageStats.counts) {
      return state.triageStats.counts;
    }
    const counts = { non_urgent: 0, urgent: 0, emergency: 0 };
    patientsForLayer('all').forEach(function (row) {
      counts[readTriageLevel(row)] += 1;
    });
    return counts;
  }

  function patientBarangayName(row) {
    return (row.barangay || '').trim() || 'Unknown';
  }

  function patientsForLayer(layer) {
    let rows = GisTriage.filterPatients(state.patients, layer);
    if (state.selectedBarangay) {
      rows = rows.filter(function (row) {
        return patientBarangayName(row) === state.selectedBarangay;
      });
    }
    return rows;
  }

  function filtersQuery() {
    const params = new URLSearchParams();
    if (els.search && els.search.value.trim()) params.set('search', els.search.value.trim());
    if (els.province && els.province.value) params.set('province', els.province.value);
    if (els.municipality && els.municipality.value) params.set('municipality', els.municipality.value);
    if (els.status && els.status.value) params.set('status', els.status.value);
    if (els.dateFrom && els.dateFrom.value) params.set('date_from', els.dateFrom.value);
    if (els.dateTo && els.dateTo.value) params.set('date_to', els.dateTo.value);
    return params.toString();
  }

  async function fetchBundle() {
    const params = new URLSearchParams(filtersQuery());
    params.set('action', 'bundle');
    const res = await fetch(apiUrl + '?' + params.toString());
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
      setTimeout(invalidateMapSize, 120);
    }
  }

  function renderSeverityStats() {
    const counts = severityCounts();
    const layer = state.heatmapLayer;

    const nonEl = document.getElementById('stat-non_urgent');
    const urgEl = document.getElementById('stat-urgent');
    const emEl = document.getElementById('stat-emergency');

    if (nonEl) nonEl.textContent = counts.non_urgent.toLocaleString();
    if (urgEl) urgEl.textContent = counts.urgent.toLocaleString();
    if (emEl) emEl.textContent = counts.emergency.toLocaleString();
    renderInsightStats();

    root.querySelectorAll('[data-severity-stat]').forEach(function (card) {
      const key = card.getAttribute('data-severity-stat');
      const highlight = layer !== 'all' && key === layer;
      card.classList.toggle('is-highlight', highlight);
    });
  }

  function renderInsightStats() {
    const monitoring = currentMonitoring();
    const brgyCountEl = document.getElementById('stat-barangays_with_cases');
    if (brgyCountEl) {
      brgyCountEl.textContent = Number(
        monitoring.barangays_with_cases || (monitoring.barangays || []).length || 0
      ).toLocaleString();
    }
  }

  function currentMonitoring() {
    try {
      const client = buildClientMonitoring();
      if ((client.barangays || []).length > 0) {
        return client;
      }
    } catch (err) {
      console.error('GIS barangay aggregation failed:', err);
    }
    if (state.monitoring && Array.isArray(state.monitoring.barangays) && state.monitoring.barangays.length) {
      return state.monitoring;
    }
    return {
      barangays: [],
      hotspots: [],
      new_cases_today: 0,
      unmapped_cases: 0,
      unassigned_bhw: 0,
      barangays_with_cases: 0,
    };
  }

  function rowDateStamp(row) {
    const raw = String(row.registration_date || '');
    if (!raw) return '';
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) return raw.slice(0, 10);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function barangayStatusForCounts(stats) {
    const emergency = stats.emergency || 0;
    const urgent = stats.urgent || 0;
    const recent = stats.recent_7d || 0;
    const total = stats.total || 0;
    if (emergency >= 2 || (emergency >= 1 && urgent >= 2)) return 'critical';
    if (emergency >= 1 || urgent >= 2) return 'elevated';
    if (urgent >= 1 || recent >= 2 || total >= 3) return 'monitoring';
    return 'stable';
  }

  function statusLabel(status) {
    if (status === 'critical') return 'Critical';
    if (status === 'elevated') return 'Elevated';
    if (status === 'monitoring') return 'Monitoring';
    return 'Stable';
  }

  function buildClientMonitoring() {
    const popMap = state.populationByBarangay || {};
    const populationAvailable = Object.keys(popMap).some(function (key) {
      return Number(popMap[key]) > 0;
    });
    const today = new Date();
    const todayStamp =
      today.getFullYear() +
      '-' +
      String(today.getMonth() + 1).padStart(2, '0') +
      '-' +
      String(today.getDate()).padStart(2, '0');
    const weekAgo = new Date(today.getTime() - 7 * 24 * 60 * 60 * 1000);
    const weekStamp =
      weekAgo.getFullYear() +
      '-' +
      String(weekAgo.getMonth() + 1).padStart(2, '0') +
      '-' +
      String(weekAgo.getDate()).padStart(2, '0');

    const barangays = {};
    let newToday = 0;
    let unmapped = 0;
    let invalid = 0;
    let unassignedBhw = 0;
    let exact = 0;
    let geocoded = 0;
    let barangayLevel = 0;

    (state.patients || []).forEach(function (row) {
      const name = (row.barangay || '').trim() || 'Unknown';
      if (!barangays[name]) {
        barangays[name] = {
          name: name,
          total: 0,
          non_urgent: 0,
          urgent: 0,
          emergency: 0,
          recent_7d: 0,
          unassigned_bhw: 0,
          unmapped: 0,
          mapped: 0,
          gps: 0,
          assigned_bhw_names: {},
          lat_min: null,
          lat_max: null,
          lng_min: null,
          lng_max: null,
          active_consultations: 0,
        };
      }
      const stats = barangays[name];
      stats.total += 1;
      stats.active_consultations += Number(row.active_consultations || 0);
      const level = readTriageLevel(row);
      if (level === SEVERITY.EMERGENCY) stats.emergency += 1;
      else if (level === SEVERITY.URGENT) stats.urgent += 1;
      else stats.non_urgent += 1;

      const created = rowDateStamp(row);
      if (created === todayStamp) newToday += 1;
      if (created && created >= weekStamp) stats.recent_7d += 1;

      const bhw = String(row.assigned_bhw || '').trim();
      if (!bhw || bhw.toLowerCase() === 'not assigned') {
        unassignedBhw += 1;
        stats.unassigned_bhw += 1;
      } else {
        stats.assigned_bhw_names[bhw] = true;
      }

      const quality = String(row.location_quality || '').toUpperCase();
      const accuracy = String(row.location_accuracy || '').toLowerCase();
      const source = String(row.location_source || '').toLowerCase();
      const hasMarker = !!row.has_map_marker && isValidCoord(Number(row.latitude), Number(row.longitude));
      if (source === 'gps' || source === 'manual' || source === 'imported' || accuracy === 'exact') {
        stats.gps += 1;
      }
      if (quality === 'INVALID_LOCATION' || accuracy === 'invalid') invalid += 1;
      if (!hasMarker || quality === 'MISSING_LOCATION' || accuracy === 'unavailable') {
        unmapped += 1;
        stats.unmapped += 1;
      } else {
        stats.mapped += 1;
        const lat = Number(row.latitude);
        const lng = Number(row.longitude);
        if (isValidCoord(lat, lng)) {
          stats.lat_min = stats.lat_min === null ? lat : Math.min(stats.lat_min, lat);
          stats.lat_max = stats.lat_max === null ? lat : Math.max(stats.lat_max, lat);
          stats.lng_min = stats.lng_min === null ? lng : Math.min(stats.lng_min, lng);
          stats.lng_max = stats.lng_max === null ? lng : Math.max(stats.lng_max, lng);
        }
        if (quality === 'EXACT_LOCATION' || accuracy === 'exact') {
          exact += 1;
        } else if (quality === 'GEOCODED_LOCATION' || accuracy === 'geocoded') {
          geocoded += 1;
        } else {
          barangayLevel += 1;
        }
      }
    });

    const list = Object.keys(barangays).map(function (name) {
      const stats = barangays[name];
      const pop = Number(popMap[name]) > 0 ? Number(popMap[name]) : null;
      stats.population = pop;
      stats.rate_per_1000 = pop ? Math.round((stats.total / pop) * 100000) / 100 : null;
      stats.status = barangayStatusForCounts(stats);
      stats.status_label = statusLabel(stats.status);
      stats.is_hotspot = stats.status === 'critical' || stats.status === 'elevated';
      stats.assigned_bhw_count = Object.keys(stats.assigned_bhw_names || {}).length;
      delete stats.assigned_bhw_names;
      stats.bounds =
        stats.lat_min !== null && stats.lng_min !== null
          ? { south: stats.lat_min, west: stats.lng_min, north: stats.lat_max, east: stats.lng_max }
          : null;
      delete stats.lat_min;
      delete stats.lat_max;
      delete stats.lng_min;
      delete stats.lng_max;
      return stats;
    });

    const rates = list.map(function (row) { return row.rate_per_1000; }).filter(function (v) { return v !== null; });
    if (rates.length >= 2) {
      const mean = rates.reduce(function (a, b) { return a + b; }, 0) / rates.length;
      const std = Math.sqrt(rates.reduce(function (a, b) { return a + (b - mean) * (b - mean); }, 0) / rates.length);
      list.forEach(function (row) {
        if (
          row.rate_per_1000 !== null &&
          row.total >= 2 &&
          row.rate_per_1000 >= mean + std &&
          row.urgent + row.emergency >= 1
        ) {
          row.is_hotspot = true;
        }
      });
    }

    list.sort(function (a, b) {
      const rank = { critical: 0, elevated: 1, monitoring: 2, stable: 3 };
      const cmp = (rank[a.status] || 9) - (rank[b.status] || 9);
      if (cmp !== 0) return cmp;
      if (b.emergency !== a.emergency) return b.emergency - a.emergency;
      return b.total - a.total;
    });

    return {
      population_available: populationAvailable,
      new_cases_today: newToday,
      unmapped_cases: unmapped,
      invalid_coordinates: invalid,
      unassigned_bhw: unassignedBhw,
      location_quality: {
        exact: exact,
        geocoded: geocoded,
        barangay: barangayLevel,
        missing: unmapped,
        invalid: invalid,
      },
      hotspots: list.filter(function (row) { return row.is_hotspot; }),
      barangays: list,
      barangays_with_cases: list.length,
      highest_case_barangay: topBarangayStat(list, 'total'),
      highest_emergency_barangay: topBarangayStat(list, 'emergency'),
      note: populationAvailable
        ? 'Case rates use recorded barangay population. GIS status is spatial monitoring only and does not change medical triage.'
        : 'Population data unavailable — case rates per 1,000 are not calculated. GIS status uses emergency/urgent concentration only and does not change medical triage.',
    };
  }

  function fillFilterOptions(patients) {
    const provinces = uniqueValues(patients, 'province');
    const municipalities = uniqueValues(patients, 'municipality');
    const barangays = uniqueValues(patients, 'barangay');
    fillSelect(els.province, provinces, 'All provinces');
    fillSelect(els.municipality, municipalities, 'All municipalities');
    fillSelect(els.barangay, barangays, 'All barangays');
    fillSelect(els.mapBarangay, barangays, 'All Barangays');
    if (state.selectedBarangay) {
      if (els.mapBarangay) els.mapBarangay.value = state.selectedBarangay;
      if (els.barangay) els.barangay.value = state.selectedBarangay;
    }
  }

  function topBarangayStat(list, field) {
    let best = null;
    (list || []).forEach(function (row) {
      const count = Number(row[field] || 0);
      if (!best || count > best.count) {
        best = {
          name: row.name,
          count: count,
          display: (row.name || '') + ' (' + count + ')',
        };
      }
    });
    if (!best || best.count <= 0 || !best.name) return null;
    return best;
  }

  function uniqueValues(rows, key) {
    return Array.from(
      new Set(
        rows
          .map(function (row) {
            const value = (row[key] || '').trim();
            if (key === 'barangay') return value || 'Unknown';
            return value;
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
    let rows = state.patients.slice();
    if (state.selectedBarangay) {
      rows = rows.filter(function (row) {
        return patientBarangayName(row) === state.selectedBarangay;
      });
    }
    if (state.heatmapLayer !== 'all') {
      rows = GisTriage.filterPatients(rows, state.heatmapLayer);
    }
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

  function normalizeLocationSource(row) {
    const source = String(row?.location_source || '').toLowerCase();
    const accuracy = String(row?.location_accuracy || '').toLowerCase();

    if (source === 'unavailable' || accuracy === 'unavailable') {
      return 'unavailable';
    }
    if (source === 'gps' || source === 'manual' || source === 'imported' || accuracy === 'exact') {
      return 'gps';
    }
    if (source === 'address_geocoded' || accuracy === 'geocoded') {
      return 'address_geocoded';
    }
    if (source === 'barangay_center' || source === 'barangay_centroid' || accuracy === 'approximate') {
      return 'barangay_center';
    }

    return 'barangay_center';
  }

  function hasMapMarker(row) {
    if (row && row.has_map_marker === false) {
      return false;
    }
    if (normalizeLocationSource(row) === 'unavailable') {
      return false;
    }
    const lat = Number(row?.latitude);
    const lng = Number(row?.longitude);
    return isValidCoord(lat, lng);
  }

  function displayAddress(row) {
    return (row?.display_address || row?.address || '').trim();
  }

  function getMapConfig() {
    return state.mapConfig || DEFAULT_MAP_CONFIG;
  }

  function getMapCenter() {
    const cfg = getMapConfig();
    const lat = Number(cfg.center?.lat ?? cfg.center?.[0]);
    const lng = Number(cfg.center?.lng ?? cfg.center?.[1]);
    if (isValidCoord(lat, lng)) {
      return [lat, lng];
    }
    return [DEFAULT_MAP_CONFIG.center.lat, DEFAULT_MAP_CONFIG.center.lng];
  }

  function getMapBounds() {
    const cfg = getMapConfig();
    const b = cfg.bounds || DEFAULT_MAP_CONFIG.bounds;
    const south = Number(b.south);
    const west = Number(b.west);
    const north = Number(b.north);
    const east = Number(b.east);
    if ([south, west, north, east].every(Number.isFinite)) {
      return [
        [south, west],
        [north, east],
      ];
    }
    const fallback = DEFAULT_MAP_CONFIG.bounds;
    return [
      [fallback.south, fallback.west],
      [fallback.north, fallback.east],
    ];
  }

  function focusMapOnMarkers(markerBounds) {
    const cfg = getMapConfig();
    const defaultZoom = Number(cfg.default_zoom || DEFAULT_MAP_CONFIG.default_zoom);

    if (!markerBounds.length) {
      state.map.setView(getMapCenter(), defaultZoom);
      return;
    }

    if (markerBounds.length === 1) {
      state.map.setView(markerBounds[0], Math.min(defaultZoom, 14));
      return;
    }

    const latLngBounds = L.latLngBounds(markerBounds);
    if (!latLngBounds.isValid()) {
      state.map.setView(getMapCenter(), defaultZoom);
      return;
    }

    const northEast = latLngBounds.getNorthEast();
    const southWest = latLngBounds.getSouthWest();
    const latSpan = Math.abs(northEast.lat - southWest.lat);
    const lngSpan = Math.abs(northEast.lng - southWest.lng);

    if (latSpan < 0.0005 && lngSpan < 0.0005) {
      state.map.setView(latLngBounds.getCenter(), Math.min(defaultZoom, 14));
      return;
    }

    state.map.fitBounds(latLngBounds, { padding: [36, 36], maxZoom: 14 });
  }

  function locationSourceMeta(row) {
    return LOCATION_SOURCE[normalizeLocationSource(row)] || LOCATION_SOURCE.barangay_center;
  }

  function locationSourceBadgeHtml(row) {
    const meta = locationSourceMeta(row);
    return (
      '<span class="gis-loc-badge ' +
      meta.badgeClass +
      '" title="' +
      escapeHtml(meta.hint) +
      '">' +
      escapeHtml(meta.label) +
      '</span>'
    );
  }

  function renderTable() {
    if (!els.tableBody) return;
    const rows = sortedPatients();
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    if (state.page > totalPages) state.page = totalPages;
    const start = (state.page - 1) * PAGE_SIZE;
    const pageRows = rows.slice(start, start + PAGE_SIZE);

    if (!pageRows.length) {
      els.tableBody.innerHTML =
        '<tr><td colspan="7" class="gis-table-empty">No patient location records match the current filters.</td></tr>';
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
            '<td>' +
            locationSourceBadgeHtml(row) +
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
    const locKey = normalizeLocationSource(row);
    const locMeta = locationSourceMeta(row);
    const address = displayAddress(row) || '—';
    let html = '<div class="gis-popup">';

    if (canShowPatientName()) {
      html += '<strong>' + escapeHtml(row.patient_name) + '</strong>';
      html += '<p class="text-xs text-muted">Patient #' + escapeHtml(row.patient_id) + '</p>';
    } else {
      html += '<strong>Patient #' + escapeHtml(row.patient_id) + '</strong>';
    }
    html +=
      '<p><strong>Barangay:</strong> ' +
      escapeHtml(row.barangay || '—') +
      '</p>';
    if ((row.purok || row.street || '').toString().trim()) {
      html +=
        '<p><strong>Purok/Street:</strong> ' +
        escapeHtml((row.purok || row.street || '').toString().trim()) +
        '</p>';
    }

    html +=
      '<p class="gis-popup__badges">' +
      '<span class="gis-badge ' +
      severityBadgeClass(severity) +
      '">' +
      escapeHtml(severityLabel) +
      '</span> ' +
      locationSourceBadgeHtml(row) +
      '</p>';

    if (locKey === 'unavailable') {
      html +=
        '<p class="gis-popup__loc-hint text-xs text-muted">No verified patient location is available for mapping.</p>' +
        '<p><strong>Address:</strong> ' +
        escapeHtml(address) +
        '</p>';
    } else if (locKey === 'gps') {
      html +=
        '<p class="gis-popup__loc-hint text-xs text-muted">' +
        escapeHtml(locMeta.hint) +
        '</p>' +
        '<p><strong>Address:</strong> ' +
        escapeHtml(address) +
        '</p>';
      if (canShowCoordinates() && isValidCoord(lat, lng)) {
        html +=
          '<p><strong>Coordinates:</strong> ' +
          escapeHtml(lat.toFixed(6) + ', ' + lng.toFixed(6)) +
          '</p>';
      }
    } else if (locKey === 'address_geocoded') {
      html +=
        '<p class="gis-popup__loc-hint text-xs text-muted">' +
        escapeHtml(locMeta.hint) +
        '</p>' +
        '<p><strong>Address:</strong> ' +
        escapeHtml(address) +
        '</p>';
      if (canShowCoordinates() && isValidCoord(lat, lng)) {
        html +=
          '<p><strong>Coordinates:</strong> ' +
          escapeHtml(lat.toFixed(6) + ', ' + lng.toFixed(6)) +
          '</p>';
      }
    } else {
      html +=
        '<p class="gis-popup__loc-hint text-xs text-muted">' +
        escapeHtml(row.location_note || locMeta.hint) +
        '</p>' +
        '<p><strong>Address:</strong> ' +
        escapeHtml(address) +
        '</p>' +
        '<p><strong>Location:</strong> ' +
        escapeHtml(row.barangay_center_label || ('Barangay ' + (row.barangay || '—') + ' center')) +
        '</p>' +
        '<p class="text-xs text-muted"><em>Note: Exact patient location is unavailable.</em></p>';
    }

    html +=
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

    if (userRole === 'provider') {
      const recordsUrl = String(root.dataset.recordsUrl || '').trim();
      const historyUrl = String(root.dataset.historyUrl || '').trim();
      const pid = encodeURIComponent(String(row.patient_id || ''));
      if (recordsUrl || historyUrl) {
        html += '<p class="gis-popup__actions">';
        if (recordsUrl) {
          html +=
            '<a class="gis-popup__link" href="' +
            escapeHtml(recordsUrl) +
            '?view=patients&amp;patient_id=' +
            pid +
            '">Open records</a>';
        }
        if (historyUrl) {
          html +=
            '<a class="gis-popup__link" href="' +
            escapeHtml(historyUrl) +
            '?patient_id=' +
            pid +
            '">Visit history</a>';
        }
        html += '</p>';
      }
    }

    html += '</div>';
    return html;
  }

  function popupOptions() {
    const size = state.map && typeof state.map.getSize === 'function'
      ? state.map.getSize()
      : { x: 640, y: 520 };
    const isNarrow = size.x < 640;
    const maxWidth = Math.max(180, Math.min(280, size.x - 72));
    const maxHeight = Math.max(
      150,
      Math.min(isNarrow ? Math.floor(size.y * 0.48) : 320, size.y - 88)
    );
    return {
      className: 'gis-leaflet-popup',
      autoPan: true,
      autoPanPaddingTopLeft: L.point(isNarrow ? 10 : 16, isNarrow ? 12 : 20),
      autoPanPaddingBottomRight: L.point(isNarrow ? 50 : 56, isNarrow ? 36 : 44),
      maxWidth: maxWidth,
      minWidth: Math.min(200, maxWidth),
      maxHeight: maxHeight,
      closeOnClick: true,
      keepInView: false,
      autoClose: true,
    };
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
    const center = getMapCenter();
    const tile = latLngToTile(center[0], center[1], 12);
    if (layerType === 'satellite') {
      return (
        'https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/' +
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
    state.satelliteTileErrors = 0;
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

  function severityMarkerIcon(severity, locationSource) {
    const color = SEVERITY_COLORS[severity] || SEVERITY_COLORS.non_urgent;
    const meta = LOCATION_SOURCE[locationSource] || LOCATION_SOURCE.barangay_center;
    const markerStyle = meta.markerStyle || 'approx';
    const isExact = markerStyle === 'exact';
    const isGeocoded = markerStyle === 'geocoded';
    const ring = isExact
      ? 'box-shadow:0 0 0 2px ' + color + '99'
      : 'box-shadow:0 0 0 2px rgba(255,255,255,0.95),0 0 0 4px ' + color + '55';
    const border = isExact ? '2px solid #fff' : isGeocoded ? '2px dotted #fff' : '2px dashed #fff';
    const title = isExact
      ? 'GPS pin (exact)'
      : isGeocoded
        ? 'Geocoded address pin'
        : 'Approximate barangay pin';
    return L.divIcon({
      className:
        'gis-severity-marker' +
        (isExact ? '' : isGeocoded ? ' gis-severity-marker--geocoded' : ' gis-severity-marker--approx'),
      html:
        '<div style="background:' +
        color +
        ';width:14px;height:14px;border-radius:50%;border:' +
        border +
        ';' +
        ring +
        '" title="' +
        title +
        '"></div>',
      iconSize: [14, 14],
      iconAnchor: [7, 7],
    });
  }

  function initMap() {
    if (state.map || typeof L === 'undefined') return;
    const cfg = getMapConfig();
    const center = getMapCenter();
    const bounds = getMapBounds();

    state.map = L.map('gis-map', {
      scrollWheelZoom: true,
      maxBounds: bounds,
      maxBoundsViscosity: 0.85,
      minZoom: Number(cfg.min_zoom || DEFAULT_MAP_CONFIG.min_zoom),
      maxZoom: 18,
      zoomControl: false,
    }).setView(center, Number(cfg.default_zoom || DEFAULT_MAP_CONFIG.default_zoom));

    L.control.zoom({ position: 'topright' }).addTo(state.map);

    state.streetLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    });

    state.satelliteLayer = L.tileLayer(
      'https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
      {
        maxZoom: 18,
        maxNativeZoom: 17,
        attribution:
          'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
      }
    );

    state.satelliteLayer.on('tileerror', function () {
      state.satelliteTileErrors += 1;
      if (state.satelliteTileErrors >= 4 && state.baseLayer === 'satellite') {
        applyBaseLayer('street');
      }
    });

    applyBaseLayer(state.baseLayer);

    state.cluster = L.markerClusterGroup({
      showCoverageOnHover: false,
      maxClusterRadius: 52,
      zoomToBoundsOnClick: true,
      spiderfyOnMaxZoom: true,
      disableClusteringAtZoom: 16,
    });
    state.map.addLayer(state.cluster);
    initLayerSwitch();
  }

  function isValidCoord(lat, lng) {
    if (
      !Number.isFinite(lat) ||
      !Number.isFinite(lng) ||
      lat < -90 ||
      lat > 90 ||
      lng < -180 ||
      lng > 180 ||
      (lat === 0 && lng === 0)
    ) {
      return false;
    }
    const bounds = getMapBounds();
    const south = bounds[0][0];
    const west = bounds[0][1];
    const north = bounds[1][0];
    const east = bounds[1][1];
    return lat >= south && lat <= north && lng >= west && lng <= east;
  }

  function heatPoints(layerName) {
    const rows = patientsForLayer(layerName).filter(hasMapMarker);
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
    const isFirstMapRender = !state.map;
    initMap();
    if (!state.map || !state.cluster) return;

    state.cluster.clearLayers();
    const bounds = [];
    const layerName = state.heatmapLayer;
    const visiblePatients = patientsForLayer(layerName);

    visiblePatients.forEach(function (row) {
      if (!hasMapMarker(row)) {
        return;
      }
      const lat = Number(row.latitude);
      const lng = Number(row.longitude);
      if (!isValidCoord(lat, lng)) return;
      bounds.push([lat, lng]);
      const severity = readTriageLevel(row);
      const marker = L.marker([lat, lng], {
        icon: severityMarkerIcon(severity, normalizeLocationSource(row)),
      });
      marker.bindPopup(popupHtml(row), popupOptions());
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

    if (isFirstMapRender) {
      if (state.selectedBarangay) {
        zoomToBarangay(state.selectedBarangay, false);
      } else {
        focusMapOnMarkers(bounds);
      }
    }
  }

  function refreshDashboard() {
    try {
      renderSeverityStats();
    } catch (err) {
      console.error('GIS stats render failed:', err);
    }
    try {
      renderTable();
    } catch (err) {
      console.error('GIS table render failed:', err);
    }
    try {
      renderMap();
    } catch (mapErr) {
      console.error('GIS map render failed:', mapErr);
    }
    try {
      renderBarangaySummary();
    } catch (err) {
      console.error('GIS barangay summary render failed:', err);
      if (els.brgyBody) {
        els.brgyBody.innerHTML =
          '<tr><td colspan="5" class="gis-table-empty">Unable to build barangay summary.</td></tr>';
      }
    }
  }

  function barangayCenterLookup(name) {
    const records = (state.mapConfig && state.mapConfig.barangay_centers) || [];
    const needle = String(name || '').toLowerCase();
    for (let i = 0; i < records.length; i += 1) {
      if (String(records[i].name || '').toLowerCase() === needle) {
        return records[i];
      }
    }
    return null;
  }

  function zoomToBarangay(name, animate) {
    if (!state.map || !name) return;
    const monitoring = currentMonitoring();
    const stats = (monitoring.barangays || []).find(function (row) {
      return row.name === name;
    });
    if (stats && stats.bounds) {
      const b = stats.bounds;
      state.map.fitBounds(
        [
          [b.south, b.west],
          [b.north, b.east],
        ],
        { padding: [40, 40], maxZoom: 15, animate: animate !== false }
      );
      return;
    }
    const center = barangayCenterLookup(name);
    if (center && isValidCoord(Number(center.lat), Number(center.lng))) {
      state.map.setView([Number(center.lat), Number(center.lng)], 14, { animate: animate !== false });
    }
  }

  function renderBarangayDetail(stats) {
    const el = els.brgyDetail;
    if (!el) return;
    if (!stats) {
      el.hidden = true;
      el.innerHTML = '';
      return;
    }
    const bhwLine =
      Number(stats.assigned_bhw_count || 0) > 0
        ? '<p class="gis-brgy-detail__meta">Assigned BHWs: ' + Number(stats.assigned_bhw_count).toLocaleString() + '</p>'
        : '';
    el.hidden = false;
    el.innerHTML =
      '<h4>' +
      escapeHtml(stats.name) +
      '</h4>' +
      '<p><strong>Total Cases: ' +
      Number(stats.total || 0).toLocaleString() +
      '</strong></p>' +
      '<p>🟢 Non-Urgent: ' +
      Number(stats.non_urgent || 0).toLocaleString() +
      '</p>' +
      '<p>🟡 Urgent: ' +
      Number(stats.urgent || 0).toLocaleString() +
      '</p>' +
      '<p>🔴 Emergency: ' +
      Number(stats.emergency || 0).toLocaleString() +
      '</p>' +
      '<p class="gis-brgy-detail__meta">Cases with valid GPS: ' +
      Number(stats.gps || 0).toLocaleString() +
      '</p>' +
      '<p class="gis-brgy-detail__meta">Cases without valid location: ' +
      Number(stats.unmapped || 0).toLocaleString() +
      '</p>' +
      bhwLine;
  }

  function renderBarangaySummary() {
    const body = els.brgyBody;
    if (!body) return;
    const monitoring = currentMonitoring();
    let rows = (monitoring.barangays || []).slice();
    const layer = state.heatmapLayer;
    if (layer === 'emergency') {
      rows = rows.filter(function (row) { return Number(row.emergency || 0) > 0; });
    } else if (layer === 'urgent') {
      rows = rows.filter(function (row) { return Number(row.urgent || 0) > 0; });
    } else if (layer === 'non_urgent') {
      rows = rows.filter(function (row) { return Number(row.non_urgent || 0) > 0; });
    }
    const q = String(state.barangaySearch || '').trim().toLowerCase();
    if (q) {
      rows = rows.filter(function (row) {
        return String(row.name || '').toLowerCase().indexOf(q) !== -1;
      });
    }
    const sortKey = state.barangaySort || 'total';
    rows.sort(function (a, b) {
      if (sortKey === 'name') {
        return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' });
      }
      const av = Number(a[sortKey] || 0);
      const bv = Number(b[sortKey] || 0);
      if (bv !== av) return bv - av;
      return String(a.name || '').localeCompare(String(b.name || ''));
    });

    const selected = (monitoring.barangays || []).find(function (row) {
      return row.name === state.selectedBarangay;
    });
    renderBarangayDetail(selected || null);

    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="5" class="gis-table-empty">No barangay cases for the current data.</td></tr>';
      return;
    }

    body.innerHTML = rows
      .map(function (row) {
        const selectedClass = row.name === state.selectedBarangay ? ' is-selected' : '';
        return (
          '<tr class="' +
          selectedClass.trim() +
          '" data-barangay="' +
          escapeHtml(row.name) +
          '" title="View this barangay on the map">' +
          '<td>' +
          escapeHtml(row.name) +
          '</td>' +
          '<td>' +
          Number(row.total || 0).toLocaleString() +
          '</td>' +
          '<td>' +
          Number(row.non_urgent || 0).toLocaleString() +
          '</td>' +
          '<td>' +
          Number(row.urgent || 0).toLocaleString() +
          '</td>' +
          '<td>' +
          Number(row.emergency || 0).toLocaleString() +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    body.querySelectorAll('tr[data-barangay]').forEach(function (tr) {
      tr.addEventListener('click', function () {
        const name = tr.getAttribute('data-barangay') || '';
        selectBarangay(name === state.selectedBarangay ? '' : name, true);
      });
    });
  }

  function selectBarangay(name, zoom) {
    state.selectedBarangay = name || '';
    if (els.mapBarangay) els.mapBarangay.value = state.selectedBarangay;
    if (els.barangay) els.barangay.value = state.selectedBarangay;
    refreshDashboard();
    if (!zoom || !state.map) return;
    if (state.selectedBarangay) {
      zoomToBarangay(state.selectedBarangay, true);
      return;
    }
    const bounds = [];
    patientsForLayer(state.heatmapLayer).forEach(function (row) {
      if (!hasMapMarker(row)) return;
      const lat = Number(row.latitude);
      const lng = Number(row.longitude);
      if (!isValidCoord(lat, lng)) return;
      bounds.push([lat, lng]);
    });
    focusMapOnMarkers(bounds);
  }

  async function fetchLightBundle() {
    const params = new URLSearchParams(filtersQuery());
    params.set('action', 'light_bundle');
    const res = await fetch(apiUrl + '?' + params.toString());
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Failed to load GIS data');
    return json.data || {};
  }

  async function fetchPatients() {
    const params = new URLSearchParams(filtersQuery());
    params.set('action', 'patients');
    const res = await fetch(apiUrl + '?' + params.toString());
    const json = await res.json();
    if (!json.success) return [];
    return (json.data && json.data.patients) || [];
  }

  async function loadAll() {
    try {
      var data = await fetchLightBundle();
      state.analytics = data.analytics || null;
      state.triageStats = data.triage_stats || data.summary?.triage_stats || null;
      state.mapConfig = data.map_config || DEFAULT_MAP_CONFIG;
      state.lastSync = data.server_ts || new Date().toISOString();
      state.patients = [];
      refreshDashboard();

      var patients = await fetchPatients();
      state.patients = patients;
      state.monitoring = null;
      fillFilterOptions(state.patients);
      refreshDashboard();
    } catch (err) {
      console.error(err);
      if (els.tableBody) {
        els.tableBody.innerHTML =
          '<tr><td colspan="7" class="gis-table-empty">Unable to load GIS dashboard data.</td></tr>';
      }
      if (els.brgyBody) {
        els.brgyBody.innerHTML =
          '<tr><td colspan="5" class="gis-table-empty">Unable to load barangay summary.</td></tr>';
      }
      try {
        renderMap();
      } catch (mapErr) {
        console.error('GIS map render failed:', mapErr);
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

  function invalidateMapSize() {
    if (!state.map) return;
    try {
      state.map.invalidateSize({ animate: false });
    } catch (e) {
      /* ignore */
    }
  }

  function bindEvents() {
    els.toggleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        setView(btn.dataset.view || 'map');
      });
    });

    [els.search, els.province, els.municipality, els.status, els.dateFrom, els.dateTo].forEach(
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

    if (els.barangay) {
      els.barangay.addEventListener('change', function () {
        selectBarangay(els.barangay.value, true);
      });
    }
    if (els.mapBarangay) {
      els.mapBarangay.addEventListener('change', function () {
        selectBarangay(els.mapBarangay.value, true);
      });
    }
    if (els.brgySearch) {
      els.brgySearch.addEventListener(
        'input',
        debounce(function () {
          state.barangaySearch = els.brgySearch.value;
          renderBarangaySummary();
        }, 200)
      );
    }
    if (els.brgySort) {
      els.brgySort.addEventListener('change', function () {
        state.barangaySort = els.brgySort.value || 'total';
        renderBarangaySummary();
      });
    }

    root.querySelectorAll('input[name="heatmap-layer"]').forEach(function (input) {
      input.addEventListener('change', function () {
        state.heatmapLayer = input.value;
        renderSeverityStats();
        try {
          renderMap();
        } catch (mapErr) {
          console.error('GIS map render failed:', mapErr);
        }
        renderTable();
        renderBarangaySummary();
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

    window.addEventListener(
      'resize',
      debounce(function () {
        if (state.view === 'map') {
          invalidateMapSize();
        }
      }, 150)
    );

    if (typeof window.matchMedia === 'function') {
      const layoutQuery = window.matchMedia('(min-width: 1200px)');
      const onLayoutChange = function () {
        if (state.view === 'map') {
          setTimeout(invalidateMapSize, 120);
        }
      };
      if (typeof layoutQuery.addEventListener === 'function') {
        layoutQuery.addEventListener('change', onLayoutChange);
      } else if (typeof layoutQuery.addListener === 'function') {
        layoutQuery.addListener(onLayoutChange);
      }
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
