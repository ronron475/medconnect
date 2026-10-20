<?php
/**
 * TEMPORARY Hostinger recovery template — copy to config/local_secrets.php
 * and fill values locally. Never commit config/local_secrets.php.
 *
 * TODO(temporary-hostinger-recovery): Delete local_secrets.php after the
 * Hostinger administrator configures production DB_* and MAIL_* in server .env.
 *
 * @return array<string, string>
 */
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => '',
    'DB_USER' => '',
    'DB_PASS' => '',
    'MAIL_HOST' => 'smtp.gmail.com',
    'MAIL_PORT' => '587',
    'MAIL_USERNAME' => '',
    'MAIL_PASSWORD' => '',
    'MAIL_FROM_EMAIL' => '',
    'MAIL_FROM_NAME' => 'MedConnect Bago City',
];
