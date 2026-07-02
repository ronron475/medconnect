<?php
session_start();
header('Content-Type: application/json');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

$action = $_POST['action'] ?? 'save';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'archive') {
    $pdo->prepare('UPDATE barangays SET is_active = 0, archived_at = NOW() WHERE id = ?')->execute([$id]);
    echo json_encode(['success' => true, 'message' => 'Barangay archived.']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$city = trim($_POST['city'] ?? 'Bago City');
$lat = $_POST['latitude'] !== '' ? $_POST['latitude'] : null;
$lng = $_POST['longitude'] !== '' ? $_POST['longitude'] : null;

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Name required.']);
    exit;
}

if ($id > 0) {
    $pdo->prepare('UPDATE barangays SET name=?, city=?, latitude=?, longitude=? WHERE id=?')->execute([$name, $city, $lat, $lng, $id]);
} else {
    $pdo->prepare('INSERT INTO barangays (name, city, latitude, longitude, is_active) VALUES (?,?,?,?,1)')->execute([$name, $city, $lat, $lng]);
}
echo json_encode(['success' => true, 'message' => 'Barangay saved.']);
