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
/* ══════════════════════════════════════════════
   DEPOSIT PAGE — LUXURIOUS AMBER HONEY THEME
   ══════════════════════════════════════════════ */
.dep-page {
  padding: 0 0 40px;
  max-width: 480px;
  margin: 0 auto;
}

/* ── TOP HERO BANNER ── */
.dep-top-banner {
  background: linear-gradient(135deg, #78350f 0%, #92400e 30%, #b45309 65%, #d97706 100%);
  padding: 16px 14px 28px;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 20px rgba(120,53,15,0.28);
  position: relative;
  overflow: hidden;
}
.dep-top-banner::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 20px 20px;
  background-position: 0 0, 10px 10px;
  opacity: 0.18; pointer-events: none;
}
.dep-top-nav {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.dep-back-btn {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 12px;
  width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 18px; text-decoration: none;
  box-shadow: 0 3px 0 #78350f; transition: transform 0.1s;
}
.dep-back-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.dep-title-pill {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 14px;
  padding: 6px 14px; font-size: 13px; font-weight: 900; color: #78350f;
  box-shadow: 0 3px 0 #78350f; display: flex; align-items: center; gap: 6px;
}

/* Mascot Dialogue */
.dep-mascot-row {
  display: flex; align-items: center; gap: 10px;
  background: rgba(255,255,255,0.96); border: 2.5px solid #78350f;
  border-radius: 16px; padding: 10px 12px; box-shadow: 0 3px 0 #78350f;
  position: relative; z-index: 2;
}
.dep-mascot-avatar {
  width: 44px; height: 44px; flex-shrink: 0;
  animation: buzzyBob 3s ease-in-out infinite;
}
.dep-mascot-avatar img { width: 100%; height: 100%; object-fit: contain; }
@keyframes buzzyBob {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-3px) rotate(3deg); }
}
.dep-mascot-text {
  font-size: 11px; font-weight: 800; color: #78350f; line-height: 1.35;
}
.dep-mascot-text strong { color: #b45309; }

/* ── BODY ── */
.dep-body {
  padding: 16px 14px 100px;
}

/* ── SALDO BELI CARD (HERO CARD) ── */
.dep-balance-card {
  background: linear-gradient(135deg, #ffffff 0%, #fffbeb 40%, #fef3c7 100%);
  border: 3.5px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 6px 0 #78350f, 0 12px 24px rgba(120,53,15,0.15);
  padding: 16px;
  margin-bottom: 16px;
  position: relative;
  overflow: hidden;
}
.dep-balance-card::before {
  content: ''; position: absolute; right: -15px; bottom: -15px;
  width: 90px; height: 90px; opacity: 0.12; pointer-events: none;
  background: url('/assets/game/honey_jar.png') no-repeat center center / contain;
}
.dep-bal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.dep-bal-tag {
  display: inline-flex; align-items: center; gap: 4px;
  background: #fde68a; border: 1.5px solid #78350f;
  padding: 2px 8px; border-radius: 8px; font-size: 9.5px; font-weight: 900;
  color: #78350f; text-transform: uppercase;
}
.dep-bal-status {
  font-size: 10px; font-weight: 900; color: #059669;
  display: flex; align-items: center; gap: 4px;
}
.dep-bal-main {
  font-size: 26px; font-weight: 900; color: #78350f; line-height: 1.15;
  margin-bottom: 4px; font-family: 'Nunito', sans-serif;
  display: flex; align-items: baseline; gap: 4px;
}
.dep-bal-main .curr { font-size: 16px; color: #d97706; }
.dep-bal-sub {
  font-size: 10.5px; font-weight: 800; color: #92400e;
}

/* ── ALERTS ── */
.dep-alert {
  padding: 11px 13px; border-radius: 14px; font-size: 11.5px; font-weight: 800;
  display: flex; gap: 8px; align-items: center; margin-bottom: 14px;
  border: 2.5px solid #78350f; line-height: 1.35; box-shadow: 0 3px 0 #78350f;
}
.dep-alert--err  { background: #fee2e2; color: #991b1b; border-color: #dc2626; box-shadow: 0 3px 0 #dc2626; }
.dep-alert--warn { background: #fffbeb; color: #78350f; }
.dep-alert--succ { background: #ecfdf5; color: #065f46; border-color: #059669; box-shadow: 0 3px 0 #059669; }
.dep-alert--info { background: #fef3c7; color: #78350f; }
.dep-alert-icon { font-size: 18px; flex-shrink: 0; }

/* ── METHOD TABS (CAPSULES) ── */
.dep-tabs {
  display: flex; background: #ffffff; border-radius: 16px; padding: 5px;
  margin-bottom: 16px; border: 2.5px solid #78350f; box-shadow: 0 4px 0 #78350f;
  gap: 6px;
}
.dep-tab {
  flex: 1; text-align: center; padding: 10px; font-size: 12px; font-weight: 900;
  color: #78350f; cursor: pointer; border-radius: 12px; transition: all 0.15s;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  border: 2px solid transparent;
}
.dep-tab.active {
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
  color: #78350f; border-color: #78350f; box-shadow: 0 3px 0 #78350f;
}

/* ── FORM CONTAINER ── */
.dep-form-card { display: none; }
.dep-form-card.active { display: block; animation: depFadeIn 0.25s ease; }
@keyframes depFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

/* ── INPUT AMOUNT ── */
.dep-input-grp { margin-bottom: 14px; }
.dep-input-lbl {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 11.5px; font-weight: 900; color: #78350f; margin-bottom: 6px;
}
.dep-amount-inner {
  display: flex; align-items: center; gap: 8px; background: #ffffff;
  border: 2.5px solid #78350f; border-radius: 16px; padding: 12px 14px;
  box-shadow: 0 4px 0 #78350f; transition: border-color 0.2s;
}
.dep-amount-inner:focus-within { border-color: #d97706; }
.dep-amount-prefix { font-size: 18px; font-weight: 900; color: #d97706; }
.dep-amount-input {
  width: 100%; background: transparent; border: none; font-size: 18px;
  font-weight: 900; color: #78350f; outline: none; font-family: 'Nunito', sans-serif;
}

/* ── QUICK CHIPS GRID ── */
.dep-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 16px;
}
.dep-amt-btn {
  background: #ffffff; border: 2px solid #78350f; border-radius: 12px;
  padding: 10px 4px; text-align: center; cursor: pointer;
  box-shadow: 0 3px 0 #78350f; transition: transform 0.1s, background 0.15s;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.dep-amt-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.dep-amt-btn.active {
  background: #fde68a; border-color: #78350f; box-shadow: 0 3px 0 #78350f;
  transform: translateY(1px);
}
.dep-amt-val { font-size: 11.5px; font-weight: 900; color: #78350f; }

/* ── BANK REKENING BOX ── */
.dep-rek-card {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  border: 3px solid #78350f; border-radius: 18px; padding: 14px;
  margin-bottom: 16px; box-shadow: 0 4px 0 #78350f; position: relative;
}
.dep-rek-tag {
  display: inline-flex; align-items: center; gap: 4px;
  background: #78350f; color: #fef08a; padding: 2px 7px; border-radius: 6px;
  font-size: 9px; font-weight: 900; text-transform: uppercase; margin-bottom: 6px;
}
.dep-rek-bank { font-size: 14px; font-weight: 900; color: #b45309; }
.dep-rek-num {
  font-family: monospace; font-size: 21px; font-weight: 900; color: #78350f;
  letter-spacing: 1.5px; margin: 4px 0;
}
.dep-rek-name { font-size: 11.5px; font-weight: 800; color: #92400e; margin-bottom: 10px; }
.dep-rek-copy-btn {
  width: 100%; background: #ffffff; border: 2px solid #78350f; border-radius: 12px;
  padding: 9px; font-size: 11px; font-weight: 900; color: #78350f; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  box-shadow: 0 3px 0 #78350f; transition: transform 0.1s;
}
.dep-rek-copy-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── FILE UPLOAD ── */
.dep-file-box {
  background: #ffffff; border: 2.5px dashed #d97706; border-radius: 16px;
  padding: 14px; text-align: center; margin-bottom: 16px;
}
.dep-file-lbl {
  display: block; font-size: 11.5px; font-weight: 900; color: #78350f; margin-bottom: 8px;
}
.dep-file-input {
  width: 100%; font-size: 11px; font-weight: 800; color: #78350f;
}

/* ── SUBMIT BUTTONS ── */
.dep-btn-submit {
  width: 100%; padding: 14px; border-radius: 18px; font-size: 15px; font-weight: 900;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  border: 3px solid #78350f; cursor: pointer; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.dep-btn-submit:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f !important; }
.dep-btn-submit--qris {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  color: #fff; box-shadow: 0 5px 0 #78350f; text-shadow: 0 1px 2px #78350f;
}
.dep-btn-submit--bank {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #fff; border-color: #064e3b; box-shadow: 0 5px 0 #064e3b;
  text-shadow: 0 1px 2px #064e3b;
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
  padding: 12px; box-shadow: 0 3px 0 #78350f; display: flex; align-items: center; gap: 10px;
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
.dep-badge.pending   { background: #fef3c7; color: #78350f; border-color: #f59e0b; }
.dep-badge.confirmed { background: #dcfce7; color: #14532d; border-color: #16a34a; }
.dep-badge.rejected  { background: #fee2e2; color: #7f1d1d; border-color: #dc2626; }
.dep-badge.error     { background: #fee2e2; color: #7f1d1d; border-color: #dc2626; }
</style>

<div class="dep-page">
  <!-- TOP HERO BANNER -->
  <div class="dep-top-banner">
    <div class="dep-top-nav">
      <a href="/home" class="dep-back-btn" title="Kembali ke Beranda">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="dep-title-pill">
        <i class="ph-fill ph-wallet" style="color:#d97706;"></i>
        <span>Isi Saldo Beli</span>
      </div>
      <div style="width:38px;"></div> <!-- Spacer -->
    </div>

    <!-- Dialogue -->
    <div class="dep-mascot-row">
      <div class="dep-mascot-avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;border-radius:12px;color:#fff;font-size:20px;">
        <i class="ph-fill ph-wallet"></i>
      </div>
      <div class="dep-mascot-text">
        Halo! Isi saldo beli kamu dengan cepat dan aman. Saldo ini dapat digunakan untuk <strong>upgrade keanggotaan VIP &amp; paket investasi</strong>!
      </div>
    </div>
  </div>

  <div class="dep-body">
    <!-- SALDO BELI CARD (HERO DISPLAY) -->
    <div class="dep-balance-card">
      <div class="dep-bal-header">
        <span class="dep-bal-tag"><i class="ph-fill ph-wallet" style="color:#d97706;"></i> Dompet Pembelian</span>
        <span class="dep-bal-status"><i class="ph-fill ph-shield-check"></i> Aktif</span>
      </div>
      <div class="dep-bal-main">
        <span class="curr">Rp</span>
        <span><?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></span>
      </div>
      <div class="dep-bal-sub">
        Saldo Beli dapat digunakan untuk belanja sarang dan booster peternakan.
      </div>
    </div>

    <!-- MINIMUM DEPOSIT ALERT -->
    <div class="dep-alert dep-alert--info">
      <div class="dep-alert-icon"><i class="ph-fill ph-lightbulb" style="color:#d97706;font-size:18px;"></i></div>
      <div style="flex:1;">
        Minimal top up adalah <strong><?= format_rp($min_deposit) ?></strong>. Saldo diproses cepat & aman.
      </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if ($flash): ?>
    <div class="dep-alert dep-alert--<?= $flashType === 'error' ? 'err' : 'succ' ?>">
      <div class="dep-alert-icon">
        <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:18px;"></i>
      </div>
      <div style="flex:1;"><?= htmlspecialchars($flash) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
    <div class="dep-alert dep-alert--err">
      <div class="dep-alert-icon"><i class="ph-bold ph-warning-circle" style="font-size:18px;"></i></div>
      <div style="flex:1;">Tidak ada metode deposit yang aktif saat ini. Silakan hubungi admin.</div>
    </div>
    <?php else: ?>

    <!-- METHOD TABS -->
    <div class="dep-tabs">
      <?php if ($qris_enabled && !empty($qris_raw)): ?>
      <div class="dep-tab" id="tab-qris" onclick="switchForm('qris')">
        <img src="/assets/qris.png?v=3" alt="QRIS" style="height:26px;object-fit:contain;">
        <span>QRIS Otomatis</span>
      </div>
      <?php endif; ?>
      <?php if ($bank_enabled): ?>
      <div class="dep-tab" id="tab-bank" onclick="switchForm('bank')">
        <i class="ph-bold ph-bank" style="font-size:16px;"></i>
        <span>Transfer Bank</span>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <!-- ── QRIS FORM ── -->
    <div class="dep-form-card" id="form-qris">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_qris">

        <!-- Official QRIS Header Badge -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;background:#fdfaf6;border:1.5px solid #fde68a;border-radius:12px;padding:8px 14px;min-height:48px;">
          <div style="font-size:11.5px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:6px;">
            <i class="ph-fill ph-shield-check" style="color:#059669;font-size:16px;"></i>
            <span>Standar Pembayaran Nasional</span>
          </div>
          <img src="/assets/qris.png?v=3" alt="QRIS Resmi" style="height:34px;max-width:110px;object-fit:contain;">
        </div>

        <div class="dep-input-grp">
          <div class="dep-input-lbl">
            <span>Nominal Top Up</span>
            <span style="font-size:10px;color:#92400e;">Min. <?= format_rp($min_deposit) ?></span>
          </div>
          <div class="dep-amount-inner">
            <span class="dep-amount-prefix">Rp</span>
            <input class="dep-amount-input" id="qris-amount" type="number" name="amount" min="<?= $min_deposit ?>" step="any" placeholder="<?= number_format($min_deposit,0,'','') ?>" required>
          </div>
        </div>

        <!-- Quick Amount Chips -->
        <div class="dep-grid">
          <?php foreach ([10000, 25000, 50000, 100000, 200000, 500000] as $q): ?>
          <div class="dep-amt-btn" onclick="setAmt('qris', <?= $q ?>, this)">
            <div class="dep-amt-val"><?= format_rp($q) ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($u_enabled): ?>
        <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
        <?php endif; ?>

        <div class="dep-alert dep-alert--warn" style="margin-bottom:16px;">
          <div class="dep-alert-icon"><i class="ph-fill ph-lightning" style="color:#d97706;font-size:18px;"></i></div>
          <div style="flex:1;">
            Dukung semua e-wallet (DANA, OVO, GoPay, ShopeePay) & M-Banking. Pembayaran terverifikasi otomatis dalam hitungan detik!
          </div>
        </div>

        <button type="submit" class="dep-btn-submit dep-btn-submit--qris no-dbl-submit">
          <img src="/assets/qris.png?v=3" alt="QRIS" style="height:26px;filter:brightness(0) invert(1);margin-right:6px;object-fit:contain;">
          <span>Lanjut Bayar dengan QRIS</span>
          <i class="ph-bold ph-arrow-right"></i>
        </button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ($bank_enabled): ?>
    <!-- ── BANK TRANSFER FORM ── -->
    <div class="dep-form-card" id="form-bank">
      <!-- Rekening Tujuan Box -->
      <div class="dep-rek-card">
        <div class="dep-rek-tag"><i class="ph-bold ph-bank"></i> Rekening Resmi MancingCuan</div>
        <div class="dep-rek-bank">Bank <?= htmlspecialchars($bankName) ?></div>
        <div class="dep-rek-num" id="rek-num"><?= htmlspecialchars($bankAccount) ?></div>
        <div class="dep-rek-name">Atas Nama: <?= htmlspecialchars($bankHolder) ?></div>
        <button type="button" class="dep-rek-copy-btn" onclick="copyRek()">
          <i class="ph-bold ph-copy"></i>
          <span>Salin Nomor Rekening</span>
        </button>
      </div>

      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_bank">

        <div class="dep-input-grp">
          <div class="dep-input-lbl">
            <span>Nominal Ditransfer</span>
            <span style="font-size:10px;color:#92400e;">Min. <?= format_rp($min_deposit) ?></span>
          </div>
          <div class="dep-amount-inner">
            <span class="dep-amount-prefix">Rp</span>
            <input class="dep-amount-input" id="bank-amount" type="number" name="amount" min="<?= $min_deposit ?>" step="any" placeholder="<?= number_format($min_deposit,0,'','') ?>" required>
          </div>
        </div>

        <!-- Quick Amount Chips -->
        <div class="dep-grid">
          <?php foreach ([10000, 25000, 50000, 100000, 200000, 500000] as $q): ?>
          <div class="dep-amt-btn" onclick="setAmt('bank', <?= $q ?>, this)">
            <div class="dep-amt-val"><?= format_rp($q) ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if ($u_enabled): ?>
        <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
        <?php endif; ?>

        <!-- Bukti Transfer Upload Box -->
        <div class="dep-file-box">
          <label class="dep-file-lbl">
            <i class="ph-bold ph-camera" style="font-size:16px;color:#d97706;"></i>
            Upload Bukti Transfer (JPG / PNG / WEBP)
          </label>
          <input class="dep-file-input" type="file" name="proof" accept="image/*" required>
        </div>

        <button type="submit" class="dep-btn-submit dep-btn-submit--bank no-dbl-submit">
          <i class="ph-bold ph-paper-plane-tilt" style="font-size:18px;"></i>
          <span>Kirim Bukti Transfer</span>
        </button>
      </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ── RIWAYAT TOP UP ── -->
    <?php if (!empty($deps)): ?>
    <div class="hist-wrap">
      <div class="hist-head">
        <h3><i class="ph-fill ph-clock-counter-clockwise" style="color:#d97706;"></i> Riwayat Top Up Terakhir</h3>
        <a href="/history">Lihat Semua →</a>
      </div>
      <div class="hist-list">
        <?php foreach ($deps as $d): ?>
          <?php
          $st = strtolower($d['status']);
          $bclass = 'error';
          if ($st === 'pending') $bclass = 'pending';
          elseif ($st === 'confirmed' || $st === 'approved') $bclass = 'confirmed';
          elseif ($st === 'rejected') $bclass = 'rejected';
          ?>
          <div class="hist-card">
            <div class="hist-card-icon" style="background:<?= $d['method']==='qris'?'#dcfce7':'#fef3c7' ?>;color:<?= $d['method']==='qris'?'#15803d':'#b45309' ?>;border-color:#78350f;">
              <i class="<?= $d['method']==='qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
            </div>
            <div class="hist-card-body">
              <div class="hist-card-amt"><?= format_rp((float)$d['amount']) ?></div>
              <div class="hist-card-date"><?= strtoupper($d['method']) ?> · <?= date('d M Y, H:i', strtotime($d['created_at'])) ?> WIB</div>
            </div>
            <div class="hist-card-right">
              <span class="hist-badge dep-badge <?= $bclass ?>"><?= ucfirst($d['status']) ?></span>
              <?php if ($st === 'pending' && $d['method'] === 'qris'): ?>
              <a href="/pay?id=<?= $d['id'] ?>" style="display:inline-flex;align-items:center;gap:3px;margin-top:4px;font-size:10px;font-weight:900;color:#fff;background:#0284c7;padding:4px 8px;border-radius:8px;text-decoration:none;border:1.5px solid #78350f;box-shadow:0 2px 0 #78350f;">
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
</div>

<script>
function switchForm(id) {
  const tabs = document.querySelectorAll('.dep-tab');
  const forms = document.querySelectorAll('.dep-form-card');
  tabs.forEach(t => t.classList.remove('active'));
  forms.forEach(f => f.classList.remove('active'));

  const targetTab = document.getElementById('tab-' + id);
  const targetForm = document.getElementById('form-' + id);

  if (targetTab) targetTab.classList.add('active');
  if (targetForm) targetForm.classList.add('active');
}

function setAmt(type, v, btn) {
  if (type === 'bank') {
    document.getElementById('bank-amount').value = v;
    const btns = document.querySelectorAll('#form-bank .dep-amt-btn');
    btns.forEach(b => b.classList.remove('active'));
  }
  if (type === 'qris') {
    document.getElementById('qris-amount').value = v;
    const btns = document.querySelectorAll('#form-qris .dep-amt-btn');
    btns.forEach(b => b.classList.remove('active'));
  }
  if (btn) btn.classList.add('active');
}

function copyRek() {
  const t = document.getElementById('rek-num').textContent.trim();
  if (typeof nToast !== 'undefined' && nToast.copy) {
    nToast.copy(t, 'Nomor rekening');
  } else {
    navigator.clipboard.writeText(t).then(() => {
      if (typeof nToast === 'function') nToast('Nomor rekening disalin: ' + t, 'copy');
    });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const tabQris = document.getElementById('tab-qris');
  const tabBank = document.getElementById('tab-bank');
  if (tabQris) {
    switchForm('qris');
  } else if (tabBank) {
    switchForm('bank');
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
