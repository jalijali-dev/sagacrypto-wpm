-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mysql
-- Generation Time: Jul 09, 2026 at 03:20 AM
-- Server version: 10.6.27-MariaDB-ubu2204
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wpm_cms`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(10) UNSIGNED NOT NULL COMMENT 'ID unik admin',
  `name` varchar(150) NOT NULL COMMENT 'Nama lengkap admin',
  `email` varchar(150) NOT NULL COMMENT 'Email login admin',
  `password_hash` varchar(255) NOT NULL COMMENT 'Password hash bcrypt',
  `role` enum('superadmin','admin','editor') NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`admin_id`, `name`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Sagarycpto Admin', 'admin@sagacrypto.com', '$2y$10$F4zx4ZtG8wyPcomGRNYcf.5Hh3j2rStzgO.Pf1gvnb5gxUNKiqM1u', 'superadmin', 1, '2026-06-28 04:20:07', '2026-07-08 06:26:38'),
(2, 'cs', 'cs@sagacrypto.com', '$2y$10$GcWZBaMeEsO6zdcEOvDlAefK48kmldYoIhxeF8j01.OIMT6Yezk6i', 'editor', 1, '2026-07-01 23:39:01', '2026-07-08 06:26:51');

-- --------------------------------------------------------

--
-- Table structure for table `agent_prompts`
--

CREATE TABLE `agent_prompts` (
  `id` int(10) UNSIGNED NOT NULL,
  `agent_key` varchar(50) NOT NULL,
  `prompt_type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` mediumtext NOT NULL,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `status` enum('draft','active','archived') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` varchar(100) NOT NULL DEFAULT 'system',
  `activated_at` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `checksum` char(64) DEFAULT NULL COMMENT 'SHA-256 of content at activation',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_agent_settings`
--

CREATE TABLE `ai_agent_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `agent_key` varchar(50) NOT NULL COMMENT 'e.g. seo_agent, account_recovery, prompt_control',
  `label` varchar(150) NOT NULL,
  `model_id` int(10) UNSIGNED DEFAULT NULL,
  `temperature` decimal(3,2) NOT NULL DEFAULT 0.70,
  `max_tokens` int(10) UNSIGNED NOT NULL DEFAULT 1024,
  `system_prompt` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ai_agent_settings`
--

INSERT INTO `ai_agent_settings` (`id`, `agent_key`, `label`, `model_id`, `temperature`, `max_tokens`, `system_prompt`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'seo_agent', 'SEO Generator', 1, 0.60, 512, 'You generate concise, high-converting SEO meta_title and meta_description for product pages. Keep meta_title under 60 chars and meta_description under 155 chars.', 1, '2026-07-07 00:00:00', '2026-07-07 00:00:00'),
(2, 'account_recovery', 'Account Recovery Bot', 1, 0.30, 512, 'You help verify an admin\'s identity during account/password recovery. Ask clarifying questions based on known account details, never reveal or guess the password, and only confirm recovery once identity is reasonably verified.', 1, '2026-07-07 00:00:00', '2026-07-07 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `ai_credentials`
--

CREATE TABLE `ai_credentials` (
  `id` int(10) UNSIGNED NOT NULL,
  `provider` enum('openai','anthropic') NOT NULL,
  `label` varchar(150) NOT NULL,
  `api_key_enc` text NOT NULL COMMENT 'AES-256-CBC encrypted API key, never shown again after save',
  `key_last4` varchar(8) DEFAULT NULL COMMENT 'Last 4 chars of key for display only',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ai_models`
--

CREATE TABLE `ai_models` (
  `id` int(10) UNSIGNED NOT NULL,
  `provider` enum('openai','anthropic') NOT NULL,
  `model_key` varchar(100) NOT NULL COMMENT 'e.g. claude-3-5-haiku-20241022, gpt-4o-mini',
  `label` varchar(150) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ai_models`
--

INSERT INTO `ai_models` (`id`, `provider`, `model_key`, `label`, `is_default`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'anthropic', 'claude-3-5-haiku-20241022', 'Claude 3.5 Haiku', 1, 1, '2026-07-07 00:00:00', '2026-07-07 00:00:00'),
(2, 'openai', 'gpt-4o-mini', 'GPT-4o mini', 1, 1, '2026-07-07 00:00:00', '2026-07-07 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `banners`
--

CREATE TABLE `banners` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` text DEFAULT NULL,
  `button_text` varchar(100) DEFAULT NULL,
  `button_url` varchar(255) DEFAULT NULL,
  `desktop_image` varchar(255) DEFAULT NULL,
  `mobile_image` varchar(255) DEFAULT NULL,
  `placement` varchar(100) NOT NULL DEFAULT 'home',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `banners`
--

INSERT INTO `banners` (`id`, `title`, `subtitle`, `button_text`, `button_url`, `desktop_image`, `mobile_image`, `placement`, `sort_order`, `is_active`, `start_date`, `end_date`, `created_at`, `updated_at`) VALUES
(1, 'Build Your Digital Foundation with TheAwsoft', 'Websites, hosting, domains, automation, and custom systems for growing businesses.', 'Start Project', '/pages/contact/', NULL, NULL, 'home', 1, 1, NULL, NULL, '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(180) NOT NULL,
  `email` varchar(180) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gallery`
--

CREATE TABLE `gallery` (
  `id` int(10) UNSIGNED NOT NULL,
  `media_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'published',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `landing_sections`
--

CREATE TABLE `landing_sections` (
  `landing_section_id` int(10) UNSIGNED NOT NULL,
  `page_key` varchar(100) NOT NULL DEFAULT 'home',
  `section_key` varchar(100) NOT NULL,
  `section_type` varchar(100) NOT NULL DEFAULT 'content',
  `title` varchar(255) DEFAULT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `button_text` varchar(100) DEFAULT NULL,
  `button_url` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('draft','published','inactive') NOT NULL DEFAULT 'published',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `landing_sections`
--

INSERT INTO `landing_sections` (`landing_section_id`, `page_key`, `section_key`, `section_type`, `title`, `subtitle`, `content`, `image`, `button_text`, `button_url`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 'home', 'hero', 'hero', 'Digital Solutions for Modern Businesses', 'Website, hosting, automation, and custom systems built to help your business grow.', '<p>TheAwsoft helps businesses launch clean, reliable, and scalable digital products.</p>', NULL, 'Contact Us', '/pages/contact/', 1, 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(2, 'home', 'services_intro', 'services', 'What We Build', 'Practical digital services for business operations.', '<p>From company websites to automation workflows, we focus on solutions that are useful, maintainable, and ready for production.</p>', NULL, 'View Services', '/pages/services/', 2, 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(3, 'home', 'why_us', 'content', 'Why TheAwsoft', 'Simple process, clean execution, and long-term maintainability.', '<p>We build with a practical engineering mindset: clear scope, clean code, secure foundations, and documentation.</p>', NULL, NULL, NULL, 3, 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `media_library`
--

CREATE TABLE `media_library` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size_kb` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `file_size` int(10) UNSIGNED DEFAULT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menus`
--

CREATE TABLE `menus` (
  `menu_id` int(10) UNSIGNED NOT NULL,
  `menu_location` varchar(50) NOT NULL DEFAULT 'header',
  `label` varchar(100) NOT NULL,
  `url` varchar(255) NOT NULL,
  `target` enum('_self','_blank') NOT NULL DEFAULT '_self',
  `css_class` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `menus`
--

INSERT INTO `menus` (`menu_id`, `menu_location`, `label`, `url`, `target`, `css_class`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 'header', 'Home', '/', '_self', NULL, 1, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(2, 'header', 'About', '/pages/about/', '_self', NULL, 2, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(3, 'header', 'Services', '/pages/services/', '_self', NULL, 3, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(4, 'header', 'Portfolio', '/pages/portfolio/', '_self', NULL, 4, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(5, 'header', 'Articles', '/pages/articles/', '_self', NULL, 5, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(6, 'header', 'Contact', '/pages/contact/', '_self', NULL, 6, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(7, 'footer', 'Privacy Policy', '/pages/privacy-policy/', '_self', NULL, 1, 'active', '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `pages`
--

CREATE TABLE `pages` (
  `page_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `featured_image` varchar(255) DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `faq_json` longtext DEFAULT NULL,
  `excerpt` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pages`
--

INSERT INTO `pages` (`page_id`, `title`, `slug`, `featured_image`, `content`, `faq_json`, `excerpt`, `meta_title`, `meta_description`, `status`, `published_at`, `created_at`, `updated_at`) VALUES
(1, 'Why Every Business Needs a Website', 'why-every-business-needs-a-website', NULL, '<h2>Why a website matters</h2><p>A website helps customers understand your business, services, credibility, and contact options in one place.</p>', NULL, 'A simple introduction to why businesses need a professional website.', 'Why Every Business Needs a Website - TheAwsoft', 'Learn why a professional website is important for modern businesses.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `portfolio`
--

CREATE TABLE `portfolio` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(180) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `client_name` varchar(180) DEFAULT NULL,
  `project_type` varchar(100) DEFAULT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `project_url` varchar(255) DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `published_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `portfolio`
--

INSERT INTO `portfolio` (`id`, `title`, `slug`, `client_name`, `project_type`, `short_description`, `description`, `thumbnail`, `project_url`, `meta_title`, `meta_description`, `is_featured`, `is_active`, `sort_order`, `published_at`, `created_at`, `updated_at`) VALUES
(1, 'Val’s Cake CMS & Website', 'vals-cake-cms-website', 'Val’s Cake', 'Website + CMS + Automation', 'Custom CMS and website for a home bakery business.', '<p>A reusable CMS foundation with products, articles, SEO tools, contact forms, and automation workflows.</p>', NULL, 'https://vals-cake.com', 'Val’s Cake CMS Project - TheAwsoft', 'CMS and website project by TheAwsoft.', 1, 1, 1, '2026-06-28 04:20:07', '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `short_description` text DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` text DEFAULT NULL,
  `price` decimal(12,2) DEFAULT 0.00,
  `thumbnail` varchar(255) DEFAULT NULL,
  `is_featured` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `category_id`, `name`, `slug`, `short_description`, `description`, `meta_title`, `meta_description`, `price`, `thumbnail`, `is_featured`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'TEST1', 'test1', 'Test1', 'Test1', NULL, NULL, 100000.00, '', 0, 1, 0, '2026-07-08 13:05:56', '2026-07-08 13:05:56');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_categories`
--

INSERT INTO `product_categories` (`id`, `name`, `slug`, `description`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Test', 'test', 'test', 1, 0, '2026-07-08 13:05:33', '2026-07-08 13:05:33');

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `alt_text` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_tags`
--

CREATE TABLE `product_tags` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_tag_map`
--

CREATE TABLE `product_tag_map` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seo_redirects`
--

CREATE TABLE `seo_redirects` (
  `id` int(10) UNSIGNED NOT NULL,
  `old_url` varchar(255) NOT NULL,
  `new_url` varchar(255) NOT NULL,
  `redirect_type` enum('301','302') NOT NULL DEFAULT '301',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `seo_schema`
--

CREATE TABLE `seo_schema` (
  `id` int(10) UNSIGNED NOT NULL,
  `schema_name` varchar(180) NOT NULL,
  `schema_type` varchar(100) NOT NULL,
  `schema_json` longtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `seo_schema`
--

INSERT INTO `seo_schema` (`id`, `schema_name`, `schema_type`, `schema_json`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Organization Schema', 'Organization', '{ \"@context\": \"https://schema.org\", \"@type\": \"Organization\", \"name\": \"TheAwsoft\", \"url\": \"https://theawsoft.com\", \"email\": \"sales-online@theawsoft.com\" }', 1, '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(2, 'Website Schema', 'WebSite', '{ \"@context\": \"https://schema.org\", \"@type\": \"WebSite\", \"name\": \"TheAwsoft\", \"url\": \"https://theawsoft.com\" }', 1, '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(180) NOT NULL,
  `slug` varchar(180) NOT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `thumbnail` varchar(255) DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `slug`, `short_description`, `description`, `icon`, `thumbnail`, `meta_title`, `meta_description`, `is_featured`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Website Development', 'website-development', 'Company profile, landing page, and CMS-based websites.', '<p>Custom website development with clean structure, SEO-ready pages, admin CMS, and maintainable code.</p>', 'globe', NULL, 'Website Development - TheAwsoft', 'Build a professional website with TheAwsoft.', 1, 1, 1, '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(2, 'Hosting & Domain', 'hosting-domain', 'Domain setup, hosting, SSL, DNS, and deployment support.', '<p>Reliable hosting and domain setup for business websites, including SSL, DNS, and deployment support.</p>', 'server', NULL, 'Hosting & Domain - TheAwsoft', 'Hosting, domain, SSL, and deployment services by TheAwsoft.', 1, 1, 2, '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(3, 'Automation & AI Agent', 'automation-ai-agent', 'Business workflow automation and AI-assisted operations.', '<p>Automation workflows, notifications, content agents, and operational tools designed to reduce manual work.</p>', 'bot', NULL, 'Automation & AI Agent - TheAwsoft', 'Business automation and AI agent solutions by TheAwsoft.', 1, 1, 3, '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `site_name` varchar(150) NOT NULL,
  `site_tagline` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `favicon_path` varchar(255) DEFAULT NULL,
  `og_image` varchar(255) DEFAULT NULL,
  `whatsapp_number` varchar(30) DEFAULT NULL,
  `instagram_url` varchar(255) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `meta_keywords` text DEFAULT NULL,
  `google_analytics_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`id`, `site_name`, `site_tagline`, `logo_path`, `favicon_path`, `og_image`, `whatsapp_number`, `instagram_url`, `email`, `address`, `meta_title`, `meta_description`, `meta_keywords`, `google_analytics_id`, `created_at`, `updated_at`) VALUES
(1, 'Saga Crypto', 'Digital Solutions, Web Development & Automation', '', '', '', '', '', 'sales-online@theawsoft.com', '', 'Saga Crypto - Portal Crypto & Market News Terkini', 'SagaCrypto menghadirkan berita crypto, edukasi blockchain, analisis market, dan update project Web3 dalam satu tempat — disajikan ringkas, akurat, dan mudah dipahami untuk pembaca dari berbagai level.', 'SagaCrypto menghadirkan berita crypto, edukasi blockchain, analisis market, dan update project Web3 dalam satu tempat', '', '2026-06-28 04:20:07', '2026-07-08 13:11:09');

-- --------------------------------------------------------

--
-- Table structure for table `special_pages`
--

CREATE TABLE `special_pages` (
  `special_page_id` int(10) UNSIGNED NOT NULL,
  `page_key` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `status` enum('draft','published') NOT NULL DEFAULT 'published',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `special_pages`
--

INSERT INTO `special_pages` (`special_page_id`, `page_key`, `title`, `slug`, `content`, `meta_title`, `meta_description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'home', 'Homepage', '', '<p>TheAwsoft builds practical digital solutions for modern businesses.</p>', 'TheAwsoft - Digital Solutions', 'Website, hosting, domain, automation, and custom software solutions for growing businesses.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(2, 'about', 'About TheAwsoft', 'about', '<p>TheAwsoft is a digital solutions studio focused on websites, hosting, automation, and business systems.</p>', 'About TheAwsoft', 'Learn more about TheAwsoft and our practical approach to digital solutions.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(3, 'services', 'Services', 'services', '<p>Explore TheAwsoft services for websites, hosting, domains, automation, and custom systems.</p>', 'TheAwsoft Services', 'Professional website, hosting, domain, automation, and software services.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(4, 'portfolio', 'Portfolio', 'portfolio', '<p>Selected projects and digital solutions delivered by TheAwsoft.</p>', 'TheAwsoft Portfolio', 'Explore selected TheAwsoft website and software projects.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(5, 'articles', 'Articles', 'articles', '<p>Tips and articles about websites, digital systems, automation, hosting, and business growth.</p>', 'TheAwsoft Articles', 'Read TheAwsoft articles about websites, hosting, automation, and digital business.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07'),
(6, 'contact', 'Contact', 'contact', '<p>Contact TheAwsoft to discuss your website, hosting, automation, or digital system needs.</p>', 'Contact TheAwsoft', 'Get in touch with TheAwsoft for website, hosting, automation, and software projects.', 'published', '2026-06-28 04:20:07', '2026-06-28 04:20:07');

-- --------------------------------------------------------

--
-- Table structure for table `testimonials`
--

CREATE TABLE `testimonials` (
  `id` int(10) UNSIGNED NOT NULL,
  `client_name` varchar(180) NOT NULL,
  `client_position` varchar(180) DEFAULT NULL,
  `client_company` varchar(180) DEFAULT NULL,
  `content` text NOT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `rating` tinyint(1) NOT NULL DEFAULT 5,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `testimonials`
--

INSERT INTO `testimonials` (`id`, `client_name`, `client_position`, `client_company`, `content`, `photo`, `rating`, `is_featured`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'Sample Client', 'Business Owner', 'Sample Company', 'TheAwsoft helped us prepare a cleaner digital foundation for our business.', NULL, 5, 1, 1, 1, '2026-06-28 04:20:07', '2026-06-28 04:20:07');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `agent_prompts`
--
ALTER TABLE `agent_prompts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_prompts_lookup` (`agent_key`,`prompt_type`,`status`,`version`);

--
-- Indexes for table `ai_agent_settings`
--
ALTER TABLE `ai_agent_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_agent_key` (`agent_key`),
  ADD KEY `fk_ai_agent_model` (`model_id`);

--
-- Indexes for table `ai_credentials`
--
ALTER TABLE `ai_credentials`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ai_models`
--
ALTER TABLE `ai_models`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_provider_model` (`provider`,`model_key`);

--
-- Indexes for table `banners`
--
ALTER TABLE `banners`
  ADD PRIMARY KEY (`id`),
  ADD KEY `placement` (`placement`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `gallery`
--
ALTER TABLE `gallery`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `landing_sections`
--
ALTER TABLE `landing_sections`
  ADD PRIMARY KEY (`landing_section_id`),
  ADD KEY `page_key` (`page_key`),
  ADD KEY `section_key` (`section_key`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `media_library`
--
ALTER TABLE `media_library`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `menus`
--
ALTER TABLE `menus`
  ADD PRIMARY KEY (`menu_id`),
  ADD KEY `menu_location` (`menu_location`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `pages`
--
ALTER TABLE `pages`
  ADD PRIMARY KEY (`page_id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `status` (`status`),
  ADD KEY `published_at` (`published_at`);

--
-- Indexes for table `portfolio`
--
ALTER TABLE `portfolio`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `is_featured` (`is_featured`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `fk_products_category` (`category_id`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_product_images_product` (`product_id`);

--
-- Indexes for table `product_tags`
--
ALTER TABLE `product_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `product_tag_map`
--
ALTER TABLE `product_tag_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product_tag_map_product_id_tag_id` (`product_id`,`tag_id`),
  ADD KEY `fk_product_tag_map_tag_id` (`tag_id`);

--
-- Indexes for table `seo_redirects`
--
ALTER TABLE `seo_redirects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `old_url` (`old_url`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `seo_schema`
--
ALTER TABLE `seo_schema`
  ADD PRIMARY KEY (`id`),
  ADD KEY `schema_type` (`schema_type`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `is_featured` (`is_featured`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `special_pages`
--
ALTER TABLE `special_pages`
  ADD PRIMARY KEY (`special_page_id`),
  ADD UNIQUE KEY `page_key` (`page_key`);

--
-- Indexes for table `testimonials`
--
ALTER TABLE `testimonials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `is_featured` (`is_featured`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID unik admin', AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `agent_prompts`
--
ALTER TABLE `agent_prompts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_agent_settings`
--
ALTER TABLE `ai_agent_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `ai_credentials`
--
ALTER TABLE `ai_credentials`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ai_models`
--
ALTER TABLE `ai_models`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `banners`
--
ALTER TABLE `banners`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery`
--
ALTER TABLE `gallery`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `landing_sections`
--
ALTER TABLE `landing_sections`
  MODIFY `landing_section_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `media_library`
--
ALTER TABLE `media_library`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `menus`
--
ALTER TABLE `menus`
  MODIFY `menu_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `pages`
--
ALTER TABLE `pages`
  MODIFY `page_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `portfolio`
--
ALTER TABLE `portfolio`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_tags`
--
ALTER TABLE `product_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_tag_map`
--
ALTER TABLE `product_tag_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seo_redirects`
--
ALTER TABLE `seo_redirects`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seo_schema`
--
ALTER TABLE `seo_schema`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `special_pages`
--
ALTER TABLE `special_pages`
  MODIFY `special_page_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `testimonials`
--
ALTER TABLE `testimonials`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_agent_settings`
--
ALTER TABLE `ai_agent_settings`
  ADD CONSTRAINT `fk_ai_agent_model` FOREIGN KEY (`model_id`) REFERENCES `ai_models` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `media_library`
--
ALTER TABLE `media_library`
  ADD CONSTRAINT `media_library_uploaded_by_fk` FOREIGN KEY (`uploaded_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_tag_map`
--
ALTER TABLE `product_tag_map`
  ADD CONSTRAINT `fk_product_tag_map_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_product_tag_map_tag_id` FOREIGN KEY (`tag_id`) REFERENCES `product_tags` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
