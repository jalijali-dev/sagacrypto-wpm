-- ============================================================
-- Migration 002: AI Management (Credentials, Models, Agent Settings)
-- Run once via phpMyAdmin, mysql CLI, or:
--   http://localhost:8008/wpm/cms-admin/migrate-ai-management.php
-- ============================================================

-- --------------------------------------------------------
-- Table: ai_credentials — encrypted API keys per provider
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_credentials` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` enum('openai','anthropic') NOT NULL,
  `label` varchar(150) NOT NULL,
  `api_key_enc` text NOT NULL COMMENT 'AES-256-CBC encrypted API key, never shown again after save',
  `key_last4` varchar(8) DEFAULT NULL COMMENT 'Last 4 chars of key for display only',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: ai_models — model catalogue per provider
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_models` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider` enum('openai','anthropic') NOT NULL,
  `model_key` varchar(100) NOT NULL COMMENT 'e.g. claude-3-5-haiku-20241022, gpt-4o-mini',
  `label` varchar(150) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_model` (`provider`,`model_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: ai_agent_settings — per-agent model + prompt config
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_agent_settings` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_key` varchar(50) NOT NULL COMMENT 'e.g. seo_agent, account_recovery, prompt_control',
  `label` varchar(150) NOT NULL,
  `model_id` int(10) UNSIGNED DEFAULT NULL,
  `temperature` decimal(3,2) NOT NULL DEFAULT 0.70,
  `max_tokens` int(10) UNSIGNED NOT NULL DEFAULT 1024,
  `system_prompt` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_key` (`agent_key`),
  KEY `fk_ai_agent_model` (`model_id`),
  CONSTRAINT `fk_ai_agent_model` FOREIGN KEY (`model_id`) REFERENCES `ai_models` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Seed: default models (Claude Haiku + GPT mini, per current focus)
-- --------------------------------------------------------
INSERT IGNORE INTO `ai_models` (`provider`, `model_key`, `label`, `is_default`, `is_active`) VALUES
('anthropic', 'claude-3-5-haiku-20241022', 'Claude 3.5 Haiku', 1, 1),
('openai',    'gpt-4o-mini',               'GPT-4o mini',      1, 1);

-- --------------------------------------------------------
-- Seed: agent settings rows (foundation for existing + upcoming agents)
-- --------------------------------------------------------
INSERT IGNORE INTO `ai_agent_settings` (`agent_key`, `label`, `model_id`, `temperature`, `max_tokens`, `system_prompt`, `is_active`)
SELECT 'seo_agent', 'SEO Generator', m.id, 0.60, 512,
       'You generate concise, high-converting SEO meta_title and meta_description for product pages. Keep meta_title under 60 chars and meta_description under 155 chars.',
       1
FROM `ai_models` m WHERE m.model_key = 'claude-3-5-haiku-20241022' LIMIT 1;

INSERT IGNORE INTO `ai_agent_settings` (`agent_key`, `label`, `model_id`, `temperature`, `max_tokens`, `system_prompt`, `is_active`)
SELECT 'account_recovery', 'Account Recovery Bot', m.id, 0.30, 512,
       'You help verify an admin''s identity during account/password recovery. Ask clarifying questions based on known account details, never reveal or guess the password, and only confirm recovery once identity is reasonably verified.',
       1
FROM `ai_models` m WHERE m.model_key = 'claude-3-5-haiku-20241022' LIMIT 1;

-- Verify result
SHOW COLUMNS FROM `ai_credentials`;
SHOW COLUMNS FROM `ai_models`;
SHOW COLUMNS FROM `ai_agent_settings`;
