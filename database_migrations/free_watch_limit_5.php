<?php
/**
 * Migrasi: limit tonton harian pangkat gratis 7 → 5 (2026-10-15).
 *
 *  - settings.free_watch_limit = 5 (dipakai user_watch_limit() untuk user tanpa membership aktif)
 *  - memberships.watch_limit pangkat gratis = 5 (ditampilkan di halaman Pangkat & Panduan)
 *  - Misi "Lempar Kail 4x" → 3x, agar bisa diselesaikan user gratis (2 umpan harian + 1 dari misi Tonton 3 Video)
 *
 * Aman dijalankan ulang. Jalankan: php database_migrations/free_watch_limit_5.php
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$before = setting($pdo, 'free_watch_limit', '7');
setting_set($pdo, 'free_watch_limit', '5');
echo "+ free_watch_limit: {$before} → 5\n";

$n = $pdo->exec("UPDATE memberships SET watch_limit = 5 WHERE price = 0");
echo "+ memberships gratis diperbarui: {$n} baris\n";

$pdo->prepare("UPDATE missions SET target_value = 3, title = 'Lempar Kail 3x', description = 'Mancing 3 kali hari ini.' WHERE slug = 'daily_cast_5'")->execute();
echo "+ misi lempar kail → 3x\n";

foreach ($pdo->query("SELECT name, price, watch_limit FROM memberships ORDER BY sort_order") as $r) echo "  {$r['name']} | Rp {$r['price']} | {$r['watch_limit']} video/hari\n";
foreach ($pdo->query("SELECT slug, title, target_value, reward_amount, reward_bait FROM missions WHERE category = 'daily' AND is_active = 1 ORDER BY sort_order") as $r) {
    echo "  {$r['slug']} | {$r['title']} | target {$r['target_value']} | Rp {$r['reward_amount']} | umpan {$r['reward_bait']}\n";
}
