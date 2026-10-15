-- ════════════════════════════════════════════════════════════════════
-- Perubahan database 2026-10-15 — jalankan di phpMyAdmin (database: lebahcua_mancing)
-- Aman dijalankan ulang: tabel/kolom yang sudah ada dilewati, nilai ditimpa dengan nilai yang sama.
-- ════════════════════════════════════════════════════════════════════


-- ── 1. GLOBAL CHAT: 4 tabel baru ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS gc_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL DEFAULT 0,
    username VARCHAR(60) NOT NULL DEFAULT '',
    tier_name VARCHAR(60) NOT NULL DEFAULT '',
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    message TEXT NULL,
    image VARCHAR(255) NULL,
    is_shadow TINYINT(1) NOT NULL DEFAULT 0,
    flag_reason VARCHAR(160) NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by VARCHAR(60) NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gc_visible (is_deleted, is_shadow, id),
    KEY idx_gc_user (user_id, created_at),
    KEY idx_gc_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gc_user_overrides (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    can_view TINYINT(1) NULL,
    can_send TINYINT(1) NULL,
    can_image TINYINT(1) NULL,
    extra_msg INT NOT NULL DEFAULT 0,
    extra_img INT NOT NULL DEFAULT 0,
    muted_until DATETIME NULL,
    note VARCHAR(160) NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gc_daily_usage (
    user_id INT UNSIGNED NOT NULL,
    period_key VARCHAR(40) NOT NULL,
    msg_count INT NOT NULL DEFAULT 0,
    img_count INT NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL,
    PRIMARY KEY (user_id, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gc_admin_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    admin VARCHAR(80) NOT NULL,
    action VARCHAR(60) NOT NULL,
    target_user_id INT UNSIGNED NULL,
    detail TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gcl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── 2. UMPAN GRATIS PINDAH KE MISI ─────────────────────────────────────
-- Kolom hadiah umpan di tabel missions (dilewati bila sudah ada)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'missions' AND COLUMN_NAME = 'reward_bait');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE missions ADD COLUMN reward_bait INT NOT NULL DEFAULT 0 AFTER reward_amount',
    'SELECT ''kolom reward_bait sudah ada''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tonton 3 Video & Tonton 6 Video → +1 umpan masing-masing
UPDATE missions SET reward_bait = 1, description = 'Tonton 3 video hari ini, dapat 1 umpan.'      WHERE slug = 'daily_watch_3';
UPDATE missions SET reward_bait = 1, description = 'Tonton 6 video hari ini, dapat 1 umpan lagi.' WHERE slug = 'daily_watch_6';

-- Umpan per video dimatikan (pengaturan Mesin Mancing di home_cms_config)
UPDATE settings
   SET `value` = JSON_SET(`value`, '$.fishing_engine.bait_per_video', 0)
 WHERE `key` = 'home_cms_config' AND JSON_VALID(`value`);


-- ── 3. LIMIT TONTON PANGKAT GRATIS 7 → 5 ───────────────────────────────
INSERT INTO settings (`key`, `value`) VALUES ('free_watch_limit', '5')
    ON DUPLICATE KEY UPDATE `value` = '5';
UPDATE memberships SET watch_limit = 5 WHERE price = 0;

-- Misi lempar kail disesuaikan dengan 3 umpan/hari (2 harian + 1 dari misi)
UPDATE missions SET target_value = 3, title = 'Lempar Kail 3x', description = 'Mancing 3 kali hari ini.'
 WHERE slug = 'daily_cast_5';


-- ── 4. SURVEI BERHADIAH DITUTUP ────────────────────────────────────────
-- Ganti '0' menjadi '1' untuk membuka lagi (atau pakai tombol di Console › Survei)
INSERT INTO settings (`key`, `value`) VALUES ('survey_enabled', '0')
    ON DUPLICATE KEY UPDATE `value` = '0';


-- ── 5. CEK HASIL ───────────────────────────────────────────────────────
SELECT slug, title, target_value, reward_amount, reward_bait
  FROM missions WHERE category = 'daily' AND is_active = 1 ORDER BY sort_order;

SELECT name, price, watch_limit FROM memberships ORDER BY sort_order;

SELECT `key`, `value` FROM settings WHERE `key` IN ('free_watch_limit', 'survey_enabled');

SELECT JSON_EXTRACT(`value`, '$.fishing_engine.bait_per_video') AS bait_per_video
  FROM settings WHERE `key` = 'home_cms_config';

SELECT TABLE_NAME FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'gc\_%';
