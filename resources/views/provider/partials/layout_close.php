    </div><!-- /provider-page-body -->
  </main><!-- /main-content -->
</div><!-- /root-wrapper -->

<?php $responsive_scripts_only = true; require VIEWS_PATH . '/partials/responsive_assets.php'; ?>
<?php $notification_scripts_only = true; require VIEWS_PATH . '/partials/notification_assets.php'; ?>
<?php
$prefsJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-preferences.js');
$idleJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-idle.js');
?>
<?php require_once VIEWS_PATH . '/partials/theme_scripts.php'; ?>
<script src="<?= ASSET_BASE ?>/assets/js/provider-preferences.js?v=<?= $prefsJsVer ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/provider-idle.js?v=<?= $idleJsVer ?>"></script>
</body>
</html>
