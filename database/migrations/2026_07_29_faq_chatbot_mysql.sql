-- medConnect FAQ chatbot — MySQL knowledge base, conversations, emotions, feedback
-- PHP-only stack (no external AI). Run once on your medConnect database.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `faq` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `question` VARCHAR(500) NOT NULL,
  `answer` TEXT NOT NULL,
  `keywords` VARCHAR(1000) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faq_category` (`category`),
  KEY `idx_faq_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FULLTEXT (optional; ignore errors if InnoDB FULLTEXT unsupported on your MySQL build)
ALTER TABLE `faq` ADD FULLTEXT KEY `ft_faq_search` (`question`, `answer`, `keywords`);

CREATE TABLE IF NOT EXISTS `chatbot_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` CHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `lang` VARCHAR(8) NOT NULL DEFAULT 'en',
  `started_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `meta_json` JSON NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chatbot_session` (`session_id`),
  KEY `idx_chatbot_user` (`user_id`),
  KEY `idx_chatbot_last` (`last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('user','bot','system') NOT NULL,
  `content` TEXT NOT NULL,
  `intent` VARCHAR(64) NULL DEFAULT NULL,
  `flow_key` VARCHAR(64) NULL DEFAULT NULL,
  `confidence` DECIMAL(5,4) NULL DEFAULT NULL,
  `faq_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cm_conv` (`conversation_id`),
  KEY `idx_cm_created` (`created_at`),
  CONSTRAINT `fk_cm_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `chatbot_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_faq` FOREIGN KEY (`faq_id`) REFERENCES `faq` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_emotions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `emotion` VARCHAR(32) NOT NULL,
  `canonical_emotion` VARCHAR(32) NOT NULL,
  `score` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `confidence` DECIMAL(5,4) NOT NULL DEFAULT 0,
  `scores_json` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_message` (`message_id`),
  KEY `idx_ce_emotion` (`canonical_emotion`),
  CONSTRAINT `fk_ce_message` FOREIGN KEY (`message_id`) REFERENCES `chatbot_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_feedback` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `rating` ENUM('helpful','not_helpful') NOT NULL,
  `comment` VARCHAR(500) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cf_message` (`message_id`),
  CONSTRAINT `fk_cf_message` FOREIGN KEY (`message_id`) REFERENCES `chatbot_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed FAQs (idempotent: skip if questions already exist)
INSERT INTO `faq` (`category`, `question`, `answer`, `keywords`, `sort_order`)
SELECT * FROM (
  SELECT 'registration' AS category,
    'How do I register for medConnect?' AS question,
    'Go to Register on the landing page, verify your email with OTP, upload your National ID for verification, then complete the patient form. If OCR details are wrong, re-upload a clear photo of your ID.' AS answer,
    'register,sign up,create account,rehistro,magrehistro,new account' AS keywords,
    10 AS sort_order
  UNION ALL SELECT 'login', 'How do I sign in?',
    'Use your verified email and password on the Sign In page. If you forgot your password, use Forgot Password to reset via OTP.',
    'login,sign in,log in,sulod,password,forgot', 20
  UNION ALL SELECT 'appointment', 'How do I book an appointment?',
    'After signing in as a patient, open Appointments and choose Book or Schedule. Pick a provider, date, and time slot, then confirm. You will receive reminders when your appointment is approved.',
    'appointment,book,schedule,consultation,mag-book,konsultasyon', 30
  UNION ALL SELECT 'consultation', 'How does video consultation work?',
    'When your provider starts a video session, open Consultations and join the video room. Allow camera and microphone when prompted. For technical issues, try another browser or check your connection.',
    'video,consultation,telemedicine,online consult,call', 40
  UNION ALL SELECT 'records', 'Where can I see my medical records?',
    'Signed-in patients can view health summary, visit history, and approved records from the patient portal. Some updates may require provider approval.',
    'records,medical history,emr,health summary,prescription', 50
  UNION ALL SELECT 'services', 'What services does the City Health Office offer through medConnect?',
    'medConnect supports registration, appointments, video consultations, messaging with providers, triage support, and access to approved health records for Bago City residents.',
    'services,features,what can,cho,city health,medconnect', 60
  UNION ALL SELECT 'hours', 'What are the office hours?',
    'City Health Office hours may vary by clinic. Use Contact or announcements in the portal for the latest schedule. For urgent symptoms, seek emergency care—this chat is not for emergencies.',
    'hours,open,close,office,oras,schedule', 70
  UNION ALL SELECT 'privacy', 'Is my information safe?',
    'medConnect uses secure sign-in, encrypted connections on production sites, and role-based access so only authorized staff see your records. Never share your password.',
    'safe,secure,privacy,trust,data', 80
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `faq` LIMIT 1);
