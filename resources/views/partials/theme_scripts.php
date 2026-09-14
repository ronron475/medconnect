<?php
$theme_js = ASSETS_PATH . '/js/medconnect-theme.js';
$theme_js_ver = file_exists($theme_js) ? (int) filemtime($theme_js) : time();
$phone_js = ASSETS_PATH . '/js/phone-validation.js';
$phone_js_ver = file_exists($phone_js) ? (int) filemtime($phone_js) : time();
$mc_modal_js = ASSETS_PATH . '/js/mc-modal.js';
$mc_modal_js_ver = file_exists($mc_modal_js) ? (int) filemtime($mc_modal_js) : time();
?>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-theme.js?v=<?= $theme_js_ver ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/phone-validation.js?v=<?= $phone_js_ver ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/mc-modal.js?v=<?= $mc_modal_js_ver ?>"></script>
<style id="mc-contact-validation-css">
.field-error,
.mc-field__error,
.invalid-feedback,
[data-field-error],
.bhw-field-error {
  display: block;
  max-width: 100%;
  overflow-wrap: anywhere;
  word-break: break-word;
  white-space: normal;
  line-height: 1.35;
}
input.invalid,
input.is-invalid,
.form-control.invalid,
.mc-field__input.invalid {
  border-color: #dc2626 !important;
}
</style>
