<?php
declare(strict_types=1);

require_once VIEWS_PATH . '/components/mc_modal.php';
require_once BASE_PATH . '/app/includes/mobile_app.php';

$mobileApp = $mobileApp ?? medconnect_mobile_app();
if (empty($mobileApp['available'])) {
    return;
}

$body = '<p class="download-app-modal__lead">This installs the official medConnect Android package on your phone. It is not from Google Play.</p>'
    . '<ul class="download-app-modal__list">'
    . '<li>Version ' . htmlspecialchars($mobileApp['version']) . ' · ' . htmlspecialchars($mobileApp['size_label']) . '</li>'
    . '<li>File: ' . htmlspecialchars($mobileApp['filename']) . '</li>'
    . '<li>After download, open the file from Downloads to install.</li>'
    . '</ul>';

$footer = '<button type="button" class="mc-modal__btn mc-modal__btn--secondary" data-mc-modal-close>Cancel</button>'
    . '<button type="button" class="mc-modal__btn mc-modal__btn--primary" id="download-apk-confirm">Download APK</button>';

mc_render_modal_shell([
    'id' => 'download-app-modal',
    'title' => 'Download medConnect APK?',
    'description' => 'Sideload the official Android app for the City Health Office portal.',
    'size' => 'md',
    'body_html' => $body,
    'footer_html' => $footer,
    'hidden' => true,
]);
