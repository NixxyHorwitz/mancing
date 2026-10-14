<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$min_deposit  = (float) setting($pdo, 'min_deposit', '10000');
$bank_enabled = setting($pdo, 'bank_enabled', '1') === '1';
$qris_enabled = setting($pdo, 'qris_enabled', '1') === '1';
$bankName     = setting($pdo, 'bank_name', 'BCA');
$bankAccount  = setting($pdo, 'bank_account', '-');
$bankHolder   = setting($pdo, 'bank_holder', 'Admin');
$qris_raw     = $_ENV['QRIS_RAW'] ?? '';

$u_enabled = setting($pdo, 'depo_unique_code_enabled', '0') === '1';
$u_min = (int)setting($pdo, 'depo_unique_code_min', '1');
$u_max = (int)setting($pdo, 'depo_unique_code_max', '999');
$unique_code = $u_enabled ? random_int(min($u_min, $u_max), max($u_min, $u_max)) : 0;

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk = 'dep_form_token';
if (empty($_SESSION[$_ftk])) $_SESSION[$_ftk] = bin2hex(random_bytes(16));
$_form_token = $_SESSION[$_ftk];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reconnect MySQL in case connection has gone away (error 2006/2013)
    pdo_reconnect($pdo);
    
    $submitted_ftk = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk] ?? '', $submitted_ftk)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
        goto end_dep;
    }
    // Invalidate immediately to prevent double-submit
    unset($_SESSION[$_ftk]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_bank') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    $u_code = (int) preg_replace('/\D/', '', $_POST['unique_code'] ?? '0');
    if ($u_enabled && $u_code >= min($u_min, $u_max) && $u_code <= max($u_min, $u_max)) {
        $amount += $u_code;
    }
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$bank_enabled) {
        $flash = 'Transfer bank lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        $proof = null;
        if (!empty($_FILES['proof']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $flash = 'Bukti transfer harus format JPG/PNG/WEBP ya.'; $flashType = 'error';
                goto end_dep;
            }
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $proof = 'deposits/' . $fname;
        }
        $pdo->prepare("INSERT INTO deposits (user_id,amount,method,proof_image) VALUES (?,?,?,?)")
            ->execute([$user['id'], $amount, 'transfer', $proof]);
        $dep_id = $pdo->lastInsertId();
        
        $msg = "<b>📢 DEPOSIT BARU (Transfer)</b>\nUser: {$user['username']}\nAmount: " . format_rp((float)$amount) . "\nStatus: Pending";
        $kb = [
            [['text'=>'✅ Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'❌ Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
            [['text'=>'⚡ Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
        ];
        send_telegram_notif($pdo, $msg, $kb, 'depo');
        
        // Success — regenerate token for next request
        $_SESSION[$_ftk] = bin2hex(random_bytes(16));
        $flash = '✅ Bukti transfer berhasil dikirim! Admin bakal memproses dalam 1×24 jam ya.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_qris') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    $u_code = (int) preg_replace('/\D/', '', $_POST['unique_code'] ?? '0');
    if ($u_enabled && $u_code >= min($u_min, $u_max) && $u_code <= max($u_min, $u_max)) {
        $amount += $u_code;
    }
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$qris_enabled || empty($qris_raw)) {
        $flash = 'QRIS lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO deposits (user_id,amount,method,status) VALUES (?,?,'qris','pending')")
            ->execute([$user['id'], $amount]);
        $dep_id = $pdo->lastInsertId();
        
        $merchant_name = 'Unknown';
        $idx = 0;
        while ($idx < strlen($qris_raw) - 4) {
            $tag = substr($qris_raw, $idx, 2);
            $len = (int)substr($qris_raw, $idx+2, 2);
            if ($tag === '59') {
                $merchant_name = substr($qris_raw, $idx+4, $len);
                break;
            }
            $idx += 4 + $len;
        }

        $fmt_amount = format_rp((float)$amount);
        $msg = "📢 <b>DEPOSIT BARU ({$fmt_amount})</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$amount) . "</code>\n";
        $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
        $msg .= "🏪 <b>QRIS:</b> <code>" . htmlspecialchars($merchant_name) . "</code>\n";
        $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
        $msg .= "⏳ <b>Status:</b> <code>Pending</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "<i>Sistem memantau secara otomatis. Status di Telegram ini akan diperbarui ketika sukses terbayar via callback!</i>";
        
        $kb = [
            [['text'=>'✅ Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'❌ Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
            [['text'=>'⚡ Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
        ];
        
        $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
        if ($tg_msg_id) {
            $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
        }

        redirect('/pay?id=' . $dep_id);
    }
}
end_dep:

$deps = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$deps->execute([$user['id']]); $deps = $deps->fetchAll();

$pageTitle  = 'Isi Saldo  ';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════
   REEF TREASURE CARGO DECK & PEARL VAULT — CLAYMORPHISM DEPOSIT
   Tema: Living Coral Reef Fantasy + 3D Tactile Claymorphism
   ══════════════════════════════════════════════════════════════ */
.depo-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 28px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base Card */
.depo-clay {
  border: 2px solid #ffffff;
  border-radius: 22px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
}

/* Keyframes */
@keyframes depoShake {
  0%, 100% { transform: translateX(0); }
  20%, 60% { transform: translateX(-6px); }
  40%, 80% { transform: translateX(6px); }
}
@keyframes pulseKelp {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.3); opacity: 0.6; }
}
@keyframes floatVaultBubble {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-8px) scale(1.08); }
}
@keyframes clamGlow {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-4px) scale(1.04); }
}

/* ── 1. TOP NAVIGATOR BAR ── */
.depo-nav-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 2px 2px 4px;
}
.depo-back-btn {
  width: 40px;
  height: 40px;
  border-radius: 14px;
  border: 2px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 19px;
  text-decoration: none;
  box-shadow: 0 4px 12px rgba(30, 27, 46, 0.08), inset 2px 2px 4px rgba(255, 255, 255, 0.9);
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s ease;
}
.depo-back-btn:active {
  transform: scale(0.92);
}
.depo-nav-title-box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgba(255, 255, 255, 0.92);
  border: 2px solid #ffffff;
  padding: 6px 14px;
  border-radius: 999px;
  box-shadow: 0 4px 14px rgba(30, 27, 46, 0.06), inset 2px 2px 4px rgba(255, 255, 255, 0.9);
}
.depo-nav-title-text {
  font-size: 13.5px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: -0.2px;
}
.depo-live-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #d1fae5;
  border: 1.5px solid #a7f3d0;
  color: #065f46;
  font-size: 10.5px;
  font-weight: 900;
  padding: 5px 10px;
  border-radius: 999px;
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.15);
}
.depo-live-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.35);
  animation: pulseKelp 1.8s ease-in-out infinite;
}

/* ── 2. THE SUNKEN PEARL VAULT HERO CARD ── */
.depo-vault-card {
  border: 2px solid #ffffff;
  border-radius: 26px;
  background:
    radial-gradient(circle at 88% 18%, rgba(254, 240, 138, 0.5) 0, transparent 42%),
    radial-gradient(circle at 10% 80%, rgba(52, 211, 153, 0.2) 0, transparent 40%),
    linear-gradient(160deg, #ffffff 0%, #fff7ed 45%, #ffedd5 100%);
  box-shadow:
    0 16px 36px rgba(244, 63, 94, 0.12),
    0 6px 16px rgba(8, 145, 178, 0.12),
    inset 3px 3px 7px rgba(255, 255, 255, 0.95),
    inset -4px -5px 10px rgba(251, 146, 60, 0.18);
  padding: 16px 16px 14px;
  position: relative;
  overflow: hidden;
}
/* Micro floating bubbles */
.depo-vault-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.85), rgba(34, 211, 238, 0.3));
  pointer-events: none;
  animation: floatVaultBubble 4s ease-in-out infinite;
}
.depo-vault-bubble--1 { width: 14px; height: 14px; top: 12px; left: 16px; animation-duration: 4.5s; }
.depo-vault-bubble--2 { width: 20px; height: 20px; bottom: 18px; left: 35%; animation-duration: 6s; animation-delay: -2s; }
.depo-vault-bubble--3 { width: 10px; height: 10px; top: 40px; right: 88px; animation-duration: 3.5s; animation-delay: -1s; }

.depo-vault-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  position: relative;
  z-index: 2;
}
.depo-vault-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.9);
  border: 1.5px solid #ffffff;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 900;
  color: var(--reef-ink);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
.depo-vault-secure {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10.5px;
  font-weight: 900;
  color: #059669;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  padding: 3px 9px;
  border-radius: 999px;
}

.depo-vault-main {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  position: relative;
  z-index: 2;
}
.depo-vault-balance-area {
  flex: 1;
  min-width: 0;
}
.depo-vault-lbl {
  font-size: 11px;
  font-weight: 800;
  color: var(--reef-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 2px;
}
.depo-vault-amount {
  font-size: 26px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: -0.5px;
  line-height: 1.15;
  display: flex;
  align-items: baseline;
  gap: 4px;
}
.depo-vault-amount .rp {
  font-size: 16px;
  font-weight: 900;
  color: var(--reef-sun-500);
}

/* 3D Clam & Pearl Illustration */
.depo-vault-art {
  width: 78px;
  height: 72px;
  flex-shrink: 0;
  filter: drop-shadow(0 6px 12px rgba(245, 158, 11, 0.28));
  animation: clamGlow 3.6s ease-in-out infinite;
}

/* Utility tags */
.depo-vault-tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 14px;
  padding-top: 10px;
  border-top: 1.5px dashed rgba(251, 146, 60, 0.35);
  position: relative;
  z-index: 2;
}
.depo-vault-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 800;
  color: #7c2d12;
  background: rgba(255, 255, 255, 0.85);
  border: 1px solid rgba(251, 146, 60, 0.3);
  padding: 3px 8px;
  border-radius: 8px;
}

/* Minimum deposit notice inside banner */
.depo-mini-notice {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: 12px;
  background: rgba(254, 243, 199, 0.7);
  border: 1.5px solid #fde68a;
  font-size: 11px;
  font-weight: 800;
  color: #92400e;
  position: relative;
  z-index: 2;
}

/* ── FLASH NOTIFICATION ── */
.depo-flash {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 18px;
  border: 2px solid #ffffff;
  font-size: 12px;
  font-weight: 800;
  line-height: 1.4;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.05);
}
.depo-flash.success {
  background: #d1fae5;
  color: #065f46;
  border-color: #a7f3d0;
}
.depo-flash.error {
  background: #ffe4e6;
  color: #9f1239;
  border-color: #fecdd3;
}
.depo-flash-icon {
  width: 28px;
  height: 28px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  background: #ffffff;
  flex-shrink: 0;
}

/* ── 3. INTERACTIVE PAYMENT VESSEL SWITCHER (DUA DERMAGA) ── */
.depo-section-lbl {
  font-size: 12.5px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 2px;
}
.depo-section-lbl span.tag {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
}

.depo-vessels-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.depo-vessel-card {
  position: relative;
  border: 2.5px solid #ffffff;
  border-radius: 20px;
  background: #ffffff;
  padding: 12px 10px 10px;
  box-shadow: 0 6px 18px rgba(30, 27, 46, 0.06), inset 2px 2px 5px rgba(255, 255, 255, 0.9);
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 110px;
  user-select: none;
}
.depo-vessel-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 22px rgba(30, 27, 46, 0.09);
}
.depo-vessel-card:active {
  transform: scale(0.96);
}
.depo-vessel-card.active {
  border-color: var(--reef-coral-500);
  background: linear-gradient(155deg, #ffffff 0%, #fff1f2 60%, #ffe4e6 100%);
  box-shadow:
    0 10px 24px rgba(244, 63, 94, 0.22),
    inset 2px 2px 5px rgba(255, 255, 255, 0.95),
    inset -2px -2px 6px rgba(244, 63, 94, 0.12);
}

.depo-vessel-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 6px;
}
.depo-vessel-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
  flex-shrink: 0;
}
.depo-vessel-card--qris .depo-vessel-icon-box {
  background: linear-gradient(135deg, #ecfdf5, #d1fae5);
}
.depo-vessel-card--bank .depo-vessel-icon-box {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
}

/* Radio circle selector */
.depo-vessel-radio {
  width: 20px;
  height: 20px;
  border-radius: 50%;
  border: 2px solid #d1d5db;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
}
.depo-vessel-card.active .depo-vessel-radio {
  border-color: var(--reef-coral-500);
  background: var(--reef-coral-500);
  box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.25);
}
.depo-vessel-radio i {
  color: #ffffff;
  font-size: 11px;
  opacity: 0;
  transform: scale(0.5);
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.depo-vessel-card.active .depo-vessel-radio i {
  opacity: 1;
  transform: scale(1);
}

.depo-vessel-info {
  margin-top: 8px;
}
.depo-vessel-name {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.2;
}
.depo-vessel-desc {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
  margin-top: 2px;
}
.depo-vessel-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin-top: 6px;
  font-size: 9px;
  font-weight: 900;
  padding: 3px 7px;
  border-radius: 6px;
}
.depo-vessel-badge--qris {
  background: #dcfce7;
  color: #15803d;
}
.depo-vessel-badge--bank {
  background: #fef3c7;
  color: #b45309;
}

/* ── 4. QUICK AMOUNT PEARL CHESTS (GRID 6 NOMINAL) ── */
.depo-chips-card {
  padding: 14px;
}
.depo-chips-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-top: 8px;
}
.depo-chip-btn {
  border: 2px solid #ffffff;
  border-radius: 16px;
  background: #ffffff;
  padding: 10px 6px;
  text-align: center;
  box-shadow: 0 4px 10px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.9);
  cursor: pointer;
  transition: all 0.16s cubic-bezier(0.34, 1.56, 0.64, 1);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
}
.depo-chip-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(30, 27, 46, 0.09);
}
.depo-chip-btn:active {
  transform: scale(0.94);
}
.depo-chip-btn.active {
  border-color: var(--reef-sun-500);
  background: linear-gradient(145deg, #ffffff 0%, #fef3c7 100%);
  box-shadow:
    0 8px 18px rgba(245, 158, 11, 0.24),
    inset 2px 2px 4px rgba(255, 255, 255, 0.95),
    inset -2px -2px 5px rgba(245, 158, 11, 0.15);
  transform: translateY(-1px);
}
.depo-chip-amt {
  font-size: 13.5px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.1;
}
.depo-chip-btn.active .depo-chip-amt {
  color: #92400e;
}
.depo-chip-tag {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
}
.depo-chip-btn.active .depo-chip-tag {
  color: #b45309;
}

/* ── 5. CUSTOM AMOUNT INPUT CAPSULE ── */
.depo-input-box {
  margin-top: 12px;
  position: relative;
}
.depo-input-lbl-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.depo-input-lbl {
  font-size: 11.5px;
  font-weight: 900;
  color: var(--reef-ink);
}
.depo-input-capsule {
  display: flex;
  align-items: center;
  background: #ffffff;
  border: 2px solid #ffffff;
  border-radius: 18px;
  padding: 4px 8px 4px 12px;
  box-shadow:
    inset 2px 2px 5px rgba(0, 0, 0, 0.05),
    0 4px 12px rgba(30, 27, 46, 0.05);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.depo-input-capsule:focus-within {
  border-color: var(--reef-coral-400);
  box-shadow:
    0 0 0 3px rgba(244, 63, 94, 0.15),
    inset 2px 2px 4px rgba(0, 0, 0, 0.04);
}
.depo-input-prefix {
  font-size: 16px;
  font-weight: 900;
  color: var(--reef-coral-500);
  margin-right: 6px;
}
.depo-amount-field {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: 18px;
  font-weight: 900;
  color: var(--reef-ink);
  font-family: inherit;
  padding: 8px 0;
  min-width: 0;
}
.depo-amount-field::placeholder {
  color: #cbd5e1;
  font-weight: 800;
}
.depo-input-clear {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: none;
  background: #f1f5f9;
  color: #64748b;
  display: none;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  cursor: pointer;
  transition: background 0.15s ease;
}
.depo-input-clear:hover {
  background: #e2e8f0;
}
.depo-input-clear.show {
  display: flex;
}

/* Helper live text under input */
.depo-input-live-helper {
  font-size: 11px;
  font-weight: 800;
  color: #059669;
  margin-top: 5px;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Unique code alert if enabled */
.depo-unique-pill {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  padding: 6px 10px;
  border-radius: 10px;
  background: #fef3c7;
  border: 1px solid #fde68a;
  font-size: 10.5px;
  font-weight: 800;
  color: #92400e;
}

/* ── 6. FORM CONTAINER SWITCH ── */
.depo-form-wrapper {
  display: none;
}
.depo-form-wrapper.active {
  display: block;
}

/* QRIS Official Card */
.depo-qris-card {
  padding: 14px;
}
.depo-qris-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border: 1.5px solid #a7f3d0;
  border-radius: 14px;
  padding: 8px 12px;
  margin-bottom: 12px;
}
.depo-qris-logo-img {
  height: 30px;
  max-width: 96px;
  object-fit: contain;
}
.depo-wallets-banner {
  background: #ffffff;
  border-radius: 14px;
  padding: 10px 12px;
  border: 1.5px solid #f1f5f9;
  margin-bottom: 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.depo-wallets-title {
  font-size: 10.5px;
  font-weight: 900;
  color: var(--reef-muted);
  display: flex;
  align-items: center;
  gap: 5px;
}
.depo-wallets-icons {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}
.depo-wallet-tag {
  font-size: 9.5px;
  font-weight: 900;
  padding: 3px 7px;
  border-radius: 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  color: #475569;
}

/* ── 7. BANK TRANSFER CARDS & DROPZONE ── */
.depo-bank-card {
  padding: 14px;
}
.depo-safe-box {
  background:
    radial-gradient(circle at 90% 10%, rgba(254, 240, 138, 0.4) 0, transparent 40%),
    linear-gradient(150deg, #ffffff 0%, #fffbeb 50%, #fef3c7 100%);
  border: 2px solid #ffffff;
  border-radius: 20px;
  padding: 14px;
  box-shadow:
    0 10px 24px rgba(245, 158, 11, 0.14),
    inset 2px 2px 6px rgba(255, 255, 255, 0.9);
  margin-bottom: 14px;
}
.depo-safe-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.depo-safe-bank-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 900;
  color: #92400e;
  background: #ffffff;
  padding: 4px 10px;
  border-radius: 10px;
  border: 1.5px solid #fde68a;
}
.depo-safe-num-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  background: #ffffff;
  border: 2px dashed #f59e0b;
  border-radius: 14px;
  padding: 8px 12px;
  margin: 6px 0;
}
.depo-safe-num {
  font-size: 19px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: 1px;
  font-family: monospace, sans-serif;
}
.depo-safe-copy-btn {
  border: none;
  background: var(--reef-sun-500);
  color: #ffffff;
  font-size: 11px;
  font-weight: 900;
  padding: 6px 12px;
  border-radius: 10px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.35);
  transition: all 0.15s ease;
  white-space: nowrap;
}
.depo-safe-copy-btn:active {
  transform: scale(0.95);
}
.depo-safe-copy-btn.copied {
  background: #10b981;
}
.depo-safe-holder {
  font-size: 11px;
  font-weight: 800;
  color: #b45309;
  margin-top: 4px;
}

/* Upload dropzone */
.depo-dropzone {
  border: 2px dashed #f43f5e;
  border-radius: 18px;
  background: #fff1f2;
  padding: 16px 12px;
  text-align: center;
  cursor: pointer;
  position: relative;
  transition: all 0.2s ease;
  margin-bottom: 14px;
}
.depo-dropzone:hover {
  background: #ffe4e6;
}
.depo-dropzone-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}
.depo-dropzone-icon {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: #ffffff;
  color: var(--reef-coral-500);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  box-shadow: 0 4px 10px rgba(244, 63, 94, 0.15);
}
.depo-dropzone-title {
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
}
.depo-dropzone-hint {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
}
.depo-dropzone-file {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
}

/* Preview selected file */
.depo-preview-box {
  display: none;
  align-items: center;
  gap: 10px;
  background: #ffffff;
  border: 2px solid #ffffff;
  border-radius: 14px;
  padding: 8px 10px;
  margin-top: 8px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
}
.depo-preview-thumb {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  object-fit: cover;
  border: 1.5px solid #fecdd3;
}
.depo-preview-meta {
  flex: 1;
  min-width: 0;
}
.depo-preview-name {
  font-size: 11px;
  font-weight: 900;
  color: var(--reef-ink);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.depo-preview-size {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
}
.depo-preview-del {
  background: #fee2e2;
  border: none;
  color: #dc2626;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 12px;
}

/* ── 8. 3D CLAY SUBMIT BUTTONS ── */
.depo-submit-btn {
  width: 100%;
  border: 2.5px solid #ffffff;
  border-radius: 20px;
  padding: 14px 16px;
  font-size: 14px;
  font-weight: 900;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.16s cubic-bezier(0.34, 1.56, 0.64, 1);
  box-shadow:
    0 10px 22px rgba(0, 0, 0, 0.15),
    inset 2.5px 2.5px 5px rgba(255, 255, 255, 0.6),
    inset -2.5px -2.5px 6px rgba(0, 0, 0, 0.2);
}
.depo-submit-btn:hover {
  transform: translateY(-2px);
}
.depo-submit-btn:active {
  transform: scale(0.97);
}
.depo-submit-btn--qris {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  box-shadow:
    0 10px 24px rgba(16, 185, 129, 0.35),
    inset 2.5px 2.5px 5px rgba(255, 255, 255, 0.5),
    inset -2.5px -2.5px 6px rgba(4, 120, 87, 0.4);
}
.depo-submit-btn--bank {
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 55%, #e11d48 100%);
  box-shadow:
    0 10px 24px rgba(244, 63, 94, 0.35),
    inset 2.5px 2.5px 5px rgba(255, 255, 255, 0.5),
    inset -2.5px -2.5px 6px rgba(159, 18, 57, 0.4);
}

/* ── 9. EXPEDITION LOG (RIWAYAT TOP UP) ── */
.depo-hist-wrap {
  margin-top: 4px;
}
.depo-hist-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  padding: 0 4px;
}
.depo-hist-title {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 6px;
}
.depo-hist-all-link {
  font-size: 11px;
  font-weight: 900;
  color: var(--reef-coral-500);
  text-decoration: none;
  background: #ffffff;
  padding: 4px 10px;
  border-radius: 10px;
  border: 1.5px solid #ffffff;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
}
.depo-hist-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.depo-hist-card {
  border: 2px solid #ffffff;
  border-radius: 18px;
  background: #ffffff;
  padding: 10px 12px;
  box-shadow: 0 4px 12px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.9);
  display: flex;
  align-items: center;
  gap: 10px;
  position: relative;
  overflow: hidden;
}
.depo-hist-icon {
  width: 40px;
  height: 40px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  border: 1.5px solid #ffffff;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.06);
}
.depo-hist-icon.qris {
  background: linear-gradient(135deg, #dcfce7, #bbf7d0);
  color: #15803d;
}
.depo-hist-icon.bank {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  color: #b45309;
}
.depo-hist-body {
  flex: 1;
  min-width: 0;
}
.depo-hist-amt {
  font-size: 14px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.2;
}
.depo-hist-time {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
  margin-top: 1px;
}
.depo-hist-status-col {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}
.depo-badge {
  font-size: 9.5px;
  font-weight: 900;
  padding: 3px 8px;
  border-radius: 8px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.depo-badge.pending {
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
}
.depo-badge.confirmed, .depo-badge.approved {
  background: #d1fae5;
  color: #065f46;
  border: 1px solid #a7f3d0;
}
.depo-badge.rejected {
  background: #fee2e2;
  color: #991b1b;
  border: 1px solid #fecdd3;
}
.depo-hist-pay-now {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 10px;
  font-weight: 900;
  color: #ffffff;
  background: #0284c7;
  padding: 3px 8px;
  border-radius: 8px;
  text-decoration: none;
  box-shadow: 0 2px 6px rgba(2, 132, 199, 0.3);
}
</style>

<div class="depo-stage">

  <!-- ── 1. TOP NAVIGATOR BAR ── -->
  <div class="depo-nav-bar">
    <a href="/home" class="depo-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="depo-nav-title-box">
      <i class="ph-fill ph-wallet" style="color:var(--reef-sun-500);font-size:16px;"></i>
      <span class="depo-nav-title-text">Dermaga Top Up Kas</span>
    </div>
    <div class="depo-live-badge">
      <span class="depo-live-dot"></span>
      <span>24 Jam</span>
    </div>
  </div>

  <!-- ── 2. THE SUNKEN PEARL VAULT (KARTU BRANKAS KAS BELANJA) ── -->
  <div class="depo-vault-card">
    <div class="depo-vault-bubble depo-vault-bubble--1"></div>
    <div class="depo-vault-bubble depo-vault-bubble--2"></div>
    <div class="depo-vault-bubble depo-vault-bubble--3"></div>

    <div class="depo-vault-top">
      <div class="depo-vault-pill">
        <i class="ph-fill ph-anchor" style="color:var(--reef-coral-500);"></i>
        <span>Brankas Pembelian Bahari</span>
      </div>
      <div class="depo-vault-secure">
        <i class="ph-fill ph-shield-check"></i>
        <span>Terproteksi</span>
      </div>
    </div>

    <div class="depo-vault-main">
      <div class="depo-vault-balance-area">
        <div class="depo-vault-lbl">Saldo Pembelian Tersedia</div>
        <div class="depo-vault-amount">
          <span class="rp">Rp</span>
          <span><?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></span>
        </div>
      </div>

      <!-- 3D Clam & Golden Pearl SVG Art -->
      <div class="depo-vault-art">
        <svg viewBox="0 0 100 90" width="100%" height="100%" fill="none" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <linearGradient id="clamTop" x1="10" y1="10" x2="90" y2="50" gradientUnits="userSpaceOnUse">
              <stop stop-color="#fb7185"/>
              <stop offset="0.6" stop-color="#f43f5e"/>
              <stop offset="1" stop-color="#be123c"/>
            </linearGradient>
            <linearGradient id="clamBase" x1="15" y1="45" x2="85" y2="85" gradientUnits="userSpaceOnUse">
              <stop stop-color="#fda4af"/>
              <stop offset="0.7" stop-color="#fb7185"/>
              <stop offset="1" stop-color="#9f1239"/>
            </linearGradient>
            <radialGradient id="pearlShine" cx="45%" cy="40%" r="55%">
              <stop offset="0%" stop-color="#ffffff"/>
              <stop offset="45%" stop-color="#fef08a"/>
              <stop offset="85%" stop-color="#f59e0b"/>
              <stop offset="100%" stop-color="#d97706"/>
            </radialGradient>
            <filter id="clamDrop" x="-10%" y="-10%" width="120%" height="130%">
              <feDropShadow dx="0" dy="4" stdDeviation="3" flood-color="#9f1239" flood-opacity="0.3"/>
            </filter>
          </defs>
          <!-- Lower shell -->
          <path d="M12 52 C14 74 32 84 50 84 C68 84 86 74 88 52 C82 56 68 59 50 59 C32 59 18 56 12 52 Z" fill="url(#clamBase)" filter="url(#clamDrop)"/>
          <path d="M22 55 Q50 64 78 55" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" opacity="0.6"/>
          <!-- Upper shell back -->
          <path d="M15 48 C12 24 32 8 50 8 C68 8 88 24 85 48 C75 36 62 30 50 30 C38 30 25 36 15 48 Z" fill="url(#clamTop)"/>
          <!-- Shell ridges -->
          <path d="M50 8 L50 30" stroke="#fda4af" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
          <path d="M35 12 Q42 22 45 30" stroke="#fda4af" stroke-width="1.8" stroke-linecap="round" opacity="0.6"/>
          <path d="M65 12 Q58 22 55 30" stroke="#fda4af" stroke-width="1.8" stroke-linecap="round" opacity="0.6"/>
          <!-- Golden Coins around pearl -->
          <ellipse cx="32" cy="55" rx="7" ry="4" fill="#fbbf24" stroke="#d97706" stroke-width="1.2" transform="rotate(-15 32 55)"/>
          <ellipse cx="68" cy="56" rx="7" ry="4" fill="#f59e0b" stroke="#b45309" stroke-width="1.2" transform="rotate(18 68 56)"/>
          <!-- Radiant Giant Pearl -->
          <circle cx="50" cy="46" r="14" fill="url(#pearlShine)" filter="drop-shadow(0 2px 6px rgba(245,158,11,0.6))"/>
          <circle cx="45" cy="41" r="4" fill="#ffffff" opacity="0.9"/>
          <!-- Sparkle stars -->
          <path d="M68 32 L70 36 L74 38 L70 40 L68 44 L66 40 L62 38 L66 36 Z" fill="#fef08a" opacity="0.9"/>
          <circle cx="28" cy="34" r="2" fill="#ffffff" opacity="0.8"/>
        </svg>
      </div>
    </div>

    <!-- Utility Tags -->
    <div class="depo-vault-tags">
      <div class="depo-vault-tag"><i class="ph-fill ph-fish"></i> Umpan &amp; Jaring Pancing</div>
      <div class="depo-vault-tag"><i class="ph-fill ph-crown"></i> Upgrade Kapal VIP</div>
      <div class="depo-vault-tag"><i class="ph-fill ph-lightning"></i> Booster Panen</div>
    </div>

    <!-- Notice info -->
    <div class="depo-mini-notice">
      <i class="ph-fill ph-info" style="color:var(--reef-sun-500);font-size:15px;flex-shrink:0;"></i>
      <span>Minimal isi saldo <strong><?= format_rp($min_deposit) ?></strong>. Diproses otomatis &amp; bebas potongan.</span>
    </div>
  </div>

  <!-- ── FLASH ALERTS ── -->
  <?php if ($flash): ?>
  <div class="depo-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <div class="depo-flash-icon">
      <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning' : 'check' ?>"></i>
    </div>
    <div><?= htmlspecialchars($flash) ?></div>
  </div>
  <?php endif; ?>

  <?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
  <div class="depo-flash error">
    <div class="depo-flash-icon"><i class="ph-bold ph-warning-octagon"></i></div>
    <div>Semua metode deposit sedang dalam pemeliharaan. Silakan hubungi layanan admin.</div>
  </div>
  <?php else: ?>

  <!-- ── 3. INTERACTIVE PAYMENT VESSEL SWITCHER (PILIH DERMAGA BAYAR) ── -->
  <div>
    <div class="depo-section-lbl">
      <span>Pilih Gerbang Pembayaran</span>
      <span class="tag">Tersedia 2 Jalur</span>
    </div>

    <div class="depo-vessels-grid">
      <?php if ($qris_enabled && !empty($qris_raw)): ?>
      <div class="depo-vessel-card depo-vessel-card--qris active" id="vessel-qris" onclick="selectVessel('qris')">
        <div class="depo-vessel-top">
          <div class="depo-vessel-icon-box">
            <img src="/assets/qris.png?v=3" alt="QRIS" style="height:22px;max-width:36px;object-fit:contain;">
          </div>
          <div class="depo-vessel-radio">
            <i class="ph-bold ph-check"></i>
          </div>
        </div>
        <div class="depo-vessel-info">
          <div class="depo-vessel-name">QRIS Kilat</div>
          <div class="depo-vessel-desc">E-Wallet &amp; M-Banking</div>
          <span class="depo-vessel-badge depo-vessel-badge--qris">
            <i class="ph-bold ph-lightning"></i> Otomatis Detik
          </span>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($bank_enabled): ?>
      <div class="depo-vessel-card depo-vessel-card--bank <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="vessel-bank" onclick="selectVessel('bank')">
        <div class="depo-vessel-top">
          <div class="depo-vessel-icon-box">
            <i class="ph-fill ph-bank" style="font-size:22px;color:#d97706;"></i>
          </div>
          <div class="depo-vessel-radio">
            <i class="ph-bold ph-check"></i>
          </div>
        </div>
        <div class="depo-vessel-info">
          <div class="depo-vessel-name">Transfer Bank</div>
          <div class="depo-vessel-desc">Bank <?= htmlspecialchars($bankName) ?></div>
          <span class="depo-vessel-badge depo-vessel-badge--bank">
            <i class="ph-bold ph-file-text"></i> Upload Struk
          </span>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 4. QUICK AMOUNT PEARL CHESTS (PILIHAN NOMINAL CUAN CEPAT) ── -->
  <div class="depo-clay depo-chips-card">
    <div class="depo-section-lbl">
      <span>Pilih Peti Nominal Cuan</span>
      <span class="tag">Sentuh untuk pilih</span>
    </div>

    <div class="depo-chips-grid">
      <?php
      $quick_amounts = [
        ['val' => 10000,  'lbl' => '10 Rb',  'tag' => 'Pemula 🐟'],
        ['val' => 25000,  'lbl' => '25 Rb',  'tag' => 'Kail Perak 🪝'],
        ['val' => 50000,  'lbl' => '50 Rb',  'tag' => 'Jaring Net 🕸️'],
        ['val' => 100000, 'lbl' => '100 Rb', 'tag' => 'Kapten 🎣'],
        ['val' => 200000, 'lbl' => '200 Rb', 'tag' => 'Peti Emas 🌟'],
        ['val' => 500000, 'lbl' => '500 Rb', 'tag' => 'Sultan 👑'],
      ];
      foreach ($quick_amounts as $qa):
      ?>
      <div class="depo-chip-btn" onclick="applyAmount(<?= $qa['val'] ?>, this)">
        <div class="depo-chip-amt"><?= $qa['lbl'] ?></div>
        <div class="depo-chip-tag"><?= $qa['tag'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Custom Amount Input -->
    <div class="depo-input-box">
      <div class="depo-input-lbl-row">
        <label class="depo-input-lbl" for="master-amount-input">Atau Masukkan Nominal Khusus:</label>
        <span style="font-size:10px;font-weight:800;color:var(--reef-muted);">Min. <?= format_rp($min_deposit) ?></span>
      </div>
      <div class="depo-input-capsule" id="master-amount-capsule">
        <span class="depo-input-prefix">Rp</span>
        <input
          type="number"
          id="master-amount-input"
          class="depo-amount-field"
          min="<?= $min_deposit ?>"
          step="any"
          placeholder="<?= number_format($min_deposit, 0, '', '') ?>"
          oninput="handleManualInput(this)"
        >
        <button type="button" class="depo-input-clear" id="master-amount-clear" onclick="clearAmount()" title="Hapus">
          <i class="ph-bold ph-x"></i>
        </button>
      </div>

      <!-- Live helper rupiah format -->
      <div class="depo-input-live-helper" id="depo-live-helper">
        <i class="ph-bold ph-check-circle"></i>
        <span id="depo-live-helper-text">Nominal: Rp <?= number_format($min_deposit, 0, ',', '.') ?></span>
      </div>

      <?php if ($u_enabled): ?>
      <div class="depo-unique-pill">
        <i class="ph-fill ph-lightning"></i>
        <span>Kode verifikasi unik <strong>+Rp <?= $unique_code ?></strong> ditambahkan otomatis ke nominal transfer.</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── 5. FORM VESSEL QRIS (JALUR KILAT OTOMATIS) ── -->
  <?php if ($qris_enabled && !empty($qris_raw)): ?>
  <div class="depo-form-wrapper active" id="form-vessel-qris">
    <div class="depo-clay depo-qris-card">
      <div class="depo-qris-header">
        <div style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:900;color:var(--reef-ink);">
          <i class="ph-fill ph-check-circle" style="color:#059669;font-size:16px;"></i>
          <span>Standar QRIS Nasional (ASPI &amp; BI)</span>
        </div>
        <img src="/assets/qris.png?v=3" alt="QRIS" class="depo-qris-logo-img">
      </div>

      <!-- Supported Wallets & M-Banking Chips -->
      <div class="depo-wallets-banner">
        <div class="depo-wallets-title">
          <i class="ph-fill ph-lightning" style="color:var(--reef-sun-500);"></i>
          <span>Mendukung Pembayaran Dari Aplikasi Manapun:</span>
        </div>
        <div class="depo-wallets-icons">
          <span class="depo-wallet-tag">DANA</span>
          <span class="depo-wallet-tag">OVO</span>
          <span class="depo-wallet-tag">GoPay</span>
          <span class="depo-wallet-tag">ShopeePay</span>
          <span class="depo-wallet-tag">BCA Mobile</span>
          <span class="depo-wallet-tag">Livin Mandiri</span>
          <span class="depo-wallet-tag">BRImo</span>
          <span class="depo-wallet-tag">BNI Mobile</span>
          <span class="depo-wallet-tag">SeaBank</span>
        </div>
      </div>

      <form method="POST" id="form-qris-submit" onsubmit="return validateDepoSubmit(event, 'qris')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_qris">
        <input type="hidden" name="amount" id="qris-hidden-amount" value="<?= (int)$min_deposit ?>">
        <?php if ($u_enabled): ?>
        <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
        <?php endif; ?>

        <button type="submit" class="depo-submit-btn depo-submit-btn--qris no-dbl-submit" id="btn-submit-qris">
          <i class="ph-bold ph-qr-code" style="font-size:19px;"></i>
          <span>Buka Barcode QRIS &amp; Bayar</span>
          <i class="ph-bold ph-arrow-right"></i>
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 6. FORM VESSEL BANK TRANSFER (REKENING & UPLOAD BUKTI) ── -->
  <?php if ($bank_enabled): ?>
  <div class="depo-form-wrapper <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="form-vessel-bank">
    <div class="depo-clay depo-bank-card">
      
      <!-- Safe Box Rekening Resmi -->
      <div class="depo-safe-box">
        <div class="depo-safe-top">
          <div class="depo-safe-bank-badge">
            <i class="ph-bold ph-bank"></i>
            <span>Bank <?= htmlspecialchars($bankName) ?></span>
          </div>
          <span style="font-size:10px;font-weight:900;color:#92400e;">Rekening Resmi</span>
        </div>
        <div class="depo-safe-num-row">
          <div class="depo-safe-num" id="bank-acc-display"><?= htmlspecialchars($bankAccount) ?></div>
          <button type="button" class="depo-safe-copy-btn" id="btn-copy-acc" onclick="copyAccNumber()">
            <i class="ph-bold ph-copy"></i>
            <span id="copy-btn-txt">Salin No. Rek</span>
          </button>
        </div>
        <div class="depo-safe-holder">
          Atas Nama: <strong><?= htmlspecialchars($bankHolder) ?></strong>
        </div>
      </div>

      <form method="POST" enctype="multipart/form-data" id="form-bank-submit" onsubmit="return validateDepoSubmit(event, 'bank')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_bank">
        <input type="hidden" name="amount" id="bank-hidden-amount" value="<?= (int)$min_deposit ?>">
        <?php if ($u_enabled): ?>
        <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
        <?php endif; ?>

        <!-- Dropzone Upload Struk -->
        <div class="depo-dropzone" id="proof-dropzone">
          <input type="file" name="proof" id="proof-file-input" class="depo-dropzone-file" accept="image/*" required onchange="handleProofSelect(this)">
          <div class="depo-dropzone-inner" id="dropzone-prompt">
            <div class="depo-dropzone-icon">
              <i class="ph-bold ph-camera"></i>
            </div>
            <div class="depo-dropzone-title">Upload Bukti Transfer Kamu</div>
            <div class="depo-dropzone-hint">Sentuh di sini untuk memilih foto struk (JPG, PNG, WEBP)</div>
          </div>

          <!-- Preview box -->
          <div class="depo-preview-box" id="proof-preview-box">
            <img src="" alt="Bukti" class="depo-preview-thumb" id="proof-preview-img">
            <div class="depo-preview-meta">
              <div class="depo-preview-name" id="proof-preview-name">foto_struk.jpg</div>
              <div class="depo-preview-size" id="proof-preview-size">0 KB</div>
            </div>
            <button type="button" class="depo-preview-del" onclick="removeProof(event)" title="Hapus foto">
              <i class="ph-bold ph-trash"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="depo-submit-btn depo-submit-btn--bank no-dbl-submit" id="btn-submit-bank">
          <i class="ph-bold ph-paper-plane-tilt" style="font-size:18px;"></i>
          <span>Kirim Bukti Pembayaran</span>
        </button>
      </form>

    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <!-- ── 7. EXPEDITION LOG (RIWAYAT TOP UP TERAKHIR) ── -->
  <?php if (!empty($deps)): ?>
  <div class="depo-hist-wrap">
    <div class="depo-hist-header">
      <div class="depo-hist-title">
        <i class="ph-fill ph-clock-counter-clockwise" style="color:var(--reef-sun-500);font-size:16px;"></i>
        <span>Riwayat Top Up Terakhir</span>
      </div>
      <a href="/history" class="depo-hist-all-link">Lihat Semua &rarr;</a>
    </div>

    <div class="depo-hist-list">
      <?php foreach ($deps as $d): ?>
        <?php
        $st = strtolower($d['status'] ?? 'pending');
        $bclass = 'error';
        if ($st === 'pending') $bclass = 'pending';
        elseif ($st === 'confirmed' || $st === 'approved') $bclass = 'confirmed';
        elseif ($st === 'rejected') $bclass = 'rejected';
        ?>
        <div class="depo-hist-card">
          <div class="depo-hist-icon <?= $d['method'] === 'qris' ? 'qris' : 'bank' ?>">
            <i class="<?= $d['method'] === 'qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
          </div>
          <div class="depo-hist-body">
            <div class="depo-hist-amt"><?= format_rp((float)$d['amount']) ?></div>
            <div class="depo-hist-time">
              <?= strtoupper($d['method']) ?> &bull; <?= date('d M Y, H:i', strtotime($d['created_at'])) ?> WIB
            </div>
          </div>
          <div class="depo-hist-status-col">
            <span class="depo-badge <?= $bclass ?>"><?= ucfirst($d['status']) ?></span>
            <?php if ($st === 'pending' && $d['method'] === 'qris'): ?>
            <a href="/pay?id=<?= $d['id'] ?>" class="depo-hist-pay-now">
              <span>Bayar</span> <i class="ph-bold ph-arrow-right"></i>
            </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
let currentVessel = '<?= ($qris_enabled && !empty($qris_raw)) ? "qris" : "bank" ?>';
const minDeposit = <?= (int)$min_deposit ?>;

function formatRp(n) {
  return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function selectVessel(vessel) {
  currentVessel = vessel;
  const cardQris = document.getElementById('vessel-qris');
  const cardBank = document.getElementById('vessel-bank');
  const formQris = document.getElementById('form-vessel-qris');
  const formBank = document.getElementById('form-vessel-bank');

  if (cardQris) cardQris.classList.toggle('active', vessel === 'qris');
  if (cardBank) cardBank.classList.toggle('active', vessel === 'bank');
  if (formQris) formQris.classList.toggle('active', vessel === 'qris');
  if (formBank) formBank.classList.toggle('active', vessel === 'bank');
}

function updateLiveHelper(val) {
  const helper = document.getElementById('depo-live-helper');
  const helperTxt = document.getElementById('depo-live-helper-text');
  if (!helper || !helperTxt) return;

  if (val >= minDeposit) {
    helper.style.color = '#059669';
    helperTxt.textContent = 'Nominal: ' + formatRp(val);
  } else {
    helper.style.color = '#dc2626';
    helperTxt.textContent = 'Kurang dari minimal (' + formatRp(minDeposit) + ')';
  }
}

function applyAmount(val, btnEl) {
  const input = document.getElementById('master-amount-input');
  const clearBtn = document.getElementById('master-amount-clear');
  input.value = val;
  if (clearBtn) clearBtn.classList.add('show');

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = val;
  if (bankHidden) bankHidden.value = val;

  updateLiveHelper(val);

  document.querySelectorAll('.depo-chip-btn').forEach(b => b.classList.remove('active'));
  if (btnEl) btnEl.classList.add('active');
}

function handleManualInput(inputEl) {
  const val = parseInt(inputEl.value.replace(/\D/g, '')) || 0;
  const clearBtn = document.getElementById('master-amount-clear');
  if (clearBtn) clearBtn.classList.toggle('show', inputEl.value.length > 0);

  document.querySelectorAll('.depo-chip-btn').forEach(b => b.classList.remove('active'));

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = val;
  if (bankHidden) bankHidden.value = val;

  updateLiveHelper(val);
}

function clearAmount() {
  const input = document.getElementById('master-amount-input');
  const clearBtn = document.getElementById('master-amount-clear');
  input.value = '';
  if (clearBtn) clearBtn.classList.remove('show');
  document.querySelectorAll('.depo-chip-btn').forEach(b => b.classList.remove('active'));

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = minDeposit;
  if (bankHidden) bankHidden.value = minDeposit;

  updateLiveHelper(minDeposit);
}

function validateDepoSubmit(e, formType) {
  const hidden = document.getElementById(formType + '-hidden-amount');
  const val = parseInt(hidden ? hidden.value : 0) || 0;
  if (val < minDeposit) {
    e.preventDefault();
    const capsule = document.getElementById('master-amount-capsule');
    const input = document.getElementById('master-amount-input');
    if (capsule) {
      capsule.style.animation = 'depoShake 0.4s ease';
      setTimeout(() => { capsule.style.animation = ''; }, 450);
    }
    if (input) input.focus();

    if (typeof nToast === 'function') {
      nToast('Minimal isi saldo adalah ' + formatRp(minDeposit), 'warning');
    } else {
      alert('Minimal isi saldo adalah ' + formatRp(minDeposit));
    }
    return false;
  }
  return true;
}

function copyAccNumber() {
  const acc = document.getElementById('bank-acc-display').textContent.trim();
  const btn = document.getElementById('btn-copy-acc');
  const txt = document.getElementById('copy-btn-txt');

  function onSuccess() {
    if (btn) btn.classList.add('copied');
    if (txt) txt.textContent = '✓ Tersalin!';
    if (typeof nToast !== 'undefined' && nToast.copy) {
      nToast.copy(acc, 'Nomor rekening ' + acc);
    } else if (typeof nToast === 'function') {
      nToast('Nomor rekening disalin: ' + acc, 'copy');
    }
    setTimeout(() => {
      if (btn) btn.classList.remove('copied');
      if (txt) txt.textContent = 'Salin No. Rek';
    }, 2200);
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(acc).then(onSuccess).catch(() => {
      fallbackCopy(acc, onSuccess);
    });
  } else {
    fallbackCopy(acc, onSuccess);
  }
}

function fallbackCopy(text, cb) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    if (cb) cb();
  } catch (e) {}
  document.body.removeChild(ta);
}

function handleProofSelect(input) {
  const file = input.files && input.files[0];
  const promptBox = document.getElementById('dropzone-prompt');
  const previewBox = document.getElementById('proof-preview-box');
  const previewImg = document.getElementById('proof-preview-img');
  const previewName = document.getElementById('proof-preview-name');
  const previewSize = document.getElementById('proof-preview-size');

  if (!file) return;

  const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!validTypes.includes(file.type)) {
    alert('Harap pilih file gambar dengan format JPG, PNG, atau WEBP.');
    input.value = '';
    return;
  }

  previewName.textContent = file.name;
  previewSize.textContent = (file.size / 1024).toFixed(1) + ' KB';

  const reader = new FileReader();
  reader.onload = function(e) {
    previewImg.src = e.target.result;
    if (promptBox) promptBox.style.display = 'none';
    if (previewBox) previewBox.style.display = 'flex';
  };
  reader.readAsDataURL(file);
}

function removeProof(e) {
  e.preventDefault();
  e.stopPropagation();
  const input = document.getElementById('proof-file-input');
  const promptBox = document.getElementById('dropzone-prompt');
  const previewBox = document.getElementById('proof-preview-box');
  const previewImg = document.getElementById('proof-preview-img');

  if (input) input.value = '';
  if (previewImg) previewImg.src = '';
  if (previewBox) previewBox.style.display = 'none';
  if (promptBox) promptBox.style.display = 'flex';
}

document.addEventListener('DOMContentLoaded', () => {
  // Preselect chip pertama jika nilainya >= minDeposit
  const chips = document.querySelectorAll('.depo-chip-btn');
  if (chips.length > 0) {
    chips[0].click();
  }

  // Handle enter key on master amount input
  const masterInput = document.getElementById('master-amount-input');
  if (masterInput) {
    masterInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (currentVessel === 'qris') {
          const btn = document.getElementById('btn-submit-qris');
          if (btn) btn.click();
        } else {
          const btn = document.getElementById('btn-submit-bank');
          if (btn) btn.click();
        }
      }
    });
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
