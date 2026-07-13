<?php /** My Sessions — video consultations (rendered by patient-portal.js). */ ?>
<div class="psess-page" id="patientSessionsPage">

  <p class="psess-lead">
    Video visits you can join live. For diagnosis notes, prescriptions, and visit history, see
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
          <span class="psess-metric__value" id="psess-metric-ready">0</span>
          <span class="psess-metric__label">Ready to join</span>
        </div>
        <div class="psess-metric">
          <span class="psess-metric__value" id="psess-metric-past">0</span>
          <span class="psess-metric__label">Past visits</span>
        </div>
      </div>

      <nav class="psess-tabs" role="tablist" aria-label="Session filters">
        <button type="button" class="psess-tab is-active" data-sess-tab="upcoming" role="tab" aria-selected="true" onclick="filterSessions('upcoming')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Upcoming
        </button>
        <button type="button" class="psess-tab" data-sess-tab="past" role="tab" aria-selected="false" onclick="filterSessions('past')">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Past Sessions
        </button>
      </nav>

      <div id="sessions-list" class="psess-list" aria-live="polite"></div>

      <p id="consult-join-hint" class="psess-join-hint" hidden></p>

    </div>

    <aside class="psess-side" aria-label="Related pages and help">

      <section class="psess-related">
        <h2 class="psess-related__title">Also in your portal</h2>
        <nav class="psess-related__nav">
          <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="psess-related__link psess-related__link--primary">
            <span class="psess-related__icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
            </span>
            <span>
              <strong>Book Consultation</strong>
              <small>Schedule your next video visit</small>
            </span>
          </a>
          <a href="<?= ASSET_BASE ?>/views/patient/my_health.php" class="psess-related__link">
            <span class="psess-related__icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </span>
            <span>
              <strong>My Health</strong>
              <small>Care timeline &amp; health files</small>
            </span>
          </a>
          <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="psess-related__link">
            <span class="psess-related__icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            </span>
            <span>
              <strong>Health Summary</strong>
              <small>Allergies, blood type, meds</small>
            </span>
          </a>
          <a href="<?= ASSET_BASE ?>/views/patient/messages.php" class="psess-related__link">
            <span class="psess-related__icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </span>
            <span>
              <strong>Messages</strong>
              <small>Chat with your provider</small>
            </span>
          </a>
          <a href="<?= ASSET_BASE ?>/views/patient/dashboard.php" class="psess-related__link">
            <span class="psess-related__icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </span>
            <span>
              <strong>Dashboard</strong>
              <small>Overview &amp; quick stats</small>
            </span>
          </a>
        </nav>
      </section>

      <details class="psess-flow">
        <summary class="psess-flow__summary">
          <span class="psess-flow__summary-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
          </span>
          How video visits work
        </summary>
        <ol class="psess-flow__steps">
          <li><span class="psess-flow__num">1</span><span><strong>Booked</strong> — slot confirmed.</span></li>
          <li><span class="psess-flow__num">2</span><span><strong>Wait</strong> — join unlocks on appointment day.</span></li>
          <li><span class="psess-flow__num">3</span><span><strong>Provider starts</strong> — doctor opens the room.</span></li>
          <li><span class="psess-flow__num">4</span><span><strong>Join</strong> — button appears here automatically.</span></li>
        </ol>
        <p class="psess-flow__note">This page refreshes every few seconds — no manual reload needed.</p>
      </details>

      <div class="psess-compare">
        <h3 class="psess-compare__title">My Sessions vs My Health</h3>
        <dl class="psess-compare__list">
          <div>
            <dt>My Sessions</dt>
            <dd>Join live video calls and see what's scheduled.</dd>
          </div>
          <div>
            <dt>My Health</dt>
            <dd>Diagnosis, prescriptions, and provider notes after visits.</dd>
          </div>
        </dl>
      </div>

    </aside>
  </div>
</div>
