    </div><!-- /portal-page-body -->
  </main>
</div><!-- /root-wrapper -->
<?php require_once VIEWS_PATH . '/patient/partials/remedy_chatbot.php'; ?>
<?php require_once VIEWS_PATH . '/patient/partials/patient_urgency_modal.php'; ?>
<?php require_once VIEWS_PATH . '/patient/partials/patient_tips_ready_cancel_modal.php'; ?>
<?php require_once VIEWS_PATH . '/components/messages_fab.php'; ?>
<?php require_once VIEWS_PATH . '/components/messages_quick_panel.php'; ?>
<?php require_once VIEWS_PATH . '/partials/logout_modal.php'; ?>
<?php $responsive_scripts_only = true; require VIEWS_PATH . '/partials/responsive_assets.php'; ?>
<?php $notification_scripts_only = true; require VIEWS_PATH . '/partials/notification_assets.php'; ?>
<?php
if (empty($portal_dark_mode_css)) {
    $portal_dark_mode_css = 'patient-dark-mode.css';
}
require_once VIEWS_PATH . '/partials/portal_dark_mode_close.php';
?>
<?php require_once VIEWS_PATH . '/partials/theme_scripts.php'; ?>
<?php require_once VIEWS_PATH . '/partials/portal_nav_badge_scripts.php'; ?>
<?php require_once VIEWS_PATH . '/components/session_video_shell.php'; ?>
<script>window.APP_BASE = window.APP_BASE || <?= json_encode(ASSET_BASE) ?>;</script>
<?php $ptUrgencyJsVer = (int) @filemtime(ASSETS_PATH . '/js/patient-urgency-modal.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/patient-urgency-modal.js?v=<?= $ptUrgencyJsVer ?>"></script>
<?php $ptRecJsVer = (int) @filemtime(ASSETS_PATH . '/js/patient-recommendation-popup.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/patient-recommendation-popup.js?v=<?= $ptRecJsVer ?>"></script>
</body>
</html>
