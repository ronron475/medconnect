/**
 * =============================================================================
 * Hospital Referral Module — Frontend Logic
 * Fully functional + mobile responsive
 * =============================================================================
 */
(function () {
  'use strict';

  /* ── State ─────────────────────────────────────────────────────────────── */
  let leafletMap  = null;
  let hospitals   = [];
  let currentIdx  = 0;
  let userLat     = null;
  let userLng     = null;
  let resultLoaded = false;   // true once hospitals are fetched — skip re-fetch on reopen

  /* ── DOM refs ───────────────────────────────────────────────────────────── */
  const overlay         = document.getElementById('referralOverlay');
  const locationStatus  = document.getElementById('refLocationStatus');
  const statusText      = document.getElementById('refStatusText');
  const errorBox        = document.getElementById('refError');
  const errorText       = document.getElementById('refErrorText');
  const hospitalCard    = document.getElementById('refHospitalCard');
  const elName          = document.getElementById('refHospitalName');
  const elAddress       = document.getElementById('refHospitalAddress');
  const elDistance      = document.getElementById('refDistance');
  const elTime          = document.getElementById('refTravelTime');
  const cacheBadge      = document.getElementById('refCacheBadge');
  const btnGo           = document.getElementById('refBtnGo');
  const btnRetry        = document.getElementById('refBtnRetry');
  const btnClose        = document.getElementById('refBtnClose');
  const step1           = document.getElementById('refStep1');
  const step2           = document.getElementById('refStep2');
  const step3           = document.getElementById('refStep3');
  const manualForm      = document.getElementById('refManualForm');
  const manualSubmit    = document.getElementById('refBtnManualSubmit');
  const navBar          = document.getElementById('refNavBar');
  const btnPrev         = document.getElementById('refBtnPrev');
  const btnNext         = document.getElementById('refBtnNext');
  const navLabel        = document.getElementById('refNavLabel');
  const hospitalCounter = document.getElementById('refHospitalCounter');
  const floatingBack    = null; // removed
  const fab             = document.getElementById('refFab');

  /* ── Public API ─────────────────────────────────────────────────────────── */
  window.BagoReferral = {
    show: function () {
      openModal();
      // If we already have results, just redisplay — no new API call needed
      if (resultLoaded && hospitals.length > 0) {
        showHospitalAt(currentIdx, false, userLat, userLng);
        return;
      }
      startLocationFlow();
    }
  };

  /* ── Modal open / close ─────────────────────────────────────────────────── */
  function openModal() {
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    if (fab) fab.style.display = 'none';
    setTimeout(function () { if (leafletMap) leafletMap.invalidateSize(); }, 300);
  }

  function closeModal() {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    if (fab) fab.style.display = 'flex';
  }

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.classList.contains('active')) closeModal();
  });
  if (btnClose)  btnClose.addEventListener('click', closeModal);
  if (btnRetry)  btnRetry.addEventListener('click', function () {
    resultLoaded = false;
    startLocationFlow();
  });

  /* ── Step tracker ───────────────────────────────────────────────────────── */
  function setStep(n) {
    [step1, step2, step3].forEach(function (s, i) {
      if (!s) return;
      s.classList.remove('active', 'done');
      if (i + 1 < n)   s.classList.add('done');
      if (i + 1 === n)  s.classList.add('active');
    });
  }

  /* ── Location flow ──────────────────────────────────────────────────────── */
  function startLocationFlow() {
    hospitals   = [];
    currentIdx  = 0;
    resultLoaded = false;
    hideError();
    hideCard();
    hideManualForm();
    if (btnGo)    btnGo.disabled = true;
    if (btnRetry) btnRetry.style.display = 'none';
    if (navBar)   navBar.style.display = 'none';
    setStep(1);

    if (!navigator.geolocation) {
      showError('🔒 Location Not Supported',
        'Your browser does not support geolocation. Enter your coordinates manually below.');
      showManualForm();
      return;
    }

    showStatus('Requesting location access…');

    navigator.geolocation.getCurrentPosition(
      onLocationSuccess,
      onLocationError,
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
  }

  function onLocationSuccess(pos) {
    userLat = pos.coords.latitude;
    userLng = pos.coords.longitude;
    setStep(2);
    showStatus('Location found. Searching for nearest hospital…');
    fetchHospitals(userLat, userLng);
  }

  function onLocationError(err) {
    hideStatus();
    setStep(1);
    var msgs = {
      1: ['📍 Location Denied',
          'Allow location access: click the lock icon in the address bar → Location → Allow. Or enter coordinates manually below.'],
      2: ['📡 Location Unavailable',
          'GPS unavailable. Ensure location/Wi-Fi is enabled. Or enter coordinates manually below.'],
      3: ['⏱ Location Timed Out',
          'Location detection took too long. On HTTP/localhost this is common — enter coordinates manually below.']
    };
    var m = msgs[err.code] || ['❌ Location Error', 'Could not detect location. Enter coordinates manually below.'];
    showError(m[0], m[1]);
    showManualForm();
    if (btnRetry) btnRetry.style.display = 'flex';
  }

  /* ── Manual form ────────────────────────────────────────────────────────── */
  function showManualForm() { if (manualForm) manualForm.style.display = 'block'; }
  function hideManualForm() { if (manualForm) manualForm.style.display = 'none';  }

  if (manualSubmit) {
    manualSubmit.addEventListener('click', function () {
      var latEl = document.getElementById('refManualLat');
      var lngEl = document.getElementById('refManualLng');
      var lat   = parseFloat(latEl.value);
      var lng   = parseFloat(lngEl.value);

      latEl.classList.remove('is-invalid');
      lngEl.classList.remove('is-invalid');

      if (isNaN(lat) || lat < -90  || lat > 90)  { latEl.classList.add('is-invalid'); return; }
      if (isNaN(lng) || lng < -180 || lng > 180)  { lngEl.classList.add('is-invalid'); return; }

      userLat = lat;
      userLng = lng;
      hideManualForm();
      hideError();
      if (btnRetry) btnRetry.style.display = 'none';
      setStep(2);
      showStatus('Searching for nearest hospital…');
      fetchHospitals(lat, lng);
    });
  }

  /* ── API fetch ──────────────────────────────────────────────────────────── */
  function fetchHospitals(lat, lng) {
    var apiBase = (window._referralConfig && window._referralConfig.apiBase)
                ? window._referralConfig.apiBase
                : 'api/nearest_hospital.php';

    var url = apiBase + '?lat=' + encodeURIComponent(lat)
                      + '&lng=' + encodeURIComponent(lng)
                      + '&bust=0';

    fetch(url)
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        hideStatus();
        if (data.error) {
          showError('⚠️ Search Failed', data.error);
          if (btnRetry) btnRetry.style.display = 'flex';
          setStep(1);
          return;
        }
        hospitals    = (data.hospitals && data.hospitals.length) ? data.hospitals : [data];
        currentIdx   = 0;
        resultLoaded = true;
        setStep(3);
        showHospitalAt(0, data.cached, lat, lng);
      })
      .catch(function (err) {
        hideStatus();
        setStep(1);
        showError('🌐 Connection Error',
          'Could not reach the hospital lookup service. Check your internet and try again.');
        if (btnRetry) btnRetry.style.display = 'flex';
        console.error('[BagoReferral]', err);
      });
  }

  /* ── Display hospital at index ──────────────────────────────────────────── */
  function showHospitalAt(idx, cached, uLat, uLng) {
    var h = hospitals[idx];
    if (!h) return;

    // Store user coords on hospital object for navigation button
    h.user_lat = uLat;
    h.user_lng = uLng;

    elName.textContent     = h.name;
    elAddress.textContent  = h.address;
    elDistance.textContent = h.distance;
    elTime.textContent     = h.travel_time;
    if (cacheBadge) cacheBadge.style.display = cached ? 'inline-flex' : 'none';

    // Counter label
    if (hospitalCounter) {
      hospitalCounter.textContent = (idx === 0 && hospitals.length > 1)
        ? 'Nearest Hospital'
        : hospitals.length > 1
          ? 'Hospital ' + (idx + 1) + ' of ' + hospitals.length
          : 'Nearest Hospital';
    }

    // Prev/Next bar
    if (navBar) {
      navBar.style.display = hospitals.length > 1 ? 'flex' : 'none';
      if (navLabel) navLabel.textContent = (idx + 1) + ' / ' + hospitals.length;
      if (btnPrev)  btnPrev.disabled = (idx === 0);
      if (btnNext)  btnNext.disabled = (idx === hospitals.length - 1);
    }

    hospitalCard.classList.add('show');
    if (btnGo) btnGo.disabled = false;

    // Render map after card is visible in DOM
    requestAnimationFrame(function () {
      setTimeout(function () {
        renderMap(uLat, uLng, h.lat, h.lng, h.name);
      }, 100);
    });
  }

  /* ── Prev / Next ────────────────────────────────────────────────────────── */
  if (btnPrev) {
    btnPrev.addEventListener('click', function () {
      if (currentIdx > 0) {
        currentIdx--;
        showHospitalAt(currentIdx, false, userLat, userLng);
      }
    });
  }
  if (btnNext) {
    btnNext.addEventListener('click', function () {
      if (currentIdx < hospitals.length - 1) {
        currentIdx++;
        showHospitalAt(currentIdx, false, userLat, userLng);
      }
    });
  }

  /* ── Leaflet map ─────────────────────────────────────────────────────────── */
  function renderMap(uLat, uLng, hLat, hLng, hName) {
    var mapEl = document.getElementById('refMap');
    if (!mapEl || typeof L === 'undefined') return;

    // Destroy previous instance cleanly
    if (leafletMap) {
      leafletMap.remove();
      leafletMap = null;
    }

    // Guard: if coords are identical, offset slightly to avoid fitBounds error
    if (uLat === hLat && uLng === hLng) hLat += 0.001;

    leafletMap = L.map('refMap', {
      center:          [(uLat + hLat) / 2, (uLng + hLng) / 2],
      zoom:            13,
      zoomControl:     true,
      scrollWheelZoom: false,
      tap:             true    // enable tap on mobile
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
      maxZoom: 19,
    }).addTo(leafletMap);

    // User pin — blue dot
    var userIcon = L.divIcon({
      className: '',
      html: '<div style="width:14px;height:14px;background:#2563eb;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 8px rgba(37,99,235,.5);"></div>',
      iconSize: [14, 14], iconAnchor: [7, 7]
    });

    // Hospital pin — white card with emoji
    var hospIcon = L.divIcon({
      className: '',
      html: '<div style="width:34px;height:34px;background:#fff;border:2px solid #2563eb;border-radius:10px;box-shadow:0 3px 10px rgba(37,99,235,.25);display:flex;align-items:center;justify-content:center;font-size:16px;">🏥</div>',
      iconSize: [34, 34], iconAnchor: [17, 17]
    });

    L.marker([uLat, uLng], { icon: userIcon })
     .addTo(leafletMap)
     .bindPopup('<strong style="font-size:12px;">Your Location</strong>');

    L.marker([hLat, hLng], { icon: hospIcon })
     .addTo(leafletMap)
     .bindPopup('<strong style="font-size:12px;">' + escHtml(hName) + '</strong>')
     .openPopup();

    // Dashed line between user and hospital
    L.polyline([[uLat, uLng], [hLat, hLng]], {
      color: '#2563eb', weight: 2, dashArray: '5 6', opacity: 0.6
    }).addTo(leafletMap);

    leafletMap.fitBounds([[uLat, uLng], [hLat, hLng]], { padding: [32, 32] });

    // Force tile redraw — needed inside CSS-animated containers
    setTimeout(function () { if (leafletMap) leafletMap.invalidateSize(); }, 250);
  }

  /* ── Go to Hospital ─────────────────────────────────────────────────────── */
  if (btnGo) {
    btnGo.addEventListener('click', function () {
      var h = hospitals[currentIdx];
      if (!h) return;
      var url = 'https://www.google.com/maps/dir/?api=1'
              + '&destination=' + encodeURIComponent(h.lat + ',' + h.lng)
              + '&travelmode=driving';
      window.open(url, '_blank', 'noopener,noreferrer');
      if (fab) fab.style.display = 'flex';
    });
  }

  /* ── UI helpers ─────────────────────────────────────────────────────────── */
  function showStatus(msg) {
    locationStatus.style.display = 'flex';
    statusText.innerHTML = '<span class="ref-spinner"></span> ' + escHtml(msg);
  }
  function hideStatus()  { locationStatus.style.display = 'none'; }
  function hideCard()    { hospitalCard.classList.remove('show'); }

  function showError(title, body) {
    errorText.innerHTML = '<strong>' + escHtml(title) + '</strong><br>' + escHtml(body);
    errorBox.classList.add('show');
  }
  function hideError() { errorBox.classList.remove('show'); }

  function escHtml(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

})();
