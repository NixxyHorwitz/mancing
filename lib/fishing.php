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

/** Jenis yang bisa dijual (punya harga pasar). */
const FISHING_BADGES = ['biasa', 'bagus', 'langka', 'super', 'jackpot'];
/** Zonk = sampah laut: bisa tertangkap, tidak bernilai, tidak masuk Ember. */
const FISHING_ZONK = 'zonk';
const FISHING_ALL_BADGES = ['biasa', 'bagus', 'langka', 'super', 'jackpot', 'zonk'];
/** Jenis sampah zonk: kunci model => [nama, berat min, berat maks (kg)]. Model 3D di assets/models/junk/. */
const FISHING_JUNK = [
    'boot'     => ['Sepatu Bot Bekas', 0.60, 1.60],
    'bottle'   => ['Botol Plastik Bekas', 0.05, 0.40],
    'can'      => ['Kaleng Minuman Ringsek', 0.02, 0.15],
    'bag'      => ['Kantong Plastik Kresek', 0.01, 0.08],
    'cup'      => ['Gelas Plastik Bekas', 0.01, 0.06],
    'trashbag' => ['Kantong Sampah Hitam', 1.00, 4.50],
];
const FISHING_BADGE_LABEL = ['biasa' => 'Biasa', 'bagus' => 'Bagus', 'langka' => 'Langka', 'super' => 'Super', 'jackpot' => 'Jackpot', 'zonk' => 'Zonk'];
const FISHING_SPECIES = ['biasa' => 'Mujair / Nila', 'bagus' => 'Gurame', 'langka' => 'Kakap Merah', 'super' => 'Barramundi', 'jackpot' => 'Peti Harta Karun', 'zonk' => 'Sampah Laut'];

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
    $tables = ['fishing_logs', 'fishing_sales', 'fishing_bait_packs', 'fishing_bait_purchases', 'fishing_rod_skins', 'fishing_market_prices', 'fishing_prefs', 'fishing_admin_logs', 'fishing_species', 'fishing_lock_uses', 'fishing_released'];
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

    fishing_species_seed($pdo);
    // Zonk / sampah laut (sekali tambah; peluang bisa diubah atau dinolkan admin)
    try {
        $pdo->exec("INSERT IGNORE INTO fishing_species (badge, name, chance, weight_min, weight_max, price_min, price_max)
                    VALUES ('zonk', 'Sampah Laut (acak)', 8, 0.01, 4.50, 0, 0)");
        $pdo->exec("UPDATE fishing_species SET name = 'Sampah Laut (acak)' WHERE badge = 'zonk' AND name = 'Sepatu Bot Bekas'");
    } catch (\Throwable) {}
    // Tingkat kesulitan tarik-ulur per jenis ikan (Barramundi dibuat sedikit lebih menantang)
    if (!fishing_column_exists($pdo, 'fishing_species', 'hardness')) {
        try {
            $pdo->exec("ALTER TABLE fishing_species ADD COLUMN hardness DECIMAL(4,2) NOT NULL DEFAULT 1.00 AFTER price_max");
            $pdo->exec("UPDATE fishing_species SET hardness = 1.25 WHERE badge = 'super'");
        } catch (\Throwable) {}
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

/* ───────────────────────── JENIS IKAN & HARGA DASAR ───────────────────────── */

const FISHING_RARITY = ['biasa' => 'Common', 'bagus' => 'Uncommon', 'langka' => 'Rare', 'super' => 'Epic', 'jackpot' => 'JACKPOT', 'zonk' => 'Junk'];
const FISHING_ICON   = ['biasa' => '🐟', 'bagus' => '🐠', 'langka' => '🐡', 'super' => '🦈', 'jackpot' => '✨', 'zonk' => '🥾'];
const FISHING_COLOR  = ['biasa' => '#0284c7', 'bagus' => '#d97706', 'langka' => '#e11d48', 'super' => '#7c3aed', 'jackpot' => '#10b981', 'zonk' => '#78716c'];

/** Isi katalog awal sekali saja, meniru rumus lama (reward_min/reward_max) agar ekonomi tidak berubah diam-diam. */
function fishing_species_seed(PDO $pdo): void {
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM fishing_species")->fetchColumn() > 0) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS fishing_species (badge VARCHAR(20) NOT NULL PRIMARY KEY, name VARCHAR(80) NOT NULL,
            chance DECIMAL(6,2) NOT NULL DEFAULT 0, weight_min DECIMAL(6,2) NOT NULL DEFAULT 0.50, weight_max DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            price_min DECIMAL(12,2) NOT NULL DEFAULT 0, price_max DECIMAL(12,2) NOT NULL DEFAULT 0,
            hardness DECIMAL(4,2) NOT NULL DEFAULT 1.00,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable) { return; }
    $fe = function_exists('home_cms_config') ? (home_cms_config($pdo)['fishing_engine'] ?? []) : [];
    $mn = max(10, (float)($fe['reward_min'] ?? 100));
    $mx = max(50, (float)($fe['reward_max'] ?? 5000));
    $rows = [
        ['biasa',   'Ikan Mujair Cuan',           45, 0.50, 1.40, $mn + (0.5 / 1.4) * $mn * 1.5,      $mn * 2.5],
        ['bagus',   'Ikan Gurame Emas',           30, 1.50, 2.90, $mn * 2.5 + (1.5 / 2.9) * $mn * 3, $mn * 5.5],
        ['langka',  'Ikan Kakap Merah VIP',       15, 3.00, 4.80, $mn * 6 + (3 / 4.8) * $mn * 5,      $mn * 11],
        ['super',   'Monster Barramundi',          7, 5.00, 8.50, $mx * 0.75 + (5 / 8.5) * $mx * 0.5, $mx * 1.25],
        ['jackpot', 'Peti Harta Karun Lautan 👑',  3, 10.0, 15.0, $mx * 1.5 + 1000,                   $mx * 1.5 + 3000],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO fishing_species (badge, name, chance, weight_min, weight_max, price_min, price_max) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($rows as $r) { $r[5] = round($r[5]); $r[6] = round($r[6]); $ins->execute($r); }
}

/** Katalog jenis ikan, urut biasa → jackpot. */
function fishing_species(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT * FROM fishing_species")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['badge']] = $r;
    $sorted = [];
    foreach (FISHING_ALL_BADGES as $b) if (isset($out[$b])) $sorted[$b] = $out[$b];
    return $sorted;
}

/** Harga dasar naik linear dengan berat: ikan terberat = price_max. */
function fishing_species_price(array $sp, float $weight): float {
    $wMin = (float)$sp['weight_min']; $wMax = (float)$sp['weight_max'];
    $t = $wMax > $wMin ? max(0.0, min(1.0, ($weight - $wMin) / ($wMax - $wMin))) : 1.0;
    return round((float)$sp['price_min'] + $t * ((float)$sp['price_max'] - (float)$sp['price_min']));
}

/** Nilai dasar tarik-ulur (kesulitan 1.0): [kekuatan tarikan, jarak awal meter]. */
const FISHING_FIGHT_BASE = ['biasa' => [0.5, 12], 'bagus' => [0.62, 14], 'langka' => [0.75, 17], 'super' => [0.9, 22], 'jackpot' => [0.85, 22], 'zonk' => [0.4, 10]];

/** Parameter tarik-ulur per jenis = nilai dasar × kesulitan jenis × skala kesulitan global. */
function fishing_fight_params(PDO $pdo, ?array $species = null): array {
    $species = $species ?? fishing_species($pdo);
    $global = max(0.3, min(3.0, (float)(home_cms_config($pdo)['fishing_engine']['fight_hardness'] ?? 1)));
    $out = [];
    foreach (FISHING_FIGHT_BASE as $b => [$pull, $dist]) {
        $h = max(0.3, min(3.0, (float)($species[$b]['hardness'] ?? 1) * $global));
        $out[$b] = ['hardness' => round($h, 3), 'pull' => round($pull * $h, 3), 'dist' => (int)round($dist * (0.6 + 0.4 * $h))];
    }
    return $out;
}

/** Undi hasil tangkapan sesuai peluang & rentang di tabel fishing_species. */
function fishing_roll_catch(PDO $pdo): array {
    $species = array_filter(fishing_species($pdo), fn($s) => (float)$s['chance'] > 0);
    if (!$species) throw new RuntimeException('Katalog ikan kosong.');
    $total = array_sum(array_map(fn($s) => (float)$s['chance'], $species));
    $roll = mt_rand(1, 1000000) / 1000000 * $total;
    $pick = array_key_last($species);
    foreach ($species as $b => $s) { $roll -= (float)$s['chance']; if ($roll <= 0) { $pick = $b; break; } }
    $sp = $species[$pick];
    if ($pick === FISHING_ZONK) { // undi jenis sampah
        $junk = array_rand(FISHING_JUNK);
        [$jName, $jMin, $jMax] = FISHING_JUNK[$junk];
        return [
            'name' => $jName, 'icon' => 'junk:' . $junk, 'rarity' => FISHING_RARITY[$pick], 'badge' => $pick, 'junk' => $junk,
            'color' => FISHING_COLOR[$pick], 'weight' => max(0.01, round(mt_rand((int)round($jMin * 100), (int)round($jMax * 100)) / 100, 2)), 'reward' => 0.0,
        ];
    }
    $weight = round(mt_rand((int)round((float)$sp['weight_min'] * 100), (int)round((float)$sp['weight_max'] * 100)) / 100, 2);
    return [
        'name' => $sp['name'], 'icon' => FISHING_ICON[$pick], 'rarity' => FISHING_RARITY[$pick], 'badge' => $pick,
        'color' => FISHING_COLOR[$pick], 'weight' => $weight, 'reward' => fishing_species_price($sp, $weight),
    ];
}

/** Rarity di database (Common, Uncommon, ...) → badge (biasa, bagus, ...) */
function fishing_badge_of(string $rarity): string {
    return [
        'common' => 'biasa', 'uncommon' => 'bagus', 'rare' => 'langka', 'epic' => 'super', 'jackpot' => 'jackpot', 'junk' => 'zonk',
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
    $s = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(UPPER(rarity) = 'JACKPOT'), 0) AS j FROM fishing_logs WHERE user_id = ? AND UPPER(rarity) <> 'JUNK'");
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

/* ───────────────────────── KUNCI IKAN & LEPASKAN IKAN ───────────────────────── */

/** Harga & jatah gratis fitur Kunci Ikan (Console › Fish System › Pengaturan). */
function fishing_lock_cfg(PDO $pdo): array {
    $fe = home_cms_config($pdo)['fishing_engine'] ?? [];
    return ['price' => max(0, (float)($fe['lock_price'] ?? 2000)), 'free' => max(0, (int)($fe['lock_free_trials'] ?? 2))];
}

function fishing_lock_free_left(PDO $pdo, int $userId): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM fishing_lock_uses WHERE user_id = ? AND is_free = 1");
    $s->execute([$userId]);
    return max(0, fishing_lock_cfg($pdo)['free'] - (int)$s->fetchColumn());
}

/** Tangkapan yang sedang ditarik: milik user, belum dijual, dilempar maksimal 15 menit lalu. */
function fishing_active_catch(PDO $pdo, int $userId, int $logId, bool $forUpdate = false): ?array {
    $s = $pdo->prepare("SELECT * FROM fishing_logs WHERE id = ? AND user_id = ? AND (sold_at IS NULL OR UPPER(rarity) = 'JUNK')
                        AND created_at >= NOW() - INTERVAL 15 MINUTE" . ($forUpdate ? " FOR UPDATE" : ""));
    $s->execute([$logId, $userId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Kunci ikan: pakai jatah gratis dulu, selebihnya potong Saldo Beli. */
function fishing_lock(PDO $pdo, int $userId, int $logId): array {
    $cfg = fishing_lock_cfg($pdo);
    try {
        $pdo->beginTransaction();
        if (!fishing_active_catch($pdo, $userId, $logId, true)) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Tidak ada ikan yang sedang ditarik.']; }
        $s = $pdo->prepare("SELECT 1 FROM fishing_lock_uses WHERE log_id = ?");
        $s->execute([$logId]);
        if ($s->fetchColumn()) { $pdo->rollBack(); return ['ok' => true, 'already' => true, 'msg' => 'Ikan sudah terkunci.']; }
        $s = $pdo->prepare("SELECT balance_dep FROM users WHERE id = ? FOR UPDATE");
        $s->execute([$userId]);
        $dep = (float)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM fishing_lock_uses WHERE user_id = ? AND is_free = 1");
        $s->execute([$userId]);
        $freeLeft = max(0, $cfg['free'] - (int)$s->fetchColumn());
        $isFree = $freeLeft > 0 || $cfg['price'] <= 0;
        $price = $isFree ? 0.0 : $cfg['price'];
        if (!$isFree && $dep < $price) {
            $pdo->rollBack();
            return ['ok' => false, 'need_deposit' => true, 'msg' => 'Saldo Beli kurang untuk Kunci Ikan (' . format_rp($price) . '). Top Up dulu yuk!'];
        }
        if ($price > 0) $pdo->prepare("UPDATE users SET balance_dep = balance_dep - ? WHERE id = ?")->execute([$price, $userId]);
        $pdo->prepare("INSERT INTO fishing_lock_uses (user_id, log_id, is_free, price) VALUES (?, ?, ?, ?)")
            ->execute([$userId, $logId, $isFree && $freeLeft > 0 ? 1 : 0, $price]);
        $pdo->commit();
        $left = $isFree && $freeLeft > 0 ? $freeLeft - 1 : $freeLeft;
        return ['ok' => true, 'free' => $isFree, 'free_left' => $left, 'balance_dep' => format_rp($dep - $price),
                'msg' => $isFree ? 'Ikan terkunci! (uji coba gratis, sisa ' . $left . 'x)' : 'Ikan terkunci! Saldo Beli -' . format_rp($price)];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'Gagal mengunci ikan.'];
    }
}

/** Lepaskan ikan (menyerah): pindahkan dari Ember ke catatan fishing_released. Umpan tidak dikembalikan. */
function fishing_release(PDO $pdo, int $userId, int $logId): array {
    try {
        $pdo->beginTransaction();
        $row = fishing_active_catch($pdo, $userId, $logId, true);
        if (!$row) { $pdo->rollBack(); return ['ok' => false, 'msg' => 'Ikan ini tidak bisa dilepas.']; }
        $s = $pdo->prepare("SELECT 1 FROM fishing_lock_uses WHERE log_id = ?");
        $s->execute([$logId]);
        $locked = (bool)$s->fetchColumn();
        $pdo->prepare("INSERT INTO fishing_released (user_id, log_id, fish_name, rarity, weight_kg, reward, was_locked, caught_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $logId, $row['fish_name'], $row['rarity'], $row['weight_kg'], $row['reward'], $locked ? 1 : 0, $row['created_at']]);
        $pdo->prepare("DELETE FROM fishing_logs WHERE id = ? AND (sold_at IS NULL OR UPPER(rarity) = 'JUNK')")->execute([$logId]);
        $pdo->commit();
        return ['ok' => true, 'msg' => 'Ikan dilepas kembali ke laut.'];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => 'Gagal melepas ikan.'];
    }
}

/* ───────────────────────── AUDIT ADMIN ───────────────────────── */

function fishing_admin_log(PDO $pdo, string $action, ?int $targetUserId = null, $detail = null): void {
    $who = (string)($_SESSION['admin']['username'] ?? ($_SESSION['staff_username'] ?? 'system'));
    try {
        $pdo->prepare("INSERT INTO fishing_admin_logs (admin, action, target_user_id, detail) VALUES (?, ?, ?, ?)")
            ->execute([$who, $action, $targetUserId, is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable) {}
}
