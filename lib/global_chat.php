<?php
/**
 * lib/global_chat.php — inti fitur Global Chat
 * Dipakai oleh user/global_chat.php, console/global_chat*.php & webhook.php.
 *
 * Konsep:
 * - Hak akses per pangkat (membership): lihat, kirim teks, kirim gambar, kuota pesan/gambar per hari.
 * - Hak per user (gc_user_overrides) mengalahkan aturan pangkat (NULL = ikut pangkat).
 * - Pesan dengan kata terlarang / tautan / nomor HP disimpan sebagai "shadow":
 *   pengirim melihatnya terkirim, user lain tidak menerimanya. Admin diberi notifikasi Telegram.
 * - Kuota harian direset pada jam tertentu; "Reset Sekarang" menaikkan reset_seq.
 */
declare(strict_types=1);

const GC_SETTING_KEY = 'global_chat_config';

function gc_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS gc_messages (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gc_user_overrides (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        can_view TINYINT(1) NULL,
        can_send TINYINT(1) NULL,
        can_image TINYINT(1) NULL,
        extra_msg INT NOT NULL DEFAULT 0,
        extra_img INT NOT NULL DEFAULT 0,
        muted_until DATETIME NULL,
        note VARCHAR(160) NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gc_daily_usage (
        user_id INT UNSIGNED NOT NULL,
        period_key VARCHAR(40) NOT NULL,
        msg_count INT NOT NULL DEFAULT 0,
        img_count INT NOT NULL DEFAULT 0,
        last_sent_at DATETIME NULL,
        PRIMARY KEY (user_id, period_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gc_admin_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        admin VARCHAR(80) NOT NULL,
        action VARCHAR(60) NOT NULL,
        target_user_id INT UNSIGNED NULL,
        detail TEXT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_gcl_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ───────────────────────── KONFIGURASI ───────────────────────── */

function gc_default_tier(bool $paid): array {
    return $paid
        ? ['view' => true, 'send' => true, 'image' => true,  'daily_msg' => 100, 'daily_img' => 10]
        : ['view' => true, 'send' => true, 'image' => false, 'daily_msg' => 20,  'daily_img' => 0];
}

function gc_config(PDO $pdo): array {
    $raw = setting($pdo, GC_SETTING_KEY, '');
    $saved = $raw ? (json_decode($raw, true) ?: []) : [];
    $cfg = array_merge([
        'enabled'        => true,
        'closed_message' => 'Global chat sedang ditutup admin. Kamu masih bisa membaca pesan.',
        'reset_hour'     => 0,
        'reset_seq'      => 0,
        'max_len'        => 300,
        'slow_mode'      => 5,
        'history_limit'  => 80,
        'banned_words'   => "kontol\nmemek\nngentot\nanjing\nbangsat\njudi\nslot gacor",
        'watch_words'    => "deposit\ntransfer\nscam\npenipu\nhack",
        'shadow_links'   => true,
        'shadow_phone'   => true,
        'notify_tg'      => true,
        'tiers'          => [],
    ], $saved);
    $cfg['tiers'] = is_array($cfg['tiers']) ? $cfg['tiers'] : [];
    foreach ($pdo->query("SELECT id, price FROM memberships ORDER BY sort_order") as $m) {
        $key = (string)$m['id'];
        $cfg['tiers'][$key] = array_merge(gc_default_tier((float)$m['price'] > 0), (array)($cfg['tiers'][$key] ?? []));
    }
    return $cfg;
}

function gc_config_save(PDO $pdo, array $cfg): void {
    setting_set($pdo, GC_SETTING_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE));
}

/* ───────────────────────── PANGKAT & HAK AKSES ───────────────────────── */

/** Pangkat aktif user (membership berbayar yang masih berlaku) atau pangkat gratis. */
function gc_user_tier(PDO $pdo, array $user): array {
    static $cache = [];
    $mid = 0;
    if (!empty($user['membership_id']) && !empty($user['membership_expires_at']) && strtotime((string)$user['membership_expires_at']) > time()) {
        $mid = (int)$user['membership_id'];
    }
    $k = $mid ?: 'free';
    if (isset($cache[$k])) return $cache[$k];
    $s = $mid
        ? $pdo->prepare("SELECT id, name FROM memberships WHERE id = ?")
        : $pdo->prepare("SELECT id, name FROM memberships WHERE price = 0 ORDER BY sort_order LIMIT 1");
    $s->execute($mid ? [$mid] : []);
    $row = $s->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'name' => 'Member'];
    return $cache[$k] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
}

function gc_override(PDO $pdo, int $userId): array {
    $s = $pdo->prepare("SELECT * FROM gc_user_overrides WHERE user_id = ?");
    $s->execute([$userId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Kunci periode kuota: tanggal batas reset terakhir + nomor reset manual. */
function gc_period_key(array $cfg): string {
    $h = max(0, min(23, (int)$cfg['reset_hour']));
    $base = (int)date('G') < $h ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
    return $base . '@' . $h . '#' . (int)$cfg['reset_seq'];
}

/** Jam reset berikutnya (untuk ditampilkan). */
function gc_next_reset(array $cfg): string {
    $h = max(0, min(23, (int)$cfg['reset_hour']));
    $t = strtotime(date('Y-m-d') . sprintf(' %02d:00:00', $h));
    if ($t <= time()) $t = strtotime('+1 day', $t);
    return date('d M H:i', $t);
}

function gc_usage(PDO $pdo, int $userId, string $period): array {
    $s = $pdo->prepare("SELECT msg_count, img_count, last_sent_at FROM gc_daily_usage WHERE user_id = ? AND period_key = ?");
    $s->execute([$userId, $period]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: ['msg_count' => 0, 'img_count' => 0, 'last_sent_at' => null];
}

/**
 * Hak akses efektif user. Urutan: pangkat → override user → mute → status chat (tutup).
 * Kembalian: view, send, image (bool), msg_left, img_left, limit_msg, limit_img, reason (kenapa tidak bisa kirim).
 */
function gc_permissions(PDO $pdo, array $user, ?array $cfg = null): array {
    $cfg  = $cfg ?? gc_config($pdo);
    $tier = gc_user_tier($pdo, $user);
    $t    = $cfg['tiers'][(string)$tier['id']] ?? gc_default_tier(false);
    $ov   = gc_override($pdo, (int)$user['id']);

    $view  = $ov && $ov['can_view'] !== null ? (bool)$ov['can_view'] : (bool)$t['view'];
    $send  = $ov && $ov['can_send'] !== null ? (bool)$ov['can_send'] : (bool)$t['send'];
    $image = $ov && $ov['can_image'] !== null ? (bool)$ov['can_image'] : (bool)$t['image'];
    $limMsg = max(0, (int)$t['daily_msg'] + (int)($ov['extra_msg'] ?? 0));
    $limImg = max(0, (int)$t['daily_img'] + (int)($ov['extra_img'] ?? 0));

    $period = gc_period_key($cfg);
    $use    = gc_usage($pdo, (int)$user['id'], $period);
    $reason = '';
    if (!$view)                          { $send = $image = false; $reason = 'Pangkatmu belum bisa membuka Global Chat.'; }
    elseif (!$cfg['enabled'])            { $send = $image = false; $reason = (string)$cfg['closed_message']; }
    elseif (!empty($ov['muted_until']) && strtotime((string)$ov['muted_until']) > time()) {
        $send = $image = false; $reason = 'Kamu dibisukan sampai ' . date('d M H:i', strtotime((string)$ov['muted_until'])) . '.';
    }
    elseif (!$send)                      { $image = false; $reason = 'Pangkatmu hanya bisa membaca. Naik pangkat untuk ikut ngobrol.'; }

    return [
        'tier' => $tier, 'view' => $view, 'send' => $send, 'image' => $image, 'reason' => $reason,
        'limit_msg' => $limMsg, 'limit_img' => $limImg,
        'msg_left' => max(0, $limMsg - (int)$use['msg_count']), 'img_left' => max(0, $limImg - (int)$use['img_count']),
        'last_sent_at' => $use['last_sent_at'], 'period' => $period, 'next_reset' => gc_next_reset($cfg),
    ];
}

/* ───────────────────────── FILTER PESAN ───────────────────────── */

/** Normalisasi anti-akal-akalan: huruf kecil, angka/simbol mirip huruf, huruf berulang dipadatkan. */
function gc_normalize(string $text): string {
    $t = mb_strtolower($text);
    $t = strtr($t, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i', '|' => 'i']);
    $t = preg_replace('/[^a-z]/u', '', $t) ?? '';
    return preg_replace('/(.)\1+/u', '$1', $t) ?? '';
}

function gc_word_list(string $raw): array {
    $out = [];
    foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $w) {
        $n = gc_normalize(trim($w));
        if (strlen($n) >= 3) $out[trim($w)] = $n;
    }
    return $out;
}

/**
 * Periksa pesan. Kembalian: ['shadow' => bool, 'notify' => bool, 'reason' => string].
 * shadow = disembunyikan dari user lain (pengirim tetap melihat terkirim).
 */
function gc_check_message(string $text, array $cfg): array {
    $norm = gc_normalize($text);
    foreach (gc_word_list((string)$cfg['banned_words']) as $orig => $w) {
        if ($w !== '' && str_contains($norm, $w)) return ['shadow' => true, 'notify' => true, 'reason' => 'Kata terlarang "' . $orig . '"'];
    }
    if (!empty($cfg['shadow_links']) && preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|net|id|xyz|me|io|co|link|site|top)\b|t\.me/|wa\.me/|bit\.ly)~i', $text)) {
        return ['shadow' => true, 'notify' => true, 'reason' => 'Mengandung tautan'];
    }
    if (!empty($cfg['shadow_phone']) && preg_match('/(\+?62|0)8[\d\s.\-]{7,13}\d/', $text)) {
        return ['shadow' => true, 'notify' => true, 'reason' => 'Mengandung nomor HP'];
    }
    foreach (gc_word_list((string)$cfg['watch_words']) as $orig => $w) {
        if ($w !== '' && str_contains($norm, $w)) return ['shadow' => false, 'notify' => true, 'reason' => 'Kata pantauan "' . $orig . '"'];
    }
    return ['shadow' => false, 'notify' => false, 'reason' => ''];
}

/* ───────────────────────── PESAN ───────────────────────── */

/** Pesan yang boleh dilihat user (shadow hanya terlihat oleh pengirimnya). */
function gc_fetch(PDO $pdo, int $viewerId, int $afterId, int $limit): array {
    $limit = max(1, min(200, $limit));
    if ($afterId > 0) {
        $s = $pdo->prepare("SELECT id, user_id, username, tier_name, is_admin, message, image, created_at
                            FROM gc_messages WHERE id > ? AND is_deleted = 0 AND (is_shadow = 0 OR user_id = ?) ORDER BY id ASC LIMIT $limit");
        $s->execute([$afterId, $viewerId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    $s = $pdo->prepare("SELECT * FROM (SELECT id, user_id, username, tier_name, is_admin, message, image, created_at
                        FROM gc_messages WHERE is_deleted = 0 AND (is_shadow = 0 OR user_id = ?) ORDER BY id DESC LIMIT $limit) x ORDER BY id ASC");
    $s->execute([$viewerId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function gc_admin_log(PDO $pdo, string $admin, string $action, ?int $target = null, $detail = null): void {
    try {
        $pdo->prepare("INSERT INTO gc_admin_logs (admin, action, target_user_id, detail) VALUES (?, ?, ?, ?)")
            ->execute([$admin, $action, $target, is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable) {}
}

function gc_console_admin(): string {
    return (string)($_SESSION['admin']['username'] ?? ($_SESSION['staff_username'] ?? 'admin'));
}

/** Notifikasi Telegram ke topik 🚨 Deteksi Abuse, dengan tombol aksi cepat. */
function gc_notify_abuse(PDO $pdo, array $msgRow, array $check, array $user, string $tierName): void {
    $text = trim((string)$msgRow['message']);
    $tg  = "🚨 <b>GLOBAL CHAT — PESAN MENCURIGAKAN</b>\n\n";
    $tg .= "👤 User: <code>" . htmlspecialchars((string)$user['username']) . "</code> (#" . (int)$user['id'] . ")\n";
    $tg .= "🏅 Pangkat: " . htmlspecialchars($tierName) . "\n";
    $tg .= "⚠️ Alasan: <b>" . htmlspecialchars($check['reason']) . "</b>\n";
    $tg .= "👁 Status: " . ($check['shadow'] ? '<b>Disembunyikan</b> (pengirim mengira terkirim)' : 'Tampil (hanya dipantau)') . "\n";
    if (!empty($msgRow['image'])) $tg .= "🖼 Lampiran gambar\n";
    $tg .= "\n💬 <i>" . htmlspecialchars(mb_strimwidth($text, 0, 300, '…')) . "</i>";
    $kb = [[
        ['text' => '🗑 Hapus', 'callback_data' => 'gc_del_' . (int)$msgRow['id']],
        ['text' => '🔇 Mute 24j', 'callback_data' => 'gc_mute_' . (int)$user['id']],
        ['text' => '⛔ Blokir Kirim', 'callback_data' => 'gc_block_' . (int)$user['id']],
    ]];
    try { send_telegram_notif($pdo, $tg, $kb, 'abuse'); } catch (\Throwable) {}
}

/** Pasang ubin "Global Chat" di menu cepat beranda (sekali, jika belum ada). */
function gc_ensure_home_tile(PDO $pdo): void {
    if (setting($pdo, 'gc_home_tile_added', '0') === '1' || !function_exists('home_cms_config')) return;
    $cfg = home_cms_config($pdo);
    $ids = array_column((array)($cfg['bento']['tiles'] ?? []), 'id');
    if (!in_array('global_chat', $ids, true)) {
        $cfg['bento']['tiles'][] = ['id' => 'global_chat', 'enabled' => true, 'label' => 'Global Chat', 'url' => '/global-chat',
                                    'icon' => 'ph-chats-circle', 'bg' => 'linear-gradient(135deg,#22d3ee,#0891b2)'];
        home_cms_config_set($pdo, $cfg);
    }
    setting_set($pdo, 'gc_home_tile_added', '1');
}
