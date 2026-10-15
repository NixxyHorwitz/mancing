-- Misi baru: video + mancing (Oktober 2026)
-- Total hadiah per periode tidak melebihi skema lama yang dibayarkan:
--   harian 3.200 (lama 3.500) · mingguan 28.000 (lama 30.000) · pencapaian 41.000 (lama 41.000)
-- Slug lama yang maknanya sama dipertahankan agar misi yang sudah diklaim tidak bisa diklaim ulang.

-- Nonaktifkan misi lama yang diganti (baris tetap disimpan untuk riwayat & log)
UPDATE missions SET is_active = 0 WHERE slug IN ('daily_watch_5', 'daily_checkin', 'weekly_streak_7', 'weekly_watch_7days');

INSERT INTO missions (slug, category, title, description, target_value, reward_amount, icon, sort_order, is_active) VALUES
  ('daily_watch_3',       'daily',    'Tonton 3 Video',      'Tonton 3 video hari ini.',               3,   1500,  'ph-play-circle',     10, 1),
  ('daily_watch_6',       'daily',    'Tonton 6 Video',      'Tonton 6 video hari ini.',               6,   2500,  'ph-film-strip',      20, 1),
  ('daily_cast_5',        'daily',    'Lempar Kail 5x',      'Mancing 5 kali hari ini.',               5,   1500,  'ph-fish-simple',     30, 1),
  ('daily_sell_fish',     'daily',    'Jual Ikan',           'Jual ikan di Pasar Ikan hari ini.',      1,   1500,  'ph-hand-coins',      40, 1),
  ('weekly_watch_20',     'weekly',   'Tonton 20 Video',     'Tonton 20 video minggu ini.',            20,  12000, 'ph-television',      10, 1),
  ('weekly_catch_30',     'weekly',   'Tangkap 30 Ikan',     'Tangkap 30 ikan minggu ini.',            30,  15000, 'ph-fish',            20, 1),
  ('weekly_rare_3',       'weekly',   '3 Ikan Langka+',      'Dapat 3 ikan Langka/Super/Peti.',        3,   10000, 'ph-sparkle',         30, 1),
  ('weekly_active_5',     'weekly',   'Aktif 5 Hari',        'Nonton atau mancing di 5 hari berbeda.', 5,   8000,  'ph-calendar-check',  40, 1),
  ('lifetime_first_ref',  'lifetime', 'Ajak 1 Kawan',        'Ajak 1 kawan daftar pakai kodemu.',      1,   10000, 'ph-user-plus',       10, 1),
  ('lifetime_5_refs',     'lifetime', 'Ajak 5 Kawan',        'Ajak 5 kawan daftar pakai kodemu.',      5,   25000, 'ph-users-three',     20, 1),
  ('lifetime_first_wd',   'lifetime', 'Tarik Pertama',       'Tarik saldo untuk pertama kali.',        1,   5000,  'ph-wallet',          30, 1),
  ('lifetime_100_videos', 'lifetime', '100 Video',           'Tonton total 100 video.',                100, 20000, 'ph-popcorn',         40, 1),
  ('lifetime_100_fish',   'lifetime', '100 Ikan',            'Tangkap total 100 ikan.',                100, 15000, 'ph-trophy',          50, 1),
  ('lifetime_jackpot',    'lifetime', 'Peti Harta',          'Temukan 1 Peti Harta Karun.',            1,   15000, 'ph-treasure-chest',  60, 1),
  ('lifetime_upgrade',    'lifetime', 'Naik Pangkat',        'Naik ke pangkat berbayar.',              1,   10000, 'ph-crown',           70, 1)
ON DUPLICATE KEY UPDATE
  category = VALUES(category), title = VALUES(title), description = VALUES(description),
  target_value = VALUES(target_value), reward_amount = VALUES(reward_amount),
  icon = VALUES(icon), sort_order = VALUES(sort_order), is_active = VALUES(is_active);
