-- ============================================================
-- Migration 018: Repositioning taxonomy -- "Crypto Intelligence &
-- Digital Asset Media" (see project doc: SagaCrypto Repositioning --
-- Crypto Intelligence & Digital Asset Media).
--
-- Adds the 8 new article categories the repositioning calls for, then
-- re-assigns the small number of existing articles (6 total in the
-- whole site as of 22 Sep 2026 -- 4 in the two categories touched here)
-- to their new home. The 9 old categories that end up with zero
-- articles (Altcoin, Sports, Guides, Market, Business, Apps, Blockchain,
-- Livescore, General News) are intentionally NOT dropped by this file --
-- see section "Old categories cleanup" at the bottom, opt-in and separate,
-- same convention as 008/012/013.
--
-- Part 1 (new categories) and Part 2 (re-assignment) are idempotent --
-- safe to run more than once. Part 3 (cleanup) is destructive/opt-in --
-- read it before running, same as every other DROP-carrying migration
-- in this folder.
--
-- Run via phpMyAdmin or:
--   mysql -u <user> -p <database> < 018_reposition_crypto_intelligence.sql
-- ============================================================

-- --------------------------------------------------------
-- Part 1: new categories (idempotent -- INSERT IGNORE on unique slug)
-- --------------------------------------------------------
INSERT IGNORE INTO `article_categories` (`name`, `slug`) VALUES
('Bitcoin & Ethereum', 'bitcoin-ethereum'),
('Altcoin',            'altcoin-new'),      -- see note below re: existing 'altcoin' slug
('DeFi & Web3',        'defi-web3'),
('Stablecoin',         'stablecoin'),
('Regulation',         'regulation'),
('Exchange',           'exchange'),
('On-chain Data',      'on-chain-data'),
('Crypto Research',    'crypto-research');

-- Note on 'Altcoin': the OLD category 'Altcoin' (slug `altcoin`, id 4) has
-- 0 articles (confirmed 22 Sep 2026), so the simplest path is to just
-- RENAME it in place instead of creating a duplicate -- see Part 1b.
-- Part 1b undoes the throwaway insert above for Altcoin specifically.
DELETE FROM `article_categories` WHERE `slug` = 'altcoin-new';
UPDATE `article_categories` SET `name` = 'Altcoin' WHERE `slug` = 'altcoin';
-- (name unchanged -- 'Altcoin' already matches the new taxonomy; this line
-- is a no-op safety net in case it was ever edited from the admin panel)

-- --------------------------------------------------------
-- Part 2: re-assign the 4 existing articles currently in categories
-- that are being retired ('Crypto News' id 1, 'Technology' id 6).
-- Targeted by page_id, not by bulk category match, so this is safe to
-- re-run and never touches an article added after this file was written.
-- --------------------------------------------------------

-- #4 "Citadel Securities Investasi $400 Juta ke Crypto.com" -> Exchange
UPDATE `pages` p
JOIN `article_categories` ac ON ac.slug = 'exchange'
SET p.category_id = ac.id
WHERE p.page_id = 4;

-- #7 "Kompak Anjlok. Bitcoin, Ethereum, Altcoin Kompak Turun..." -> Bitcoin & Ethereum
UPDATE `pages` p
JOIN `article_categories` ac ON ac.slug = 'bitcoin-ethereum'
SET p.category_id = ac.id
WHERE p.page_id = 7;

-- #8 "Pengadilan Argentina Bekukan 25 Akun Kripto Dalam..." -> Regulation
UPDATE `pages` p
JOIN `article_categories` ac ON ac.slug = 'regulation'
SET p.category_id = ac.id
WHERE p.page_id = 8;

-- #5 "Apa Itu Crypto" -> Crypto Research (closest fit; this is an
-- educational/explainer piece, no dedicated "Education" category exists
-- in this 8-category taxonomy by design -- see project doc)
UPDATE `pages` p
JOIN `article_categories` ac ON ac.slug = 'crypto-research'
SET p.category_id = ac.id
WHERE p.page_id = 5;

-- Part 2b (added post-verify, 23 Sep 2026): the OLD "Bitcoin" category
-- (id 3, slug `bitcoin`) turned out to have its OWN 2 published articles
-- that Part 2 above never touched (they were never in Crypto News/
-- Technology -- missed in the initial mapping pass). Both are Bitcoin
-- market-analysis pieces, re-assigned here to the new Bitcoin & Ethereum
-- category so the old `bitcoin` row can safely reach 0 for Part 3.
UPDATE `pages` p
JOIN `article_categories` ac ON ac.slug = 'bitcoin-ethereum'
SET p.category_id = ac.id
WHERE p.page_id IN (3, 6);
-- #3 "Diuji Kembali Mampukah Bitcoin Bertahan Dari Data..."
-- #6 "Dampak Tekanan Geopolitik & Suku Bunga Global Terh..."

-- --------------------------------------------------------
-- Verify (run manually, not part of the migration logic)
-- --------------------------------------------------------
-- SELECT ac.name AS kategori, COUNT(p.page_id) AS jumlah_artikel
-- FROM article_categories ac
-- LEFT JOIN pages p ON p.category_id = ac.id
-- GROUP BY ac.id
-- ORDER BY jumlah_artikel DESC;

-- ============================================================
-- Part 3: OLD CATEGORIES CLEANUP -- destructive, executed 23 Sep 2026
-- (kept here, uncommented, as the accurate historical record -- this
-- ran successfully against production via phpMyAdmin, 10 rows deleted).
-- 'bitcoin' was added to this list after Part 2b above surfaced its 2
-- orphaned articles and re-assigned them; it was not in the original
-- 9-category list. 'blockchain' was deliberately LEFT IN PLACE (0
-- articles, but kept as a still-relevant concept pending a possible
-- future "DeFi & Web3" merge decision -- Donnie's call, not automatic).
-- ============================================================

DELETE FROM `article_categories`
WHERE `slug` IN (
  'crypto-news',   -- retired, replaced by the split above (Exchange/Bitcoin & Ethereum/Regulation)
  'technology',    -- retired, replaced by Crypto Research for its one article
  'bitcoin',       -- retired, replaced by Bitcoin & Ethereum (Part 2b)
  'sports',        -- leftover from the removed Livescore module (15 Jul 2026)
  'livescore',     -- leftover from the removed Livescore module (15 Jul 2026)
  'apps',          -- not part of the new positioning
  'guides',        -- not part of the new positioning as a top-level category
  'market',        -- 0 articles, superseded by the MARKET *pillar* (not a category) in the new positioning
  'business',      -- not part of the new positioning
  'general-news'   -- not part of the new positioning
)
AND NOT EXISTS (
  SELECT 1 FROM `pages` p WHERE p.category_id = article_categories.id
);
