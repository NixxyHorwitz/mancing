<?php
declare(strict_types=1);
/**
 * lib/fishing.php — Fish System (Pemancingan Bahari Nusantara)
 *
 * Semua data yang bisa disimpan ada di database (lihat database_migrations/create_fishing_system.sql):
 *   fishing_logs            tangkapan / isi Ember (sold_at NULL = belum dijual)
 *   fishing_sales           transaksi jual ikan → Saldo Tarik
 *   fishing_bait_packs      katalog paket umpan (dikelola admin)
 *   fishing_bait_purchases  riwayat beli umpan (pakai Saldo Beli)
 *   fishing_rod_skins       katalog joran: syarat & bonus (dikelola admin)
 *   fishing_market_prices   pengali harga pasar harian (otomatis / manual admin)
 *   fishing_prefs           joran dipakai, suara, panduan yang sudah dilihat
 *   fishing_admin_logs      jejak audit admin
 *
 * Alur cuan: ikan masuk Ember dulu; Saldo Tarik bertambah ketika ikan dijual
 * di Pasar dengan harga = nilai dasar × pengali pasar hari itu (85%–115%).
 */

const FISHING_BADGES = ['biasa', 'bagus', 'langka', 'super', 'jackpot'];
const FISHING_BADGE_LABEL = ['biasa' => 'Biasa', 'bagus' => 'Bagus', 'langka' => 'Langka', 'super' => 'Super', 'jackpot' => 'Jackpot'];
const FISHING_SPECIES = ['biasa' => 'Mujair / Nila', 'bagus' => 'Gurame', 'langka' => 'Kakap Merah', 'super' => 'Barramundi', 'jackpot' => 'Peti Harta Karun'];

function fishing_column_exists(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $s->execute([$table, $col]);
    return (bool)$s->fetchColumn();
}

/** Buat/migrasi semua tabel Fish System (idempoten, aman dipanggil tiap request). */
function fishing_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $sqlFile = dirname(__DIR__) . '/database_migrations/create_fishing_system.sql';
    $tables = ['fishing_logs', 'fishing_sales', 'fishing_bait_packs', 'fishing_bait_purchases', 'fishing_rod_skins', 'fishing_market_prices', 'fishing_prefs', 'fishing_admin_logs'];
    $in = implode(',', array_fill(0, count($tables), '?'));
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
    $s->execute($tables);
    if ((int)$s->fetchColumn() < count($tables) && is_file($sqlFile)) {
        foreach (array_filter(array_map('trim', explode(";\n", (string)file_get_contents($sqlFile)))) as $stmt) {
            $lines = array_filter(explode("\n", $stmt), fn($l) => !str_starts_with(ltrim($l), '--'));
            $q = trim(implode("\n", $lines));
            if ($q !== '') { try { $pdo->exec($q); } catch (\Throwable) {} }
        }
    }

    // Migrasi kolom pada tabel lama
    if (!fishing_column_exists($pdo, 'fishing_logs', 'sold_at')) {
        $cut = (string)$pdo->query("SELECT NOW()")->fetchColumn();
        try {
            $pdo->exec("ALTER TABLE fishing_logs ADD COLUMN sold_at DATETIME NULL DEFAULT NULL, ADD COLUMN sold_price DECIMAL(12,2) NULL DEFAULT NULL, ADD KEY idx_unsold (user_id, sold_at)");
            // tangkapan lama sudah dikredit saat memancing → tandai terjual agar tidak dibayar dua kali
            $pdo->prepare("UPDATE fishing_logs SET sold_at = created_at, sold_price = reward WHERE sold_at IS NULL AND created_at < ?")->execute([$cut]);
        } catch (\Throwable) {}
    }
    $adds = [
        ['fishing_logs', 'sale_id', "ALTER TABLE fishing_logs ADD COLUMN sale_id BIGINT NULL DEFAULT NULL, ADD KEY idx_sale (sale_id)"],
        ['fishing_prefs', 'sound_muted', "ALTER TABLE fishing_prefs ADD COLUMN sound_muted TINYINT(1) NOT NULL DEFAULT 0"],
        ['fishing_prefs', 'tutorial_seen', "ALTER TABLE fishing_prefs ADD COLUMN tutorial_seen VARCHAR(120) NOT NULL DEFAULT ''"],
    ];
    foreach ($adds as [$t, $c, $sql]) {
        if (!fishing_column_exists($pdo, $t, $c)) { try { $pdo->exec($sql); } catch (\Throwable) {} }
    }
}

/** Rarity di database (Common, Uncommon, ...) → badge (biasa, bagus, ...) */
function fishing_badge_of(string $rarity): string {
    return [
        'common' => 'biasa', 'uncommon' => 'bagus', 'rare' => 'langka', 'epic' => 'super', 'jackpot' => 'jackpot',
    ][strtolower($rarity)] ?? 'biasa';
}

/* ───────────────────────── HARGA PASAR ───────────────────────── */

/** Pengali otomatis deterministik per tanggal & jenis (0.85 – 1.15). */
function fishing_market_auto(string $date, string $badge): float {
    $key = (string)($_ENV['APP_KEY'] ?? 'mancing-bahari');
    $h = hexdec(substr(hash_hmac('sha256', $date . '|' . $badge, $key), 0, 8));
    return round(0.85 + ($h / 0xFFFFFFFF) * 0.30, 3);
}

/** Pengali yang tersimpan di DB untuk satu tanggal (dibuat otomatis bila belum ada). */
function fishing_market_for(PDO $pdo, string $date): array {
    $s = $pdo->prepare("SELECT badge, mult, is_manual FROM fishing_market_prices WHERE price_date = ?");
    $s->execute([$date]);
    $rows = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['badge']] = $r;
    $ins = $pdo->prepare("INSERT IGNORE INTO fishing_market_prices (price_date, badge, mult, is_manual) VALUES (?, ?, ?, 0)");
    $out = [];
    foreach (FISHING_BADGES as $b) {
        if (!isset($rows[$b])) {
            $m = fishing_market_auto($date, $b);
            try { $ins->execute([$date, $b, $m]); } catch (\Throwable) {}
            $rows[$b] = ['mult' => $m, 'is_manual' => 0];
        }
        $out[$b] = ['mult' => (float)$rows[$b]['mult'], 'manual' => (int)$rows[$b]['is_manual'] === 1];
    }
    return $out;
}

/** Harga pasar hari ini + tren dibanding kemarin. */
function fishing_market_today(PDO $pdo): array {
    $today = fishing_market_for($pdo, date('Y-m-d'));
    $yday  = fishing_market_for($pdo, date('Y-m-d', strtotime('-1 day')));
    $out = [];
    foreach (FISHING_BADGES as $b) {
        $m = $today[$b]['mult']; $p = $yday[$b]['mult'];
        $out[$b] = ['mult' => $m, 'prev' => $p, 'manual' => $today[$b]['manual'],
                    'trend' => $m > $p + 0.005 ? 'up' : ($m < $p - 0.005 ? 'down' : 'flat')];
    }
    return $out;
}

function fishing_sell_price(float $base, string $badge, array $market): float {
    return (float)max(1, round($base * ($market[$badge]['mult'] ?? 1.0)));
}

/* ───────────────────────── EMBER & PENJUALAN ───────────────────────── */

function fishing_unsold(PDO $pdo, int $userId, int $limit = 60): array {
    $s = $pdo->prepare("SELECT id, fish_name, rarity, weight_kg, reward, created_at
                        FROM fishing_logs WHERE user_id = ? AND sold_at IS NULL
                        ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
    $s->execute([$userId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Jual ikan milik user. Harga dihitung ulang di server. */
function fishing_sell(PDO $pdo, int $userId, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids || count($ids) > 100) return ['ok' => false, 'msg' => 'Pilih ikan yang ingin dijual (maksimal 100 ekor).'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE")->execute([$userId]);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT id, rarity, reward FROM fishing_logs WHERE user_id = ? AND sold_at IS NULL AND id IN ($in) FOR UPDATE");
        $s->execute(array_merge([$userId], $ids));
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Ikan tidak ditemukan atau sudah terjual.']; }

        $market = fishing_market_today($pdo);
        $pdo->prepare("INSERT INTO fishing_sales (user_id, fish_count, total) VALUES (?, 0, 0)")->execute([$userId]);
        $saleId = (int)$pdo->lastInsertId();
        $upd = $pdo->prepare("UPDATE fishing_logs SET sold_at = NOW(), sold_price = ?, sale_id = ? WHERE id = ?");
        $total = 0.0; $sold = [];
        foreach ($rows as $r) {
            $price = fishing_sell_price((float)$r['reward'], fishing_badge_of((string)$r['rarity']), $market);
            $upd->execute([$price, $saleId, $r['id']]);
            $total += $price; $sold[] = (int)$r['id'];
        }
        $pdo->prepare("UPDATE fishing_sales SET fish_count = ?, total = ? WHERE id = ?")->execute([count($sold), $total, $saleId]);
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")->execute([$total, $total, $userId]);
        try {
            $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'mancing', ?, ?)")->execute([$userId, count($sold), $total]);
        } catch (\Throwable) {}
        $pdo->commit();

        $b = $pdo->prepare("SELECT balance_wd FROM users WHERE id = ?"); $b->execute([$userId]);
        return ['ok' => true, 'msg' => count($sold) . ' ikan terjual seharga ' . format_rp($total) . '!',
                'sale_id' => $saleId, 'sold_ids' => $sold, 'total' => $total, 'total_rp' => format_rp($total),
                'balance_wd' => format_rp((float)$b->fetchColumn())];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'Gagal menjual ikan. Coba lagi ya.'];
    }
}

/* ───────────────────────── UMPAN ───────────────────────── */

function fishing_bait_packs(PDO $pdo, bool $activeOnly = true): array {
    $sql = "SELECT * FROM fishing_bait_packs" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC, id ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function fishing_buy_bait(PDO $pdo, int $userId, int $packId): array {
    $s = $pdo->prepare("SELECT * FROM fishing_bait_packs WHERE id = ? AND is_active = 1");
    $s->execute([$packId]);
    $pack = $s->fetch(PDO::FETCH_ASSOC);
    if (!$pack) return ['ok' => false, 'msg' => 'Paket umpan tidak tersedia.'];
    $price = (float)$pack['price'];
    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare("SELECT balance_dep, fishing_baits FROM users WHERE id = ? FOR UPDATE");
        $s->execute([$userId]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        if ((float)$u['balance_dep'] < $price) {
            $pdo->rollBack();
            return ['ok' => false, 'need_deposit' => true, 'msg' => 'Saldo Beli kurang untuk ' . $pack['name'] . ' (' . format_rp($price) . '). Top Up dulu yuk!'];
        }
        $newDep = (float)$u['balance_dep'] - $price;
        $newBaits = (int)$u['fishing_baits'] + (int)$pack['qty'];
        $pdo->prepare("UPDATE users SET balance_dep = ?, fishing_baits = ? WHERE id = ?")->execute([$newDep, $newBaits, $userId]);
        $pdo->prepare("INSERT INTO fishing_bait_purchases (user_id, pack_id, pack_name, qty, price) VALUES (?, ?, ?, ?, ?)")
            ->execute([$userId, (int)$pack['id'], $pack['name'], (int)$pack['qty'], $price]);
        $pdo->commit();
        return ['ok' => true, 'msg' => 'Berhasil membeli ' . $pack['name'] . ' (+' . (int)$pack['qty'] . ' umpan)!',
                'new_baits' => $newBaits, 'balance_dep' => format_rp($newDep)];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'Gagal memproses pembelian umpan.'];
    }
}

/* ───────────────────────── JORAN ───────────────────────── */

function fishing_rod_catalog(PDO $pdo, bool $activeOnly = true): array {
    $rows = $pdo->query("SELECT * FROM fishing_rod_skins" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[$r['skin_key']] = [
            'name' => $r['name'], 'rarity' => $r['rarity'], 'tier' => (int)$r['req_tier'],
            'catches' => (int)$r['req_catches'], 'jackpots' => (int)$r['req_jackpots'],
            'reel' => (int)$r['reel_bonus'], 'bite' => (int)$r['bite_bonus'], 'desc' => (string)$r['description'],
        ];
    }
    return $out;
}

/** Status unlock joran untuk user (tier = sort_order membership aktif, lihat user/upgrade.php). */
function fishing_rod_state(PDO $pdo, array $user, array $skins): array {
    $tier = 0;
    if (!empty($user['membership_id']) && !empty($user['membership_expires_at']) && strtotime((string)$user['membership_expires_at']) > time()) {
        $s = $pdo->prepare("SELECT sort_order FROM memberships WHERE id = ?");
        $s->execute([$user['membership_id']]);
        $tier = max(0, (int)$s->fetchColumn());
    }
    $tierNames = [];
    try {
        foreach ($pdo->query("SELECT name, sort_order FROM memberships WHERE is_active = 1 ORDER BY sort_order ASC") as $m) $tierNames[(int)$m['sort_order']] = (string)$m['name'];
    } catch (\Throwable) {}
    $s = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(UPPER(rarity) = 'JACKPOT'), 0) AS j FROM fishing_logs WHERE user_id = ?");
    $s->execute([$user['id']]);
    $agg = $s->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'j' => 0];
    $catches = (int)$agg['c']; $jackpots = (int)$agg['j'];
    $selected = fishing_prefs($pdo, (int)$user['id'])['rod_skin'];

    $out = [];
    foreach ($skins as $key => $sk) {
        $okTier = $tier >= $sk['tier']; $okCatch = $catches >= $sk['catches']; $okJack = $jackpots >= $sk['jackpots'];
        if ($sk['tier'] > 0) { $req = 'Butuh level ' . ($tierNames[$sk['tier']] ?? ('Level ' . $sk['tier'])); $progress = $okTier ? 1 : 0; }
        elseif ($sk['jackpots'] > 0) { $req = 'Temukan ' . $sk['jackpots'] . ' Peti Harta Karun (' . min($jackpots, $sk['jackpots']) . '/' . $sk['jackpots'] . ')'; $progress = min(1, $jackpots / $sk['jackpots']); }
        elseif ($sk['catches'] > 0) { $req = 'Tangkap ' . $sk['catches'] . ' ikan (' . min($catches, $sk['catches']) . '/' . $sk['catches'] . ')'; $progress = min(1, $catches / $sk['catches']); }
        else { $req = 'Joran bawaan'; $progress = 1; }
        $out[] = ['key' => $key, 'name' => $sk['name'], 'desc' => $sk['desc'], 'rarity' => $sk['rarity'], 'reel' => $sk['reel'], 'bite' => $sk['bite'],
                  'unlocked' => $okTier && $okCatch && $okJack, 'req' => $req, 'progress' => round($progress, 3), 'needUpgrade' => $sk['tier'] > 0 && !$okTier];
    }
    $valid = array_column(array_filter($out, fn($r) => $r['unlocked']), 'key');
    if (!in_array($selected, $valid, true)) $selected = $valid[0] ?? 'bambu';
    return ['rods' => $out, 'selected' => $selected, 'tier' => $tier, 'catches' => $catches, 'jackpots' => $jackpots];
}

/* ───────────────────────── PREFERENSI PEMAIN ───────────────────────── */

function fishing_prefs(PDO $pdo, int $userId): array {
    $s = $pdo->prepare("SELECT rod_skin, sound_muted, tutorial_seen FROM fishing_prefs WHERE user_id = ?");
    $s->execute([$userId]);
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'rod_skin' => (string)($r['rod_skin'] ?? 'bambu'),
        'sound_muted' => (int)($r['sound_muted'] ?? 0) === 1,
        'tutorial_seen' => array_values(array_filter(explode(',', (string)($r['tutorial_seen'] ?? '')))),
    ];
}

function fishing_prefs_save(PDO $pdo, int $userId, array $fields): void {
    $cur = fishing_prefs($pdo, $userId);
    $rod = (string)($fields['rod_skin'] ?? $cur['rod_skin']);
    $muted = array_key_exists('sound_muted', $fields) ? (int)(bool)$fields['sound_muted'] : (int)$cur['sound_muted'];
    $seen = $cur['tutorial_seen'];
    if (!empty($fields['tutorial_seen'])) {
        foreach ((array)$fields['tutorial_seen'] as $ph) {
            $ph = preg_replace('/[^a-z]/', '', strtolower((string)$ph));
            if ($ph !== '' && !in_array($ph, $seen, true)) $seen[] = $ph;
        }
    }
    $pdo->prepare("INSERT INTO fishing_prefs (user_id, rod_skin, sound_muted, tutorial_seen) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE rod_skin = VALUES(rod_skin), sound_muted = VALUES(sound_muted), tutorial_seen = VALUES(tutorial_seen)")
        ->execute([$userId, $rod, $muted, substr(implode(',', $seen), 0, 120)]);
}

/* ───────────────────────── AUDIT ADMIN ───────────────────────── */

function fishing_admin_log(PDO $pdo, string $action, ?int $targetUserId = null, $detail = null): void {
    $who = (string)($_SESSION['admin']['username'] ?? ($_SESSION['staff_username'] ?? 'system'));
    try {
        $pdo->prepare("INSERT INTO fishing_admin_logs (admin, action, target_user_id, detail) VALUES (?, ?, ?, ?)")
            ->execute([$who, $action, $targetUserId, is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable) {}
}
