<?php
/**
 * TEMPORARY Hostinger recovery template — copy to config/local_secrets.php
 * and fill values locally. Never commit config/local_secrets.php.
 *
 * TODO(temporary-hostinger-recovery): Delete local_secrets.php after the
 * Hostinger administrator configures production DB_* and MAIL_* in server .env.
 *
 * Loaded by config/env_loader.php before db.php / mailer.php. Existing
 * environment variables are never overridden.
 *
 * Temporary email mode: PHP mailer uses direct Gmail SMTP (PHPMailer) only.
 * Railway /email/send is not used by the PHP mailer. Keep AI service token
 * placeholders empty here unless needed for OCR/triage elsewhere.
 *
 * @return array<string, string>
 */
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    // Gmail SMTP / PHPMailer (required for temporary direct SMTP mode)
    'MAIL_HOST' => 'smtp.gmail.com',
    'MAIL_PORT' => '587',
    'MAIL_USERNAME' => '',
    'MAIL_PASSWORD' => '',
    'MAIL_FROM_EMAIL' => '',
    'MAIL_FROM_NAME' => 'MedConnect Bago City',
    // Optional AI service auth (OCR/triage) — not used by PHP mailer SMTP mode
    'EMAIL_API_KEY' => '',
    'MEDCONNECT_AI_SERVICE_TOKEN' => '',
];
