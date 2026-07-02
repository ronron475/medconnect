# Migration Log (medconnect)

All listed files were moved to their new locations during the directory re-architecture. Paths are relative to the `medconnect` project root.

| Old Path | New Path |
| --- | --- |
| `style.css` | `assets/css/style.css` |
| `login.css` | `assets/css/login.css` |
| `register.css` | `assets/css/register.css` |
| `script.js` | `assets/js/script.js` |
| `Functions/login.js` | `assets/js/login.js` |
| `Functions/register.js` | `assets/js/register.js` |
| `db.php` | `config/db.php` |
| `login.controller.php` | `controllers/auth/login.controller.php` |
| `register.controller.php` | `controllers/auth/register.controller.php` |
| `send_otp.php` | `controllers/auth/send_otp.php` |
| `verify_otp.php` | `controllers/auth/verify_otp.php` |
| `verify_reset_otp.php` | `controllers/auth/verify_reset_otp.php` |
| `process_id_ocr.php` | `controllers/patient/process_id_ocr.php` |
| `includes/mailer.php` | `core/includes/mailer.php` |
| `views/login.view.php` | `views/auth/login.view.php` |
| `views/register.view.php` | `views/auth/register.view.php` |
| `patient/dashboard.php` | `views/patient/dashboard.php` |
| `index.php` | `public/index.php` |
| `login.php` | `public/login.php` |
| `register.php` | `public/register.php` |
| `forgot_password.php` | `public/forgot_password.php` |
| `reset_password.php` | `public/reset_password.php` |
| `verify.php` | `public/verify.php` |
| `verification-success.php` | `public/verification-success.php` |
| `uploads/ids/.htaccess` | `storage/uploads/ids/.htaccess` |

## Structure cleanup (2026-06)

| Item | Location |
| --- | --- |
| App config (AI URL, timeouts) | `config/app.php` |
| Shared API / AI PHP classes | `app/core/Api.php`, `AiServiceClient.php`, `TranscriptAnalyzer.php` |
| Landing page view | `views/landing/home.php` (entry: `index.php`) |
| Forgot-password JS | `assets/js/forgot-password.js` |
| Python AI modules | `ai_service/analyzer.py`, `transcriber.py`, `server.py` |
| Dev-only scripts | `scripts/dev/check_schema.php`, `fix_bhw_auth.php` |

Removed duplicates: `hospital-referral/js/referral.js` (use `assets/js/referral.js`), `libs/PHPMailer/src/src/`.

