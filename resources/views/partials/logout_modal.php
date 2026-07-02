<!-- Logout Confirmation Modal -->
<div id="logout-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title"
     style="display:none; position:fixed; inset:0; z-index:10050;
            background:rgba(0,0,0,0.55); backdrop-filter:blur(4px);
            align-items:center; justify-content:center;">

  <div id="logout-modal-box"
       style="background:#0d1b2a; border:1px solid rgba(2,128,144,0.35);
              border-radius:16px; padding:36px 40px; width:360px; max-width:90vw;
              box-shadow:0 24px 60px rgba(0,0,0,0.6);
              transform:scale(0.92); opacity:0;
              transition:transform .22s cubic-bezier(.34,1.56,.64,1), opacity .18s ease;">

    <div style="display:flex; justify-content:center; margin-bottom:18px;">
      <div style="width:56px; height:56px; border-radius:50%;
                  background:rgba(2,128,144,0.15); border:2px solid rgba(2,128,144,0.4);
                  display:flex; align-items:center; justify-content:center;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#028090"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </div>
    </div>

    <h2 id="logout-modal-title"
        style="margin:0 0 8px; text-align:center; font-size:1.15rem;
               font-weight:700; color:#e8f4f6; letter-spacing:0.01em;">
      Confirm Logout
    </h2>

    <p style="margin:0 0 28px; text-align:center; color:#8bb4bc;
              font-size:0.9rem; line-height:1.55;">
      Are you sure you want to log out?<br>Any unsaved changes will be lost.
    </p>

    <div style="display:flex; gap:12px;">
      <button id="logout-modal-no" type="button"
              style="flex:1; padding:11px 0; border-radius:10px; border:1px solid rgba(2,128,144,0.4);
                     background:transparent; color:#028090; font-size:0.9rem; font-weight:600;
                     cursor:pointer; transition:background .18s, color .18s;">
        No, Stay
      </button>
      <button id="logout-modal-yes" type="button" data-logout-confirm
              style="flex:1; padding:11px 0; border-radius:10px; border:none;
                     background:linear-gradient(135deg,#028090,#015f6b);
                     color:#fff; font-size:0.9rem; font-weight:600;
                     display:flex; align-items:center; justify-content:center;
                     cursor:pointer; transition:opacity .18s;">
        Yes, Log Out
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('logout-modal-overlay');
  var box     = document.getElementById('logout-modal-box');
  if (!overlay || !box) return;

  window.showLogoutModal = function () {
    overlay.style.display = 'flex';
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        box.style.transform = 'scale(1)';
        box.style.opacity   = '1';
      });
    });
  };

  window.hideLogoutModal = function () {
    box.style.transform = 'scale(0.92)';
    box.style.opacity   = '0';
    setTimeout(function () { overlay.style.display = 'none'; }, 200);
  };

  var noBtn = document.getElementById('logout-modal-no');
  if (noBtn) {
    noBtn.addEventListener('click', function () { window.hideLogoutModal(); });
  }

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) window.hideLogoutModal();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay.style.display === 'flex') {
      window.hideLogoutModal();
    }
  });

  if (window.MedConnectLogout && typeof window.MedConnectLogout.init === 'function') {
    window.MedConnectLogout.init();
  }
})();
</script>
