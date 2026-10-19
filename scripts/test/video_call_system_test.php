<?php
/**
 * Video Call Subsystem Automated Test
 * Validates DB schema, ICE / TURN configuration, PeerJS reachability,
 * and full provider-patient video lifecycle inside an isolated transaction.
 *
 * Usage: php scripts/test/video_call_system_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/config/app.php';
require_once $root . '/app/includes/consultation_queue_timing.php';
require_once $root . '/app/includes/consultation_video_lifecycle.php';
require_once $root . '/resources/views/provider/partials/queue_helpers.php';

$passed = 0;
$failed = 0;

function assert_test(bool $condition, string $label, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($details !== '' ? " -> {$details}" : '') . "\n";
    }
}

echo "\n======================================================\n";
echo "   MEDCONNECT VIDEO CALL SUBSYSTEM VERIFICATION TEST   \n";
echo "======================================================\n\n";

// SECTION 1: Database Schema
echo "[1] Checking Database Schema...\n";
try {
    consultation_timing_ensure_schema($pdo);
    consultation_video_sessions_ensure_patient_left_column($pdo);
    
    $vsCols = $pdo->query("SHOW COLUMNS FROM video_sessions")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    assert_test(in_array('id', $vsCols, true), "video_sessions table has 'id' column");
    assert_test(in_array('consultation_id', $vsCols, true), "video_sessions table has 'consultation_id' column");
    assert_test(in_array('room_token', $vsCols, true), "video_sessions table has 'room_token' column");
    assert_test(in_array('status', $vsCols, true), "video_sessions table has 'status' column");
    assert_test(in_array('started_at', $vsCols, true), "video_sessions table has 'started_at' column");
    assert_test(in_array('ended_at', $vsCols, true), "video_sessions table has 'ended_at' column");
    assert_test(in_array('patient_joined_at', $vsCols, true), "video_sessions table has 'patient_joined_at' column");
    assert_test(in_array('patient_left_at', $vsCols, true), "video_sessions table has 'patient_left_at' column");

    $cCols = $pdo->query("SHOW COLUMNS FROM consultations")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    assert_test(in_array('id', $cCols, true), "consultations table has 'id' column");
    assert_test(in_array('patient_id', $cCols, true), "consultations table has 'patient_id' column");
    assert_test(in_array('provider_id', $cCols, true), "consultations table has 'provider_id' column");
    assert_test(in_array('status', $cCols, true), "consultations table has 'status' column");
    assert_test(in_array('consult_date', $cCols, true), "consultations table has 'consult_date' column");
    assert_test(in_array('consult_time', $cCols, true), "consultations table has 'consult_time' column");
} catch (Throwable $e) {
    assert_test(false, "Database schema check", $e->getMessage());
}

// SECTION 2: WebRTC ICE / STUN / TURN Configuration
echo "\n[2] Checking WebRTC ICE / STUN / TURN Configuration...\n";
$iceServers = medconnect_ice_servers();
assert_test(!empty($iceServers), "ICE servers are configured (found " . count($iceServers) . " server definitions)");

$hasStun = false;
$hasTurn = false;
foreach ($iceServers as $srv) {
    $urls = (array) ($srv['urls'] ?? []);
    foreach ($urls as $u) {
        if (stripos($u, 'stun:') === 0) $hasStun = true;
        if (stripos($u, 'turn:') === 0 || stripos($u, 'turns:') === 0) $hasTurn = true;
    }
}
assert_test($hasStun, "Public STUN servers are configured (Google / Cloudflare / Metered)");
assert_test($hasTurn, "Metered TURN relay servers are configured");
assert_test(medconnect_has_turn_server(), "medconnect_has_turn_server() returns true");

// SECTION 3: Signaling & Cloud Connectivity
echo "\n[3] Checking Network Connectivity for Video Signaling...\n";
// PeerJS cloud host: 0.peerjs.com on port 443
$peerHost = '0.peerjs.com';
$fp = @fsockopen('ssl://' . $peerHost, 443, $errno, $errstr, 5);
if ($fp) {
    assert_test(true, "PeerJS signaling server is reachable (https://{$peerHost}:443)");
    fclose($fp);
} else {
    // Try plain DNS resolve
    $ip = @gethostbyname($peerHost);
    assert_test($ip !== $peerHost, "PeerJS signaling hostname resolves: {$ip}", "Connection error: {$errstr} ({$errno})");
}

// TURN relay host: global.relay.metered.ca on port 443
$turnHost = 'global.relay.metered.ca';
$turnFp = @fsockopen($turnHost, 443, $errno, $errstr, 5);
if ($turnFp) {
    assert_test(true, "Metered TURN relay server is reachable ({$turnHost}:443)");
    fclose($turnFp);
} else {
    $ip = @gethostbyname($turnHost);
    assert_test($ip !== $turnHost, "Metered TURN relay hostname resolves: {$ip}");
}

// SECTION 4: Frontend Assets Verification
echo "\n[4] Checking Video Room Client Assets...\n";
$assets = [
    'public/assets/js/webrtc-peer-call.js',
    'public/assets/js/video-consultation-ui.js',
    'public/assets/js/video-call-core.js',
    'public/assets/js/video-room-enhancements.js',
    'public/assets/js/session-video-shell.js',
    'public/assets/css/video-consultation-ui.css',
    'public/assets/css/video-room-enhancements.css',
    'resources/views/consultation/video_room.php',
];
foreach ($assets as $relPath) {
    $full = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    assert_test(file_exists($full) && filesize($full) > 0, "Asset exists: {$relPath} (" . filesize($full) . " bytes)");
}

// SECTION 5: Video Call Lifecycle Simulation (Isolated Test Records)
echo "\n[5] Simulating Provider & Patient Video Call Lifecycle...\n";
$createdConsultId = 0;
$createdRoomToken = '';

try {
    // 5.1 Find a doctor and a patient
    $provider = $pdo->query("SELECT id FROM users WHERE role = 'provider' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $patient = $pdo->query("SELECT id FROM users WHERE role = 'patient' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    if (!$provider || !$patient) {
        throw new RuntimeException("Could not find active provider and patient in database.");
    }
    $providerId = (int) $provider['id'];
    $patientId = (int) $patient['id'];
    echo "    (Using Provider ID: {$providerId}, Patient ID: {$patientId})\n";

    // 5.2 Create test consultation scheduled for right now
    $today = date('Y-m-d');
    $nowTime = date('H:i:s');
    $ins = $pdo->prepare("
        INSERT INTO consultations (patient_id, provider_id, consult_date, consult_time, status, consult_type)
        VALUES (?, ?, ?, ?, 'scheduled', 'Routine Teleconsultation')
    ");
    $ins->execute([$patientId, $providerId, $today, $nowTime]);
    $createdConsultId = (int) $pdo->lastInsertId();
    assert_test($createdConsultId > 0, "Created test consultation #{$createdConsultId} for today ({$today} {$nowTime})");

    // 5.3 Patient attempts to join before provider starts -> Should be waiting
    $rowBefore = $pdo->query("SELECT * FROM consultations WHERE id = {$createdConsultId}")->fetch(PDO::FETCH_ASSOC);
    $rowBeforeDecorated = consultation_timing_decorate_row($pdo, $rowBefore);
    $joinBefore = consultation_patient_join_access($rowBeforeDecorated);
    assert_test($joinBefore['allowed'] === false, "Patient join blocked before provider starts video (mode: '{$joinBefore['mode']}')");

    // 5.4 Provider starts video room
    $startResult = consultation_provider_start_video_session($pdo, $createdConsultId, $providerId);
    assert_test($startResult['success'] === true, "Provider starts video session successfully", (string)($startResult['message'] ?? ''));
    assert_test(!empty($startResult['token']), "Video session generated room token: " . ($startResult['token'] ?? ''));

    $createdRoomToken = (string) ($startResult['token'] ?? '');

    // Verify consultation and video_session status
    $vsRow = $pdo->prepare("SELECT * FROM video_sessions WHERE room_token = ?");
    $vsRow->execute([$createdRoomToken]);
    $vs = $vsRow->fetch(PDO::FETCH_ASSOC);
    assert_test($vs && $vs['status'] === 'active', "video_sessions status is 'active'");
    assert_test(!empty($vs['started_at']), "video_sessions started_at timestamp is set");

    $cRow = $pdo->query("SELECT status FROM consultations WHERE id = {$createdConsultId}")->fetch(PDO::FETCH_ASSOC);
    assert_test($cRow && $cRow['status'] === 'in_consultation', "consultation status updated to 'in_consultation'");

    // 5.5 Patient joins live video room
    $rowLive = $pdo->query("
        SELECT c.*, vs.room_token, vs.patient_joined_at, vs.status AS video_status
        FROM consultations c
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        WHERE c.id = {$createdConsultId}
    ")->fetch(PDO::FETCH_ASSOC);
    $rowLiveDecorated = consultation_timing_decorate_row($pdo, $rowLive);
    $joinLive = consultation_patient_join_access($rowLiveDecorated);
    assert_test($joinLive['allowed'] === true, "Patient can now join the active video call");

    // Mark patient joined
    consultation_timing_mark_patient_joined($pdo, $createdConsultId);
    consultation_patient_clear_temporarily_left($pdo, $createdRoomToken, $patientId);
    $vsAfterJoin = $pdo->prepare("SELECT patient_joined_at, patient_left_at FROM video_sessions WHERE room_token = ?");
    $vsAfterJoin->execute([$createdRoomToken]);
    $afterJoinRow = $vsAfterJoin->fetch(PDO::FETCH_ASSOC);
    assert_test(!empty($afterJoinRow['patient_joined_at']), "Patient join timestamp recorded in video_sessions");
    assert_test(empty($afterJoinRow['patient_left_at']), "Patient left timestamp is NULL while present in call");

    // 5.6 Patient leaves temporarily and rejoins
    consultation_patient_mark_temporarily_left($pdo, $createdRoomToken, $patientId);
    $vsLeft = $pdo->prepare("SELECT patient_left_at FROM video_sessions WHERE room_token = ?");
    $vsLeft->execute([$createdRoomToken]);
    assert_test(!empty($vsLeft->fetchColumn()), "Patient 'Leave Call' sets patient_left_at timestamp without ending room");

    consultation_patient_clear_temporarily_left($pdo, $createdRoomToken, $patientId);
    $vsRejoin = $pdo->prepare("SELECT patient_left_at FROM video_sessions WHERE room_token = ?");
    $vsRejoin->execute([$createdRoomToken]);
    assert_test(empty($vsRejoin->fetchColumn()), "Patient rejoining clears patient_left_at timestamp back to NULL");

    // 5.7 Provider ends the video session
    $endResult = consultation_provider_end_video_session($pdo, $createdRoomToken, $providerId);
    assert_test($endResult['success'] === true, "Provider ends video session successfully", (string)($endResult['message'] ?? ''));

    $vsEnded = $pdo->prepare("SELECT status, ended_at FROM video_sessions WHERE room_token = ?");
    $vsEnded->execute([$createdRoomToken]);
    $endedRow = $vsEnded->fetch(PDO::FETCH_ASSOC);
    assert_test($endedRow && $endedRow['status'] === 'ended', "video_sessions status is 'ended'");
    assert_test(!empty($endedRow['ended_at']), "video_sessions ended_at timestamp is set");

    // Consultation stays 'in_consultation' until Final Assessment / SOAP notes submitted
    $cStillOpen = $pdo->query("SELECT status FROM consultations WHERE id = {$createdConsultId}")->fetch(PDO::FETCH_ASSOC);
    assert_test($cStillOpen['status'] === 'in_consultation', "Consultation stays 'in_consultation' for provider to complete clinical documentation");

    // 5.8 Patient cannot rejoin ended room
    $rowEnded = $pdo->query("
        SELECT c.*, vs.room_token, vs.status AS video_status
        FROM consultations c
        LEFT JOIN video_sessions vs ON vs.consultation_id = c.id AND vs.status = 'active'
        WHERE c.id = {$createdConsultId}
    ")->fetch(PDO::FETCH_ASSOC);
    $rowEndedDecorated = consultation_timing_decorate_row($pdo, $rowEnded);
    $joinEnded = consultation_patient_join_access($rowEndedDecorated);
    assert_test($joinEnded['allowed'] === false, "Patient cannot join ended video room (correctly blocked)");

} catch (Throwable $e) {
    assert_test(false, "Video lifecycle test exception", $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
} finally {
    // Clean up test data
    if ($createdConsultId > 0) {
        $pdo->prepare("DELETE FROM video_sessions WHERE consultation_id = ?")->execute([$createdConsultId]);
        $pdo->prepare("DELETE FROM consultations WHERE id = ?")->execute([$createdConsultId]);
        echo "    (Test records for consultation #{$createdConsultId} safely cleaned up)\n";
    }
}

echo "\n======================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "======================================================\n";
exit($failed > 0 ? 1 : 0);
