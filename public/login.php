<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// Basic input validation
if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Auto-detect role based on email or check all roles
// First try to find user by email across all roles
$stmt = $pdo->prepare("SELECT id, first_name, last_name, email, password, role, is_active, is_email_verified FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

if (!$user['is_active']) {
    echo json_encode(['success' => false, 'message' => 'Your account is inactive. Please contact the administrator.']);
    exit;
}

// Set session
$_SESSION['user_id']    = $user['id'];
$_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role']  = $user['role'];

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appRootUrl = $protocol . '://' . $host . dirname(dirname($_SERVER['PHP_SELF']));

$redirect = match($user['role']) {
    'patient'  => $appRootUrl . '/views/patient/dashboard.php',
    'provider' => $appRootUrl . '/provider/dashboard.php',
    default    => $appRootUrl . '/public/index.php',
};

echo json_encode(['success' => true, 'redirect' => $redirect]);
