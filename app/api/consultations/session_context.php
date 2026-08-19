<?php
/**
 * Video consultation context — metadata for in-call panels (waiting, info, clinical).
 */
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/resources/views/provider/partials/queue_helpers.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/provider_clinical_support.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/includes/patient_health_summary.php';

Api::startJson();

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    Api::error('Room token is required.');
}

$uid  = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['user_role'] ?? '');
if ($uid <= 0 || $role === '') {
    Api::error('Authentication required.', 401);
}

$stmt = $pdo->prepare("
    SELECT vs.*, c.id AS consultation_id, c.patient_id, c.provider_id,
           c.consult_date, c.consult_time, c.status AS consult_status, c.provider_name,
           c.consult_type,
           p.first_name AS patient_first, p.last_name AS patient_last,
           pr.date_of_birth AS patient_dob, pr.age AS patient_age, pr.gender AS patient_sex,
           d.first_name AS doctor_first, d.last_name AS doctor_last,
           pp.specialty AS provider_specialty,
           s.slot_date, s.start_time AS slot_start, s.end_time AS slot_end
    FROM video_sessions vs
    JOIN consultations c ON vs.consultation_id = c.id
    LEFT JOIN users p ON c.patient_id = p.id
    LEFT JOIN patient_registrations pr ON pr.user_id = c.patient_id
    LEFT JOIN users d ON c.provider_id = d.id
    LEFT JOIN provider_profiles pp ON pp.user_id = c.provider_id
    LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
    WHERE vs.room_token = ? AND vs.status = 'active'
    LIMIT 1
");
try {
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('session_context query: ' . $e->getMessage());
    Api::error('Could not load consultation details.', 500);
}

if (!$row) {
    Api::error('Active consultation session not found.', 404);
}

$patientId    = (int) ($row['patient_id'] ?? 0);
$providerId   = (int) ($row['provider_id'] ?? 0);
$consultId    = (int) ($row['consultation_id'] ?? 0);
$isPatient    = $role === 'patient' && $uid === $patientId;
$isProvider   = $role === 'provider' && $uid === $providerId;

if (!$isPatient && !$isProvider) {
    Api::error('Access denied.', 403);
}

$providerName = trim(($row['doctor_first'] ?? '') . ' ' . ($row['doctor_last'] ?? ''));
if ($providerName === '' && !empty($row['provider_name'])) {
    $providerName = trim((string) $row['provider_name']);
}
$patientName = trim(($row['patient_first'] ?? '') . ' ' . ($row['patient_last'] ?? ''));

$slotDate = (string) ($row['slot_date'] ?? $row['consult_date'] ?? '');
$slotStart = (string) ($row['slot_start'] ?? $row['consult_time'] ?? '');
$slotEnd   = (string) ($row['slot_end'] ?? '');

$appointmentLabel = '';
if ($slotDate !== '') {
    $appointmentLabel = date('l, M j, Y', strtotime($slotDate));
    if ($slotStart !== '') {
        $appointmentLabel .= ' · ' . date('g:i A', strtotime($slotStart));
        if ($slotEnd !== '') {
            $appointmentLabel .= ' – ' . date('g:i A', strtotime($slotEnd));
        }
    }
}

$clinical = [];
try {
    $clinical = provider_consultation_clinical_support($pdo, $consultId, $patientId);
} catch (Throwable $e) {
    error_log('session_context clinical: ' . $e->getMessage());
    $clinical = [];
}
$chiefComplaint = trim((string) ($clinical['chief_complaint'] ?? $clinical['patient_original_complaint'] ?? $row['consult_type'] ?? ''));

$waiting = [
    'doctor_name'         => $providerName !== '' ? 'Dr. ' . preg_replace('/^dr\.?\s*/i', '', $providerName) : 'your healthcare provider',
    'patient_name'        => $patientName !== '' ? $patientName : 'your patient',
    'title'               => $isPatient
        ? ('Waiting for ' . ($providerName !== '' ? 'Dr. ' . preg_replace('/^dr\.?\s*/i', '', $providerName) : 'your healthcare provider'))
        : ('Waiting for ' . ($patientName !== '' ? $patientName : 'your patient')),
    'subtitle'            => $isPatient
        ? 'Your visit will begin automatically when your doctor joins.'
        : 'The visit will begin automatically when your patient joins the call.',
    'appointment_label'   => $appointmentLabel,
    'estimated_wait'      => $isPatient
        ? 'Usually within 5–10 minutes after your scheduled time'
        : 'Patient can join from their dashboard once you start the room',
    'doctor_status'       => ($row['consult_status'] ?? '') === 'in_consultation' ? 'In clinic — connecting' : 'Preparing session',
    'patient_status'      => 'Waiting to join',
    'queue_position'      => null,
    'connection_status'   => 'Secure signaling active',
];

$patientAge = trim((string) ($row['patient_age'] ?? ''));
if ($patientAge === '' && !empty($row['patient_dob'])) {
    try {
        $dob = new DateTime((string) $row['patient_dob']);
        $patientAge = (string) $dob->diff(new DateTime('today'))->y;
    } catch (Throwable $e) {
        $patientAge = '';
    }
}

$health = [
    'allergies' => [],
    'conditions' => [],
    'medications' => [],
    'blood_type' => '—',
];
try {
    $loaded = patient_health_summary_load($pdo, $patientId);
    if (is_array($loaded)) {
        $health = array_merge($health, $loaded);
    }
} catch (Throwable $e) {
    error_log('session_context health: ' . $e->getMessage());
}

$patientNumber = 'MC-' . str_pad((string) $patientId, 6, '0', STR_PAD_LEFT);
$aiClass = trim((string) ($clinical['ai_urgency'] ?? ''));
$finalClass = trim((string) ($clinical['final_urgency'] ?? ''));
if ($finalClass === '') {
    $finalClass = $aiClass !== '' ? $aiClass : 'Not assessed';
}
if ($aiClass === '') {
    $aiClass = 'Not assessed';
}
$finalBucket = (string) ($clinical['risk_bucket'] ?? $clinical['doctor_urgency_bucket'] ?? 'unknown');
$aiBucket = (string) ($clinical['ai_urgency_bucket'] ?? 'unknown');
$finalizedBy = !empty($clinical['manual_urgency']) ? 'Doctor' : '';

$patientPanel = [
    'doctor_name'       => $waiting['doctor_name'],
    'specialization'    => trim((string) ($row['provider_specialty'] ?? 'General Medicine')) ?: 'General Medicine',
    'appointment_label' => $appointmentLabel,
    'chief_complaint'   => $chiefComplaint,
    'triage_level'      => $finalClass,
    'triage_bucket'     => $finalBucket,
    'ai_triage_level'   => $aiClass,
    'ai_triage_bucket'  => $aiBucket,
    'final_triage_level'=> $finalClass,
    'finalized_by'      => $finalizedBy,
    'consultation_id'   => $consultId,
];

$providerPanel = [
    'patient_name'         => $patientName,
    'patient_number'       => $patientNumber,
    'age'                  => $patientAge,
    'sex'                  => (string) ($row['patient_sex'] ?? '—'),
    'chief_complaint'      => $chiefComplaint,
    'ai_classification'    => $aiClass !== '' ? $aiClass : 'Not assessed',
    'final_classification' => $finalClass !== '' ? $finalClass : '—',
    'confidence'           => (string) ($clinical['confidence_display'] ?? ''),
    'appointment_label'    => $appointmentLabel,
    'consultation_id'      => $consultId,
    'allergies'            => $health['allergies'] ?? [],
    'conditions'           => $health['conditions'] ?? [],
    'medications'          => $health['medications'] ?? [],
    'blood_type'           => (string) ($health['blood_type'] ?? '—'),
    'possible_conditions'  => $clinical['possible_conditions'] ?? [],
];

Api::success([
    'consultation_id' => $consultId,
    'role'            => $isPatient ? 'patient' : 'provider',
    'waiting'         => $waiting,
    'patient_panel'   => $patientPanel,
    'provider_panel'  => $providerPanel,
    'clinical'        => $clinical,
]);
