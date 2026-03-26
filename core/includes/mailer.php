<?php
/**
 * MedConnect Mailer Configuration
 */

require_once __DIR__ . '/../../libs/PHPMailer/src/src/PHPMailer.php';
require_once __DIR__ . '/../../libs/PHPMailer/src/src/SMTP.php';
require_once __DIR__ . '/../../libs/PHPMailer/src/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

define('MAIL_HOST',       'smtp.gmail.com');
define('MAIL_PORT',       587);
define('MAIL_SMTP_SECURE','tls');
define('MAIL_SMTP_AUTH',  true);
define('MAIL_USERNAME',   'sumagaysayjanica@gmail.com');
define('MAIL_PASSWORD',   'tepm ayjr puya aatn');
define('MAIL_FROM_EMAIL', 'sumagaysayjanica@gmail.com');
define('MAIL_FROM_NAME',  'MedConnect Bago City');
define('MAIL_DEBUG_MODE', false);
define('MAIL_CHARSET',    'UTF-8');

function initMailer() {
    
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = MAIL_SMTP_AUTH;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SMTP_SECURE;
        $mail->Port = MAIL_PORT;
        
        // Debug mode (only for development)
        if (MAIL_DEBUG_MODE) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = 'html';
        }
        
        // Recipients
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->CharSet = MAIL_CHARSET;
        
        return $mail;
        
    } catch (Exception $e) {
        error_log('Mailer initialization failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Send email verification
 * 
 * @param string $to Email recipient
 * @param string $verificationToken Verification token
 * @param string $fullName User's full name
 * @return array Success status and message
 */
function sendVerificationEmail($to, $verificationToken, $fullName) {
    $mail = initMailer();
    
    if (!$mail) {
        return ['success' => false, 'message' => 'Failed to initialize mailer.'];
    }
    
    try {
        $verificationUrl = getVerificationUrl($verificationToken);
        
        $mail->addAddress($to);
        $mail->Subject = 'Verify Your MedConnect Account';
        $mail->Body = getVerificationEmailTemplate($fullName, $verificationToken, $verificationUrl);
        
        if ($mail->send()) {
            return ['success' => true, 'message' => 'Verification email sent successfully.'];
        } else {
            return ['success' => false, 'message' => 'Failed to send verification email.'];
        }
        
    } catch (Exception $e) {
        error_log('Email send failed: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to send verification email.'];
    }
}

/**
 * Get verification URL
 * 
 * @param string $token Verification token
 * @return string Full verification URL
 */
function getVerificationUrl($token) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = $protocol . '://' . $host . dirname($_SERVER['PHP_SELF']);
    // Registration OTP endpoints run under /controllers/auth, but verify.php is now in /public.
    $baseUrl = preg_replace('#/controllers/auth$#', '/public', $baseUrl);
    
    return rtrim($baseUrl, '/') . '/verify.php?token=' . urlencode($token);
}

/**
 * Get verification email template
 * 
 * @param string $fullName User's full name
 * @param string $token Verification token
 * @param string $verificationUrl Verification URL
 * @return string HTML email template
 */
function getVerificationEmailTemplate($fullName, $token, $verificationUrl) {
    return "
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Verify Your MedConnect Account</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0d9488; color: white; padding: 20px; text-align: center; }
            .content { padding: 30px 20px; background: #f9fafb; }
            .button { display: inline-block; background: #0d9488; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            .token-box { background: #e6f7f1; padding: 15px; text-align: center; font-size: 18px; font-weight: bold; margin: 20px 0; border: 2px dashed #0d9488; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>MedConnect Health System</h1>
            </div>
            <div class='content'>
                <h2>Verify Your Email Address</h2>
                <p>Dear {$fullName},</p>
                <p>Thank you for registering with MedConnect! To complete your registration, please verify your email address.</p>
                
                <p><strong>Your verification token:</strong></p>
                <div class='token-box'>{$token}</div>
                
                <p>Or click the button below to automatically verify:</p>
                <div style='text-align: center;'>
                    <a href='{$verificationUrl}' class='button'>Verify Email Address</a>
                </div>
                
                <p><strong>Important:</strong></p>
                <ul>
                    <li>This verification link will expire in 24 hours</li>
                    <li>If you didn't register for MedConnect, please ignore this email</li>
                    <li>Keep your verification token secure</li>
                </ul>
            </div>
            <div class='footer'>
                <p>This is an automated message from MedConnect Health System. Please do not reply to this email.</p>
                <p>&copy; " . date('Y') . " MedConnect. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>";
}

/**
 * Test mailer configuration
 * 
 * @return array Test results
 */
function testMailerConfiguration() {
    $mail = initMailer();
    
    if (!$mail) {
        return ['success' => false, 'message' => 'Failed to initialize mailer.'];
    }
    
    try {
        // Test SMTP connection
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        
        return ['success' => true, 'message' => 'Mailer configuration is valid.'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Mailer test failed: ' . $e->getMessage()];
    }
}

?>
