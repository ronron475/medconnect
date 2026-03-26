<?php
session_start();
require_once __DIR__ . '/../../config/db.php';

// Auth check
if (empty($_SESSION['user_id']) || $_SESSION['user_role'] !== 'patient') {
    header('Location: ../../public/index.php');
    exit;
}

// Fetch patient data
$stmt = $pdo->prepare("
    SELECT u.first_name, u.last_name, u.email,
           p.age, p.gender, p.full_address, p.blood_type,
           p.philhealth_status, p.contact_number, p.status,
           p.barangay, p.city_municipality, p.province
    FROM users u
    LEFT JOIN patient_registrations p ON p.email = u.email
    WHERE u.id = ? LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

$full_name = htmlspecialchars(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
$initials  = strtoupper(substr($patient['first_name'] ?? 'P', 0, 1) . substr($patient['last_name'] ?? '', 0, 1));
$age       = $patient['age'] ?? '—';
$gender    = $patient['gender'] ?? '—';
$address   = $patient['city_municipality'] ?? ($patient['full_address'] ?? '—');
$status    = $patient['status'] ?? 'active';
$now       = date('h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — medConnect</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Segoe UI', system-ui, sans-serif;
      background: #f0f4f8;
      display: flex;
      min-height: 100vh;
      color: #1e293b;
    }

    /* ── Sidebar ── */
    .sidebar {
      width: 240px;
      min-height: 100vh;
      background: linear-gradient(180deg, #0b1f3a 0%, #0f2d50 100%);
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
      position: fixed;
      top: 0; left: 0; bottom: 0;
    }
    .sidebar-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 24px 20px 20px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .logo-icon {
      width: 36px; height: 36px;
      border-radius: 9px;
      background: linear-gradient(135deg, #0d9488, #0ea5e9);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
    }
    .logo-icon svg { width: 20px; height: 20px; }
    .logo-text { font-size: 18px; font-weight: 800; color: #fff; }
    .logo-text span { color: #2dd4bf; }

    .sidebar-nav { flex: 1; padding: 20px 12px; }
    .nav-label {
      font-size: 10px;
      font-weight: 700;
      color: rgba(255,255,255,0.35);
      text-transform: uppercase;
      letter-spacing: 0.8px;
      padding: 0 8px;
      margin-bottom: 8px;
    }
    .nav-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 10px;
      color: rgba(255,255,255,0.6);
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      transition: background 0.2s, color 0.2s;
      cursor: pointer;
    }
    .nav-item:hover { background: rgba(255,255,255,0.07); color: #fff; }
    .nav-item.active {
      background: linear-gradient(135deg, rgba(13,148,136,0.3), rgba(14,165,233,0.2));
      color: #fff;
      border: 1px solid rgba(45,212,191,0.2);
    }
    .nav-item svg { width: 17px; height: 17px; flex-shrink: 0; }

    .sidebar-profile {
      margin: 12px;
      padding: 14px;
      background: rgba(255,255,255,0.06);
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.08);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .profile-avatar {
      width: 38px; height: 38px;
      border-radius: 50%;
      background: linear-gradient(135deg, #0d9488, #0ea5e9);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; font-weight: 700; color: #fff;
      flex-shrink: 0;
    }
    .profile-info { overflow: hidden; }
    .profile-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .profile-role { font-size: 11px; color: rgba(255,255,255,0.45); }

    /* ── Main ── */
    .main {
      margin-left: 240px;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    /* ── Header ── */
    .header {
      background: #fff;
      padding: 0 28px;
      height: 64px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid #e2e8f0;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .header-title { font-size: 20px; font-weight: 800; color: #0f172a; }
    .header-right { display: flex; align-items: center; gap: 14px; }
    .header-time { font-size: 13px; color: #64748b; font-weight: 500; }
    .btn-notif {
      position: relative;
      background: none;
      border: none;
      cursor: pointer;
      color: #64748b;
      padding: 6px;
      border-radius: 8px;
      transition: background 0.2s;
    }
    .btn-notif:hover { background: #f1f5f9; }
    .notif-badge {
      position: absolute;
      top: 4px; right: 4px;
      width: 8px; height: 8px;
      background: #ef4444;
      border-radius: 50%;
      border: 2px solid #fff;
    }
    .btn-logout {
      padding: 8px 18px;
      border-radius: 9px;
      border: none;
      background: linear-gradient(135deg, #0d9488, #0ea5e9);
      color: #fff;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: opacity 0.2s;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn-logout:hover { opacity: 0.88; }

    /* ── Content ── */
    .content { padding: 28px; flex: 1; }

    /* ── Welcome Card ── */
    .welcome-card {
      background: linear-gradient(135deg, #0d9488 0%, #0ea5e9 60%, #38bdf8 100%);
      border-radius: 20px;
      padding: 32px 36px;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
      box-shadow: 0 8px 32px rgba(13,148,136,0.28);
      margin-bottom: 28px;
      position: relative;
      overflow: hidden;
    }
    .welcome-card::before {
      content: '';
      position: absolute;
      top: -40px; right: -40px;
      width: 200px; height: 200px;
      border-radius: 50%;
      background: rgba(255,255,255,0.07);
    }
    .welcome-card::after {
      content: '';
      position: absolute;
      bottom: -60px; right: 80px;
      width: 160px; height: 160px;
      border-radius: 50%;
      background: rgba(255,255,255,0.05);
    }
    .welcome-left { position: relative; z-index: 1; }
    .welcome-greeting { font-size: 14px; font-weight: 500; opacity: 0.85; margin-bottom: 4px; }
    .welcome-name { font-size: 28px; font-weight: 800; margin-bottom: 10px; letter-spacing: -0.5px; }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255,255,255,0.2);
      border: 1px solid rgba(255,255,255,0.35);
      padding: 4px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      backdrop-filter: blur(4px);
    }
    .status-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: #4ade80;
      box-shadow: 0 0 6px #4ade80;
    }
    .welcome-info {
      display: flex;
      gap: 28px;
      margin-top: 20px;
    }
    .info-item { display: flex; flex-direction: column; gap: 2px; }
    .info-label { font-size: 11px; opacity: 0.7; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
    .info-value { font-size: 15px; font-weight: 700; }

    .welcome-right { position: relative; z-index: 1; }
    .btn-edit {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 11px 22px;
      border-radius: 11px;
      border: 2px solid rgba(255,255,255,0.5);
      background: rgba(255,255,255,0.15);
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      backdrop-filter: blur(8px);
      transition: background 0.2s, border-color 0.2s;
    }
    .btn-edit:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.7); }

    /* ── Info Cards ── */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 18px;
    }
    .info-card {
      background: #fff;
      border-radius: 16px;
      padding: 22px 24px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 2px 12px rgba(0,0,0,0.05);
    }
    .info-card-label {
      font-size: 11px;
      font-weight: 700;
      color: #94a3b8;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .info-card-label svg { width: 13px; height: 13px; color: #0d9488; }
    .info-card-value { font-size: 16px; font-weight: 700; color: #0f172a; }
    .info-card-sub { font-size: 12px; color: #64748b; margin-top: 3px; }

    @media (max-width: 768px) {
      .sidebar { display: none; }
      .main { margin-left: 0; }
      .cards-grid { grid-template-columns: 1fr; }
      .welcome-card { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </div>
    <div class="logo-text">med<span>Connect</span></div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-label">Menu</div>
    <a href="dashboard.php" class="nav-item active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      My Profile
    </a>
  </nav>

  <div class="sidebar-profile">
    <div class="profile-avatar"><?= $initials ?></div>
    <div class="profile-info">
      <div class="profile-name"><?= $full_name ?></div>
      <div class="profile-role">Patient</div>
    </div>
  </div>
</aside>

<!-- Main -->
<div class="main">

  <!-- Header -->
  <header class="header">
    <div class="header-title">Overview</div>
    <div class="header-right">
      <span class="header-time"><?= $now ?></span>
      <button class="btn-notif" aria-label="Notifications">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="notif-badge"></span>
      </button>
      <a href="../../logout.php" class="btn-logout">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Logout
      </a>
    </div>
  </header>

  <!-- Content -->
  <div class="content">

    <!-- Welcome Card -->
    <div class="welcome-card">
      <div class="welcome-left">
        <div class="welcome-greeting">Welcome back,</div>
        <div class="welcome-name"><?= $full_name ?></div>
        <div class="status-badge">
          <span class="status-dot"></span>
          Active
        </div>
        <div class="welcome-info">
          <div class="info-item">
            <span class="info-label">Age</span>
            <span class="info-value"><?= $age ?> yrs</span>
          </div>
          <div class="info-item">
            <span class="info-label">Gender</span>
            <span class="info-value"><?= htmlspecialchars($gender) ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Address</span>
            <span class="info-value"><?= htmlspecialchars($address) ?></span>
          </div>
        </div>
      </div>
      <div class="welcome-right">
        <a href="profile.php" class="btn-edit">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Edit Profile
        </a>
      </div>
    </div>

    <!-- Info Cards -->
    <div class="cards-grid">
      <div class="info-card">
        <div class="info-card-label">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          Blood Type
        </div>
        <div class="info-card-value"><?= htmlspecialchars($patient['blood_type'] ?? '—') ?></div>
        <div class="info-card-sub">Blood group</div>
      </div>
      <div class="info-card">
        <div class="info-card-label">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.15 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.06 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"/></svg>
          Contact
        </div>
        <div class="info-card-value"><?= htmlspecialchars($patient['contact_number'] ?? '—') ?></div>
        <div class="info-card-sub">Mobile number</div>
      </div>
      <div class="info-card">
        <div class="info-card-label">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          PhilHealth
        </div>
        <div class="info-card-value"><?= htmlspecialchars($patient['philhealth_status'] ?? '—') ?></div>
        <div class="info-card-sub">Membership status</div>
      </div>
    </div>

  </div>
</div>

</body>
</html>
