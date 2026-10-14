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
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
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
            send_telegram_notif($pdo, $msg, $kb, 'wd');
            
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
?>

<style>
/* ══════════════════════════════════════════════
   WITHDRAW PAGE — LUXURIOUS AMBER HONEY THEME
   ══════════════════════════════════════════════ */
.wd-page-wrap {
  min-height: 100vh;
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 40px;
}

/* ── TOP HERO BANNER ── */
.wd-top-banner {
  background: linear-gradient(135deg, #78350f 0%, #92400e 30%, #b45309 65%, #d97706 100%);
  padding: 16px 14px 28px;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 20px rgba(120,53,15,0.28);
  position: relative;
  overflow: hidden;
}
.wd-top-banner::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 20px 20px;
  background-position: 0 0, 10px 10px;
  opacity: 0.18; pointer-events: none;
}
.wd-top-nav {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.wd-back-btn {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 12px;
  width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 18px; text-decoration: none;
  box-shadow: 0 3px 0 #78350f; transition: transform 0.1s;
}
.wd-back-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.wd-title-pill {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 14px;
  padding: 6px 14px; font-size: 13px; font-weight: 900; color: #78350f;
  box-shadow: 0 3px 0 #78350f; display: flex; align-items: center; gap: 6px;
}

/* Mascot Dialogue / Notification Box */
.wd-notice-box {
  display: flex; align-items: center; gap: 10px;
  background: rgba(255,255,255,0.96); border: 2.5px solid #78350f;
  border-radius: 16px; padding: 10px 12px; box-shadow: 0 3px 0 #78350f;
  position: relative; z-index: 2;
}
.wd-notice-avatar {
  width: 44px; height: 44px; flex-shrink: 0;
  animation: buzzyBob 3s ease-in-out infinite;
}
.wd-notice-avatar img { width: 100%; height: 100%; object-fit: contain; }
@keyframes buzzyBob {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-3px) rotate(3deg); }
}
.wd-notice-text {
  font-size: 11px; font-weight: 800; color: #78350f; line-height: 1.35;
}

/* ── BODY ── */
.wd-body {
  padding: 16px 14px 100px;
}

/* ── SALDO SIAP TARIK CARD ── */
.wd-balance-card {
  background: linear-gradient(135deg, #ffffff 0%, #fffbeb 40%, #fef3c7 100%);
  border: 3.5px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 6px 0 #78350f, 0 12px 24px rgba(120,53,15,0.15);
  padding: 16px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.wd-bal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.wd-bal-tag {
  display: inline-flex; align-items: center; gap: 4px;
  background: #fde68a; border: 1.5px solid #78350f;
  padding: 2px 8px; border-radius: 8px; font-size: 9.5px; font-weight: 900;
  color: #78350f; text-transform: uppercase;
}
.wd-bal-status {
  display: inline-flex; align-items: center; gap: 5px;
  background: #ecfdf5; border: 1.5px solid #10b981;
  padding: 2.5px 8px; border-radius: 10px; font-size: 9.5px; font-weight: 900; color: #059669;
}
.wd-bal-status .pulse-dot {
  width: 6px; height: 6px; background: #10b981; border-radius: 50%;
  animation: pulseGreen 1.5s infinite;
}
@keyframes pulseGreen {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129,0.7); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(16,185,129,0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}
.wd-bal-main {
  font-size: 28px; font-weight: 900; color: #78350f; line-height: 1.15;
  margin-bottom: 4px; font-family: 'Nunito', sans-serif;
  display: flex; align-items: baseline; gap: 4px;
}
.wd-bal-main .curr { font-size: 17px; color: #d97706; }
.wd-bal-sub {
  font-size: 10.5px; font-weight: 800; color: #92400e;
}

/* ── NOMINAL SELECTION SECTION ── */
.wd-section-hdr {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.wd-sh-title {
  font-size: 13.5px; font-weight: 900; color: #78350f;
  display: flex; align-items: center; gap: 6px;
}
.wd-sh-badge {
  background: #fef3c7; color: #b45309; font-size: 10px; font-weight: 900;
  padding: 3px 8px; border-radius: 8px; border: 1.5px solid #78350f;
}

/* 2-column tactile chips */
.wd-amt-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
  margin-bottom: 20px;
}
.wd-amt-btn {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 16px;
  padding: 14px 10px; text-align: center; font-size: 15px; font-weight: 900;
  color: #78350f; box-shadow: 0 4px 0 #78350f; cursor: pointer;
  transition: transform 0.1s, background 0.15s, color 0.15s;
  outline: none; font-family: 'Nunito', sans-serif;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.wd-amt-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f; }
.wd-amt-btn.active {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border-color: #064e3b; color: #ffffff; box-shadow: 0 4px 0 #064e3b;
  text-shadow: 0 1px 1px #064e3b;
}

/* ── REKENING TUJUAN CARD ── */
.wd-bank-target-card {
  background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
  border: 2.5px solid #78350f; border-radius: 18px; padding: 14px 16px;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 20px; box-shadow: 0 4px 0 #78350f; cursor: pointer;
  transition: transform 0.1s;
}
.wd-bank-target-card:active { transform: translateY(2px); box-shadow: 0 2px 0 #78350f; }
.wd-btc-left { display: flex; align-items: center; gap: 10px; }
.wd-btc-icon {
  width: 40px; height: 40px; border-radius: 12px; background: #fef3c7;
  border: 2px solid #78350f; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.wd-btc-icon img { width: 26px; height: 26px; object-fit: contain; }
.wd-btc-details { line-height: 1.25; }
.wd-btc-bank { font-size: 13px; font-weight: 900; color: #78350f; }
.wd-btc-acc { font-family: monospace; font-size: 12px; font-weight: 900; color: #b45309; }
.wd-btc-right {
  display: flex; align-items: center; gap: 4px; font-size: 11px;
  font-weight: 900; color: #d97706;
}

/* Bank Inputs if not linked */
.wd-input-grp { margin-bottom: 12px; }
.wd-input-grp label {
  display: block; font-size: 11.5px; font-weight: 900; color: #78350f; margin-bottom: 6px;
}
.wd-input-grp input {
  width: 100%; padding: 12px 14px; border-radius: 14px; border: 2.5px solid #78350f;
  background: #ffffff; font-weight: 800; font-size: 13px; color: #78350f;
  box-shadow: 0 3px 0 #78350f; outline: none; font-family: 'Nunito', sans-serif;
  box-sizing: border-box;
}

/* ── SUBMIT BUTTON ── */
.wd-submit-btn {
  width: 100%; padding: 15px; border-radius: 20px; font-size: 16px; font-weight: 900;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  border: 3px solid #064e3b; background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #fff; box-shadow: 0 5px 0 #064e3b; text-shadow: 0 1.5px 0 #064e3b;
  cursor: pointer; font-family: 'Nunito', sans-serif; transition: transform 0.1s;
}
.wd-submit-btn:active { transform: translateY(3px); box-shadow: 0 2px 0 #064e3b; }
.wd-submit-btn:disabled {
  background: #e2e8f0; border-color: #78350f; color: #64748b;
  box-shadow: 0 4px 0 #78350f; cursor: not-allowed; text-shadow: none;
}

/* Upgrade CTA Button */
.wd-upgrade-cta {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  margin-top: 10px; width: 100%; padding: 14px; border-radius: 20px;
  font-size: 14px; font-weight: 900; color: #78350f; text-decoration: none;
  background: linear-gradient(135deg, #fde047 0%, #f59e0b 100%);
  border: 3px solid #78350f; box-shadow: 0 5px 0 #78350f;
  box-sizing: border-box; transition: transform 0.1s;
}
.wd-upgrade-cta:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

/* ── CONFIRMATION MODAL ── */
.cg-modal {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65);
  z-index: 99999; align-items: center; justify-content: center; padding: 16px;
  backdrop-filter: blur(4px);
}
.cg-modal-box {
  background: #ffffff; width: 100%; max-width: 340px; border-radius: 24px;
  border: 3.5px solid #78350f; box-shadow: 0 8px 0 #78350f; overflow: hidden;
  animation: modalPop 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes modalPop { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.cg-modal-hdr {
  background: linear-gradient(135deg, #f59e0b, #d97706); padding: 14px;
  text-align: center; color: #ffffff; font-weight: 900; font-size: 15px;
  border-bottom: 2.5px solid #78350f; text-shadow: 0 1px 2px #78350f;
}
.cg-modal-bd {
  padding: 20px 16px; text-align: center; color: #78350f; font-weight: 800; font-size: 13px;
}
.cg-modal-actions {
  display: flex; gap: 10px; padding: 0 16px 20px;
}
.cg-btn-cancel {
  flex: 1; padding: 12px; background: #f1f5f9; border: 2.5px solid #78350f;
  border-radius: 14px; font-weight: 900; color: #64748b; font-size: 13px;
  box-shadow: 0 3px 0 #78350f; cursor: pointer;
}
.cg-btn-confirm {
  flex: 1.5; padding: 12px; background: linear-gradient(135deg, #10b981, #059669);
  border: 2.5px solid #064e3b; border-radius: 14px; font-weight: 900; color: #ffffff;
  box-shadow: 0 3px 0 #064e3b; font-size: 13px; cursor: pointer;
}

/* ── HISTORY ── */
.hist-wrap {
  margin-top: 24px; border-top: 2.5px dashed #fde68a; padding-top: 18px;
}
.hist-head {
  display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
}
.hist-head h3 {
  font-size: 13.5px; font-weight: 900; color: #78350f; margin: 0;
  display: flex; align-items: center; gap: 6px;
}
.hist-head a {
  font-size: 11px; font-weight: 900; color: #78350f; text-decoration: none;
  background: #fde68a; padding: 5px 12px; border-radius: 10px; border: 2px solid #78350f;
  box-shadow: 0 2px 0 #78350f;
}
.hist-list { display: flex; flex-direction: column; gap: 10px; }
.hist-card {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 16px;
  padding: 12px; box-shadow: 0 3px 0 #78350f; display: flex; flex-direction: column; gap: 8px;
}
.hist-card-main {
  display: flex; align-items: center; gap: 10px; width: 100%;
}
.hist-card-icon {
  width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0; border: 2px solid #78350f;
}
.hist-card-body { flex: 1; min-width: 0; }
.hist-card-amt { font-size: 14.5px; font-weight: 900; color: #78350f; line-height: 1.2; }
.hist-card-date { font-size: 10px; font-weight: 800; color: #92400e; margin-top: 2px; }
.hist-card-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.hist-badge {
  font-size: 9.5px; font-weight: 900; padding: 3px 8px; border-radius: 8px;
  text-transform: uppercase; border: 1.5px solid;
}
.wd-badge.pending   { background: #fef3c7; color: #78350f; border-color: #f59e0b; }
.wd-badge.approved  { background: #dcfce7; color: #14532d; border-color: #16a34a; }
.wd-badge.rejected  { background: #fee2e2; color: #7f1d1d; border-color: #dc2626; }
.wd-badge.hold      { background: #f1f5f9; color: #475569; border-color: #94a3b8; }
.wd-badge.refunded  { background: #e0f2fe; color: #0369a1; border-color: #38bdf8; }

.hist-card-reason {
  border-radius: 10px; padding: 8px 10px; font-size: 11px; font-weight: 700;
  display: flex; align-items: flex-start; gap: 8px; line-height: 1.4;
}
.hist-card-reason.rejected {
  background: #fff1f2; border: 1.5px dashed #f43f5e; color: #9f1239;
}
.hist-card-reason.hold {
  background: #f8fafc; border: 1.5px dashed #94a3b8; color: #334155;
}
.hist-card-reason.info {
  background: #f0fdf4; border: 1.5px dashed #86efac; color: #166534;
}
.hist-card-reason-lbl {
  font-size: 9.5px; font-weight: 900; text-transform: uppercase; display: block; margin-bottom: 2px;
}
</style>

<div class="wd-page-wrap">
  <!-- TOP HERO BANNER -->
  <div class="wd-top-banner">
    <div class="wd-top-nav">
      <a href="/home" class="wd-back-btn" title="Kembali ke Beranda">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="wd-title-pill">
        <i class="ph-fill ph-arrow-up-right" style="color:#059669;"></i>
        <span>Tarik Saldo Cuan</span>
      </div>
      <div style="width:38px;"></div> <!-- Spacer -->
    </div>

    <!-- Notification or Mascot dialogue -->
    <?php if ($wd_estimation && $wd_locked): ?>
      <div class="wd-notice-box" style="background:#fef2f2; border-color:#dc2626; box-shadow:0 3px 0 #991b1b;">
        <div style="font-size:20px;">🔒</div>
        <div class="wd-notice-text" style="color:#7f1d1d;"><?= strip_tags($wd_estimation) ?></div>
      </div>
    <?php elseif ($has_pending_wd): ?>
      <div class="wd-notice-box">
        <div style="font-size:20px;">🔔</div>
        <div class="wd-notice-text">Penarikan kamu sedang diproses tim verifikasi (estimasi 1-24 jam).</div>
      </div>
    <?php elseif (!empty($wds) && strtolower($wds[0]['status']) === 'rejected' && strtotime($wds[0]['created_at']) > strtotime('-2 days')): ?>
      <div class="wd-notice-box" style="background:#fff1f2; border-color:#e11d48; box-shadow:0 3px 0 #9f1239;">
        <div style="font-size:22px;color:#e11d48;display:flex;align-items:center;"><i class="ph-bold ph-warning-circle"></i></div>
        <div class="wd-notice-text" style="color:#9f1239;font-size:12px;line-height:1.4;">
          <strong>Penarikan Terakhir Ditolak</strong> (Saldo telah dikembalikan):<br>
          <span style="font-weight:700;color:#e11d48;">Alasan: <?= htmlspecialchars($wds[0]['admin_note'] ?: 'Data rekening belum sesuai') ?></span>
        </div>
      </div>
    <?php elseif ($flash): ?>
      <div class="wd-notice-box" style="<?= $flashType==='error' ? 'background:#fef2f2; border-color:#dc2626; box-shadow:0 3px 0 #991b1b;' : 'background:#ecfdf5; border-color:#16a34a; box-shadow:0 3px 0 #14532d;' ?>">
        <div style="font-size:20px;"><?= $flashType==='error' ? '❌' : '✨' ?></div>
        <div class="wd-notice-text" style="<?= $flashType==='error' ? 'color:#7f1d1d;' : 'color:#065f46;' ?>"><?= htmlspecialchars($flash) ?></div>
      </div>
    <?php else: ?>
      <div class="wd-notice-box">
        <div class="wd-notice-avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;border-radius:12px;color:#fff;font-size:20px;">
          <i class="ph-fill ph-wallet"></i>
        </div>
        <div class="wd-notice-text">
          Pilih nominal lalu cairkan saldo hasil nonton video &amp; aktivitas cuanmu ke rekening atau e-wallet!
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- BODY -->
  <div class="wd-body">
    <!-- SALDO SIAP TARIK (HERO CARD) -->
    <div class="wd-balance-card">
      <div class="wd-bal-header">
        <span class="wd-bal-tag"><i class="ph-fill ph-wallet" style="color:#d97706;"></i> Saldo Siap Tarik (WD)</span>
        <span class="wd-bal-status">
          <span class="pulse-dot"></span>
          <span>Siap Cair 24 Jam</span>
        </span>
      </div>
      <div class="wd-bal-main">
        <span class="curr">Rp</span>
        <span><?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></span>
      </div>
      <div class="wd-bal-sub">
        Minimal penarikan: <?= format_rp($min_withdraw) ?><?= $max_withdraw > 0 ? ' · Maksimal: ' . format_rp($max_withdraw) : '' ?>
      </div>
    </div>

    <!-- WITHDRAW FORM -->
    <form method="POST" id="wd-form">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
      <input type="hidden" name="amount" id="selected-amount" value="" required>

      <div class="wd-section-hdr">
        <div class="wd-sh-title">
          <i class="ph-fill ph-coins" style="color:#d97706;"></i>
          <span>Pilih Nominal Penarikan</span>
        </div>
        <div class="wd-sh-badge">Cair Cepat</div>
      </div>

      <!-- AMOUNT GRID -->
      <div class="wd-amt-grid">
        <?php 
        $rendered = 0;
        foreach ($available_amounts as $amt): 
        ?>
          <button type="button" class="wd-amt-btn" data-value="<?= $amt ?>" onclick="selectWdAmount(this, <?= $amt ?>)">
            <span><?= format_rp($amt) ?></span>
          </button>
        <?php $rendered++; endforeach; ?>

        <?php if ($rendered == 0): ?>
          <div style="grid-column: 1/-1; background:#fffbeb; border:2px solid #78350f; border-radius:16px; padding:16px; text-align:center; font-weight:800; color:#b45309; box-shadow:0 3px 0 #78350f;">
            Belum ada opsi penarikan yang tersedia untuk saat ini.
          </div>
        <?php endif; ?>
      </div>

      <!-- REKENING TUJUAN CARD -->
      <div class="wd-section-hdr">
        <div class="wd-sh-title">
          <i class="ph-fill ph-bank" style="color:#d97706;"></i>
          <span>Rekening / E-Wallet Tujuan</span>
        </div>
      </div>

      <?php if ($has_bank): ?>
        <div class="wd-bank-target-card" onclick="window.location.href='/edit-rekening'" title="Klik untuk mengganti rekening">
          <div class="wd-btc-left">
            <div class="wd-btc-icon">
              <?php 
              $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; 
              if ($user_wl): ?>
                <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars($user['bank_name']) ?>">
              <?php else: ?>
                <i class="ph-fill ph-bank" style="font-size:20px; color:#d97706;"></i>
              <?php endif; ?>
            </div>
            <div class="wd-btc-details">
              <div class="wd-btc-bank"><?= htmlspecialchars($user['bank_name']) ?></div>
              <div class="wd-btc-acc"><?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?> · <?= htmlspecialchars($user['account_name'] ?? '') ?></div>
            </div>
          </div>
          <div class="wd-btc-right">
            <span>Ubah</span>
            <i class="ph-bold ph-caret-right"></i>
          </div>
        </div>
      <?php else: ?>
        <div style="background:#ffffff; border:2.5px solid #78350f; border-radius:18px; padding:14px; margin-bottom:20px; box-shadow:0 4px 0 #78350f;">
          <div class="wd-input-grp">
            <label>Pilih Bank / E-Wallet</label>
            <input type="text" name="bank_name" value="<?= $is_free_level ? 'DANA' : '' ?>" <?= $is_free_level ? 'readonly' : 'placeholder="BCA, DANA, OVO, BRI..." required' ?>>
          </div>
          <div class="wd-input-grp">
            <label>Nomor Rekening / No HP</label>
            <input type="text" name="account_number" placeholder="Contoh: 081234567890" required>
          </div>
          <div class="wd-input-grp">
            <label>Nama Pemilik (Sesuai Buku Tabungan / Akun)</label>
            <input type="text" name="account_name" placeholder="Nama Lengkap Pemilik" required>
          </div>
        </div>
      <?php endif; ?>

      <!-- SUBMIT BUTTON -->
      <?php if ($free_wd_limit_reached): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Batas Limit Habis)</button>
      <?php elseif ($free_wrong_bank): ?>
        <button type="button" class="wd-submit-btn" disabled>Hanya DANA untuk Level <?= htmlspecialchars($user_mem['name']) ?></button>
      <?php elseif ($wd_locked): ?>
        <button type="button" class="wd-submit-btn" disabled>Penarikan Sedang Terkunci</button>
      <?php elseif ($level_blocked): ?>
        <?php if ((float)$user['balance_wd'] < 50000): ?>
          <button type="button" class="wd-submit-btn" disabled>Minimal Tarik Rp 50.000</button>
        <?php else: ?>
          <button type="button" class="wd-submit-btn" disabled>Butuh Upgrade Level</button>
          <a href="/upgrade" class="wd-upgrade-cta">
            <i class="ph-fill ph-crown"></i>
            <span>Upgrade Level Sekarang untuk Tarik Dana →</span>
          </a>
        <?php endif; ?>
      <?php elseif ($free_age_blocked): ?>
        <button type="button" class="wd-submit-btn" disabled>Akun Baru Min. 1 Hari</button>
      <?php elseif (!$user['can_withdraw']): ?>
        <button type="button" class="wd-submit-btn" disabled>Akses Penarikan Dibatasi</button>
      <?php elseif ($has_pending_bank): ?>
        <button type="button" class="wd-submit-btn" disabled>Sedang Verifikasi Rekening</button>
      <?php elseif ($has_pending_wd): ?>
        <button type="button" class="wd-submit-btn" disabled>Ada Penarikan Sedang Diproses</button>
      <?php elseif ((float)$user['balance_wd'] < $min_withdraw): ?>
        <button type="button" class="wd-submit-btn" disabled>Saldo Belum Mencukupi</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="wd-submit-btn">
          <i class="ph-fill ph-paper-plane-tilt" style="font-size:20px;"></i>
          <span>Tarik Saldo Sekarang</span>
        </button>
      <?php endif; ?>

    </form>

    <!-- ── RIWAYAT PENARIKAN TERAKHIR ── -->
    <?php if (!empty($wds)): ?>
    <div class="hist-wrap">
      <div class="hist-head">
        <h3><i class="ph-fill ph-clock-counter-clockwise" style="color:#d97706;"></i> Riwayat Penarikan Terakhir</h3>
        <a href="/history?tab=withdraw">Lihat Semua →</a>
      </div>
      <div class="hist-list">
        <?php foreach ($wds as $w): ?>
          <?php
          $st = strtolower($w['status']);
          $bclass = 'hold';
          $stLabel = ucfirst($st);
          $iconI = 'ph-arrow-u-up-left';
          $iconStyle = 'background:#f1f5f9;color:#475569;';

          if ($st === 'pending') { 
              $bclass = 'pending'; 
              $stLabel = 'Diproses'; 
              $iconI = 'ph-clock';
              $iconStyle = 'background:#fef3c7;color:#d97706;';
          } elseif ($st === 'approved' || $st === 'confirmed') { 
              $bclass = 'approved'; 
              $stLabel = 'Sukses'; 
              $iconI = 'ph-check';
              $iconStyle = 'background:#dcfce7;color:#15803d;';
          } elseif ($st === 'rejected') { 
              $bclass = 'rejected'; 
              $stLabel = 'Ditolak'; 
              $iconI = 'ph-x';
              $iconStyle = 'background:#fee2e2;color:#dc2626;';
          } elseif ($st === 'hold') {
              $bclass = 'hold';
              $stLabel = 'Ditahan';
              $iconI = 'ph-pause';
              $iconStyle = 'background:#f1f5f9;color:#64748b;';
          } elseif ($st === 'refunded') {
              $bclass = 'refunded';
              $stLabel = 'Dikembalikan';
              $iconI = 'ph-arrow-u-up-left';
              $iconStyle = 'background:#e0f2fe;color:#0284c7;';
          }
          ?>
          <div class="hist-card">
            <div class="hist-card-main">
              <div class="hist-card-icon" style="<?= $iconStyle ?>border-color:#78350f;">
                <i class="ph-bold <?= $iconI ?>"></i>
              </div>
              <div class="hist-card-body">
                <div class="hist-card-amt"><?= format_rp((float)$w['amount']) ?></div>
                <div class="hist-card-date"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M Y, H:i', strtotime($w['created_at'])) ?> WIB</div>
              </div>
              <div class="hist-card-right">
                <span class="hist-badge wd-badge <?= $bclass ?>"><?= $stLabel ?></span>
              </div>
            </div>

            <?php if (!empty($w['admin_note'])): ?>
              <div class="hist-card-reason <?= $st === 'rejected' ? 'rejected' : ($st === 'hold' ? 'hold' : 'info') ?>">
                <i class="ph-bold <?= $st === 'rejected' ? 'ph-warning-circle' : 'ph-info' ?>" style="font-size:15px;flex-shrink:0;margin-top:1px;"></i>
                <div style="flex:1;">
                  <span class="hist-card-reason-lbl">
                    <?= $st === 'rejected' ? 'Alasan Penolakan:' : ($st === 'hold' ? 'Catatan Hold:' : 'Catatan Admin:') ?>
                  </span>
                  <div><?= htmlspecialchars((string)$w['admin_note']) ?></div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<!-- CONFIRMATION MODAL -->
<div id="cg-modal" class="cg-modal">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">
      <i class="ph-fill ph-shield-check"></i> Konfirmasi Penarikan Saldo
    </div>
    <div class="cg-modal-bd">
      <div style="font-size:12px;font-weight:800;color:#9a3412;margin-bottom:6px;">Nominal yang Akan Dicairkan:</div>
      <div id="cg-modal-amt" style="font-size:26px;font-weight:900;color:#78350f;margin-bottom:12px;letter-spacing:-0.5px;"></div>
      <?php if (!$has_bank): ?>
      <div style="font-size:11px;font-weight:800;color:#b91c1c;background:#fee2e2;padding:8px 10px;border-radius:12px;border:1.5px solid #dc2626;">
        ⚠️ Pastikan nomor rekening dan nama Anda sudah benar!
      </div>
      <?php else: ?>
      <div style="font-size:11px;font-weight:800;color:#065f46;background:#dcfce7;padding:8px 10px;border-radius:12px;border:1.5px solid #10b981;">
        Dana akan ditransfer langsung ke rekening terdaftar: <strong><?= htmlspecialchars($user['bank_name']) ?> (<?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?>)</strong>
      </div>
      <?php endif; ?>
    </div>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="confirmCGWd()">Gas Tarik!</button>
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
    if (btn) { btn.disabled = true; btn.innerText = 'Memproses Transaksi...'; }

    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        if (typeof nToast !== 'undefined') nToast(res.error, 'error');
        if (btn) { btn.disabled = false; btn.innerText = 'Tarik Saldo Sekarang'; }
      } else {
        if (typeof nToast !== 'undefined') nToast(res.message, 'success');
        setTimeout(() => window.location.href = '/history?tab=withdraw', 1500);
      }
    })
    .catch(() => {
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus. Silakan coba lagi.', 'error');
      if (btn) { btn.disabled = false; btn.innerText = 'Tarik Saldo Sekarang'; }
    });
  };
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>