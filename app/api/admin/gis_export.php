<?php
/**
 * API: Export GIS patient location records (CSV).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/BagoBarangayCentroids.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/core/GisDashboardService.php';
require_once BASE_PATH . '/app/includes/audit_log.php';
require_once BASE_PATH . '/app/includes/csv_export.php';

$role = (string) ($_SESSION['user_role'] ?? '');
if (!in_array($role, ['admin', 'provider', 'superadmin'], true)) {
    http_response_code(403);
    die('Unauthorized.');
}

$gis = new GisDashboardService($pdo);

$filters = [
    'search'       => trim((string) ($_GET['search'] ?? '')),
    'province'     => trim((string) ($_GET['province'] ?? '')),
    'municipality' => trim((string) ($_GET['municipality'] ?? '')),
    'barangay'     => trim((string) ($_GET['barangay'] ?? '')),
    'status'       => trim((string) ($_GET['status'] ?? '')),
    'date_from'    => trim((string) ($_GET['date_from'] ?? '')),
    'date_to'      => trim((string) ($_GET['date_to'] ?? '')),
    'viewer_role'  => $role,
];
if ($role === 'provider') {
    $filters['provider_id'] = (int) ($_SESSION['user_id'] ?? 0);
}

// Same on-screen table filters as admin-gis-dashboard.js (selected barangay + triage layer).
$selectedBarangay = trim((string) ($_GET['selected_barangay'] ?? ''));
$triageLayer = strtolower(trim((string) ($_GET['triage_layer'] ?? 'all')));
$triageLabels = ['non_urgent' => 'Non-Urgent', 'urgent' => 'Urgent', 'emergency' => 'Emergency'];
if (!isset($triageLabels[$triageLayer])) {
    $triageLayer = 'all';
}

$rows = $gis->getPatientRecords($filters, $role);
$rows = array_values(array_filter($rows, static function (array $row) use ($selectedBarangay, $triageLayer, $triageLabels): bool {
    if ($selectedBarangay !== '') {
        $name = trim((string) ($row['barangay'] ?? ''));
        if (($name !== '' ? $name : 'Unknown') !== $selectedBarangay) {
            return false;
        }
    }
    if ($triageLayer !== 'all') {
        $level = strtolower((string) ($row['triage_level'] ?? ''));
        if (!isset($triageLabels[$level])) {
            $level = 'non_urgent';
        }
        if ($level !== $triageLayer) {
            return false;
        }
    }

    return true;
}));

audit_log($pdo, [
    'patient_id'  => (int) ($_SESSION['user_id'] ?? 0),
    'action_type' => AuditAction::REPORT_EXPORT,
    'description' => 'GIS location report exported (csv).',
]);

/** Mirrors locationSourceMeta() labels in admin-gis-dashboard.js. */
$locationLabel = static function (array $row): string {
    $apiLabel = trim((string) ($row['location_accuracy_label'] ?? ''));
    if ($apiLabel !== '') {
        return $apiLabel;
    }
    $quality = strtoupper((string) ($row['location_quality'] ?? ''));
    $source = strtolower((string) ($row['location_source'] ?? ''));
    $accuracy = strtolower((string) ($row['location_accuracy'] ?? ''));
    if ($quality === 'NEEDS_VERIFICATION' || $source === 'needs_verification' || $accuracy === 'needs_verification') {
        return 'Location Needs Verification';
    }
    if ($source === 'unavailable' || $accuracy === 'unavailable' || $source === '') {
        return 'Location unavailable';
    }
    if (in_array($source, ['gps', 'manual', 'imported'], true) || $accuracy === 'exact' || $quality === 'EXACT_LOCATION') {
        return 'Exact Location — Verified Coordinates';
    }
    if ($quality === 'PUROK_LOCATION' || in_array($source, ['purok_center', 'purok_centroid'], true)) {
        return 'Purok Location — Approximate';
    }

    return 'Barangay Location — Approximate';
};

$out = csv_export_begin('medConnect_GIS_Report_' . date('Y-m-d') . '.csv');
csv_export_row($out, ['medConnect GIS Location Report']);
csv_export_row($out, ['Generated', csv_export_generated_at()]);
if ($selectedBarangay !== '') {
    csv_export_row($out, ['Barangay', $selectedBarangay]);
}
if ($triageLayer !== 'all') {
    csv_export_row($out, ['Triage Level', $triageLabels[$triageLayer]]);
}
csv_export_row($out, ['Total Records', count($rows)]);
csv_export_row($out, []);
csv_export_row($out, [
    'Patient No.', 'Patient Name', 'Triage Level', 'Barangay', 'Purok', 'Municipality', 'Province',
    'Registration Date', 'Location', 'Status', 'Address', 'Latitude', 'Longitude',
]);

foreach ($rows as $row) {
    $level = strtolower((string) ($row['triage_level'] ?? ''));
    $patientNo = trim((string) ($row['patient_number'] ?? ''));
    if ($patientNo === '' && (int) ($row['patient_id'] ?? 0) > 0) {
        $patientNo = 'MC-' . str_pad((string) (int) $row['patient_id'], 6, '0', STR_PAD_LEFT);
    }
    $purok = trim((string) ($row['purok'] ?? ''));
    $address = trim((string) ($row['display_address'] ?? ($row['address'] ?? '')));
    csv_export_row($out, [
        $patientNo !== '' ? $patientNo : '—',
        $row['patient_name'] ?? '',
        $triageLabels[$level] ?? $triageLabels['non_urgent'],
        trim((string) ($row['barangay'] ?? '')) !== '' ? $row['barangay'] : 'Unknown',
        $purok !== '' ? $purok : '—',
        $row['municipality'] ?? '',
        $row['province'] ?? '',
        csv_export_date((string) ($row['registration_date'] ?? '')),
        $locationLabel($row),
        $row['patient_status'] ?? '',
        $address !== '' ? $address : '—',
        $row['latitude'] ?? '',
        $row['longitude'] ?? '',
    ]);
}

fclose($out);
exit;
