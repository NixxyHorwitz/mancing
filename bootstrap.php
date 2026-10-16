<?php
declare(strict_types=1);

// ============================================================
// BOOTSTRAP   Platform
// ============================================================

// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
        putenv(trim($k) . '=' . trim($v));
    }
}

// Timezone — WIB (UTC+7)
date_default_timezone_set('Asia/Jakarta');

// Session — lifetime 30 hari
if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 30 * 24 * 60 * 60; // 30 hari dalam detik
    ini_set('session.gc_maxlifetime',  (string)$lifetime);
    ini_set('session.cookie_lifetime', (string)$lifetime);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Track global referral cookie
if (!empty($_GET['ref'])) {
    $ref_code = strtoupper(trim($_GET['ref']));
    setcookie('tonton_ref', $ref_code, time() + (86400 * 30), '/');
    $_COOKIE['tonton_ref'] = $ref_code;
    setcookie('ref_code', $ref_code, time() + (86400 * 30), '/');
    $_COOKIE['ref_code'] = $ref_code;
}

// PDO connection with automatic retry on connection spikes (Error 1040 / 1203) and emergency local fallback
function createPdo(): PDO {
    $primaryHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $primaryDb   = $_ENV['DB_DATABASE'] ?? 'caracuan';
    $primaryUser = $_ENV['DB_USERNAME'] ?? 'root';
    $primaryPass = $_ENV['DB_PASSWORD'] ?? '';
    $primaryPort = $_ENV['DB_PORT'] ?? '3306';

    $hosts = [];

    // On Linux/cPanel where web & MySQL reside on the same server:
    // Connecting to 'localhost' automatically uses local UNIX domain socket (/var/lib/mysql/mysql.sock),
    // which bypasses network TCP overhead, prevents TCP port exhaustion, and avoids 'Too many connections' limits.
    if (PHP_OS_FAMILY === 'Linux' && $primaryHost !== 'localhost') {
        $hosts[] = [
            'host'     => 'localhost',
            'port'     => $primaryPort,
            'dbname'   => $primaryDb,
            'username' => $primaryUser,
            'password' => $primaryPass,
        ];
    }

    $hosts[] = [
        'host'     => $primaryHost,
        'port'     => $primaryPort,
        'dbname'   => $primaryDb,
        'username' => $primaryUser,
        'password' => $primaryPass,
    ];

    // If fallback is explicitly configured, register fallback
    if (!empty($_ENV['DB_FALLBACK_HOST'])) {
        $hosts[] = [
            'host'     => $_ENV['DB_FALLBACK_HOST'],
            'port'     => $_ENV['DB_FALLBACK_PORT'] ?? '3306',
            'dbname'   => $_ENV['DB_FALLBACK_DATABASE'] ?? 'caracuan',
            'username' => $_ENV['DB_FALLBACK_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_FALLBACK_PASSWORD'] ?? '',
        ];
    } elseif ($primaryHost !== '127.0.0.1' && $primaryHost !== 'localhost') {
        // Automatically provide local Laragon MySQL as emergency fallback if remote server is exhausted
        $hosts[] = [
            'host'     => '127.0.0.1',
            'port'     => '3306',
            'dbname'   => 'caracuan',
            'username' => 'root',
            'password' => '',
        ];
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 4,
        // Short wait_timeout prevents idle connections from accumulating and hitting max_connections
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+07:00', wait_timeout=15, interactive_timeout=15",
    ];

    $lastException = null;

    foreach ($hosts as $cfg) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['dbname']);
        $isLocal = ($cfg['host'] === '127.0.0.1' || $cfg['host'] === 'localhost');
        $maxRetries = $isLocal ? 1 : 3;
        $retryDelayMs = 150;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return new PDO($dsn, $cfg['username'], $cfg['password'], $options);
            } catch (PDOException $e) {
                $lastException = $e;
                $code = (int)($e->errorInfo[1] ?? 0);
                $msg = $e->getMessage();
                $isConnLimit = ($code === 1040 || $code === 1203 || str_contains($msg, 'Too many connections') || str_contains($msg, 'max_user_connections'));

                if ($isConnLimit && $attempt < $maxRetries) {
                    usleep($retryDelayMs * 1000);
                    $retryDelayMs *= 2;
                    continue;
                }
                break;
            }
        }
    }

    throw $lastException ?? new PDOException("Could not connect to any database host.");
}

/**
 * Reconnect PDO if the MySQL connection has gone away (error 2006 / 2013).
 * Usage: pdo_reconnect($pdo); before any critical query block.
 */
function pdo_reconnect(PDO &$pdo): void {
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        $code = (int)$e->errorInfo[1];
        if ($code === 2006 || $code === 2013) {
            try {
                $pdo = createPdo();
            } catch (\Throwable) {
                // silently fail, the next query will throw a proper error
            }
        }
    }
}

try {
    $pdo = createPdo();
    // Cleanly close connection when script finishes
    register_shutdown_function(function() {
        global $pdo;
        $pdo = null;
    });
} catch (PDOException $e) {
    http_response_code(503);
    $msg = htmlspecialchars($e->getMessage());
    $isConn = str_contains($msg, '1040') || str_contains($msg, 'Too many connections');
    $hint = $isConn ? '<p style="color:#b45309;font-weight:bold;margin:12px 0;">💡 Tips cPanel: Pada file <code>.env</code> di hosting cPanel, pastikan <code>DB_HOST=localhost</code> (bukan IP publik) agar menggunakan UNIX domain socket lokal yang jauh lebih cepat, hemat port, dan tidak terkena limit TCP max_connections.</p>' : '';
    die('<h1 style="font-family:sans-serif">⚠️ Database Error</h1><p>Please start MySQL and check .env config</p>' . $hint . '<pre style="background:#f5f5f5;padding:12px;border-radius:6px">' . $msg . '</pre>');
}

// ============================================================
// HELPERS
// ============================================================

/** 
 * Clean input strings to prevent XSS. 
 * Strips HTML tags and encodes special characters.
 */
function clean_input(string $data): string {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

/** Read setting from DB with static cache */
function setting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $cache[$key] = ($v !== false ? (string)$v : $default);
    } catch (\Throwable) { return $cache[$key] = $default; }
}

/** Upsert setting */
function setting_set(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
        ->execute([$key, $value, $value]);
}

/** Get site logo URL from settings with fallback to favicon_path or default logo */
function site_logo(PDO $pdo): string {
    $logo = setting($pdo, 'site_logo', '');
    if (!$logo) {
        $logo = setting($pdo, 'favicon_path', '/assets/logo.png');
    }
    if (!$logo) {
        $logo = '/assets/logo.png';
    }
    return $logo;
}

/**
 * Get Home CMS configuration with robust defaults
 */
function home_cms_config(PDO $pdo): array {
    $raw = setting($pdo, 'home_cms_config', '');
    $saved = $raw ? json_decode($raw, true) : null;
    if (!is_array($saved)) {
        $saved = [];
    }

    $defaults = [
        // 1. HERO USER & SALDO
        'hero' => [
            'show_user_bar'      => true,
            'show_mascot'        => true,
            'mascot_text'        => 'Halo {username}! Nonton video hari ini & kumpulkan saldo Rupiah. Pancing ikan cuan & bagikan bukti hasilmu!',
            'show_balance_card'  => true,
            'show_today_stats'   => true,
            'show_ref_strip'     => true,
            'show_watch_mission' => true,
            'show_upgrade_btn'   => true,
            'show_watch_cta'     => true,
            'watch_cta_text'     => 'Mulai Tonton Video Sekarang',
            'show_quick_actions' => true,
        ],
        // 2. TOUR PANDUAN PENGGUNA BARU
        'tour' => [
            'show_banner' => true,
            'title'       => 'Baru di MancingCuan? Yuk Ikuti Panduan Singkat! 🎣',
            'desc'        => 'Pelajari langkah mudah menghasilkan saldo rupiah dari nonton video dan mancing cuan:',
        ],
        // 3. BENTO QUICK TILES
        'bento' => [
            'show_section' => true,
            'title'        => 'Menu Cepat & Fitur Cuan',
            'tiles'        => [
                [
                    'id'      => 'watch',
                    'enabled' => true,
                    'label'   => 'Tonton',
                    'url'     => '/videos',
                    'icon'    => 'ph-film-strip',
                    'bg'      => 'linear-gradient(135deg,#f97316,#ea580c)',
                ],
                [
                    'id'      => 'fishing',
                    'enabled' => true,
                    'label'   => 'Mancing',
                    'url'     => '/minigame',
                    'icon'    => 'ph-fish',
                    'bg'      => 'linear-gradient(135deg,#0284c7,#0369a1)',
                ],
                [
                    'id'      => 'threads',
                    'enabled' => true,
                    'label'   => 'Threads',
                    'url'     => '/threads',
                    'icon'    => 'ph-threads-logo',
                    'bg'      => 'linear-gradient(135deg,#18181b,#27272a)',
                ],
                [
                    'id'      => 'survey',
                    'enabled' => true,
                    'label'   => 'Survei',
                    'url'     => '/survey',
                    'icon'    => 'ph-clipboard-text',
                    'bg'      => 'linear-gradient(135deg,#059669,#047857)',
                ],
                [
                    'id'      => 'checkin',
                    'enabled' => true,
                    'label'   => 'Check-in',
                    'url'     => '/checkin',
                    'icon'    => 'ph-calendar-check',
                    'bg'      => 'linear-gradient(135deg,#f59e0b,#d97706)',
                ],
                [
                    'id'      => 'missions',
                    'enabled' => true,
                    'label'   => 'Misi',
                    'url'     => '/missions',
                    'icon'    => 'ph-target',
                    'bg'      => 'linear-gradient(135deg,#8b5cf6,#6d28d9)',
                ],
                [
                    'id'      => 'redeem',
                    'enabled' => true,
                    'label'   => 'Redeem',
                    'url'     => '/redeem',
                    'icon'    => 'ph-gift',
                    'bg'      => 'linear-gradient(135deg,#ec4899,#be185d)',
                ],
                [
                    'id'      => 'referral',
                    'enabled' => true,
                    'label'   => 'Undang',
                    'url'     => '/referral',
                    'icon'    => 'ph-users-three',
                    'bg'      => 'linear-gradient(135deg,#10b981,#047857)',
                ],
                [
                    'id'      => 'upgrade',
                    'enabled' => true,
                    'label'   => 'VIP Upgrade',
                    'url'     => '/upgrade',
                    'icon'    => 'ph-crown',
                    'bg'      => 'linear-gradient(135deg,#fbbf24,#d97706)',
                ],
            ],
        ],
        // 4. BANNER SURVEI PENGGUNA
        'survey_banner' => [
            'enabled' => true,
            'title'   => 'Survei Pengguna Berhadiah',
            'badge'   => '+Rp 15.000 WD',
            'desc'    => 'Bantu kami berkembang 2 menit & dapatkan Rp 15.000 saldo tarik langsung!',
            'cta'     => 'Isi Survei',
        ],
        // 5. BANNER AJAKAN PROMOSI / PROMO HUB
        'promo_banner' => [
            'enabled' => true,
            'title'   => 'Ajak Teman & Promosi Cuan',
            'badge'   => '+Rp 75.000',
            'desc'    => 'Viral di Threads & bagikan link untuk panen komisi saldo tunai!',
            'cta'     => 'Mulai',
        ],
        // 6. RIWAYAT LANJUTKAN MENONTON
        'continue_watching' => [
            'enabled' => true,
            'title'   => 'Lanjutkan Menonton',
            'limit'   => 3,
        ],
        // 7. CARD PROMOSI THREADS
        'threads_card' => [
            'enabled'     => true,
            'badge_event' => 'EVENT PROMOSI THREADS',
            'badge_cuan'  => '+Rp 75.000 & Gaji Pasti',
            'title'       => 'Viral di Threads & Jadi Promotor Khusus! 👑',
            'desc'        => 'Posting ceritamu, raih reward di Langkah 1 & bonus gaji pasti jika tembus 5K views!',
        ],
        // 8. TUGAS VIDEO PILIHAN
        'featured_videos' => [
            'enabled' => true,
            'title'   => 'Video Pilihan Berhadiah',
            'desc'    => 'Tonton video pilihan berdurasi singkat dan saldo cuan langsung bertambah otomatis!',
            'limit'   => 4,
        ],
        // 9. SPOTLIGHT GAME MANCING CUAN
        'fishing_spotlight' => [
            'enabled' => true,
            'badge'   => '🎣 GAME SPOTLIGHT',
            'title'   => 'Kolam Pemancingan Cuan 🐟',
            'desc'    => 'Lempar kailmu, strike ikan berkantong uang tunai, dan kumpulkan saldo tarik rupiah!',
            'cta'     => 'Mulai Mancing Sekarang',
            'url'     => '/minigame',
        ],
        // 10. TIPS CUAN
        'tips' => [
            'enabled' => true,
            'title'   => 'Tips Cuan Harian',
            'text'    => 'Tonton video sampai kuota harianmu penuh, pancing ikan cuan, dan undang teman untuk komisi pasif tanpa batas!',
        ],
        // 11. INBOX NOTIFIKASI
        'notif_inbox' => [
            'enabled' => true,
        ],
        // 12. FLOATING PROMO BUTTON
        'floating_promo' => [
            'enabled' => true,
            'title'   => 'Bonus Promosi',
            'sub'     => 'Raih Rp 75K',
            'badge'   => 'Hot',
        ],
        // 13. SISTEM ENGINE MANCING
        'fishing_engine' => [
            'enabled'          => true,
            'daily_free_baits' => 3,
            'bait_per_video'   => 0,  // umpan gratis kini dari misi harian (Tonton 3 & 6 Video)
            'ember_3d'         => true,
            'lock_price'       => 2000,
            'lock_free_trials' => 2,
            'fight_hardness'   => 1,
            'reward_min'       => 100,
            'reward_max'       => 5000,
            'bait_pack_price'  => 5000,
            'bait_pack_qty'    => 5,
        ],
    ];

    // Deep merge saved into defaults
    foreach ($defaults as $sec => $val) {
        if (!isset($saved[$sec])) {
            $saved[$sec] = $val;
        } elseif (is_array($val) && $sec !== 'bento') {
            $saved[$sec] = array_merge($val, (array)$saved[$sec]);
        }
    }

    if (!isset($saved['bento']['tiles']) || !is_array($saved['bento']['tiles'])) {
        $saved['bento']['tiles'] = $defaults['bento']['tiles'];
    } else {
        // Otomatis masukkan default tile baru (seperti redeem) jika belum ada di database
        $existing_ids = array_column($saved['bento']['tiles'], 'id');
        foreach ($defaults['bento']['tiles'] as $defTile) {
            if (!in_array($defTile['id'], $existing_ids, true)) {
                $saved['bento']['tiles'][] = $defTile;
            }
        }
    }

    return $saved;
}

/**
 * Save Home CMS configuration
 */
function home_cms_config_set(PDO $pdo, array $config): void {
    setting_set($pdo, 'home_cms_config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ============================================================
// MAINTENANCE MODE ENFORCEMENT
// ============================================================
if (setting($pdo, 'maintenance_mode', '0') === '1') {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    
    // Allow /console/ (Admin Area) and API callbacks to bypass
    $is_console = str_starts_with($uri, '/console');
    $is_webhook = str_starts_with($uri, '/webhook.php') || str_contains($uri, '/webhook.php') || str_starts_with($uri, '/api/');
    
    // Allow logged in admins/staff to bypass frontend maintenance
    $is_admin = !empty($_SESSION['admin']) || !empty($_SESSION['staff_username']);

    // Allow guest users (not logged in) to access the registration page
    $is_register = str_starts_with($uri, '/register') || str_starts_with($uri, '/auth/register.php');
    $is_logged_in = (auth_user($pdo) !== null);
    $allow_register_guest = $is_register && !$is_logged_in;

    if (!$is_console && !$is_admin && !$is_webhook && !$allow_register_guest) {
        $msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
        
        // Ensure session lock is released immediately to prevent hanging requests!
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        
        // Anti-Cache Headers (extremely aggressive)
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        header('Connection: close');
        header('Clear-Site-Data: "cache"');
        
        http_response_code(503);
        
        // If it is an AJAX/Fetch request, return JSON so JS does not choke on HTML
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
                   str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
                   
        if ($is_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Maintenance', 'message' => $msg]);
            exit;
        }

        echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Under Maintenance</title>
    <meta http-equiv="refresh" content="30">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        body { background:#0f1117; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; font-family:"Inter",sans-serif; text-align:center; padding:20px; box-sizing:border-box; }
        .box { background:#131520; border:1px solid #1f2235; padding:40px 30px; border-radius:16px; max-width:400px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.5); }
        .icon { font-size:48px; margin-bottom:16px; animation: pulse 2s infinite; }
        h1 { color:#FF6B35; font-size:22px; font-weight:900; margin:0 0 10px 0; }
        p { color:#a0a4b8; font-size:14px; line-height:1.6; margin:0; margin-bottom:20px; }
        .btn { background:#3b82f6; color:#fff; text-decoration:none; padding:10px 20px; border-radius:8px; font-size:14px; font-weight:700; display:inline-block; transition:0.2s; }
        .btn:hover { background:#2563eb; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">🔧</div>
        <h1>Sedang Perbaikan</h1>
        <p>' . nl2br(htmlspecialchars($msg)) . '</p>
        <a href="' . htmlspecialchars($uri) . '" class="btn">🔄 Coba Muat Ulang</a>
    </div>
</body>
</html>';
        exit;
    }
}


/**
 * Processes deposit referral commission for the upline user.
 * If referral_hold_days > 0, commission is inserted as 'locked' with unlock_at in the future.
 * If referral_hold_days == 0, commission is credited immediately to balance_wd.
 * Returns the commission amount (0.0 if none).
 */
function credit_deposit_referral_commission(PDO $pdo, int $depositor_user_id, float $deposit_amount): float {
    if ($deposit_amount <= 0) return 0.0;
    
    // Find upline of depositor
    $stmt = $pdo->prepare(
        "SELECT u2.id, u2.username, u2.is_promotor 
         FROM users u 
         JOIN users u2 ON TRIM(UPPER(u2.referral_code)) = TRIM(UPPER(u.referred_by)) 
         WHERE u.id = ? LIMIT 1"
    );
    $stmt->execute([$depositor_user_id]);
    $ref = $stmt->fetch();
    
    if ($ref && !empty($ref['id']) && (int)$ref['is_promotor'] !== 1) {
        $pct = (float) setting($pdo, 'referral_commission_percent', '5');
        if ($pct <= 0) return 0.0;
        
        $commission = round(($deposit_amount * $pct) / 100, 2);
        if ($commission > 0) {
            $hold_days = max(0, (int) setting($pdo, 'referral_hold_days', '3'));
            
            if ($hold_days > 0) {
                // Frozen / Locked commission: holding period before claiming
                $unlock_at = date('Y-m-d H:i:s', strtotime("+{$hold_days} days"));
                $pdo->prepare(
                    "INSERT INTO referral_commissions (user_id, from_user_id, amount, status, unlock_at) 
                     VALUES (?, ?, ?, 'locked', ?)"
                )->execute([$ref['id'], $depositor_user_id, $commission, $unlock_at]);
            } else {
                // Instant credit
                $now = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")
                    ->execute([$commission, $commission, $ref['id']]);
                $pdo->prepare(
                    "INSERT INTO referral_commissions (user_id, from_user_id, amount, status, unlock_at, claimed_at) 
                     VALUES (?, ?, ?, 'claimed', ?, ?)"
                )->execute([$ref['id'], $depositor_user_id, $commission, $now, $now]);
            }
            return $commission;
        }
    }
    return 0.0;
}

/**
 * Processes registration referral bonus for the upline user.
 * If referral_hold_days > 0, bonus is inserted as 'locked' with unlock_at in the future.
 * If referral_hold_days == 0, bonus is credited immediately to balance_wd.
 * Returns the bonus amount (0.0 if none).
 */
function credit_registration_referral_bonus(PDO $pdo, int $new_user_id, string $ref_code): float {
    $ref_code = strtoupper(trim($ref_code));
    if (!$ref_code) return 0.0;
    
    $stmt = $pdo->prepare("SELECT id, username, is_promotor FROM users WHERE TRIM(UPPER(referral_code)) = ? LIMIT 1");
    $stmt->execute([$ref_code]);
    $upline = $stmt->fetch();
    if (!$upline || empty($upline['id'])) return 0.0;
    
    $bonus = 0.0;
    if ((int)$upline['is_promotor'] !== 1) {
        $bonus = (float) setting($pdo, 'referral_bonus', '1000');
    } else {
        $bonus = (float) setting($pdo, 'promotor_per_member_bonus', '0');
    }
    
    if ($bonus > 0) {
        $hold_days = max(0, (int) setting($pdo, 'referral_hold_days', '3'));
        
        if ($hold_days > 0) {
            // Frozen / Locked bonus
            $unlock_at = date('Y-m-d H:i:s', strtotime("+{$hold_days} days"));
            $pdo->prepare(
                "INSERT INTO referral_commissions (user_id, from_user_id, amount, status, unlock_at) 
                 VALUES (?, ?, ?, 'locked', ?)"
            )->execute([$upline['id'], $new_user_id, $bonus, $unlock_at]);
        } else {
            // Instant credit
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")
                ->execute([$bonus, $bonus, $upline['id']]);
            $pdo->prepare(
                "INSERT INTO referral_commissions (user_id, from_user_id, amount, status, unlock_at, claimed_at) 
                 VALUES (?, ?, ?, 'claimed', ?, ?)"
            )->execute([$upline['id'], $new_user_id, $bonus, $now, $now]);
        }
        return $bonus;
    }
    return 0.0;
}

/**
 * Claims all unlocked referral commissions for a user and transfers to balance_wd.
 * Uses atomic transaction and row locking to guarantee consistency.
 * Returns ['success' => bool, 'claimed_amount' => float, 'count' => int, 'message' => string]
 */
function claim_user_referral_commissions(PDO $pdo, int $user_id): array {
    if ($user_id <= 0) {
        return ['success' => false, 'claimed_amount' => 0.0, 'count' => 0, 'message' => 'User tidak valid.'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // Lock rows eligible for claiming (status 'locked' and unlock_at <= NOW)
        $stmt = $pdo->prepare(
            "SELECT id, amount 
             FROM referral_commissions 
             WHERE user_id = ? AND status = 'locked' AND (unlock_at IS NULL OR unlock_at <= NOW()) 
             FOR UPDATE"
        );
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($rows)) {
            $pdo->rollBack();
            return [
                'success' => false, 
                'claimed_amount' => 0.0, 
                'count' => 0, 
                'message' => 'Belum ada komisi yang siap dicairkan atau masa tunggu belum selesai.'
            ];
        }
        
        $totalClaim = 0.0;
        $ids = [];
        foreach ($rows as $r) {
            $totalClaim += (float)$r['amount'];
            $ids[] = (int)$r['id'];
        }
        
        if ($totalClaim <= 0 || empty($ids)) {
            $pdo->rollBack();
            return [
                'success' => false, 
                'claimed_amount' => 0.0, 
                'count' => 0, 
                'message' => 'Nominal komisi tidak mencukupi untuk dicairkan.'
            ];
        }
        
        // Update referral_commissions status to claimed
        $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $updateStmt = $pdo->prepare(
            "UPDATE referral_commissions 
             SET status = 'claimed', claimed_at = NOW() 
             WHERE id IN ($inPlaceholders)"
        );
        $updateStmt->execute($ids);
        
        // Update user balances (balance_wd and total_earned)
        $userStmt = $pdo->prepare(
            "UPDATE users 
             SET balance_wd = balance_wd + ?, total_earned = total_earned + ? 
             WHERE id = ?"
        );
        $userStmt->execute([$totalClaim, $totalClaim, $user_id]);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'claimed_amount' => $totalClaim,
            'count' => count($ids),
            'message' => 'Berhasil mencairkan komisi sebesar ' . format_rp($totalClaim) . ' ke Saldo Penarikan!'
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false, 
            'claimed_amount' => 0.0, 
            'count' => 0, 
            'message' => 'Terjadi kesalahan sistem saat mencairkan komisi: ' . $e->getMessage()
        ];
    }
}

/**
 * Gets summary of referral commissions for a user:
 * - locked_amount (frozen, unlock_at > NOW)
 * - claimable_amount (unlocked, ready to claim)
 * - claimed_amount (already claimed into balance_wd)
 * - total_earned (sum of all commissions ever earned)
 * - earliest_unlock (next unlock timestamp or null)
 */
function get_user_referral_commission_summary(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare(
        "SELECT 
            COALESCE(SUM(CASE WHEN status = 'locked' AND unlock_at > NOW() THEN amount ELSE 0 END), 0) as locked_amount,
            COALESCE(SUM(CASE WHEN status = 'locked' AND (unlock_at IS NULL OR unlock_at <= NOW()) THEN amount ELSE 0 END), 0) as claimable_amount,
            COALESCE(SUM(CASE WHEN status = 'claimed' THEN amount ELSE 0 END), 0) as claimed_amount,
            COALESCE(SUM(amount), 0) as total_earned,
            MIN(CASE WHEN status = 'locked' AND unlock_at > NOW() THEN unlock_at ELSE NULL END) as earliest_unlock
         FROM referral_commissions 
         WHERE user_id = ?"
    );
    $stmt->execute([$user_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    return [
        'locked_amount'    => (float)($res['locked_amount'] ?? 0),
        'claimable_amount' => (float)($res['claimable_amount'] ?? 0),
        'claimed_amount'   => (float)($res['claimed_amount'] ?? 0),
        'total_earned'     => (float)($res['total_earned'] ?? 0),
        'earliest_unlock'  => $res['earliest_unlock'] ?? null,
    ];
}

// Backward-compatible alias
function process_referral_commission(PDO $pdo, int $user_id, float $amount, int $depth = 1): void {
    credit_deposit_referral_commission($pdo, $user_id, $amount);
}


/** Format currency IDR */
function format_rp(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/** Mask account/phone number: show first 4 and last 4 chars, mask middle with **** */
function mask_account(string $num): string {
    $num = trim($num);
    $len = mb_strlen($num);
    if ($len <= 8) return str_repeat('*', $len); // too short, mask all
    $visible = 4;
    $tail    = 4;
    return mb_substr($num, 0, $visible) . str_repeat('*', $len - $visible - $tail) . mb_substr($num, -$tail);
}

/** Generate CSRF token */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Render CSRF hidden input */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** Verify CSRF token */
function csrf_verify(): bool {
    $tok = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals(csrf_token(), (string)$tok);
}

/** Enforce CSRF on POST — aborts with 403 if invalid */
function csrf_enforce(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
            http_response_code(413);
            die('<h1 style="font-family:sans-serif">413 Payload Too Large</h1><p>File yang diupload terlalu besar (melebihi limit PHP post_max_size/upload_max_filesize).</p><button onclick="history.back()">Kembali</button>');
        }
        if (!csrf_verify()) {
            http_response_code(403);
            die('<h1>403 Invalid CSRF Token</h1>');
        }
    }
}

/** HTTP redirect (clean URL) */
function redirect(string $url): never {
    header("Location: {$url}");
    exit;
}

/** Generate unique referral code */
function generate_referral_code(PDO $pdo): string {
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE referral_code=?");
        $exists->execute([$code]);
    } while ($exists->fetchColumn());
    return $code;
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $scheme = 'https';
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
            $cf = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
            if (!empty($cf['scheme']) && $cf['scheme'] === 'https') {
                $scheme = 'https';
            }
        }
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (str_contains($host, ',')) {
            $host = trim(explode(',', $host)[0]);
        }
        $root = rtrim($scheme . '://' . $host . '/', '/') . '/';
        return $root . ltrim($path, '/');
    }
}

/** Extract YouTube video ID from various URL formats */
function extract_youtube_id(string $url): string {
    $patterns = [
        '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/',
        '/^([a-zA-Z0-9_-]{11})$/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) return $m[1];
    }
    return '';
}

/** Get YouTube thumbnail URL (Legacy) */
function yt_thumb(string $youtube_id): string {
    return "https://img.youtube.com/vi/{$youtube_id}/maxresdefault.jpg";
}

/** Get logged-in user or null */
function set_auth_cookie(int $user_id): void {
    $expires = time() + (86400 * 30); // 30 days
    $data = $user_id . '|' . $expires;
    $signature = hash_hmac('sha256', $data, $_ENV['APP_KEY'] ?? 'default_secret_key');
    $cookie_val = $data . '|' . $signature;
    setcookie('tonton_session', $cookie_val, $expires, '/', '', false, true); // HTTPOnly true
}

function get_auth_cookie(): ?int {
    if (empty($_COOKIE['tonton_session'])) return null;
    $parts = explode('|', $_COOKIE['tonton_session']);
    if (count($parts) !== 3) return null;
    [$user_id, $expires, $signature] = $parts;
    if (time() > (int)$expires) return null; // expired
    $data = $user_id . '|' . $expires;
    $expected_sig = hash_hmac('sha256', $data, $_ENV['APP_KEY'] ?? 'default_secret_key');
    if (!hash_equals($expected_sig, $signature)) return null; // tampered
    return (int)$user_id;
}

function clear_auth_cookie(): void {
    setcookie('tonton_session', '', time() - 3600, '/');
}

function auth_user(PDO $pdo): ?array {
    $uid = get_auth_cookie();
    if (!$uid) return null;
    static $user = null;
    if ($user !== null) return $user;
    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND is_active=1");
    $s->execute([$uid]);
    return $user = ($s->fetch() ?: null);
}

/** Require user auth — redirects to /login if not logged in */
function require_auth(PDO $pdo): array {
    $u = auth_user($pdo);
    if (!$u) redirect('/login');
    return $u;
}

/** Get user's daily watch limit based on active membership */
function user_watch_limit(PDO $pdo, array $user): int {
    if ($user['membership_id'] && $user['membership_expires_at']
        && strtotime((string)$user['membership_expires_at']) > time()) {
        $s = $pdo->prepare("SELECT watch_limit FROM memberships WHERE id=? AND is_active=1");
        $s->execute([$user['membership_id']]);
        $v = $s->fetchColumn();
        if ($v !== false) return (int)$v;
    }
    return (int) setting($pdo, 'free_watch_limit', '7');
}

/** Count videos user watched today */
function user_watch_today(PDO $pdo, array $user): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
    $s->execute([$user['id']]);
    return (int)$s->fetchColumn();
}

function get_free_tier_name(PDO $pdo): string {
    static $free_name = null;
    if ($free_name === null) {
        $free_name = $pdo->query('SELECT name FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1')->fetchColumn() ?: 'Free';
    }
    return $free_name;
}

/** Get user membership sort_order level (0 = Free) */
function user_membership_level(PDO $pdo, array $user): int {
    if ($user['membership_id'] && $user['membership_expires_at']
        && strtotime((string)$user['membership_expires_at']) > time()) {
        $s = $pdo->prepare("SELECT sort_order FROM memberships WHERE id=?");
        $s->execute([$user['membership_id']]);
        $v = $s->fetchColumn();
        if ($v !== false) return (int)$v;
    }
    return 0;
}

/** Check if site is in maintenance mode */
function is_maintenance(PDO $pdo): bool {
    return setting($pdo, 'maintenance_mode', '0') === '1';
}

/** Check if withdrawals are currently locked by time window */
function is_wd_locked(PDO $pdo): bool {
    $start = setting($pdo, 'wd_lock_start', '');
    $end   = setting($pdo, 'wd_lock_end', '');
    if ($start === '' || $end === '') return false;
    $now   = (int)date('Hi'); // e.g. 2230
    $s     = (int)str_replace(':', '', $start);
    $e     = (int)str_replace(':', '', $end);
    if ($s <= $e) return $now >= $s && $now < $e;
    // crosses midnight: e.g. 22:00 → 06:00
    return $now >= $s || $now < $e;
}

/**
 * Generate dynamic QRIS by modifying the raw QRIS string amount field (Tag 54).
 * Returns base64-encoded QR PNG using a free QR API, or empty string on failure.
 */
function qris_with_amount(string $qris_raw, int $amount): string {
    if (empty($qris_raw)) return '';
    // Remove existing CRC (last 4 hex chars after 6304)
    $pos = strpos($qris_raw, '6304');
    if ($pos !== false) {
        $qris_raw = substr($qris_raw, 0, $pos);
    }
    // Remove existing Tag 54 (Transaction Amount) if present
    $qris_raw = preg_replace('/5402\d{2}[\d.]+/', '', $qris_raw);
    // Build Tag 54 with amount
    $amt_str  = (string)$amount;
    $tag54    = '54' . str_pad((string)strlen($amt_str), 2, '0', STR_PAD_LEFT) . $amt_str;
    // Insert before tag 58 (Country Code)
    $qris_raw = preg_replace('/(5802)/', $tag54 . '$1', $qris_raw);
    // Recalculate CRC-16/CCITT-FALSE
    $qris_raw .= '6304';
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($qris_raw); $i++) {
        $crc ^= (ord($qris_raw[$i]) << 8);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
        }
        $crc &= 0xFFFF;
    }
    $qris_final = $qris_raw . strtoupper(sprintf('%04X', $crc));
    return $qris_final;
}

/** Get logged-in admin or null */
function auth_admin(): ?array {
    return $_SESSION['admin'] ?? null;
}

/** Require admin auth — redirects to /console/login */
function require_admin(): array {
    $a = auth_admin();
    if (!$a) redirect('/console/login');
    return $a;
}


/** Send message to Telegram Admin Group/Channel */
function send_telegram_notif(PDO $pdo, string $message, array $inline_keyboard = [], ?string $topic = null): ?int {
    $token = setting($pdo, 'tg_bot_token', '');
    $chat_id = setting($pdo, 'tg_chat_id', '');
    if (!$token || !$chat_id) return null;
    
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $post = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    if ($topic) {
        $thread_id = setting($pdo, "tg_topic_{$topic}", '');
        if (!$thread_id && $topic === 'abuse') {
            $thread_id = setting($pdo, "tg_topic_log", '');
        }
        if (!$thread_id && ($topic === 'threads' || $topic === 'threads_promo')) {
            $thread_id = setting($pdo, "tg_topic_permintaan", '');
        }
        if ($thread_id) {
            $post['message_thread_id'] = $thread_id;
        }
    }
    
    if (!empty($inline_keyboard)) {
        $post['reply_markup'] = json_encode(['inline_keyboard' => $inline_keyboard]);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $res = curl_exec($ch);
    curl_close($ch);
    
    if ($res) {
        $data = json_decode($res, true);
        if (isset($data['ok']) && $data['ok'] && isset($data['result']['message_id'])) {
            return (int)$data['result']['message_id'];
        }
    }
    return null;
}

/** Send photo to Telegram Admin Group/Channel */
function send_telegram_photo(PDO $pdo, string $photo_path, string $caption = '', array $inline_keyboard = [], ?string $topic = null): ?int {
    $token = setting($pdo, 'tg_bot_token', '');
    $chat_id = setting($pdo, 'tg_chat_id', '');
    if (!$token || !$chat_id) return null;
    
    $url = "https://api.telegram.org/bot{$token}/sendPhoto";
    $post = [
        'chat_id' => $chat_id,
        'photo' => new CURLFile($photo_path),
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];
    
    if ($topic) {
        $thread_id = setting($pdo, "tg_topic_{$topic}", '');
        if (!$thread_id && ($topic === 'threads' || $topic === 'threads_promo')) {
            $thread_id = setting($pdo, "tg_topic_permintaan", '');
        }
        if ($thread_id) {
            $post['message_thread_id'] = $thread_id;
        }
    }
    
    if (!empty($inline_keyboard)) {
        $post['reply_markup'] = json_encode(['inline_keyboard' => $inline_keyboard]);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $res = curl_exec($ch);
    curl_close($ch);
    
    if ($res) {
        $data = json_decode($res, true);
        if (isset($data['ok']) && $data['ok'] && isset($data['result']['message_id'])) {
            return (int)$data['result']['message_id'];
        }
    }
    return null;
}

/** Edit message in Telegram Admin Group/Channel */
function edit_telegram_notif(PDO $pdo, int $message_id, string $message, array $inline_keyboard = []): void {
    $token = setting($pdo, 'tg_bot_token', '');
    $chat_id = setting($pdo, 'tg_chat_id', '');
    if (!$token || !$chat_id || !$message_id) return;
    
    $url = "https://api.telegram.org/bot{$token}/editMessageText";
    $post = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    if (!empty($inline_keyboard)) {
        $post['reply_markup'] = json_encode(['inline_keyboard' => $inline_keyboard]);
    } else {
        $post['reply_markup'] = json_encode(['inline_keyboard' => []]);
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_exec($ch);
    curl_close($ch);
}

/** Track a page view (fire-and-forget, safe to fail) */
function track_pageview(PDO $pdo, string $path): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        $ip = trim($ip);
        
        $ref = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);
        $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
        // Skip bots
        if (preg_match('/bot|crawl|spider|slurp|baidu|bing|google/i', $ua)) return;
        
        $userId = get_auth_cookie();
        $pdo->prepare("INSERT INTO page_views (path,user_id,ip_hash,referrer,user_agent) VALUES (?,?,?,?,?)")
            ->execute([$path, $userId, $ip, $ref, $ua]);
    } catch (\Throwable) {
        // Silently fail — never break user experience for analytics
    }
}

// ============================================================
// PROMOTOR CLICK TRACKING & DAILY TARGET SYNC
// ============================================================

// Detect referral code in URL and track promotor clicks
if (!empty($_GET['ref'])) {
    try {
        $ref_code = strtoupper(trim($_GET['ref']));
        $stmt = $pdo->prepare("SELECT id, is_promotor FROM users WHERE referral_code = ? LIMIT 1");
        $stmt->execute([$ref_code]);
        $promotor = $stmt->fetch();
        if ($promotor && (int)$promotor['is_promotor'] === 1) {
            // Log click if not logged in the last 1 hour for this IP to prevent spamming
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
            } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            }
            $ip = trim($ip);

            $chk = $pdo->prepare("SELECT 1 FROM referral_clicks WHERE promotor_id = ? AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $chk->execute([$promotor['id'], $ip]);
            if (!$chk->fetchColumn()) {
                $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);
                // Skip bots
                if (!preg_match('/bot|crawl|spider|slurp|baidu|bing|google/i', $ua)) {
                    $pdo->prepare("INSERT INTO referral_clicks (promotor_id, ip_address, user_agent) VALUES (?, ?, ?)")
                        ->execute([$promotor['id'], $ip, $ua]);
                }
            }
            // Set cookie so the register page automatically picks it up
            setcookie('ref_code', $ref_code, time() + 86400 * 30, '/');
        }
    } catch (\Throwable $e) {
        // Silently fail to never break user loading experience
    }
}

/**
 * Dynamically computes and updates the snapshot for a promotor's daily performance.
 */
function sync_promotor_daily_targets(PDO $pdo, int|string $promotor_id, ?string $date = null): void {
    $promotor_id = (int)$promotor_id;
    if (!$date) $date = date('Y-m-d');
    
    try {
        // Get promotor info
        $p_stmt = $pdo->prepare("SELECT referral_code, promotor_target_deposits, promotor_target_regs, promotor_salary_rate FROM users WHERE id=? AND is_promotor=1");
        $p_stmt->execute([$promotor_id]);
        $p = $p_stmt->fetch();
        if (!$p) return;
        
        // Calculate actual deposits today from referred users (COUNT and SUM)
        $dep_stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(d.amount), 0) as total_amt, COUNT(d.id) as total_cnt
             FROM deposits d 
             JOIN users u ON u.id = d.user_id 
             WHERE u.referred_by = ? AND d.status = 'confirmed' AND DATE(d.confirmed_at) = ?"
        );
        $dep_stmt->execute([$p['referral_code'], $date]);
        $dep_data = $dep_stmt->fetch();
        $actual_deposits_amt = (float)$dep_data['total_amt'];
        $actual_deposits_cnt = (int)$dep_data['total_cnt'];
        
        // Calculate actual registrations today
        $reg_stmt = $pdo->prepare(
            "SELECT COUNT(*) 
             FROM users 
             WHERE referred_by = ? AND DATE(created_at) = ?"
        );
        $reg_stmt->execute([$p['referral_code'], $date]);
        $actual_regs = (int)$reg_stmt->fetchColumn();
        
        // Flat Rate & Scheme Calculation
        $bonus_reg  = (float)setting($pdo, 'promotor_per_member_bonus', '0');
        $scheme = setting($pdo, 'promotor_deposit_scheme', 'flat');
        $bonus_depo_flat = (float)setting($pdo, 'promotor_per_deposit_bonus', '0');
        $bonus_depo_pct = (float)setting($pdo, 'promotor_deposit_percent', '0');
        
        $earned_from_regs = $actual_regs * $bonus_reg;
        $earned_from_deposits = 0;
        
        if ($scheme === 'flat') {
            $earned_from_deposits = $actual_deposits_cnt * $bonus_depo_flat;
        } elseif ($scheme === 'percent') {
            $earned_from_deposits = $actual_deposits_amt * ($bonus_depo_pct / 100);
        } elseif ($scheme === 'hybrid') {
            $earned_from_deposits = ($actual_deposits_cnt * $bonus_depo_flat) + ($actual_deposits_amt * ($bonus_depo_pct / 100));
        }
        
        $earned_amount = $earned_from_regs + $earned_from_deposits;
        
        $percentage = 100.0;
        
        // We repurpose fields to store flat rate info:
        // actual_deposits = the sum nominal of deposits
        // target_deposits = the count of deposits (so we can display it later)
        // target_regs = the total earned from regs (so we can display it)
        // percentage = 100
        // salary_rate = earned_amount
        
        // Upsert into promotor_daily_targets
        $check = $pdo->prepare("SELECT id, is_paid FROM promotor_daily_targets WHERE user_id=? AND date=?");
        $check->execute([$promotor_id, $date]);
        $exist = $check->fetch();
        
        if ($exist) {
            if ((int)$exist['is_paid'] === 0) {
                $pdo->prepare(
                    "UPDATE promotor_daily_targets 
                     SET actual_deposits=?, actual_regs=?, percentage=?, target_deposits=?, target_regs=?, salary_rate=?
                     WHERE id=?"
                )->execute([$actual_deposits_amt, $actual_regs, $percentage, $actual_deposits_cnt, ($actual_regs * $bonus_reg), $earned_amount, $exist['id']]);
            } else {
                $pdo->prepare(
                    "UPDATE promotor_daily_targets 
                     SET actual_deposits=?, actual_regs=?, percentage=? 
                     WHERE id=?"
                )->execute([$actual_deposits_amt, $actual_regs, $percentage, $exist['id']]);
            }
        } else {
            $pdo->prepare(
                "INSERT INTO promotor_daily_targets (user_id, date, target_deposits, actual_deposits, target_regs, actual_regs, percentage, salary_rate, is_paid) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
            )->execute([$promotor_id, $date, $actual_deposits_cnt, $actual_deposits_amt, ($actual_regs * $bonus_reg), $actual_regs, $percentage, $earned_amount]);
        }
    } catch (\Throwable $th) {
        // Silently fail to not block requests
    }
}

require_once __DIR__ . '/depo_canceller.php';
require_once __DIR__ . '/withdraw_canceller.php';
