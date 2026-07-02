<?php
session_start();
header('Content-Type: application/json');
require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS facilities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(150) NOT NULL,
    facility_type VARCHAR(50) NOT NULL DEFAULT 'Hospital',
    address VARCHAR(255) NULL,
    contact_number VARCHAR(30) NULL,
    latitude DECIMAL(10,8) NULL,
    longitude DECIMAL(11,8) NULL,
    status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['facility_name'] ?? '');
$type = trim($_POST['facility_type'] ?? 'Hospital');
$address = trim($_POST['address'] ?? '');
$contact = trim($_POST['contact_number'] ?? '');
$lat = $_POST['latitude'] !== '' ? $_POST['latitude'] : null;
$lng = $_POST['longitude'] !== '' ? $_POST['longitude'] : null;

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Facility name required.']);
    exit;
}

if ($id > 0) {
    $pdo->prepare('UPDATE facilities SET facility_name=?, facility_type=?, address=?, contact_number=?, latitude=?, longitude=? WHERE id=?')
        ->execute([$name, $type, $address, $contact, $lat, $lng, $id]);
} else {
    $pdo->prepare('INSERT INTO facilities (facility_name, facility_type, address, contact_number, latitude, longitude, status) VALUES (?,?,?,?,?,?,\'active\')')
        ->execute([$name, $type, $address, $contact, $lat, $lng]);
}
echo json_encode(['success' => true, 'message' => 'Facility saved.']);
