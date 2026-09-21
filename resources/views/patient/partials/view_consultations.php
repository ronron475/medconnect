<?php /** My Sessions — video consultations (rendered by patient-portal.js). */ ?>
<div class="psess-page" id="patientSessionsPage">

  <p class="psess-lead">
    Upcoming, active, and past video consultations. Medical notes and prescriptions stay in
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php">My Health</a>.
    Your permanent profile (blood type, allergies) is in
    <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php">Health Summary</a>.
  </p>

  <div class="psess-layout">
    <div class="psess-main">

      <div id="psess-live-banner" class="psess-live-banner" hidden role="status" aria-live="polite">
        <span class="psess-live-banner__pulse" aria-hidden="true"></span>
        <div class="psess-live-banner__text">
          <strong id="psess-live-banner-title">Checking session status…</strong>
          <span id="psess-live-banner-sub" class="text-sm"></span>
        </div>
      </div>

      <div class="psess-metrics" id="psess-metrics" aria-label="Session summary">
        <div class="psess-metric">
          <span class="psess-metric__value" id="psess-metric-upcoming">0</span>
          <span class="psess-metric__label">Upcoming</span>
        </div>
        <div class="psess-metric psess-metric--accent">
          <span class="psess-metric__value" id="psess-metric-active">0</span>
          <span class="psess-metric__label">Active</span>
        </div>
        <div class="psess-metric">
          <span class="psess-metric__value" id="psess-metric-past">0</span>
          <span class="psess-metric__label">Past Sessions</span>
        </div>
      </div>

      <nav class="psess-tabs" role="tablist" aria-label="Session filters">
        <button type="button" class="psess-tab is-active" data-sess-tab="upcoming" role="tab" aria-selected="true" onclick="filterSessions('upcoming')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Upcoming
        </button>
        <button type="button" class="psess-tab" data-sess-tab="active" role="tab" aria-selected="false" onclick="filterSessions('active')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 10l4.553-2.276A1 1 0 0 1 21 8.618v6.764a1 1 0 0 1-1.447.894L15 14M5 18h8a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2z"/></svg>
          Active
        </button>
        <button type="button" class="psess-tab" data-sess-tab="past" role="tab" aria-selected="false" onclick="filterSessions('past')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Past Sessions
        </button>
      </nav>

      <div id="sessions-list" class="psess-list" aria-live="polite"></div>

      <p id="consult-join-hint" class="psess-join-hint" hidden></p>

    </div>
  </div>
</div>
