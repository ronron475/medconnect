<?php
declare(strict_types=1);

/**
 * Official medConnect Android app metadata.
 * The downloadable file is always public/downloads/medConnect.apk (whitelisted).
 */
function medconnect_mobile_app_filename(): string
{
    return 'medConnect.apk';
}

function medconnect_mobile_app_version(): string
{
    return '1.0.0';
}

function medconnect_mobile_app_path(): string
{
    return PUBLIC_PATH . '/downloads/' . medconnect_mobile_app_filename();
}

function medconnect_format_bytes(int $bytes): string
{
    if ($bytes < 1) {
        return '';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $kb = $bytes / 1024;
    if ($kb < 1024) {
        return (round($kb) >= 10 ? (string) (int) round($kb) : number_format($kb, 1)) . ' KB';
    }
    $mb = $kb / 1024;
    $label = $mb >= 10 ? (string) (int) round($mb) : number_format($mb, 1);

    return $label . ' MB';
}

function medconnect_apk_is_valid(?string $path = null): bool
{
    $path = $path ?? medconnect_mobile_app_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $bytes = (int) filesize($path);
    if ($bytes < 10240) {
        return false;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $magic = fread($handle, 4);
    fclose($handle);
    if ($magic !== "PK\x03\x04") {
        return false;
    }
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $hasManifest = $zip->locateName('AndroidManifest.xml') !== false;
        $zip->close();
        return $hasManifest;
    }

    return true;
}

/** @return array<string, mixed> */
function medconnect_mobile_app(): array
{
    $filename = medconnect_mobile_app_filename();
    $path = medconnect_mobile_app_path();
    $valid = medconnect_apk_is_valid($path);
    $bytes = $valid ? (int) filesize($path) : 0;
    $mtime = ($valid && is_file($path)) ? (int) filemtime($path) : 0;
    $base = defined('ASSET_BASE') ? ASSET_BASE : '';
    $version = medconnect_mobile_app_version();
    $downloadUrl = $base . '/download-apk.php?v=' . rawurlencode($version);
    if ($mtime > 0) {
        $downloadUrl .= '&t=' . $mtime;
    }

    return [
        'name' => 'medConnect',
        'label' => 'medConnect Mobile App',
        'version' => $version,
        'platform' => 'Android',
        'filename' => $filename,
        'available' => $valid,
        'size_bytes' => $bytes,
        'size_label' => $valid ? medconnect_format_bytes($bytes) : '',
        'download_url' => $downloadUrl,
        'start_url' => $base . '/index.php',
        'manifest_url' => $base . '/manifest.php',
        'icon_url' => $base . '/assets/img/medcon_logo.png',
        'production_url' => 'https://medconnect.bccbsis.com',
    ];
}

/** @return array<string, mixed> */
function medconnect_web_manifest(): array
{
    $app = medconnect_mobile_app();
    $base = defined('ASSET_BASE') ? ASSET_BASE : '';
    $icon = $app['icon_url'];

    return [
        'name' => 'medConnect',
        'short_name' => 'medConnect',
        'description' => 'Online video consultation and AI-powered triage for the City Health Office of Bago City.',
        'start_url' => $app['start_url'],
        'scope' => ($base === '' ? '/' : rtrim($base, '/') . '/'),
        'display' => 'standalone',
        'orientation' => 'portrait',
        'background_color' => '#012A4A',
        'theme_color' => '#012A4A',
        'lang' => 'en',
        'categories' => ['health', 'medical'],
        'icons' => [
            [
                'src' => $icon,
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => $icon,
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ],
        ],
    ];
}
