<?php
/**
 * API: Logout handler
 * URL: /app/api/logout.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/includes/remember_me.php';
require_once dirname(__DIR__, 2) . '/app/includes/auth_guard.php';

auth_prevent_back_cache();

$wantsJson = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$csrfToken = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? '');
$hasValidCsrf = !empty($csrfToken) && auth_csrf_validate($csrfToken);

// If an active session exists, protect against Cross-Site Request Forgery (forced logout)
if (!empty($_SESSION['user_id'])) {
    if ($requestMethod === 'POST') {
        if (!$hasValidCsrf) {
            http_response_code(403);
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid security token.', 'code' => 'csrf_invalid']);
                exit;
            }
            echo 'Invalid security token for logout.';
            exit;
        }
    } else {
        // GET request from an active session
        if (!$hasValidCsrf) {
            if ($wantsJson) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Security token required for logout.', 'code' => 'csrf_required']);
                exit;
            }
            // Render friendly confirmation screen for direct browser GET navigation
            $sessionCsrf = (string) ($_SESSION['csrf_token'] ?? '');
            $asset = ASSET_BASE;
            $returnUrl = htmlspecialchars(BASE_URL . '/index.php', ENT_QUOTES, 'UTF-8');
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8"/>
              <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
              <title>Confirm Sign Out — medConnect</title>
              <link rel="stylesheet" href="<?= $asset ?>/assets/css/style.css"/>
              <style>
                .logout-box { max-width: 440px; margin: 120px auto; padding: 36px 32px; background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; border: 1px solid #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
                .logout-title { font-size: 20px; font-weight: 700; color: #0f172a; margin: 0 0 8px; }
                .logout-desc { font-size: 14px; color: #64748b; margin: 0 0 24px; line-height: 1.5; }
                .logout-btn { background: #dc2626; color: #fff; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; transition: opacity .15s; }
                .logout-btn:hover { opacity: 0.9; }
                .cancel-btn { display: inline-block; margin-left: 10px; padding: 12px 20px; border-radius: 10px; font-size: 14px; color: #475569; text-decoration: none; font-weight: 500; background: #f1f5f9; }
                .cancel-btn:hover { background: #e2e8f0; }
              </style>
            </head>
            <body style="background: #f8fafc; margin: 0;">
              <div class="logout-box">
                <h1 class="logout-title">Sign Out of MedConnect</h1>
                <p class="logout-desc">Are you sure you want to sign out of your account?</p>
                <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF'] ?? '/app/api/logout.php', ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($sessionCsrf, ENT_QUOTES, 'UTF-8') ?>"/>
                  <button type="submit" class="logout-btn">Yes, Sign Out</button>
                  <a href="<?= $returnUrl ?>" class="cancel-btn">Cancel</a>
                </form>
              </div>
            </body>
            </html>
            <?php
            exit;
        }
    }
}

// ── Audit Log ───────────────────────────────────────────────
if (!empty($_SESSION['user_id'])) {
    try {
        require_once dirname(__DIR__, 2) . '/config/db.php';
        $logoutUserId = (int) $_SESSION['user_id'];
        require_once BASE_PATH . '/app/includes/audit_log.php';
        if (($_SESSION['user_role'] ?? '') === 'bhw') {
            require_once dirname(dirname(__DIR__)) . '/app/includes/bhw_activity.php';
            bhw_activity_log($pdo, 'bhw_logout', 'BHW logged out of the portal.');
        }
        audit_log($pdo, [
            'patient_id'  => $_SESSION['user_id'],
            'action_type' => AuditAction::LOGOUT,
            'description' => 'User logged out.',
        ]);
        try {
            require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/security.php';
            superadmin_clear_session($pdo, $logoutUserId);
        } catch (Throwable $e) { /* non-fatal */ }
    } catch (Exception $e) { /* non-fatal */ }
}

// Revoke remember-me token (best-effort)
try {
    remember_me_revoke_current_cookie($pdo);
    remember_me_clear_idle_hold();
} catch (Throwable $e) { /* non-fatal */ }

$_SESSION = [];
session_unset();
if (!function_exists('medconnect_expire_session_cookie')) {
    require_once dirname(__DIR__, 2) . '/app/includes/session_cookie.php';
}
medconnect_expire_session_cookie();
session_destroy();

$redirect = BASE_URL . '/index.php';

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'authenticated' => false, 'redirect' => $redirect]);
    exit;
}

header('Location: ' . $redirect);
exit;
