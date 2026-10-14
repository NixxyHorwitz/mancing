-- ══════════════════════════════════════════════════════════════════
-- FISH SYSTEM — Pemancingan Bahari Nusantara
-- Semua data yang bisa disimpan untuk fitur mancing.
-- Aman dijalankan berulang (IF NOT EXISTS). Kolom tambahan pada tabel
-- lama ditangani lib/fishing.php::fishing_ensure_schema().
-- ══════════════════════════════════════════════════════════════════

-- Tangkapan ikan = isi Ember. sold_at NULL = belum dijual.
CREATE TABLE IF NOT EXISTS `fishing_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `fish_name` VARCHAR(100) NOT NULL,
  `fish_icon` VARCHAR(50) NOT NULL DEFAULT '',
  `rarity` VARCHAR(30) NOT NULL DEFAULT 'common',
  `weight_kg` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `reward` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'nilai dasar dari server',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `sold_at` DATETIME NULL DEFAULT NULL,
  `sold_price` DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'harga jual = reward x pengali pasar',
  `sale_id` BIGINT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_unsold` (`user_id`, `sold_at`),
  KEY `idx_sale` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaksi penjualan ikan di Pasar (1 baris per klik "Jual")
CREATE TABLE IF NOT EXISTS `fishing_sales` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `fish_count` INT NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paket umpan (dikelola admin)
CREATE TABLE IF NOT EXISTS `fishing_bait_packs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `qty` INT NOT NULL DEFAULT 1,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tag` VARCHAR(30) NULL DEFAULT NULL,
  `description` VARCHAR(160) NULL DEFAULT NULL,
  `color` VARCHAR(9) NOT NULL DEFAULT '#f472b6',
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Riwayat pembelian umpan
CREATE TABLE IF NOT EXISTS `fishing_bait_purchases` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `pack_id` INT NULL DEFAULT NULL,
  `pack_name` VARCHAR(80) NOT NULL,
  `qty` INT NOT NULL DEFAULT 0,
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Katalog joran (skin): syarat unlock & bonus tempo permainan (dikelola admin)
CREATE TABLE IF NOT EXISTS `fishing_rod_skins` (
  `skin_key` VARCHAR(32) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `rarity` VARCHAR(20) NOT NULL DEFAULT 'biasa',
  `req_tier` INT NOT NULL DEFAULT 0 COMMENT 'sort_order membership minimal',
  `req_catches` INT NOT NULL DEFAULT 0,
  `req_jackpots` INT NOT NULL DEFAULT 0,
  `reel_bonus` INT NOT NULL DEFAULT 0 COMMENT '% kecepatan tarikan',
  `bite_bonus` INT NOT NULL DEFAULT 0 COMMENT '% pengurangan waktu tunggu',
  `description` VARCHAR(200) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`skin_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Harga pasar harian per jenis tangkapan (otomatis atau diatur manual admin)
CREATE TABLE IF NOT EXISTS `fishing_market_prices` (
  `price_date` DATE NOT NULL,
  `badge` VARCHAR(20) NOT NULL,
  `mult` DECIMAL(5,3) NOT NULL DEFAULT 1.000,
  `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`price_date`, `badge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferensi pemain: joran dipakai, suara, panduan yang sudah dilihat
CREATE TABLE IF NOT EXISTS `fishing_prefs` (
  `user_id` INT UNSIGNED NOT NULL,
  `rod_skin` VARCHAR(32) NOT NULL DEFAULT 'bambu',
  `sound_muted` TINYINT(1) NOT NULL DEFAULT 0,
  `tutorial_seen` VARCHAR(120) NOT NULL DEFAULT '',
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jejak audit tindakan admin di Fish System
CREATE TABLE IF NOT EXISTS `fishing_admin_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `admin` VARCHAR(80) NOT NULL,
  `action` VARCHAR(60) NOT NULL,
  `target_user_id` INT UNSIGNED NULL DEFAULT NULL,
  `detail` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data awal
INSERT IGNORE INTO `fishing_rod_skins` (`skin_key`, `name`, `rarity`, `req_tier`, `req_catches`, `req_jackpots`, `reel_bonus`, `bite_bonus`, `description`, `sort_order`) VALUES
('bambu',  'Joran Bambu Nelayan',     'biasa',   0, 0,  0, 0,  0,  'Bambu petung warisan nelayan pesisir. Ringan dan setia menemani.', 1),
('rotan',  'Joran Rotan Merah Putih', 'bagus',   0, 15, 0, 8,  10, 'Gagang rotan anyaman dengan ikatan merah putih dan pita kebanggaan.', 2),
('keris',  'Joran Pusaka Keris',      'langka',  0, 0,  1, 20, 22, 'Batang berpamor luk keris dengan ukiran gagang kayu. Hadiah penemu harta karun.', 3),
('batik',  'Joran Batik Parang',      'langka',  1, 0,  0, 15, 18, 'Berbalut motif batik parang yang melambangkan semangat pantang menyerah.', 4),
('wayang', 'Joran Wayang Emas',       'super',   2, 0,  0, 25, 28, 'Pernis hitam berlapis prada emas dengan hiasan gunungan wayang.', 5),
('naga',   'Joran Naga Nusantara',    'jackpot', 3, 0,  0, 35, 40, 'Batang giok bersisik emas dengan kepala naga dan mustika bercahaya.', 6);

INSERT INTO `fishing_bait_packs` (`name`, `qty`, `price`, `tag`, `description`, `color`, `sort_order`)
SELECT * FROM (
  SELECT 'Cacing Tanah' AS n, 5 AS q, 5000 AS p, NULL AS t, 'Umpan harian nelayan pesisir' AS d, '#f472b6' AS c, 1 AS s
  UNION ALL SELECT 'Pelet Super', 15, 12000, 'HEMAT 20%', 'Aroma kuat, disukai ikan tawar', '#a16207', 2
  UNION ALL SELECT 'Udang Hidup VIP', 35, 25000, 'HEMAT 30%', 'Umpan favorit predator laut', '#fb923c', 3
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `fishing_bait_packs`);

-- Katalog jenis ikan: peluang, rentang berat & harga dasar (dikelola di Console › Fish System › Harga Ikan).
-- Baris awal diisi otomatis oleh lib/fishing.php dari pengaturan reward_min/reward_max lama.
CREATE TABLE IF NOT EXISTS `fishing_species` (
  `badge` VARCHAR(20) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `chance` DECIMAL(6,2) NOT NULL DEFAULT 0,
  `weight_min` DECIMAL(6,2) NOT NULL DEFAULT 0.50,
  `weight_max` DECIMAL(6,2) NOT NULL DEFAULT 1.00,
  `price_min` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `price_max` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`badge`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
