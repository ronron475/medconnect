<?php
$gisUserRole = (string) ($userRole ?? $_SESSION['user_role'] ?? '');
$gisIsProvider = $gisUserRole === 'provider';
$gisRecordsUrl = $gisIsProvider ? (rtrim((string) $assetBase, '/') . '/views/provider/medical_records.php') : '';
$gisHistoryUrl = $gisIsProvider ? (rtrim((string) $assetBase, '/') . '/views/provider/consultation_history.php') : '';
$gisSubtitle = $gisIsProvider
    ? 'Your assigned patients across Bago City — Non-Urgent, Urgent, and Emergency cases on your caseload. Pins show barangay location, not exact home GPS.'
    : 'Monitor patient severity geography across Bago City — identify Non-Urgent, Urgent, and Emergency cases at a glance.';
$gisMapNote = $gisIsProvider
    ? 'This map lists only patients already assigned to you (consultations, booked visits, Care tips review, or pending Health Summary requests). Severity comes from each patient\'s latest triage level. Exact home GPS is hidden; pins use the verified barangay center.'
    : 'Severity is sourced from each patient\'s latest triage_level. Pin badges reflect GPS, geocoded address, or verified barangay-center accuracy. Patients without a verified location are listed but not mapped.';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" crossorigin=""/>
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" crossorigin=""/>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>/assets/css/admin-gis-dashboard.css?v=4.1"/>

<div class="gis-page" id="gis-dashboard"
     data-api="<?= htmlspecialchars($apiBase) ?>"
     data-export="<?= htmlspecialchars($exportBase) ?>"
     data-user-role="<?= htmlspecialchars($gisUserRole) ?>"
     data-records-url="<?= htmlspecialchars($gisRecordsUrl) ?>"
     data-history-url="<?= htmlspecialchars($gisHistoryUrl) ?>">

  <div class="gis-header">
    <div>
      <h2 class="text-h2 gis-title"><?= $gisIsProvider ? 'My patient map' : 'GIS Dashboard' ?></h2>
      <p class="text-muted gis-subtitle"><?= htmlspecialchars($gisSubtitle) ?></p>
    </div>
    <div class="gis-view-toggle" role="tablist" aria-label="GIS view mode">
      <button type="button" class="gis-toggle-btn is-active" data-view="map" role="tab" aria-selected="true">Map View</button>
      <button type="button" class="gis-toggle-btn" data-view="table" role="tab" aria-selected="false">Table View</button>
    </div>
  </div>

  <div class="gis-summary-grid" id="gis-summary-grid">
    <div class="mc-card gis-stat-card gis-stat-card--non-urgent" data-severity-stat="non_urgent">
      <div class="gis-stat-label">🟢 Non-Urgent Cases</div>
      <div class="gis-stat-value" id="stat-non_urgent">—</div>
    </div>
    <div class="mc-card gis-stat-card gis-stat-card--urgent" data-severity-stat="urgent">
      <div class="gis-stat-label">🟡 Urgent Cases</div>
      <div class="gis-stat-value" id="stat-urgent">—</div>
    </div>
    <div class="mc-card gis-stat-card gis-stat-card--emergency" data-severity-stat="emergency">
      <div class="gis-stat-label">🔴 Emergency Cases</div>
      <div class="gis-stat-value" id="stat-emergency">—</div>
    </div>
    <div class="mc-card gis-stat-card gis-stat-card--barangay" data-severity-stat="barangay">
      <div class="gis-stat-label"><?= $gisIsProvider ? '📍 Top barangay on your list' : '📍 Most Login Barangay' ?></div>
      <div class="gis-stat-value gis-stat-value--text" id="stat-top_barangay">—</div>
    </div>
    <div class="mc-card gis-stat-card" data-gis-insight="today">
      <div class="gis-stat-label"><?= $gisIsProvider ? 'New on your list today' : 'New cases today' ?></div>
      <div class="gis-stat-value" id="stat-new_today">—</div>
    </div>
    <div class="mc-card gis-stat-card" data-gis-insight="unmapped">
      <div class="gis-stat-label">Cases without valid location</div>
      <div class="gis-stat-value" id="stat-unmapped">—</div>
    </div>
    <?php if ($gisIsProvider): ?>
    <div class="mc-card gis-stat-card" data-gis-insight="visits_today">
      <div class="gis-stat-label">Visits scheduled today</div>
      <div class="gis-stat-value" id="stat-visits_today">—</div>
    </div>
    <div class="mc-card gis-stat-card" data-gis-insight="pending_review">
      <div class="gis-stat-label">Awaiting your review</div>
      <div class="gis-stat-value" id="stat-pending_review">—</div>
    </div>
    <?php else: ?>
    <div class="mc-card gis-stat-card" data-gis-insight="bhw">
      <div class="gis-stat-label">Cases without assigned BHW</div>
      <div class="gis-stat-value" id="stat-unassigned_bhw">—</div>
    </div>
    <div class="mc-card gis-stat-card" data-gis-insight="hotspots">
      <div class="gis-stat-label">Detected hotspots</div>
      <div class="gis-stat-value" id="stat-hotspots">—</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="gis-panel gis-panel--map is-active" id="gis-map-panel" role="tabpanel">
    <div class="mc-card gis-map-card">
      <div class="gis-map-toolbar">
        <div class="gis-heatmap-toggles">
          <span class="gis-toolbar-label">Severity layer</span>
          <label class="gis-chip gis-chip--all"><input type="radio" name="heatmap-layer" value="all" checked> 🌈 All Cases</label>
          <label class="gis-chip gis-chip--non-urgent"><input type="radio" name="heatmap-layer" value="non_urgent"> 🟢 Non-Urgent</label>
          <label class="gis-chip gis-chip--urgent"><input type="radio" name="heatmap-layer" value="urgent"> 🟡 Urgent</label>
          <label class="gis-chip gis-chip--emergency"><input type="radio" name="heatmap-layer" value="emergency"> 🔴 Emergency</label>
        </div>
      </div>
      <div class="gis-map-wrap">
        <div id="gis-map" class="gis-map" aria-label="Interactive patient severity map"></div>
        <div class="gis-map-legend" id="gis-map-legend" aria-label="Map legend">
          <div class="gis-map-legend__title">Severity</div>
          <div class="gis-map-legend__item"><span class="gis-map-legend__dot gis-map-legend__dot--non-urgent"></span> Non-Urgent</div>
          <div class="gis-map-legend__item"><span class="gis-map-legend__dot gis-map-legend__dot--urgent"></span> Urgent</div>
          <div class="gis-map-legend__item"><span class="gis-map-legend__dot gis-map-legend__dot--emergency"></span> Emergency</div>
          <div class="gis-map-legend__divider" aria-hidden="true"></div>
          <div class="gis-map-legend__title">Pin accuracy</div>
          <div class="gis-map-legend__item"><span class="gis-map-legend__dot gis-map-legend__dot--gps"></span> GPS (Exact)</div>
          <div class="gis-map-legend__item"><span class="gis-map-legend__dot gis-map-legend__dot--geocoded"></span> Address (Geocoded)</div>
          <div class="gis-map-legend__item"><span class="gis-map-legend__dot gis-map-legend__dot--approx"></span> Approximate (Barangay)</div>
        </div>
        <button type="button" class="gis-map-layer-switch" id="gisMapLayerSwitch" aria-label="Switch map layer">
          <span class="gis-map-layer-switch__thumb" id="gisMapLayerThumb" aria-hidden="true"></span>
          <span class="gis-map-layer-switch__label" id="gisMapLayerLabel">Satellite</span>
        </button>
      </div>
      <p class="gis-map-note text-xs text-muted"><?= $gisMapNote ?></p>
    </div>
    <div class="gis-analytics-grid" id="gis-analytics-grid">
      <div class="mc-card gis-analytics-card">
        <h3 class="text-h3">Cases by Barangay</h3>
        <p class="gis-analytics-caption text-xs text-muted" data-analytics-caption>Loading…</p>
        <ul class="gis-rank-list" id="analytics-barangay"><li class="gis-rank-list__loading">Loading…</li></ul>
      </div>
      <div class="mc-card gis-analytics-card">
        <h3 class="text-h3">🔴 Emergency by Barangay</h3>
        <p class="gis-analytics-caption text-xs text-muted" data-analytics-caption>Loading…</p>
        <ul class="gis-rank-list" id="analytics-emergency"><li class="gis-rank-list__loading">Loading…</li></ul>
      </div>
      <div class="mc-card gis-analytics-card">
        <h3 class="text-h3">Active Consultations by Area</h3>
        <p class="gis-analytics-caption text-xs text-muted" data-analytics-caption>Loading…</p>
        <ul class="gis-rank-list" id="analytics-consultations"><li class="gis-rank-list__loading">Loading…</li></ul>
      </div>
      <div class="mc-card gis-analytics-card">
        <h3 class="text-h3">Triage Levels by Area</h3>
        <p class="gis-analytics-caption text-xs text-muted" data-analytics-caption>Loading…</p>
        <ul class="gis-rank-list" id="analytics-symptoms"><li class="gis-rank-list__loading">Loading…</li></ul>
      </div>
      <div class="mc-card gis-analytics-card">
        <h3 class="text-h3">Barangay monitoring status</h3>
        <p class="gis-analytics-caption text-xs text-muted" id="analytics-status-caption">Spatial monitoring only — does not change medical triage.</p>
        <ul class="gis-rank-list" id="analytics-status"><li class="gis-rank-list__loading">Loading…</li></ul>
      </div>
      <div class="mc-card gis-analytics-card">
        <h3 class="text-h3">Coverage &amp; location quality</h3>
        <p class="gis-analytics-caption text-xs text-muted" id="analytics-quality-caption">From registered BHW assignment and validated map locations.</p>
        <ul class="gis-rank-list" id="analytics-quality"><li class="gis-rank-list__loading">Loading…</li></ul>
      </div>
    </div>
  </div>

  <div class="gis-panel gis-panel--table" id="gis-table-panel" role="tabpanel" hidden>
    <div class="mc-card gis-table-card">
      <div class="gis-table-toolbar">
        <input type="search" id="gis-search" class="gis-input" placeholder="Search patient name, ID, or email…"/>
        <select id="gis-filter-barangay" class="gis-input"><option value="">All barangays</option></select>
        <select id="gis-filter-status" class="gis-input"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
        <label class="gis-date-field">
          <span class="gis-date-field__label">From date</span>
          <input type="date" id="gis-filter-from" class="gis-input" aria-label="From date"/>
        </label>
        <label class="gis-date-field">
          <span class="gis-date-field__label">To date</span>
          <input type="date" id="gis-filter-to" class="gis-input" aria-label="To date"/>
        </label>
      </div>
      <div class="gis-table-wrap">
        <table class="gis-table" id="gis-patient-table">
          <thead><tr><th>Patient</th><th>Barangay</th><th>Municipality</th><th>Province</th><th>Registration Date</th><th>Pin accuracy</th><th>Status</th></tr></thead>
          <tbody id="gis-table-body"><tr><td colspan="7" class="gis-table-empty">Loading…</td></tr></tbody>
        </table>
      </div>
      <div class="gis-pagination" id="gis-pagination"></div>
    </div>
  </div>

  <div class="gis-live-indicator" id="gis-live-indicator" aria-live="polite"><span class="gis-live-dot"></span> Live updates enabled</div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js" crossorigin=""></script>
<script src="<?= htmlspecialchars($assetBase) ?>/assets/js/admin-gis-dashboard.js?v=4.1"></script>
