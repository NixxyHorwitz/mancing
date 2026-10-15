<?php
/**
 * Migrasi: umpan pancing gratis pindah dari "per video" ke misi harian (opsi A, 2026-10-15).
 *
 *  - missions.reward_bait (kolom baru, default 0)
 *  - "Tonton 3 Video" & "Tonton 6 Video" → +1 umpan masing-masing (tiap 3 video = 1 umpan, maks 2/hari)
 *  - "Lempar Kail 5x" → 4x, agar tetap bisa diselesaikan user gratis (2 umpan harian + 2 dari misi)
 *  - fishing_engine.bait_per_video → 0
 *
 * Aman dijalankan ulang. Jalankan: php database_migrations/bait_from_missions.php
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$hasCol = (bool)$pdo->query("SHOW COLUMNS FROM missions LIKE 'reward_bait'")->fetch();
if (!$hasCol) {
    $pdo->exec("ALTER TABLE missions ADD COLUMN reward_bait INT NOT NULL DEFAULT 0 AFTER reward_amount");
    echo "+ kolom missions.reward_bait\n";
}

$pdo->prepare("UPDATE missions SET reward_bait = 1, description = ? WHERE slug = 'daily_watch_3'")
    ->execute(['Tonton 3 video hari ini, dapat 1 umpan.']);
$pdo->prepare("UPDATE missions SET reward_bait = 1, description = ? WHERE slug = 'daily_watch_6'")
    ->execute(['Tonton 6 video hari ini, dapat 1 umpan lagi.']);
$pdo->prepare("UPDATE missions SET target_value = 4, title = 'Lempar Kail 4x', description = 'Mancing 4 kali hari ini.' WHERE slug = 'daily_cast_5'")
    ->execute();
echo "+ misi harian diperbarui\n";

$cfg = home_cms_config($pdo);
$before = $cfg['fishing_engine']['bait_per_video'] ?? null;
$cfg['fishing_engine']['bait_per_video'] = 0;
home_cms_config_set($pdo, $cfg);
echo "+ bait_per_video: " . var_export($before, true) . " → 0\n";

foreach ($pdo->query("SELECT slug, title, target_value, reward_amount, reward_bait FROM missions WHERE category = 'daily' AND is_active = 1 ORDER BY sort_order") as $r) {
    echo "  {$r['slug']} | {$r['title']} | target {$r['target_value']} | Rp {$r['reward_amount']} | umpan {$r['reward_bait']}\n";
}
