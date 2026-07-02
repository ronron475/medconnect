    </div><!-- /portal-page-body -->
  </div><!-- /main -->
</div><!-- /portal-shell -->

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  // BHW Dashboard Clock
  const clockEl = document.getElementById('global-time');
  if (clockEl) {
    function tick() {
      clockEl.textContent = new Date().toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit', hour12:true});
    }
    tick(); setInterval(tick, 1000);
  }
})();
</script>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-sidebar.js"></script>
<?php require __DIR__ . '/feedback_modal.php'; ?>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-portal.js"></script>
<?php if (!empty($bhw_extra_js) && is_array($bhw_extra_js)): ?>
<?php foreach ($bhw_extra_js as $bhwJsSrc): ?>
<script src="<?= htmlspecialchars((string) $bhwJsSrc, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($bhw_inline_script)): ?>
<script><?= $bhw_inline_script ?></script>
<?php endif; ?>
<?php $responsive_scripts_only = true; require VIEWS_PATH . '/partials/responsive_assets.php'; ?>
<?php $notification_scripts_only = true; require VIEWS_PATH . '/partials/notification_assets.php'; ?>
<?php require_once VIEWS_PATH . '/partials/theme_scripts.php'; ?>
</body>
</html>
