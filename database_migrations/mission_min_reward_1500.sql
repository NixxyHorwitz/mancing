-- Hadiah misi minimal Rp 1.500 (2026-10-15). Aman dijalankan ulang.
UPDATE missions SET reward_amount = 1500 WHERE slug = 'daily_watch_3';   -- Tonton 3 Video (+1 umpan), dulu 800
UPDATE missions SET reward_amount = 2000 WHERE slug = 'daily_watch_6';   -- Tonton 6 Video (+1 umpan), dulu 1.200
UPDATE missions SET reward_amount = 1500 WHERE slug = 'daily_cast_5';    -- Lempar Kail 3x, dulu 600
UPDATE missions SET reward_amount = 1500 WHERE slug = 'daily_sell_fish'; -- Jual Ikan, dulu 600
-- Jaring pengaman: misi lain (termasuk yang nonaktif) tidak boleh di bawah Rp 1.500
UPDATE missions SET reward_amount = 1500 WHERE reward_amount < 1500;

SELECT category, slug, title, reward_amount, reward_bait, is_active FROM missions
 ORDER BY is_active DESC, FIELD(category, 'daily', 'weekly', 'lifetime'), sort_order;
