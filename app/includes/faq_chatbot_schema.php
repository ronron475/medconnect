<?php
/**
 * Ensures FAQ chatbot tables exist (dev-friendly; production should run migrations).
 */
function faq_chatbot_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
          KEY `idx_cm_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
          KEY `idx_ce_emotion` (`canonical_emotion`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `chatbot_feedback` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `message_id` BIGINT UNSIGNED NOT NULL,
          `rating` ENUM('helpful','not_helpful') NOT NULL,
          `comment` VARCHAR(500) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_cf_message` (`message_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    faq_chatbot_seed_if_empty($pdo);
    faq_chatbot_ensure_nlp_tables($pdo);
}

function faq_chatbot_ensure_nlp_tables(PDO $pdo): void
{
    $migration = BASE_PATH . '/database/migrations/2026_07_30_faq_chatbot_nlp_full.sql';
    if (!is_readable($migration)) {
        return;
    }
    $sql = file_get_contents($migration);
    if ($sql === false) {
        return;
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (Throwable) {
            // ignore duplicate index / exists
        }
    }

    $dict = new FaqChatbotDictionaryRepository($pdo);
    $dict->ensureSeeded();

    $pdo->exec("INSERT IGNORE INTO chatbot_intents (slug, label_en) VALUES
        ('greeting','Greeting'),('goodbye','Goodbye'),('appointment','Appointment'),
        ('consultation','Video consultation'),('medicine','Medicine'),('symptoms','Symptoms'),
        ('emergency','Emergency'),('registration','Registration'),('login','Login'),
        ('medical_record','Medical record'),('prescription','Prescription'),('faq','FAQ')");

    $tpl = $pdo->prepare(
        'INSERT IGNORE INTO response_templates (template_key, lang, body_html, tone) VALUES (:k, :l, :b, \'warm\')'
    );
    foreach (['en', 'fil', 'hil'] as $L) {
        $tpl->execute([':k' => 'not_understood', ':l' => $L, ':b' => FaqChatbotResponseTemplates::html('not_understood', $L)]);
        $tpl->execute([':k' => 'no_exact_faq', ':l' => $L, ':b' => FaqChatbotResponseTemplates::html('no_exact_faq', $L)]);
    }
}

function faq_chatbot_seed_if_empty(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM faq')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $migration = BASE_PATH . '/database/migrations/2026_07_29_faq_chatbot_mysql.sql';
    if (!is_readable($migration)) {
        return;
    }

    $sql = file_get_contents($migration);
    if ($sql === false) {
        return;
    }

    // Extract idempotent FAQ seed block (full INSERT … WHERE NOT EXISTS subquery).
    if (preg_match(
        '/INSERT INTO `faq`[\s\S]+WHERE NOT EXISTS \(SELECT 1 FROM `faq` LIMIT 1\);/s',
        $sql,
        $m
    )) {
        $pdo->exec($m[0]);
    }
}
