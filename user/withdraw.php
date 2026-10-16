<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$wd_lock_notice = setting($pdo, 'wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');
$wd_lock_start  = setting($pdo, 'wd_lock_start', '');
$wd_lock_end    = setting($pdo, 'wd_lock_end', '');

// Fetch membership min/max WD — only if membership is still active (not expired)
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT id, name, min_wd, max_wd, wd_hold, allow_edit_bank, is_wd_disabled FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT id, name, min_wd, max_wd, wd_hold, allow_edit_bank, is_wd_disabled FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$min_withdraw  = $user_mem ? (float)$user_mem['min_wd'] : 0;
$max_withdraw  = $user_mem ? (float)$user_mem['max_wd'] : 0;
$max_available = min((float)$user['balance_wd'], $max_withdraw > 0 ? $max_withdraw : (float)$user['balance_wd']);

$predefined_amounts = [10000,  50000, 100000, 150000, 250000, 500000, 1000000, 2500000, 5000000];

if ($min_withdraw > 0 && !in_array((int)$min_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$min_withdraw;
}
if ($max_withdraw > 0 && !in_array((int)$max_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$max_withdraw;
}

sort($predefined_amounts);

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

$wd_locked = is_wd_locked($pdo);
$wd_global_enabled = setting($pdo, 'wd_global_enabled', '1') === '1';

// Level block — only enforced if admin enables the toggle
$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$available_amounts = [];
foreach ($predefined_amounts as $amt) {
    if ($level_blocked) {
        // Jika terblokir karena butuh upgrade, pamerkan opsi 50rb s/d 500rb
        if ($amt >= 50000 && $amt <= 500000) {
            $available_amounts[] = $amt;
        }
    } else {
        if ($amt >= $min_withdraw && ($max_withdraw == 0 || $amt <= $max_withdraw)) {
            $available_amounts[] = $amt;
        }
    }
}

$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

// Cek apakah ada WD pending
$pending_wd = $pdo->prepare("SELECT id FROM withdrawals WHERE user_id=? AND status IN ('pending', 'hold') LIMIT 1");
$pending_wd->execute([$user['id']]);
$has_pending_wd = (bool)$pending_wd->fetchColumn();

$is_free_level = !$membership_active;
$free_wd_limit_reached = false;
$free_wrong_bank = false;
$free_age_blocked = false;

if ($is_free_level) {
    $wd_free_only_dana = setting($pdo, 'wd_free_only_dana', '1') === '1';
    $wd_free_limit_1x  = setting($pdo, 'wd_free_limit_1x', '1') === '1';
    $wd_free_require_1day = setting($pdo, 'wd_free_require_1day', '1') === '1';
    
    if ($wd_free_require_1day && strtotime($user['created_at']) > strtotime('-1 day')) {
        $free_age_blocked = true;
    }
    
    if ($wd_free_only_dana && $has_bank && strtolower(trim($user['bank_name'])) !== 'dana') {
        $free_wrong_bank = true;
    }
    
    if ($wd_free_limit_1x) {
        $wd_cnt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'");
        $wd_cnt->execute([$user['id']]);
        if ($wd_cnt->fetchColumn() >= 1) {
            $free_wd_limit_reached = true;
        }
    }
}

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk_wd = 'wd_form_token';
if (empty($_SESSION[$_ftk_wd])) $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
$_form_token_wd = $_SESSION[$_ftk_wd];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_ftk_wd = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk_wd] ?? '', $submitted_ftk_wd)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
    } elseif (!$user['can_withdraw']) {
        $flash = '❌ Akses withdraw kamu dibatasi nih. Hubungi admin yuk buat info lebih lanjut!'; $flashType = 'error';
    } elseif (!$wd_global_enabled) {
        $flash = '🔴 Fitur penarikan saat ini sedang dinonaktifkan secara global (Maintenance).'; $flashType = 'error';
    } elseif ($wd_locked) {
        $flash = '⏰ ' . $wd_lock_notice; $flashType = 'error';
    } elseif ($free_wd_limit_reached) {
        $flash = '❌ Level ' . $user_mem['name'] . ' maksimal WD 1 kali. Yuk upgrade level buat tarik dana sepuasnya!'; $flashType = 'error';
    } elseif ($free_wrong_bank) {
        $flash = '❌ Level ' . $user_mem['name'] . ' hanya bisa menarik ke e-wallet DANA. Silakan ganti rekening Anda!'; $flashType = 'error';
    } elseif ($has_pending_wd) {
        $flash = '⏳ Kamu masih punya request WD yang lagi diproses nih. Tunggu kelar dulu ya!'; $flashType = 'error';
    } elseif (!empty($user_mem['is_wd_disabled'])) {
        $flash = '🔴 Penarikan untuk level Anda saat ini sedang ditutup (Maintenance). Silakan upgrade level Anda!'; $flashType = 'error';
    } elseif ($level_blocked) {
        if ((float)$user['balance_wd'] < 50000) {
            $flash = 'Minimal withdraw Rp 50.000 ya.'; $flashType = 'error';
        } else {
            $flash = "Upgrade ke {$min_level_name} dulu yuk biar bisa tarik saldo!"; $flashType = 'error';
        }
    } elseif ($free_age_blocked) {
        $flash = 'Akun harus berumur min. 1 hari untuk WD (Level ' . $user_mem['name'] . ').'; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        
        $bank    = $has_bank ? $user['bank_name'] : trim($_POST['bank_name'] ?? '');
        $accnum  = $has_bank ? $user['account_number'] : trim($_POST['account_number'] ?? '');
        $accname = $has_bank ? $user['account_name'] : trim($_POST['account_name'] ?? '');

        if (!in_array((int)$amount, $available_amounts, true)) {
            $flash = 'Nominal penarikan gak valid nih. Harus pilih dari daftar ya!'; $flashType = 'error';
        } elseif ($is_free_level && setting($pdo, 'wd_free_only_dana', '1') === '1' && strtolower($bank) !== 'dana') {
            $flash = 'Level ' . $user_mem['name'] . ' hanya bisa menggunakan e-wallet DANA.'; $flashType = 'error';
        } elseif ($amount < $min_withdraw) {
            $flash = 'Minimal withdraw ' . format_rp($min_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($max_withdraw > 0 && $amount > $max_withdraw) {
            $flash = 'Maksimal withdraw ' . format_rp($max_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($amount > (float)$user['balance_wd']) {
            $flash = 'Saldo penarikan kamu gak cukup nih.'; $flashType = 'error';
        } elseif (!$bank || !$accnum || !$accname) {
            $flash = 'Lengkapi dulu data rekeningmu ya.'; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            if (!$has_bank) {
                $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")->execute([$bank, $accnum, $accname, $user['id']]);
                $has_bank = true;
                $user['bank_name'] = $bank;
                $user['account_number'] = $accnum;
                $user['account_name'] = $accname;
            }
            $is_auto_hold = (isset($user_mem['wd_hold']) && $user_mem['wd_hold'] == 1);
            $wd_status = $is_auto_hold ? 'hold' : 'pending';
            $admin_note = null;
            
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name,status,admin_note) VALUES (?,?,?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname, $wd_status, $admin_note]);
            $wd_id = $pdo->lastInsertId();
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            
            $levelInfo = $user_mem ? ($user_mem['name'] ?? 'Free') : 'Free';
            $wdHoldNote = $is_auto_hold ? ' ⏳ (Auto Hold Scheduled)' : '';
            $msg = "<b>💸 WITHDRAW BARU</b>\n👤 User: {$user['username']}\n🏅 Level: {$levelInfo}{$wdHoldNote}\n💰 Amount: " . format_rp((float)$amount) . "\n🏦 Bank: {$bank} - {$accnum}\n👨‍💼 a/n: {$accname}\n📋 Status: " . ucfirst($wd_status);
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'wd_approve_'.$wd_id], ['text'=>'❌ Reject', 'callback_data'=>'wd_reject_'.$wd_id]],
                [['text'=>'⏸ Hold (Selesai non-refund)', 'callback_data'=>'wd_hold_'.$wd_id]],
                [['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_wd_'.$wd_id]]
            ];
            tg_store_msg_id($pdo, 'withdrawals', (int)$wd_id, send_telegram_notif($pdo, $msg, $kb, 'wd'));
            
            // Regenerate token
            $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
            $flash = '✅ Request withdraw berhasil dikirim! Proses 1-10 menit ya.';
            $flashType = 'success';
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        if ($flashType === 'error' || $flash === '') {
            echo json_encode(['error' => $flash ?: 'Terjadi kesalahan.']);
        } else {
            echo json_encode(['ok' => true, 'message' => $flash]);
        }
        exit;
    }
}
end_wd:

$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

$stmtPendingBank = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPendingBank->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPendingBank->fetchColumn();

$wd_estimation = '';
if (!$wd_global_enabled) {
    $wd_estimation = "🔴 Penarikan saat ini <strong>DINONAKTIFKAN</strong> (Maintenance).";
} elseif ($wd_lock_start && $wd_lock_end) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $wd_lock_start);
    $e_ts = strtotime(date('Y-m-d ') . $wd_lock_end);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "Penarikan saat ini <strong>DITUTUP</strong>. Akan dibuka dalam <strong>{$h} jam {$m} menit</strong>.";
    } else {
        $wd_estimation = "✅ Penarikan Buka (Tutup Jam " . date('H:i', strtotime($wd_lock_start)) . ")";
    }
}

$pageTitle  = 'Withdraw  ';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';

// Label nominal ringkas: 50rb, 1jt, 2,5jt
$wd_short = static function (float $v): string {
    if ($v >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 1, ',', ''), '0'), ',') . 'jt';
    return number_format($v / 1000, 0, ',', '.') . 'rb';
};

// Status tombol utama: [teks, aktif?]
$wd_btn = ['Tarik Sekarang', true];
if ($free_wd_limit_reached)                         $wd_btn = ['Batas WD tercapai', false];
elseif ($free_wrong_bank)                           $wd_btn = ['Hanya DANA untuk ' . $user_mem['name'], false];
elseif ($wd_locked)                                 $wd_btn = ['Penarikan sedang ditutup', false];
elseif ($level_blocked)                             $wd_btn = [(float)$user['balance_wd'] < 50000 ? 'Minimal tarik Rp 50.000' : 'Butuh upgrade level', false];
elseif ($free_age_blocked)                          $wd_btn = ['Akun minimal berumur 1 hari', false];
elseif (!$user['can_withdraw'])                     $wd_btn = ['Akses penarikan dibatasi', false];
elseif ($has_pending_bank)                          $wd_btn = ['Rekening sedang diverifikasi', false];
elseif ($has_pending_wd)                            $wd_btn = ['Ada penarikan diproses', false];
elseif ((float)$user['balance_wd'] < $min_withdraw) $wd_btn = ['Saldo belum cukup', false];

// Satu notifikasi teratas (urutan sama seperti sebelumnya)
$wd_note = null;
if ($wd_estimation && $wd_locked) {
    $wd_note = ['err', 'ph-lock-key', strip_tags($wd_estimation)];
} elseif ($has_pending_wd) {
    $wd_note = ['warn', 'ph-hourglass-medium', 'Penarikanmu sedang diproses kasir.'];
} elseif (!empty($wds) && strtolower($wds[0]['status']) === 'rejected' && strtotime($wds[0]['created_at']) > strtotime('-2 days')) {
    $wd_note = ['err', 'ph-warning-circle', 'Penarikan terakhir ditolak, saldo dikembalikan. Alasan: ' . ($wds[0]['admin_note'] ?: 'Rekening tidak valid')];
} elseif ($flash) {
    $wd_note = [$flashType === 'error' ? 'err' : 'ok', $flashType === 'error' ? 'ph-x-circle' : 'ph-check-circle', $flash];
}

$wd_status_map = [
    'pending'   => ['Diproses', 'warn'],
    'approved'  => ['Sukses', 'ok'],
    'confirmed' => ['Sukses', 'ok'],
    'rejected'  => ['Ditolak', 'err'],
    'hold'      => ['Ditahan', 'mute'],
    'refunded'  => ['Kembali', 'info'],
];
$user_wl = $has_bank ? ($channel_logos[strtolower($user['bank_name'] ?? '')] ?? null) : null;

// Status antrian WD untuk level user ini saja (Lancar / Ramai / Withdraw Overload)
require_once dirname(__DIR__) . '/lib/wd_levels.php';
$wd_lv = $user_mem ? wd_level_status($pdo, (int)$user_mem['id']) : null;
?>

<style>
/* ══ WITHDRAW — compact minimal · living coral reef clay ══ */
.wx { width: 100%; max-width: 440px; margin: 0 auto; padding: 6px 12px 28px; display: flex; flex-direction: column; gap: 9px; }

.wx-top { display: flex; align-items: center; gap: 8px; }
.wx-back { width: 34px; height: 34px; border-radius: 11px; border: 1.5px solid #fff; background: #fff; display: inline-flex; align-items: center; justify-content: center; color: var(--reef-ink); font-size: 16px; text-decoration: none; box-shadow: 0 3px 8px rgba(30, 27, 46, .06); flex-shrink: 0; }
.wx-back:active { transform: scale(.92); }
.wx-top h1 { flex: 1; margin: 0; font-size: 15px; font-weight: 900; color: var(--reef-ink); letter-spacing: -.2px; }
.wx-chip { display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 900; color: #065f46; background: #d1fae5; border: 1.5px solid #fff; }

.wx-note { display: flex; align-items: flex-start; gap: 7px; padding: 8px 11px; border-radius: 13px; font-size: 11px; font-weight: 800; line-height: 1.4; border: 1.5px solid #fff; }
.wx-note i { font-size: 15px; flex-shrink: 0; margin-top: 0; }
.wx-note.err  { background: #fff1f2; color: #9f1239; }
.wx-note.warn { background: #fffbeb; color: #92400e; }
.wx-note.ok   { background: #ecfdf5; color: #065f46; }

/* Status antrian WD level user (Lancar / Ramai / Overload) */
.wx-lvl { display: flex; gap: 10px; padding: 11px 12px; border-radius: 16px; border: 2px solid #fff; box-shadow: 0 3px 10px rgba(30, 27, 46, .06); }
.wx-lvl.s-normal   { background: linear-gradient(135deg, #ecfdf5, #ffffff); --lc: #059669; --lb: #d1fae5; }
.wx-lvl.s-busy     { background: linear-gradient(135deg, #fffbeb, #ffffff); --lc: #d97706; --lb: #fef3c7; }
.wx-lvl.s-overload { background: linear-gradient(135deg, #fff1f2, #ffffff); --lc: #e11d48; --lb: #ffe4e6; }
.wx-lvl-ic { width: 36px; height: 36px; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; background: var(--lc); border: 2px solid #fff; box-shadow: 0 2px 6px rgba(30, 27, 46, .12); }
.wx-lvl.s-overload .wx-lvl-ic { animation: wxLvlPulse 1.6s ease-in-out infinite; }
@keyframes wxLvlPulse { 50% { box-shadow: 0 0 0 5px rgba(225, 29, 72, .18); } }
.wx-lvl-tx { flex: 1; min-width: 0; }
.wx-lvl-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.wx-lvl-top b { font-size: 13px; font-weight: 900; color: var(--lc); }
.wx-lvl-tag { font-size: 9.5px; font-weight: 900; color: var(--lc); background: var(--lb); padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.wx-lvl p { margin: 3px 0 5px; font-size: 11px; font-weight: 700; color: var(--reef-ink); line-height: 1.4; }
.wx-lvl-meta { display: flex; flex-wrap: wrap; gap: 4px 10px; font-size: 10px; font-weight: 800; color: var(--reef-muted); }
.wx-lvl-meta span { display: inline-flex; align-items: center; gap: 3px; }
.wx-lvl-bar { height: 5px; border-radius: 999px; background: var(--lb); margin-top: 7px; overflow: hidden; }
.wx-lvl-bar i { display: block; height: 100%; border-radius: 999px; background: var(--lc); }

/* Kartu saldo + rekening tujuan dalam satu blok */
.wx-card { border: 2px solid #fff; border-radius: 20px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }
.wx-bal { padding: 14px 15px 12px; background: linear-gradient(135deg, #ecfdf5 0%, #ffffff 70%); }
.wx-bal small { display: block; font-size: 10.5px; font-weight: 800; color: var(--reef-muted); }
.wx-bal b { display: block; font-size: 26px; font-weight: 900; color: #065f46; letter-spacing: -.6px; line-height: 1.15; margin: 1px 0 7px; }
.wx-bal b em { font-style: normal; font-size: 14px; color: #10b981; margin-right: 3px; }
.wx-lim { display: flex; gap: 5px; flex-wrap: wrap; }
.wx-lim span { padding: 3px 8px; border-radius: 999px; background: #fff; font-size: 9.5px; font-weight: 900; color: var(--reef-muted); box-shadow: 0 1px 3px rgba(30, 27, 46, .05); }

.wx-dest { display: flex; align-items: center; gap: 10px; padding: 10px 15px; border-top: 1.5px dashed #d1fae5; text-decoration: none; color: inherit; }
.wx-dest:active { background: #f0fdf4; }
.wx-dest-ic { width: 32px; height: 32px; border-radius: 10px; background: #f0fdf4; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #10b981; font-size: 16px; }
.wx-dest-ic img { width: 22px; height: 22px; object-fit: contain; }
.wx-dest-tx { flex: 1; min-width: 0; line-height: 1.25; }
.wx-dest-tx b { display: block; font-size: 12px; font-weight: 900; color: var(--reef-ink); }
.wx-dest-tx span { display: block; font-size: 10.5px; font-weight: 700; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wx-dest-go { font-size: 10.5px; font-weight: 900; color: var(--reef-coral-500); display: inline-flex; align-items: center; gap: 2px; }

.wx-bankform { padding: 10px 15px 12px; border-top: 1.5px dashed #fed7aa; display: grid; gap: 6px; }
.wx-bankform label { font-size: 10px; font-weight: 900; color: var(--reef-muted); margin-bottom: -3px; }
.wx-bankform input { width: 100%; box-sizing: border-box; padding: 8px 11px; border-radius: 11px; border: 1.5px solid #fed7aa; background: #fff; font: 700 12px/1.2 inherit; font-family: inherit; color: var(--reef-ink); outline: none; }
.wx-bankform input:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, .15); }
.wx-bankform input[readonly] { background: #f8fafc; }

/* Pilihan nominal */
.wx-lbl { font-size: 11px; font-weight: 900; color: var(--reef-ink); padding: 0 2px; margin-bottom: -3px; }
.wx-amts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.wx-amt { padding: 10px 0; border-radius: 12px; border: 1.5px solid #fff; background: #fff; font: 900 13px/1 inherit; font-family: inherit; color: var(--reef-ink); cursor: pointer; box-shadow: 0 2px 6px rgba(30, 27, 46, .05); transition: transform .12s, background .12s; }
.wx-amt:active { transform: scale(.94); }
.wx-amt.active { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 4px 10px rgba(16, 185, 129, .32); }
.wx-amt:disabled { opacity: .45; cursor: not-allowed; }
.wx-empty { grid-column: 1 / -1; padding: 10px; border-radius: 12px; background: #fffbeb; color: #b45309; font-size: 11px; font-weight: 800; text-align: center; }

.wx-go { width: 100%; padding: 12px; border-radius: 15px; border: 2px solid #fff; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font: 900 13.5px/1 inherit; font-family: inherit; display: flex; align-items: center; justify-content: center; gap: 7px; cursor: pointer; box-shadow: 0 4px 12px rgba(16, 185, 129, .32), 0 3px 0 #047857; }
.wx-go:active { transform: translateY(3px); box-shadow: 0 1px 4px rgba(16, 185, 129, .3); }
.wx-go:disabled { background: #e2e8f0; color: #94a3b8; box-shadow: none; transform: none; cursor: not-allowed; }
.wx-up { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px; border-radius: 14px; border: 2px solid #fff; background: linear-gradient(135deg, #fef08a, #f59e0b); color: #78350f; font-size: 12px; font-weight: 900; text-decoration: none; box-shadow: 0 2px 0 #b45309; }

/* Riwayat ringkas */
.wx-hh { display: flex; justify-content: space-between; align-items: center; padding: 4px 2px 0; }
.wx-hh b { font-size: 11px; font-weight: 900; color: var(--reef-ink); }
.wx-hh a { font-size: 10.5px; font-weight: 900; color: var(--reef-coral-500); text-decoration: none; }
.wx-hist { border: 2px solid #fff; border-radius: 16px; background: #fff; box-shadow: 0 2px 8px rgba(30, 27, 46, .05); overflow: hidden; }
.wx-row { padding: 8px 12px; display: grid; grid-template-columns: 8px 1fr auto; column-gap: 9px; align-items: center; }
.wx-row + .wx-row { border-top: 1px solid #f1f5f9; }
.wx-dot { width: 8px; height: 8px; border-radius: 50%; }
.wx-row b { font-size: 12.5px; font-weight: 900; color: var(--reef-ink); }
.wx-row small { display: block; font-size: 9.5px; font-weight: 700; color: var(--reef-muted); }
.wx-st { font-size: 9.5px; font-weight: 900; padding: 3px 8px; border-radius: 999px; }
.wx-row p { grid-column: 2 / -1; margin: 4px 0 0; font-size: 10px; font-weight: 700; color: var(--reef-muted); line-height: 1.35; }
.t-ok   { --c: #10b981; } .t-warn { --c: #f59e0b; } .t-err { --c: #f43f5e; } .t-mute { --c: #94a3b8; } .t-info { --c: #0ea5e9; }
.wx-row .wx-dot { background: var(--c); }
.wx-row .wx-st { color: var(--c); background: color-mix(in srgb, var(--c) 13%, #fff); }
.wx-row.t-err p { color: #be123c; }

/* Konfirmasi (bottom sheet) */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, .5); backdrop-filter: blur(3px); align-items: flex-end; justify-content: center; }
.cg-modal-box { width: 100%; max-width: 440px; background: var(--reef-card); border-radius: 22px 22px 0 0; border: 2px solid #fff; border-bottom: 0; padding: 8px 16px calc(16px + env(safe-area-inset-bottom)); animation: wxUp .22s cubic-bezier(.2, .9, .3, 1.1); }
@keyframes wxUp { from { transform: translateY(100%); } to { transform: none; } }
.cg-grab { width: 38px; height: 4px; border-radius: 4px; background: #cbd5e1; margin: 0 auto 12px; }
.cg-sum { text-align: center; }
.cg-sum small { font-size: 10.5px; font-weight: 800; color: var(--reef-muted); }
.cg-sum b { display: block; font-size: 26px; font-weight: 900; color: #065f46; letter-spacing: -.6px; margin: 1px 0 10px; }
.cg-to { display: flex; justify-content: space-between; gap: 8px; padding: 9px 12px; border-radius: 12px; background: #fff; font-size: 11px; font-weight: 800; color: var(--reef-muted); }
.cg-to span:last-child { color: var(--reef-ink); text-align: right; }
.cg-warn { margin-top: 7px; padding: 7px 10px; border-radius: 11px; background: #fff1f2; color: #9f1239; font-size: 10.5px; font-weight: 800; text-align: center; }
.cg-modal-actions { display: flex; gap: 8px; margin-top: 12px; }
.cg-btn-cancel, .cg-btn-confirm { padding: 11px; border-radius: 13px; border: 1.5px solid #fff; font: 900 12.5px/1 inherit; font-family: inherit; cursor: pointer; }
.cg-btn-cancel { flex: 1; background: #fff; color: var(--reef-muted); }
.cg-btn-confirm { flex: 1.6; background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 3px 10px rgba(16, 185, 129, .3); }
</style>

<div class="wx">

  <div class="wx-top">
    <a href="/home" class="wx-back" title="Kembali"><i class="ph-bold ph-caret-left"></i></a>
    <h1>Tarik Saldo</h1>
    <span class="wx-chip"><i class="ph-fill ph-lightning"></i>1–10 menit</span>
  </div>

  <?php if ($wd_note): ?>
    <div class="wx-note <?= $wd_note[0] ?>"><i class="ph-fill <?= $wd_note[1] ?>"></i><span><?= htmlspecialchars($wd_note[2]) ?></span></div>
  <?php endif; ?>

  <?php if ($wd_lv && $wd_lv['show']): ?>
    <div class="wx-lvl s-<?= $wd_lv['state'] ?>">
      <div class="wx-lvl-ic"><i class="ph-fill <?= $wd_lv['icon'] ?>"></i></div>
      <div class="wx-lvl-tx">
        <div class="wx-lvl-top">
          <b><?= htmlspecialchars($wd_lv['label']) ?></b>
          <span class="wx-lvl-tag"><?= htmlspecialchars($user_mem['name']) ?></span>
        </div>
        <p><?= htmlspecialchars($wd_lv['message']) ?></p>
        <div class="wx-lvl-meta">
          <span><i class="ph-bold ph-clock"></i> Estimasi <?= htmlspecialchars($wd_lv['eta']) ?></span>
          <?php if ($wd_lv['queue'] > 0): ?><span><i class="ph-bold ph-users-three"></i> <?= (int)$wd_lv['queue'] ?> antrian di level kamu</span><?php endif; ?>
        </div>
        <?php if ($wd_lv['state'] !== 'normal'): ?>
          <div class="wx-lvl-bar"><i style="width:<?= $wd_lv['state'] === 'overload' ? 100 : 62 ?>%"></i></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="POST" id="wd-form" style="display:flex;flex-direction:column;gap:9px;">
    <?= csrf_field() ?>
    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
    <input type="hidden" name="amount" id="selected-amount" value="" required>

    <div class="wx-card">
      <div class="wx-bal">
        <small>Saldo siap tarik</small>
        <b><em>Rp</em><?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></b>
        <div class="wx-lim">
          <span>Min <?= format_rp($min_withdraw) ?></span>
          <?php if ($max_withdraw > 0): ?><span>Maks <?= format_rp($max_withdraw) ?></span><?php endif; ?>
          <?php if ($user_mem): ?><span><?= htmlspecialchars($user_mem['name']) ?></span><?php endif; ?>
        </div>
      </div>

      <?php if ($has_bank): ?>
        <a href="/edit-rekening" class="wx-dest" title="Ganti rekening">
          <span class="wx-dest-ic">
            <?php if ($user_wl): ?><img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars($user['bank_name']) ?>"><?php else: ?><i class="ph-fill ph-bank"></i><?php endif; ?>
          </span>
          <span class="wx-dest-tx">
            <b><?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></b>
            <span><?= htmlspecialchars($user['account_name'] ?? '') ?></span>
          </span>
          <span class="wx-dest-go">Ubah<i class="ph-bold ph-caret-right"></i></span>
        </a>
      <?php else: ?>
        <div class="wx-bankform">
          <label>Bank / E-Wallet</label>
          <input type="text" name="bank_name" value="<?= $is_free_level ? 'DANA' : '' ?>" <?= $is_free_level ? 'readonly' : 'placeholder="BCA, DANA, OVO, BRI..." required' ?>>
          <label>No. Rekening / HP</label>
          <input type="text" name="account_number" placeholder="081234567890" inputmode="numeric" required>
          <label>Nama Pemilik</label>
          <input type="text" name="account_name" placeholder="Sesuai rekening" required>
        </div>
      <?php endif; ?>
    </div>

    <div class="wx-lbl">Pilih nominal</div>
    <div class="wx-amts">
      <?php foreach ($available_amounts as $amt): ?>
        <button type="button" class="wd-amt-btn wx-amt" data-value="<?= $amt ?>" title="<?= format_rp($amt) ?>"
          onclick="selectWdAmount(this, <?= $amt ?>)" <?= !$level_blocked && $amt > (float)$user['balance_wd'] ? 'disabled' : '' ?>><?= $wd_short((float)$amt) ?></button>
      <?php endforeach; ?>
      <?php if (!$available_amounts): ?><div class="wx-empty">Belum ada nominal yang tersedia.</div><?php endif; ?>
    </div>

    <?php if ($wd_btn[1]): ?>
      <button type="submit" id="wd-submit-btn" class="wx-go"><i class="ph-fill ph-paper-plane-tilt"></i><span>Tarik Sekarang</span></button>
    <?php else: ?>
      <button type="button" class="wx-go" disabled><?= htmlspecialchars($wd_btn[0]) ?></button>
      <?php if ($free_wd_limit_reached || ($wd_btn[0] === 'Butuh upgrade level')): ?>
        <a href="/upgrade" class="wx-up"><i class="ph-fill ph-crown"></i>Upgrade untuk buka penarikan</a>
      <?php endif; ?>
    <?php endif; ?>
  </form>

  <?php if (!empty($wds)): ?>
    <div class="wx-hh"><b>Riwayat</b><a href="/history?tab=withdraw">Semua →</a></div>
    <div class="wx-hist">
      <?php foreach ($wds as $w):
        $st = strtolower((string)$w['status']);
        [$stLabel, $tone] = $wd_status_map[$st] ?? [ucfirst($st), 'mute']; ?>
        <div class="wx-row t-<?= $tone ?>">
          <span class="wx-dot"></span>
          <div><b><?= format_rp((float)$w['amount']) ?></b><small><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M, H:i', strtotime($w['created_at'])) ?></small></div>
          <span class="wx-st"><?= $stLabel ?></span>
          <?php if (!empty($w['admin_note'])): ?><p><?= htmlspecialchars((string)$w['admin_note']) ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<div id="cg-modal" class="cg-modal" onclick="if(event.target===this)this.style.display='none'">
  <div class="cg-modal-box">
    <div class="cg-grab"></div>
    <div class="cg-sum"><small>Kamu akan menarik</small><b id="cg-modal-amt"></b></div>
    <?php if ($has_bank): ?>
      <div class="cg-to"><span>Ke</span><span><?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></span></div>
    <?php else: ?>
      <div class="cg-warn">Pastikan nomor & nama pemilik rekening sudah benar.</div>
    <?php endif; ?>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="confirmCGWd()">Ya, Tarik</button>
    </div>
  </div>
</div>

<script>
function selectWdAmount(btn, val) {
  document.querySelectorAll('.wd-amt-btn').forEach(el => el.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('selected-amount').value = val;
}

(function(){
  const form  = document.getElementById('wd-form');
  const btn   = document.getElementById('wd-submit-btn');
  const minWd = <?= (int)$min_withdraw ?>;
  const maxWd = <?= (float)$max_available ?>;
  const label = '<i class="ph-fill ph-paper-plane-tilt"></i><span>Tarik Sekarang</span>';

  if (!form) return;

  form.addEventListener('submit', function(e) {
    const amtInput = document.querySelector('[name=amount]');
    const amt = amtInput ? parseFloat(amtInput.value) : 0;

    if (!amt || isNaN(amt)) {
      e.preventDefault();
      if(typeof nToast !== 'undefined') nToast('Pilih nominal penarikan dulu ya!', 'warn');
      return;
    }
    if (amt < minWd) {
      e.preventDefault();
      if(typeof nToast !== 'undefined') nToast('Minimal penarikan Rp ' + minWd.toLocaleString('id-ID'), 'error');
      return;
    }
    if (amt > maxWd) {
      e.preventDefault();
      if(typeof nToast !== 'undefined') nToast('Maksimal penarikan Rp ' + maxWd.toLocaleString('id-ID'), 'error');
      return;
    }

    if (!form.dataset.confirmed) {
      e.preventDefault();
      document.getElementById('cg-modal-amt').innerText = 'Rp ' + amt.toLocaleString('id-ID');
      document.getElementById('cg-modal').style.display = 'flex';
    }
  });

  window.confirmCGWd = function() {
    document.getElementById('cg-modal').style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerText = 'Memproses...'; }

    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        if (typeof nToast !== 'undefined') nToast(res.error, 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = label; }
      } else {
        if (typeof nToast !== 'undefined') nToast(res.message, 'success');
        setTimeout(() => window.location.href = '/history?tab=withdraw', 1500);
      }
    })
    .catch(() => {
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus. Silakan coba lagi.', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
    });
  };
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
