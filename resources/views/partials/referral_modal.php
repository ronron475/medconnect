<?php
/**
 * referral_modal.php — Hospital Referral Modal
 * Included in register.view.php before </body>.
 * Triggered by: window.BagoReferral.show()
 *
 * Shown automatically when OCR confirms the applicant is NOT a Bago City resident.
 * API is served from hospital-referral folder now inside medconnect.
 */

// API endpoint — now lives inside medconnect/hospital-referral/
$referral_api = rtrim(ASSET_BASE, '/') . '/hospital-referral/api/nearest_hospital.php';
?>

<!-- Leaflet.js CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- Referral module CSS -->
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/referral.css">

<!-- ── REFERRAL MODAL MARKUP ───────────────────────────────────────────── -->
<div id="referralOverlay" role="dialog" aria-modal="true" aria-labelledby="refModalTitle">
  <div class="ref-modal">

    <!-- Header -->
    <div class="ref-header">
      <div class="ref-header-icon">
        <i class="bi bi-hospital" style="color:#2563eb;font-size:20px;"></i>
      </div>
      <div class="ref-header-text">
        <h2 id="refModalTitle">Not Eligible for Registration in Bago City</h2>
        <p><i class="bi bi-shield-exclamation me-1"></i>Residency Verification Result</p>
      </div>
      <div class="ref-header-pulse" title="System active"></div>
    </div>

    <!-- Progress Steps -->
    <div class="ref-steps">
      <div class="ref-step active" id="refStep1">
        <span class="ref-step-num">1</span> Locate
      </div>
      <div class="ref-step-divider"></div>
      <div class="ref-step" id="refStep2">
        <span class="ref-step-num">2</span> Search
      </div>
      <div class="ref-step-divider"></div>
      <div class="ref-step" id="refStep3">
        <span class="ref-step-num">3</span> Navigate
      </div>
    </div>

    <!-- Scrollable body -->
    <div class="ref-body">

      <!-- Eligibility notice -->
      <div class="ref-notice">
        <i class="bi bi-info-circle-fill me-2" style="color:#dc3545;"></i>
        Based on your <span class="highlight">National ID verification</span>, you are
        <span class="highlight">not a resident of Bago City</span>.
        This facility only processes registrations for verified Bago City residents.<br><br>
        <i class="bi bi-arrow-right-circle me-1" style="color:#6b7280;"></i>
        Please proceed to the <strong>nearest hospital in your area</strong> for proper
        assistance and registration.
      </div>

      <!-- Location status spinner -->
      <div id="refLocationStatus" class="ref-location-status">
        <div class="ref-spinner"></div>
        <span id="refStatusText">Requesting your location…</span>
      </div>

      <!-- Error box -->
      <div id="refError" class="ref-error" role="alert">
        <span class="ref-error-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
        <span id="refErrorText"></span>
      </div>

      <!-- Manual coordinate fallback -->
      <div id="refManualForm" class="ref-manual-form" style="display:none;">
        <div class="ref-manual-title">
          <i class="bi bi-map me-1"></i> Enter Your Location Manually
        </div>
        <p class="ref-manual-hint">
          Find your coordinates free at
          <a href="https://www.latlong.net" target="_blank" rel="noopener noreferrer">latlong.net</a>
          — search your city and copy the numbers.
        </p>
        <div class="ref-manual-inputs">
          <div class="ref-manual-field">
            <label for="refManualLat">Latitude</label>
            <input type="number" id="refManualLat" step="any"
                   placeholder="e.g. 10.5333" min="-90" max="90"
                   class="ref-manual-input">
            <div class="invalid-feedback">Enter a valid latitude (−90 to 90)</div>
          </div>
          <div class="ref-manual-field">
            <label for="refManualLng">Longitude</label>
            <input type="number" id="refManualLng" step="any"
                   placeholder="e.g. 122.9333" min="-180" max="180"
                   class="ref-manual-input">
            <div class="invalid-feedback">Enter a valid longitude (−180 to 180)</div>
          </div>
        </div>
        <button id="refBtnManualSubmit" class="btn-manual-submit" type="button">
          <i class="bi bi-search me-1"></i> Find Nearest Hospital
        </button>
      </div>

      <!-- Hospital result card -->
      <div id="refHospitalCard" class="ref-hospital-card" aria-live="polite">
        <div class="ref-hospital-card-header">
          <div class="hc-icon">🏥</div>
          <div style="flex:1;">
            <p class="ref-hospital-name" id="refHospitalName">—</p>
            <p class="ref-hospital-type">
              <i class="bi bi-geo-alt me-1"></i>
              <span id="refHospitalCounter">Nearest Hospital</span>
              <span id="refCacheBadge" class="ref-cache-badge" style="display:none;">
                <i class="bi bi-lightning-charge-fill"></i> Cached
              </span>
            </p>
          </div>
        </div>

        <!-- Prev / Next navigation -->
        <div id="refNavBar" class="ref-nav-bar" style="display:none;">
          <button id="refBtnPrev" class="ref-nav-btn" type="button" disabled>
            <i class="bi bi-chevron-left"></i> Prev
          </button>
          <span id="refNavLabel" class="ref-nav-label">1 / 1</span>
          <button id="refBtnNext" class="ref-nav-btn" type="button">
            Next <i class="bi bi-chevron-right"></i>
          </button>
        </div>

        <div class="ref-hospital-card-body">
          <div class="ref-hospital-address">
            <i class="bi bi-pin-map"></i>
            <span id="refHospitalAddress">—</span>
          </div>
          <div class="ref-stats">
            <div class="ref-stat distance">
              <div class="ref-stat-value" id="refDistance">—</div>
              <div class="ref-stat-label"><i class="bi bi-rulers me-1"></i>Distance</div>
            </div>
            <div class="ref-stat time">
              <div class="ref-stat-value" id="refTravelTime">—</div>
              <div class="ref-stat-label"><i class="bi bi-clock me-1"></i>Est. Travel</div>
            </div>
          </div>
        </div>

        <!-- Leaflet map -->
        <div class="ref-map-wrapper">
          <div id="refMap"></div>
          <div class="ref-map-legend">
            <span class="ref-map-legend-item">
              <span class="ref-legend-dot user"></span> Your Location
            </span>
            <span class="ref-map-legend-item">
              <span class="ref-legend-dot hospital"></span> Hospital
            </span>
          </div>
        </div>
      </div><!-- /.ref-hospital-card -->

    </div><!-- /.ref-body -->

    <!-- Footer -->
    <div class="ref-footer">
      <button id="refBtnRetry" class="btn-retry" type="button" style="display:none;">
        <i class="bi bi-arrow-clockwise"></i> Retry Location Detection
      </button>
      <button id="refBtnGo" class="btn-go-hospital" type="button" disabled
              aria-label="Open turn-by-turn navigation to nearest hospital">
        <i class="bi bi-map-fill"></i> Go to Hospital <i class="bi bi-arrow-right"></i>
      </button>
      <p class="ref-maps-hint">
        <i class="bi bi-box-arrow-up-right me-1"></i>
        Opens Google Maps in a new tab. Come back here to change hospital.
      </p>
      <div class="ref-footer-row">
        <button id="refBtnClose" class="btn-close-ref" type="button">
          <i class="bi bi-x-circle me-1"></i> Close
        </button>
      </div>
    </div>

    <p class="ref-disclaimer">
      <i class="bi bi-lock-fill me-1"></i>
      Your location is used only to find the nearest hospital and is never stored.
      For emergencies, call <strong>911</strong>.
    </p>

  </div><!-- /.ref-modal -->
</div><!-- /#referralOverlay -->

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Tell referral.js where the API lives -->
<script>
  window._referralConfig = {
    apiBase:     <?= json_encode($referral_api) ?>,
    isLocalhost: true,
    isHttps:     false
  };
</script>

<!-- Referral module JS -->
<script src="<?= ASSET_BASE ?>/assets/js/referral.js"></script>
