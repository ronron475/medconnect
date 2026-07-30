-- medConnect FAQ chatbot — full NLP schema (translation, synonyms, templates, keywords)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `faq_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `label_en` VARCHAR(120) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_faq_cat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `faq_keywords` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `faq_id` INT UNSIGNED NOT NULL,
  `keyword` VARCHAR(120) NOT NULL,
  `weight` DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id`),
  KEY `idx_fk_faq` (`faq_id`),
  KEY `idx_fk_kw` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `translation_dictionary` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_lang` VARCHAR(8) NOT NULL DEFAULT 'hil',
  `source_text` VARCHAR(255) NOT NULL,
  `target_lang` VARCHAR(8) NOT NULL DEFAULT 'en',
  `target_text` VARCHAR(500) NOT NULL,
  `category` VARCHAR(64) NOT NULL DEFAULT 'general',
  `is_phrase` TINYINT(1) NOT NULL DEFAULT 0,
  `priority` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_td_source` (`source_lang`, `source_text`),
  KEY `idx_td_cat` (`category`),
  KEY `idx_td_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `medical_terms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term_hil` VARCHAR(120) NOT NULL,
  `term_en` VARCHAR(200) NOT NULL,
  `body_part` VARCHAR(64) NULL,
  `symptom_key` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mt_hil` (`term_hil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `synonyms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term` VARCHAR(120) NOT NULL,
  `synonym` VARCHAR(120) NOT NULL,
  `lang` VARCHAR(8) NOT NULL DEFAULT 'en',
  PRIMARY KEY (`id`),
  KEY `idx_syn_term` (`term`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatbot_intents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `label_en` VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_intent_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `response_templates` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(64) NOT NULL,
  `lang` VARCHAR(8) NOT NULL DEFAULT 'en',
  `body_html` TEXT NOT NULL,
  `tone` VARCHAR(32) NOT NULL DEFAULT 'warm',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rt_key_lang` (`template_key`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `session_id` CHAR(64) NOT NULL,
  `user_message` TEXT NOT NULL,
  `translated_message` TEXT NULL,
  `detected_lang` VARCHAR(8) NULL,
  `emotion` VARCHAR(32) NULL,
  `intent` VARCHAR(64) NULL,
  `bot_response` TEXT NULL,
  `confidence` DECIMAL(5,4) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ch_session` (`session_id`),
  KEY `idx_ch_conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
