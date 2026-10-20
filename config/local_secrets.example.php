<?php
/**
 * TEMPORARY Hostinger recovery template — copy to config/local_secrets.php
 * and fill values locally. Never commit config/local_secrets.php.
 *
 * TODO(temporary-hostinger-recovery): Delete local_secrets.php after the
 * Hostinger administrator configures production DB_*, MAIL_*, and Railway
 * email relay keys in server .env.
 *
 * Loaded by config/env_loader.php before db.php / mailer.php. Existing
 * environment variables are never overridden.
 *
 * @return array<string, string>
 */
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    // Gmail SMTP / PHPMailer (often blocked outbound on Hostinger)
    'MAIL_HOST' => 'smtp.gmail.com',
    'MAIL_PORT' => '587',
    'MAIL_USERNAME' => '',
    'MAIL_PASSWORD' => '',
    'MAIL_FROM_EMAIL' => '',
    'MAIL_FROM_NAME' => 'MedConnect Bago City',
    // Railway /email/send relay (recommended when Hostinger blocks SMTP)
    'EMAIL_API_KEY' => '',
    'MEDCONNECT_AI_SERVICE_TOKEN' => '',
];
