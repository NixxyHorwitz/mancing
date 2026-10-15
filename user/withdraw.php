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
/* ══════════════════════════════════════════════════════════════
   OCEAN VAULT WITHDRAWAL — PENARIKAN SALDO SAMUDRA
   Tema: Living Coral Reef Fantasy & 3D Tactile Claymorphism
   Konsep: Ultra Compact & Minimalist
   ══════════════════════════════════════════════════════════════ */

.wd-reef-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 32px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base */
.wd-clay {
  border: 2px solid #ffffff;
  border-radius: 20px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
  overflow: hidden;
}

/* Ambient Marine Floating Bubbles */
.wd-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.95) 0%, rgba(103, 232, 249, 0.35) 60%, rgba(16, 185, 129, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.85);
  box-shadow: inset 1px 1px 2px #ffffff, 0 2px 6px rgba(8, 145, 178, 0.15);
  pointer-events: none;
  z-index: 0;
}
.wd-bubble--1 {
  width: 16px;
  height: 16px;
  top: 10px;
  right: 18px;
  animation: wdBubbleFloat 5s infinite ease-in-out alternate;
}
.wd-bubble--2 {
  width: 24px;
  height: 24px;
  bottom: 12px;
  right: 14px;
  animation: wdBubbleFloat 6.5s infinite ease-in-out alternate-reverse;
  opacity: 0.65;
}
@keyframes wdBubbleFloat {
  0% { transform: translateY(0) scale(0.96); }
  50% { transform: translateY(-6px) scale(1.04); }
  100% { transform: translateY(2px) scale(0.98); }
}

/* ── 1. COMPACT TOP NAVIGATOR ── */
.wd-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.wd-back-btn {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  border: 1.5px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 17px;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.95);
  transition: transform 0.12s ease;
}
.wd-back-btn:active { transform: scale(0.92); }

.wd-nav-title {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04);
}

.wd-fast-pill {
  background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
  border: 1.5px solid #ffffff;
  color: #065f46;
  font-weight: 900;
  font-size: 11px;
  padding: 6px 11px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 2px 8px rgba(16, 185, 129, 0.18), inset 1px 1px 2px #ffffff;
}

/* ── 2. COMPACT NOTICE / STATUS BANNER ── */
.wd-notice-clay {
  background: rgba(255, 255, 255, 0.94);
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 9px 12px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
  display: flex;
  align-items: center;
  gap: 9px;
}
.wd-notice-icon {
  width: 28px;
  height: 28px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
  border: 1px solid #ffffff;
}
.wd-notice-icon.emerald {
  background: linear-gradient(135deg, #d1fae5 0%, #10b981 100%);
  color: #ffffff;
}
.wd-notice-icon.danger {
  background: linear-gradient(135deg, #fecdd3 0%, #f43f5e 100%);
  color: #ffffff;
}
.wd-notice-icon.amber {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  color: #78350f;
}
.wd-notice-text {
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-ink);
  line-height: 1.35;
  flex: 1;
}

/* ── 3. SALDO SIAP TARIK (HERO VAULT CARD) ── */
.wd-vault-card {
  padding: 16px 16px 14px;
}
.wd-vault-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.wd-vault-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 800;
  color: var(--reef-muted);
}
.wd-live-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #ffffff;
  border: 1px solid rgba(16, 185, 129, 0.3);
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 9.5px;
  font-weight: 900;
  color: #065f46;
  box-shadow: 0 1px 4px rgba(16, 185, 129, 0.12);
}
.wd-pulse-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #10b981;
  animation: wdDotPulse 1.6s infinite;
}
@keyframes wdDotPulse {
  0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1.2); box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.wd-vault-main {
  display: flex;
  align-items: baseline;
  gap: 4px;
  font-size: 27px;
  font-weight: 900;
  color: #065f46;
  line-height: 1.15;
  margin-bottom: 6px;
  letter-spacing: -0.5px;
}
.wd-vault-main .rp {
  font-size: 16px;
  font-weight: 900;
  color: #10b981;
}
.wd-vault-limits {
  font-size: 10px;
  font-weight: 700;
  color: var(--reef-muted);
  display: flex;
  align-items: center;
  gap: 6px;
}

/* ── 4. NOMINAL SELECTION (COMPACT 3-COL GRID) ── */
.wd-card-section {
  padding: 14px 14px 16px;
}
.wd-section-title {
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
}
.wd-amt-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 7px;
  margin-bottom: 12px;
}
.wd-amt-btn {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 13px;
  padding: 10px 4px;
  text-align: center;
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04), inset 1px 1px 2px #ffffff;
  transition: all 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
  outline: none;
  font-family: inherit;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  user-select: none;
}
.wd-amt-btn:hover {
  transform: translateY(-1px);
  background: #f0fdf4;
}
.wd-amt-btn:active {
  transform: scale(0.94);
}
.wd-amt-btn.active {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border-color: #ffffff;
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35), inset 1px 1px 2px rgba(255, 255, 255, 0.5);
  transform: translateY(-2px);
}

/* ── 5. REKENING TUJUAN CARD (COMPACT) ── */
.wd-bank-card {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
  cursor: pointer;
  transition: transform 0.12s ease;
  user-select: none;
  text-decoration: none;
}
.wd-bank-card:active {
  transform: scale(0.98);
}
.wd-bank-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.wd-bank-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: #f0fdf4;
  border: 1px solid #dcfce7;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.wd-bank-icon img {
  width: 24px;
  height: 24px;
  object-fit: contain;
}
.wd-bank-details {
  line-height: 1.25;
}
.wd-bank-name {
  font-size: 12.5px;
  font-weight: 900;
  color: var(--reef-ink);
}
.wd-bank-acc {
  font-size: 11px;
  font-weight: 700;
  color: #059669;
  font-family: monospace;
}
.wd-bank-right {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 10.5px;
  font-weight: 800;
  color: var(--reef-coral-500);
}

/* Bank Inputs if not linked */
.wd-input-grp { margin-bottom: 8px; }
.wd-input-grp:last-child { margin-bottom: 0; }
.wd-input-grp label {
  display: block;
  font-size: 10.5px;
  font-weight: 800;
  color: var(--reef-ink);
  margin-bottom: 4px;
}
.wd-input-grp input {
  width: 100%;
  padding: 9px 12px;
  border-radius: 12px;
  border: 1.5px solid #fed7aa;
  background: #ffffff;
  font-weight: 700;
  font-size: 12px;
  color: var(--reef-ink);
  outline: none;
  font-family: inherit;
  box-sizing: border-box;
  transition: border-color 0.15s;
}
.wd-input-grp input:focus {
  border-color: #10b981;
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}

/* ── 6. TACTILE 3D SUBMIT BUTTON ── */
.wd-submit-btn {
  width: 100%;
  padding: 13px 16px;
  border-radius: 16px;
  font-size: 13.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 2px solid #ffffff;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #ffffff;
  box-shadow: 0 5px 14px rgba(16, 185, 129, 0.35), 0 3px 0 #047857, inset 1px 1px 2px rgba(255, 255, 255, 0.6);
  cursor: pointer;
  font-family: inherit;
  transition: all 0.15s ease;
  user-select: none;
}
.wd-submit-btn:hover {
  filter: brightness(1.03);
}
.wd-submit-btn:active {
  transform: translateY(3px);
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3), 0 0 0 #047857;
}
.wd-submit-btn:disabled {
  background: #e2e8f0;
  color: #94a3b8;
  border-color: #cbd5e1;
  box-shadow: none;
  cursor: not-allowed;
  transform: none;
}

/* Upgrade CTA Button */
.wd-upgrade-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-top: 8px;
  width: 100%;
  padding: 12px;
  border-radius: 16px;
  font-size: 12.5px;
  font-weight: 900;
  color: #78350f;
  text-decoration: none;
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  border: 2px solid #ffffff;
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28), 0 2px 0 #b45309;
  box-sizing: border-box;
  transition: transform 0.1s;
}
.wd-upgrade-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #b45309;
}

/* ── 7. COMPACT RIWAYAT PENARIKAN ── */
.wd-hist-wrap {
  margin-top: 4px;
}
.wd-hist-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
  padding: 0 2px;
}
.wd-hist-title {
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 5px;
}
.wd-hist-link {
  font-size: 10.5px;
  font-weight: 800;
  color: var(--reef-coral-500);
  text-decoration: none;
}
.wd-hist-list {
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.wd-hist-item {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 9px 12px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04);
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.wd-hist-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.wd-hist-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.wd-hist-icon {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
  border: 1px solid #ffffff;
}
.wd-hist-amt {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.2;
}
.wd-hist-date {
  font-size: 9.5px;
  font-weight: 700;
  color: var(--reef-muted);
}
.wd-badge {
  font-size: 9px;
  font-weight: 900;
  padding: 3px 7px;
  border-radius: 8px;
  text-transform: uppercase;
  border: 1px solid;
}
.wd-badge.pending   { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.wd-badge.approved  { background: #dcfce7; color: #14532d; border-color: #bbf7d0; }
.wd-badge.rejected  { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.wd-badge.hold      { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }
.wd-badge.refunded  { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }

.wd-hist-note {
  border-radius: 8px;
  padding: 6px 9px;
  font-size: 10.5px;
  font-weight: 700;
  line-height: 1.35;
  display: flex;
  align-items: flex-start;
  gap: 6px;
}
.wd-hist-note.rejected { background: #fff1f2; border: 1px dashed #f43f5e; color: #9f1239; }
.wd-hist-note.hold     { background: #f8fafc; border: 1px dashed #94a3b8; color: #334155; }
.wd-hist-note.info     { background: #f0fdf4; border: 1px dashed #86efac; color: #166534; }

/* ── 8. CONFIRMATION MODAL (CLAYMORPHIC) ── */
.cg-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 16px;
  backdrop-filter: blur(4px);
}
.cg-modal-box {
  background: var(--reef-card);
  width: 100%;
  max-width: 330px;
  border-radius: 22px;
  border: 2px solid #ffffff;
  box-shadow: 0 16px 36px rgba(0, 0, 0, 0.2);
  overflow: hidden;
  animation: cgModalPop 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes cgModalPop {
  from { transform: scale(0.9); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}
.cg-modal-hdr {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  padding: 12px;
  text-align: center;
  color: #ffffff;
  font-weight: 900;
  font-size: 13.5px;
  border-bottom: 1.5px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.cg-modal-bd {
  padding: 18px 16px 14px;
  text-align: center;
}
.cg-modal-actions {
  display: flex;
  gap: 8px;
  padding: 0 16px 18px;
}
.cg-btn-cancel {
  flex: 1;
  padding: 10px;
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  font-weight: 800;
  color: var(--reef-muted);
  font-size: 12px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.05);
  cursor: pointer;
}
.cg-btn-confirm {
  flex: 1.4;
  padding: 10px;
  background: linear-gradient(135deg, #10b981, #059669);
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  font-weight: 900;
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);
  font-size: 12px;
  cursor: pointer;
}
</style>

<div class="wd-reef-stage">

  <!-- ── 1. COMPACT TOP NAVIGATOR ── -->
  <div class="wd-top-nav">
    <a href="/home" class="wd-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="wd-nav-title">
      <i class="ph-fill ph-hand-coins" style="color:#10b981;"></i>
      <span>Tarik Saldo Cuan</span>
    </div>
    <div class="wd-fast-pill">
      <i class="ph-bold ph-lightning"></i>
      <span>Cair 1-10 Menit</span>
    </div>
  </div>

  <!-- ── 2. COMPACT NOTICE / STATUS ── -->
  <?php if ($wd_estimation && $wd_locked): ?>
    <div class="wd-notice-clay" style="background:#fff1f2;border-color:#fecdd3;">
      <div class="wd-notice-icon danger">
        <i class="ph-bold ph-lock-key"></i>
      </div>
      <div class="wd-notice-text" style="color:#9f1239;">
        <?= strip_tags($wd_estimation) ?>
      </div>
    </div>
  <?php elseif ($has_pending_wd): ?>
    <div class="wd-notice-clay" style="background:#fffbeb;border-color:#fde68a;">
      <div class="wd-notice-icon amber">
        <i class="ph-bold ph-hourglass-medium"></i>
      </div>
      <div class="wd-notice-text" style="color:#92400e;">
        Penarikanmu sedang diproses oleh tim verifikasi kasir.
      </div>
    </div>
  <?php elseif (!empty($wds) && strtolower($wds[0]['status']) === 'rejected' && strtotime($wds[0]['created_at']) > strtotime('-2 days')): ?>
    <div class="wd-notice-clay" style="background:#fff1f2;border-color:#fecdd3;">
      <div class="wd-notice-icon danger">
        <i class="ph-bold ph-warning-circle"></i>
      </div>
      <div class="wd-notice-text" style="color:#9f1239;">
        <strong>Penarikan Ditolak:</strong> Saldo kembali. Alasan: <?= htmlspecialchars($wds[0]['admin_note'] ?: 'Rekening tidak valid') ?>
      </div>
    </div>
  <?php elseif ($flash): ?>
    <div class="wd-notice-clay" style="<?= $flashType==='error' ? 'background:#fff1f2;border-color:#fecdd3;' : 'background:#ecfdf5;border-color:#a7f3d0;' ?>">
      <div class="wd-notice-icon <?= $flashType==='error' ? 'danger' : 'emerald' ?>">
        <i class="ph-bold <?= $flashType==='error' ? 'ph-x' : 'ph-check' ?>"></i>
      </div>
      <div class="wd-notice-text" style="<?= $flashType==='error' ? 'color:#9f1239;' : 'color:#065f46;' ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── 3. SALDO SIAP TARIK (THE OCEAN VAULT CARD) ── -->
  <div class="wd-clay wd-vault-card">
    <div class="wd-bubble wd-bubble--1"></div>
    <div class="wd-bubble wd-bubble--2"></div>

    <div class="wd-vault-top">
      <span class="wd-vault-tag">
        <i class="ph-fill ph-wallet" style="color:#10b981;font-size:14px;"></i>
        <span>Saldo Siap Cair</span>
      </span>
      <span class="wd-live-badge">
        <span class="wd-pulse-dot"></span>
        <span>Siap 24 Jam</span>
      </span>
    </div>

    <div class="wd-vault-main">
      <span class="rp">Rp</span>
      <span><?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></span>
    </div>

    <div class="wd-vault-limits">
      <span>Min: <?= format_rp($min_withdraw) ?></span>
      <?php if ($max_withdraw > 0): ?>
        <span>&bull;</span>
        <span>Maks: <?= format_rp($max_withdraw) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 4. WITHDRAW FORM ── -->
  <form method="POST" id="wd-form" style="display:flex;flex-direction:column;gap:10px;">
    <?= csrf_field() ?>
    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
    <input type="hidden" name="amount" id="selected-amount" value="" required>

    <!-- PILIH NOMINAL CARD -->
    <div class="wd-clay wd-card-section">
      <div class="wd-section-title">
        <i class="ph-fill ph-coins" style="color:#f59e0b;font-size:15px;"></i>
        <span>Pilih Nominal Pencairan</span>
      </div>

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
          <div style="grid-column: 1/-1; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:12px; text-align:center; font-weight:800; font-size:11px; color:#b45309;">
            Belum ada opsi penarikan yang tersedia untuk saat ini.
          </div>
        <?php endif; ?>
      </div>

      <!-- REKENING / E-WALLET TUJUAN -->
      <div class="wd-section-title" style="margin-top:6px;margin-bottom:8px;">
        <i class="ph-fill ph-bank" style="color:#0ea5e9;font-size:15px;"></i>
        <span>Tujuan Rekening / E-Wallet</span>
      </div>

      <?php if ($has_bank): ?>
        <a href="/edit-rekening" class="wd-bank-card" title="Klik untuk mengganti rekening">
          <div class="wd-bank-left">
            <div class="wd-bank-icon">
              <?php 
              $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; 
              if ($user_wl): ?>
                <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars($user['bank_name']) ?>">
              <?php else: ?>
                <i class="ph-fill ph-bank" style="font-size:18px; color:#10b981;"></i>
              <?php endif; ?>
            </div>
            <div class="wd-bank-details">
              <div class="wd-bank-name"><?= htmlspecialchars($user['bank_name']) ?></div>
              <div class="wd-bank-acc"><?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?> &bull; <?= htmlspecialchars($user['account_name'] ?? '') ?></div>
            </div>
          </div>
          <div class="wd-bank-right">
            <span>Ubah</span>
            <i class="ph-bold ph-caret-right"></i>
          </div>
        </a>
      <?php else: ?>
        <div style="background:#ffffff; border-radius:14px; padding:10px 12px; border:1px solid #fed7aa;">
          <div class="wd-input-grp">
            <label>Pilih Bank / E-Wallet</label>
            <input type="text" name="bank_name" value="<?= $is_free_level ? 'DANA' : '' ?>" <?= $is_free_level ? 'readonly' : 'placeholder="BCA, DANA, OVO, BRI..." required' ?>>
          </div>
          <div class="wd-input-grp">
            <label>Nomor Rekening / No HP</label>
            <input type="text" name="account_number" placeholder="Contoh: 081234567890" required>
          </div>
          <div class="wd-input-grp">
            <label>Nama Pemilik Rekening</label>
            <input type="text" name="account_name" placeholder="Nama Lengkap Pemilik" required>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── 5. SUBMIT ACTION BUTTON ── -->
    <?php if ($free_wd_limit_reached): ?>
      <button type="button" class="wd-submit-btn" disabled>Batas Limit WD Tercapai</button>
    <?php elseif ($free_wrong_bank): ?>
      <button type="button" class="wd-submit-btn" disabled>Hanya DANA untuk Level <?= htmlspecialchars($user_mem['name']) ?></button>
    <?php elseif ($wd_locked): ?>
      <button type="button" class="wd-submit-btn" disabled>Penarikan Sedang Terkunci</button>
    <?php elseif ($level_blocked): ?>
      <?php if ((float)$user['balance_wd'] < 50000): ?>
        <button type="button" class="wd-submit-btn" disabled>Minimal Tarik Rp 50.000</button>
      <?php else: ?>
        <button type="button" class="wd-submit-btn" disabled>Butuh Upgrade Level</button>
        <a href="/upgrade" class="wd-upgrade-btn">
          <i class="ph-fill ph-crown"></i>
          <span>Upgrade Level untuk Buka Penarikan &rarr;</span>
        </a>
      <?php endif; ?>
    <?php elseif ($free_age_blocked): ?>
      <button type="button" class="wd-submit-btn" disabled>Akun Baru (Min. Umur 1 Hari)</button>
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
        <i class="ph-fill ph-paper-plane-tilt" style="font-size:18px;"></i>
        <span>Tarik Saldo Sekarang</span>
      </button>
    <?php endif; ?>

  </form>

  <!-- ── 6. COMPACT RIWAYAT PENARIKAN TERAKHIR ── -->
  <?php if (!empty($wds)): ?>
  <div class="wd-hist-wrap">
    <div class="wd-hist-header">
      <div class="wd-hist-title">
        <i class="ph-fill ph-clock-counter-clockwise" style="color:#0ea5e9;"></i>
        <span>Riwayat Penarikan</span>
      </div>
      <a href="/history?tab=withdraw" class="wd-hist-link">Lihat Semua &rarr;</a>
    </div>

    <div class="wd-hist-list">
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
        <div class="wd-hist-item">
          <div class="wd-hist-main">
            <div class="wd-hist-left">
              <div class="wd-hist-icon" style="<?= $iconStyle ?>">
                <i class="ph-bold <?= $iconI ?>"></i>
              </div>
              <div>
                <div class="wd-hist-amt"><?= format_rp((float)$w['amount']) ?></div>
                <div class="wd-hist-date"><?= htmlspecialchars($w['bank_name']) ?> &bull; <?= date('d M Y, H:i', strtotime($w['created_at'])) ?> WIB</div>
              </div>
            </div>
            <div>
              <span class="wd-badge <?= $bclass ?>"><?= $stLabel ?></span>
            </div>
          </div>

          <?php if (!empty($w['admin_note'])): ?>
            <div class="wd-hist-note <?= $st === 'rejected' ? 'rejected' : ($st === 'hold' ? 'hold' : 'info') ?>">
              <i class="ph-bold <?= $st === 'rejected' ? 'ph-warning-circle' : 'ph-info' ?>" style="font-size:14px;flex-shrink:0;margin-top:1px;"></i>
              <div>
                <strong><?= $st === 'rejected' ? 'Alasan Penolakan:' : ($st === 'hold' ? 'Catatan Hold:' : 'Catatan Admin:') ?></strong>
                <?= htmlspecialchars((string)$w['admin_note']) ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- ── 7. CONFIRMATION MODAL (CLAYMORPHIC) ── -->
<div id="cg-modal" class="cg-modal">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">
      <i class="ph-fill ph-shield-check"></i>
      <span>Konfirmasi Penarikan Saldo</span>
    </div>
    <div class="cg-modal-bd">
      <div style="font-size:11px;font-weight:800;color:var(--reef-muted);margin-bottom:4px;">Nominal yang Akan Dicairkan:</div>
      <div id="cg-modal-amt" style="font-size:24px;font-weight:900;color:#065f46;margin-bottom:12px;letter-spacing:-0.5px;"></div>
      
      <?php if (!$has_bank): ?>
      <div style="font-size:11px;font-weight:800;color:#9f1239;background:#fff1f2;padding:8px 10px;border-radius:12px;border:1px solid #fecdd3;">
        Pastikan nomor rekening dan nama pemilik sudah benar!
      </div>
      <?php else: ?>
      <div style="font-size:11px;font-weight:700;color:#065f46;background:#d1fae5;padding:8px 10px;border-radius:12px;border:1px solid #a7f3d0;">
        Ditransfer langsung ke rekening: <strong><?= htmlspecialchars($user['bank_name']) ?> (<?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?>)</strong>
      </div>
      <?php endif; ?>
    </div>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="confirmCGWd()">Ya, Cairkan!</button>
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
