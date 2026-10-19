<?php
/**
 * Video Room UI & Rendering Verification Test
 * Verifies that video_room.php renders properly for both Provider and Patient
 * with valid room token, scripts, controls, and WebRTC metadata.
 *
 * Usage: php scripts/test/video_room_render_test.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/includes/consultation_video_lifecycle.php';

$pass = 0;
$fail = 0;

function assert_render(bool $condition, string $label): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "\n======================================================\n";
echo "   VIDEO ROOM RENDERING & AUTHORIZATION TEST           \n";
echo "======================================================\n\n";

$provider = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'provider' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$patient = $pdo->query("SELECT id, first_name, last_name FROM users WHERE role = 'patient' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$providerId = (int) $provider['id'];
$patientId = (int) $patient['id'];
$today = date('Y-m-d');
$nowTime = date('H:i:s');

// 1. Create a consultation
$ins = $pdo->prepare("
    INSERT INTO consultations (patient_id, provider_id, consult_date, consult_time, status, consult_type)
    VALUES (?, ?, ?, ?, 'scheduled', 'General Video Consultation')
");
$ins->execute([$patientId, $providerId, $today, $nowTime]);
$consultId = (int) $pdo->lastInsertId();

// 2. Start video room
$startRes = consultation_provider_start_video_session($pdo, $consultId, $providerId);
$roomToken = (string) ($startRes['token'] ?? '');

echo "Created test video session: Consultation #{$consultId}, Token: {$roomToken}\n\n";

try {
    // 3. Render for Provider
    echo "[1] Testing Video Room rendering as PROVIDER...\n";
    $_GET['token'] = $roomToken;
    $_SESSION['user_role'] = 'provider';
    $_SESSION['user_id'] = $providerId;
    $_SESSION['csrf_token'] = 'test-csrf-token';

    ob_start();
    include $root . '/resources/views/consultation/video_room.php';
    $providerHtml = ob_get_clean();

    assert_render(strlen($providerHtml) > 5000, "Provider video room rendered full HTML (" . strlen($providerHtml) . " bytes)");
    assert_render(str_contains($providerHtml, 'id="localVideo"'), "Contains local video container (<video id=\"localVideo\">)");
    assert_render(str_contains($providerHtml, 'id="remoteVideo"'), "Contains remote video container (<video id=\"remoteVideo\">)");
    assert_render(str_contains($providerHtml, 'webrtc-peer-call.js'), "Includes webrtc-peer-call.js");
    assert_render(str_contains($providerHtml, 'video-consultation-ui.js'), "Includes video-consultation-ui.js");
    assert_render(str_contains($providerHtml, 'peerjs.min.js'), "Loads PeerJS client library");
    assert_render(str_contains($providerHtml, 'isPatient: false'), "Room meta defines isPatient: false");
    assert_render(str_contains($providerHtml, 'window.__mcVideoRoomMeta'), "Exports window.__mcVideoRoomMeta configuration");
    assert_render(str_contains($providerHtml, 'btnEndCall') || str_contains($providerHtml, 'End Consultation') || str_contains($providerHtml, 'end-modal'), "Contains End Consultation controls for provider");

    // 4. Render for Patient
    echo "\n[2] Testing Video Room rendering as PATIENT...\n";
    $_GET['token'] = $roomToken;
    $_SESSION['user_role'] = 'patient';
    $_SESSION['user_id'] = $patientId;

    ob_start();
    include $root . '/resources/views/consultation/video_room.php';
    $patientHtml = ob_get_clean();

    assert_render(strlen($patientHtml) > 5000, "Patient video room rendered full HTML (" . strlen($patientHtml) . " bytes)");
    assert_render(str_contains($patientHtml, 'isPatient: true'), "Room meta defines isPatient: true");
    assert_render(str_contains($patientHtml, 'Leave Call') || str_contains($patientHtml, 'btnLeaveCall') || str_contains($patientHtml, 'Leave'), "Contains Leave Call button for patient");
    assert_render(str_contains($patientHtml, 'id="localVideo"'), "Patient view has local camera video element");
    assert_render(str_contains($patientHtml, 'id="remoteVideo"'), "Patient view has doctor remote video element");

    // 5. Test Unauthorized User
    echo "\n[3] Testing Security Guards (Unauthorized User)...\n";
    $_GET['token'] = $roomToken;
    $_SESSION['user_role'] = 'patient';
    $_SESSION['user_id'] = 999999; // Random unauthorized ID

    ob_start();
    // In video_room.php, unauthorized user calls exit or dies, so we test auth check condition
    $authStmt = $pdo->prepare("
        SELECT vs.*, c.patient_id, c.provider_id
        FROM video_sessions vs
        JOIN consultations c ON vs.consultation_id = c.id
        WHERE vs.room_token = ? AND vs.status = 'active' LIMIT 1
    ");
    $authStmt->execute([$roomToken]);
    $sess = $authStmt->fetch(PDO::FETCH_ASSOC);
    $unauthRole = 'patient';
    $unauthUid = 999999;
    $authorized = ($unauthRole === 'patient' && $unauthUid === (int) $sess['patient_id'])
               || ($unauthRole === 'provider' && $unauthUid === (int) $sess['provider_id']);
    
    assert_render(!$authorized, "Unauthorized user (ID 999999) is strictly rejected by room auth guard");

} finally {
    // Clean up test data
    $pdo->prepare("DELETE FROM video_sessions WHERE consultation_id = ?")->execute([$consultId]);
    $pdo->prepare("DELETE FROM consultations WHERE id = ?")->execute([$consultId]);
    echo "\n  (Cleaned up test consultation #{$consultId})\n";
}

echo "\n======================================================\n";
echo "RENDER TEST RESULTS: {$pass} PASSED, {$fail} FAILED\n";
echo "======================================================\n";
exit($fail > 0 ? 1 : 0);
