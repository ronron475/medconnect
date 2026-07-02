<?php
/**
 * =============================================================================
 * index.php  —  Demo / Integration Page
 * =============================================================================
 * Simulates your existing registration flow after OCR determines
 * the applicant is NOT a Bago City resident.
 *
 * In your real system:
 *   1. Include referral_modal.php before </body>
 *   2. Call window.BagoReferral.show() wherever you process the OCR result
 * =============================================================================
 */
declare(strict_types=1);

// ── Simulated OCR output (your system already produces this) ──────────────────
$ocrResult = [
    'verified'         => true,
    'is_bago_resident' => false,       // ← The flag your OCR sets
    'applicant_name'   => 'Juan dela Cruz',
    'id_type'          => 'PhilSys National ID',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Healthcare Registration — Bago City Health Office</title>

  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
      background: #f1f5f9;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      margin: 0;
    }
    .demo-shell {
      background: #fff;
      border-radius: 16px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 4px 24px rgba(15,23,42,.07);
      width: 100%;
      max-width: 520px;
      overflow: hidden;
    }
    .demo-topbar {
      padding: 16px 20px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .demo-topbar .logo {
      width: 38px; height: 38px;
      background: #eff6ff;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      font-size: 18px; flex-shrink: 0;
    }
    .demo-topbar .title {
      font-size: 14px; font-weight: 700;
      color: #0f172a; margin: 0; line-height: 1.2;
    }
    .demo-topbar .subtitle {
      font-size: 11px; color: #64748b;
      margin: 0; font-weight: 500;
    }
    .demo-topbar .status-dot {
      margin-left: auto;
      width: 8px; height: 8px;
      background: #22c55e;
      border-radius: 50%;
      flex-shrink: 0;
      box-shadow: 0 0 0 3px rgba(34,197,94,.15);
    }
    .demo-body { padding: 20px; }
    .ocr-card {
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 14px;
    }
    .ocr-card-header {
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      padding: 9px 14px;
      font-size: 10px; font-weight: 700;
      color: #94a3b8;
      text-transform: uppercase; letter-spacing: .6px;
      display: flex; align-items: center; gap: 7px;
    }
    .ocr-card-body { padding: 2px 14px 8px; }
    .ocr-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 9px 0;
      border-bottom: 1px solid #f1f5f9;
      font-size: 13px; gap: 8px;
    }
    .ocr-row:last-child { border-bottom: none; }
    .ocr-label { color: #64748b; font-weight: 500; flex-shrink: 0; }
    .ocr-value { color: #0f172a; font-weight: 600; text-align: right; }
    .badge-ok  {
      background: #f0fdf4; color: #15803d;
      padding: 3px 10px; border-radius: 20px;
      font-size: 11px; font-weight: 700;
      border: 1px solid #bbf7d0; white-space: nowrap;
    }
    .badge-no  {
      background: #fef2f2; color: #dc2626;
      padding: 3px 10px; border-radius: 20px;
      font-size: 11px; font-weight: 700;
      border: 1px solid #fecaca; white-space: nowrap;
    }
    .alert-banner {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 14px;
      display: flex; align-items: flex-start; gap: 10px;
      font-size: 12.5px; color: #7f1d1d; line-height: 1.55;
    }
    .alert-banner .ab-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
    .alert-banner strong   { display: block; font-size: 13px; margin-bottom: 2px; }
    .btn-trigger {
      width: 100%;
      padding: 13px;
      background: #2563eb;
      color: #fff;
      border: none; border-radius: 10px;
      font-size: 14px; font-weight: 700;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: background .15s, transform .1s;
      min-height: 48px; touch-action: manipulation;
    }
    .btn-trigger:hover  { background: #1d4ed8; }
    .btn-trigger:active { transform: scale(.98); }
    .demo-note {
      text-align: center; font-size: 11px;
      color: #94a3b8; margin-top: 12px; line-height: 1.6;
    }
    @media (max-width: 400px) {
      .demo-topbar .subtitle { display: none; }
      .ocr-row { font-size: 12px; }
    }
  </style>
</head>
<body>

<div class="demo-shell">
  <div class="demo-topbar">
    <div class="logo">🏥</div>
    <div>
      <p class="title">Bago City Health Office</p>
      <p class="subtitle">Healthcare Registration System</p>
    </div>
    <div class="status-dot" title="System online"></div>
  </div>

  <div class="demo-body">
    <div class="ocr-card">
      <div class="ocr-card-header">
        <i class="bi bi-card-text"></i> ID Verification Result
      </div>
      <div class="ocr-card-body">
        <div class="ocr-row">
          <span class="ocr-label">Applicant</span>
          <span class="ocr-value"><?= htmlspecialchars($ocrResult['applicant_name']) ?></span>
        </div>
        <div class="ocr-row">
          <span class="ocr-label">ID Type</span>
          <span class="ocr-value"><?= htmlspecialchars($ocrResult['id_type']) ?></span>
        </div>
        <div class="ocr-row">
          <span class="ocr-label">ID Status</span>
          <span class="badge-ok">✓ Verified</span>
        </div>
        <div class="ocr-row">
          <span class="ocr-label">Bago City Resident</span>
          <span class="badge-no">✗ Not a Resident</span>
        </div>
      </div>
    </div>

    <div class="alert-banner">
      <span class="ab-icon">⚠️</span>
      <div>
        <strong>Registration Cannot Proceed</strong>
        This applicant is not a registered Bago City resident.
        Please proceed to the nearest hospital in your area.
      </div>
    </div>

    <button class="btn-trigger" onclick="window.BagoReferral.show()" type="button">
      <i class="bi bi-hospital-fill"></i> View Hospital Referral
    </button>

    <p class="demo-note">
      <i class="bi bi-info-circle me-1"></i>
      In production the modal opens automatically on OCR result.
    </p>
  </div>
</div>

<!-- ── FAB: reopen modal anytime ─────────────────────────────────────────── -->
<button id="refFab" onclick="window.BagoReferral.show()" title="Open Hospital Referral"
  style="display:none;position:fixed;bottom:28px;right:24px;z-index:9998;
         width:52px;height:52px;border-radius:50%;
         background:#2563eb;color:#fff;border:none;
         box-shadow:0 4px 16px rgba(37,99,235,.4);
         cursor:pointer;align-items:center;justify-content:center;
         font-size:22px;touch-action:manipulation;
         transition:transform .15s,box-shadow .15s;">
  🏥
</button>
<style>
  #refFab:hover { transform:scale(1.08); box-shadow:0 6px 22px rgba(37,99,235,.5); }
  #refFab:active{ transform:scale(.95); }
</style>



<?php
// ── Include the referral modal component ──────────────────────────────────────
include 'referral_modal.php';
?>

<script>
  // ── Auto-trigger for non-residents ──────────────────────────────────────────
  // Remove or wrap this in your OCR result handler in production.
  <?php if (!$ocrResult['is_bago_resident']): ?>
  document.addEventListener('DOMContentLoaded', function () {
    // Small delay so the page renders first
    setTimeout(function () {
      window.BagoReferral.show();
    }, 600);
  });
  <?php endif; ?>
</script>

</body>
</html>
