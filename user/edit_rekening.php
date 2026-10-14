<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Fetch membership info including allow_edit_bank
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=? AND is_active=1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$can_edit_bank     = (bool)($user_mem['allow_edit_bank'] ?? 0);
$level_name        = $user_mem['name'] ?? 'Free';

// Promotor bypass: skip semua pembatasan level
$is_promotor = ((int)($user['is_promotor'] ?? 0) === 1);
if ($is_promotor) {
    $can_edit_bank   = true;
}

$min_saldo_edit = (float)($user['edit_bank_deposit_min'] ?? 0);
if ($min_saldo_edit <= 0) {
    $min_saldo_edit = 50000;
}
$has_enough_balance = $is_promotor || ((float)$user['balance_dep'] >= $min_saldo_edit);

$flash = $flashType = '';

// Cek apakah ada request ganti rekening yang masih pending
$stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPending->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPending->fetchColumn();

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($has_pending_bank) {
        $flash = 'Data pengajuan ganti rekening kamu sebelumnya masih dalam proses verifikasi admin.'; 
        $flashType = 'warn';
    } elseif (!$can_edit_bank) {
        $flash = 'Level akun kamu belum memiliki izin untuk mengubah data rekening.'; 
        $flashType = 'error';
    } elseif (!$has_enough_balance) {
        $flash = 'Kamu harus memiliki Saldo Beli minimal ' . format_rp($min_saldo_edit) . ' yang mengendap untuk mengubah rekening.'; 
        $flashType = 'error';
    } else {
        $new_bank    = trim($_POST['bank_name']      ?? '');
        $new_accnum  = trim($_POST['account_number'] ?? '');
        $new_accname = trim($_POST['account_name']   ?? '');

        if (!$new_bank || !$new_accnum || !$new_accname) {
            $flash = 'Semua kolom formulir wajib diisi dengan lengkap.'; 
            $flashType = 'error';
        } else {
            $payload = json_encode(['bank_name' => $new_bank, 'account_number' => $new_accnum, 'account_name' => $new_accname]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'change_bank', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "🏦 <b>REQUEST GANTI REKENING</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💳 Rekening Baru:\n";
            $msg .= "- Bank: <b>{$new_bank}</b>\n";
            $msg .= "- No. Rek: <code>{$new_accnum}</code>\n";
            $msg .= "- A.N: <b>{$new_accname}</b>\n";
            $kb = [
                [['text'=>'Approve', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'permintaan');
            
            $flash = 'Pengajuan perubahan rekening baru berhasil dikirim dan sedang diverifikasi admin!';
            $flashType = 'success';
            $has_pending_bank = true;
        }
    }
}

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

// Load available payment channels
$channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
}
$banks    = array_filter($channels, fn($c) => $c['type'] === 'bank');
$ewallets = array_filter($channels, fn($c) => $c['type'] === 'ewallet');

$pageTitle  = 'Edit Rekening';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   EDIT REKENING — MANCINGCUAN CASUAL THEME
   ══════════════════════════════════════════════ */
body {
  background: #fef8ee !important;
  font-family: 'Nunito', sans-serif;
  color: #1e293b;
  margin: 0;
  padding-bottom: 96px;
}

.rek-page-wrap {
  min-height: 100vh;
  max-width: 480px;
  margin: 0 auto;
}

/* ── TOP HERO BANNER (WARM HONEY AMBER) ── */
.rek-top-banner {
  background: linear-gradient(180deg, #78350f 0%, #92400e 30%, #b45309 65%, #d97706 100%);
  padding: 16px 14px 32px;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 8px 24px rgba(120,53,15,0.28);
  position: relative;
  overflow: hidden;
}
.rek-top-banner::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 20px 20px;
  background-position: 0 0, 10px 10px;
  opacity: 0.16; pointer-events: none;
}

.rek-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  position: relative;
  z-index: 2;
}

.rek-back-btn {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  width: 38px;
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #78350f;
  font-size: 18px;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s;
}
.rek-back-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}

.rek-title-pill {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 900;
  color: #78350f;
  box-shadow: 0 3px 0 #78350f;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Mascot Speech Box */
.rek-notice-box {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255,255,255,0.96);
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 10px 14px;
  box-shadow: 0 3.5px 0 #78350f;
  position: relative;
  z-index: 2;
}
.rek-notice-avatar {
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  animation: beeFloat 3s ease-in-out infinite;
}
.rek-notice-avatar img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
@keyframes beeFloat {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-3px) rotate(4deg); }
}
.rek-notice-text {
  font-size: 11.5px;
  font-weight: 800;
  color: #78350f;
  line-height: 1.4;
}

/* ── BODY CONTENT ── */
.rek-body {
  padding: 16px 14px;
}

/* ── REKENING AKTIF CARD ── */
.rek-card-active {
  background: linear-gradient(135deg, #ffffff 0%, #fffbeb 50%, #fef3c7 100%);
  border: 2.5px solid #d97706;
  border-radius: 18px;
  padding: 14px 16px;
  box-shadow: 0 4.5px 0 #78350f, 0 10px 20px rgba(120,53,15,0.1);
  margin-bottom: 16px;
  position: relative;
}
.rek-card-active__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.rek-card-active__lbl {
  font-size: 11px;
  font-weight: 900;
  color: #92400e;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.rek-card-active__badge {
  background: #ecfdf5;
  border: 1px solid #10b981;
  color: #065f46;
  font-size: 10px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.rek-card-active__body {
  display: flex;
  align-items: center;
  gap: 12px;
}
.rek-logo-box {
  width: 52px;
  height: 52px;
  background: #ffffff;
  border: 2px solid #fed7aa;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 6px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.06);
  flex-shrink: 0;
}
.rek-logo-box img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}
.rek-num-text {
  font-size: 18px;
  font-weight: 900;
  color: #78350f;
  letter-spacing: 0.5px;
  font-family: inherit;
}
.rek-name-text {
  font-size: 12.5px;
  font-weight: 800;
  color: #92400e;
  margin-top: 2px;
}

/* ── ALERTS (BEE STYLED) ── */
.rek-alert {
  padding: 12px 14px;
  border-radius: 14px;
  font-size: 12px;
  font-weight: 800;
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 14px;
  border: 2px solid;
  line-height: 1.4;
  box-shadow: 0 3px 0 rgba(0,0,0,0.06);
}
.rek-alert--err {
  background: #fef2f2;
  color: #991b1b;
  border-color: #fca5a5;
}
.rek-alert--warn {
  background: #fffbeb;
  color: #92400e;
  border-color: #f59e0b;
}
.rek-alert--succ {
  background: #ecfdf5;
  color: #065f46;
  border-color: #34d399;
}
.rek-alert-icon {
  font-size: 20px;
  flex-shrink: 0;
}

/* ── FORM CONTAINER ── */
.rek-form-card {
  background: #ffffff;
  border: 2.5px solid #d97706;
  border-radius: 20px;
  padding: 18px 16px;
  box-shadow: 0 4.5px 0 #78350f, 0 10px 20px rgba(120,53,15,0.08);
}
.rek-form-title {
  font-size: 14px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 6px;
  border-bottom: 2px dashed #fed7aa;
  padding-bottom: 10px;
}

.rek-input-grp {
  margin-bottom: 14px;
}
.rek-input-grp label {
  display: block;
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 6px;
  padding-left: 2px;
}
.rek-input-grp input, .custom-select-trigger {
  width: 100%;
  box-sizing: border-box;
  background: #fffbeb;
  border: 2px solid #f59e0b;
  border-radius: 12px;
  padding: 12px 14px;
  font-size: 13.5px;
  font-weight: 800;
  color: #78350f;
  outline: none;
  font-family: inherit;
  transition: all 0.15s ease;
  box-shadow: 0 2px 0 #d97706;
}
.rek-input-grp input::placeholder {
  color: #b45309;
  opacity: 0.6;
}
.rek-input-grp input:focus, .custom-select-trigger.open {
  border-color: #b45309;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.25);
}

/* Custom Select Styling */
.custom-select-wrap { position: relative; width: 100%; }
.custom-select-trigger { display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
.sel-val { display: flex; align-items: center; gap: 8px; }
.sel-val img, .custom-option img { height: 20px; width: auto; border-radius: 4px; object-fit: contain; }
.custom-select-options {
  position: absolute; top: calc(100% + 4px); left: 0; width: 100%;
  background: #ffffff; border: 2.5px solid #d97706; border-radius: 14px;
  box-shadow: 0 8px 24px rgba(120,53,15,0.2); z-index: 100;
  max-height: 220px; overflow-y: auto; display: none; padding: 6px;
}
.custom-select-options.open { display: block; }
.custom-optgroup {
  font-size: 11px; font-weight: 900; color: #b45309; padding: 8px 8px 4px;
  text-transform: uppercase; border-bottom: 2px dashed #fde68a; margin-bottom: 4px;
}
.custom-option {
  padding: 9px 12px; font-size: 13px; font-weight: 800; color: #78350f;
  border-radius: 10px; cursor: pointer; display: flex; align-items: center; gap: 8px;
}
.custom-option:hover { background: #fef3c7; color: #92400e; }

/* ── SUBMIT BUTTON ── */
.rek-submit-btn {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: none;
  border-radius: 14px;
  padding: 14px;
  font-size: 15px;
  font-weight: 900;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: 0 4.5px 0 #78350f, 0 8px 16px rgba(217, 119, 6, 0.3);
  cursor: pointer;
  transition: all 0.1s ease;
  margin-top: 10px;
}
.rek-submit-btn:hover {
  filter: brightness(1.03);
}
.rek-submit-btn:active:not(:disabled) {
  transform: translateY(3px);
  box-shadow: 0 1.5px 0 #78350f;
}
.rek-submit-btn:disabled {
  background: #cbd5e1;
  color: #64748b;
  box-shadow: none;
  cursor: not-allowed;
  transform: none;
}

/* ── CONFIRM MODAL (BEE THEMED) ── */
#cg-modal {
  display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65);
  z-index: 99999; align-items: center; justify-content: center; padding: 16px;
  backdrop-filter: blur(4px);
}
.cg-modal-box {
  background: #ffffff; width: 100%; max-width: 340px; border-radius: 20px;
  border: 3.5px solid #d97706; box-shadow: 0 8px 0 #78350f;
  animation: popIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); overflow: hidden;
}
.cg-modal-hdr {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  padding: 14px 16px; text-align: center; color: #78350f;
  font-weight: 900; font-size: 15px; border-bottom: 2px solid #fed7aa;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.cg-modal-bd { padding: 16px; text-align: left; }
.cg-modal-actions { display: flex; gap: 10px; padding: 0 16px 16px; }
.cg-btn-cancel {
  flex: 1; padding: 11px; background: #f1f5f9; border: 2px solid #cbd5e1;
  border-radius: 12px; font-weight: 800; color: #475569; font-size: 13px;
  box-shadow: 0 3px 0 #94a3b8; cursor: pointer;
}
.cg-btn-cancel:active { transform: translateY(2px); box-shadow: none; }
.cg-btn-confirm {
  flex: 1.5; padding: 11px; background: linear-gradient(135deg, #f59e0b, #d97706);
  border: none; border-radius: 12px; font-weight: 900; color: #fff;
  box-shadow: 0 3px 0 #78350f; font-size: 13px; cursor: pointer;
}
.cg-btn-confirm:active { transform: translateY(2px); box-shadow: none; }
@keyframes popIn { from { transform: scale(0.85); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>

<div class="rek-page-wrap">
  
  <!-- TOP BANNER -->
  <div class="rek-top-banner">
    <div class="rek-top-nav">
      <a href="/profile" class="rek-back-btn" title="Kembali ke Profil">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="rek-title-pill">
        <i class="ph-fill ph-bank" style="color:#d97706;"></i>
        <span>Rekening Penarikan</span>
      </div>
      <div style="width:38px;"></div> <!-- spacer -->
    </div>

    <!-- Notice Dialogue -->
    <div class="rek-notice-box">
      <div class="rek-notice-avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;border-radius:12px;color:#fff;font-size:20px;">
        <i class="ph-fill ph-bank"></i>
      </div>
      <div class="rek-notice-text">
        Pastikan nomor rekening &amp; e-wallet sudah sesuai atas nama kamu agar penarikan saldo cuan berjalan lancar tanpa kendala!
      </div>
    </div>
  </div>

  <div class="rek-body">

    <!-- REKENING AKTIF SAAT INI -->
    <div class="rek-card-active">
      <div class="rek-card-active__head">
        <div class="rek-card-active__lbl">
          <i class="ph-bold ph-credit-card"></i> Rekening Aktif
        </div>
        <?php if ($has_bank): ?>
          <span class="rek-card-active__badge">
            <i class="ph-bold ph-shield-check"></i> Terhubung
          </span>
        <?php else: ?>
          <span class="badge bg-secondary small">Belum Diatur</span>
        <?php endif; ?>
      </div>

      <div class="rek-card-active__body">
        <div class="rek-logo-box">
          <?php 
            $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; 
          ?>
          <?php if ($user_wl): ?>
            <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars((string)$user['bank_name']) ?>">
          <?php else: ?>
            <i class="ph-fill ph-bank" style="font-size:26px;color:#d97706;"></i>
          <?php endif; ?>
        </div>
        <div>
          <?php if ($has_bank): ?>
            <div class="rek-num-text">
              <?= htmlspecialchars((string)$user['bank_name']) ?> &bull; <?= htmlspecialchars(mask_account($user['account_number'])) ?>
            </div>
            <div class="rek-name-text">
              <i class="ph-bold ph-user" style="font-size:11px;"></i> a.n. <?= htmlspecialchars((string)$user['account_name']) ?>
            </div>
          <?php else: ?>
            <div class="rek-num-text" style="font-size:15px;color:#64748b;">
              Belum ada data rekening terdaftar
            </div>
            <div class="rek-name-text" style="color:#94a3b8;">
              Silakan isi formulir di bawah untuk mendaftarkan rekening.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- FLASH MESSAGE -->
    <?php if ($flash): ?>
      <div class="rek-alert rek-alert--<?= $flashType === 'error' ? 'err' : ($flashType === 'warn' ? 'warn' : 'succ') ?>">
        <div class="rek-alert-icon">
          <i class="ph-bold <?= $flashType === 'error' ? 'ph-warning-circle' : ($flashType === 'warn' ? 'ph-clock' : 'ph-check-circle') ?>"></i>
        </div>
        <div style="flex:1"><?= htmlspecialchars($flash) ?></div>
      </div>
    <?php endif; ?>

    <!-- STATUS & CONDITIONS -->
    <?php if ($has_pending_bank): ?>
      <div class="rek-alert rek-alert--warn">
        <div class="rek-alert-icon">
          <i class="ph-bold ph-hourglass-high"></i>
        </div>
        <div style="flex:1">
          <div style="font-weight:900;margin-bottom:2px;">Verifikasi Sedang Berlangsung</div>
          <div style="font-size:11px;font-weight:600;">Pengajuan rekening baru kamu sedang diverifikasi oleh Tim Admin. Mohon menunggu beberapa saat.</div>
        </div>
      </div>
    <?php elseif (!$can_edit_bank): ?>
      <div class="rek-alert rek-alert--err" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div class="rek-alert-icon">
            <i class="ph-bold ph-lock-key"></i>
          </div>
          <div>
            <div style="font-weight:900;margin-bottom:2px;">Akses Ganti Rekening Terkunci</div>
            <div style="font-size:10.5px;font-weight:600;">Level <?= htmlspecialchars($level_name) ?> belum memiliki izin ganti rekening.</div>
          </div>
        </div>
        <a href="/upgrade" class="btn btn-sm btn-warning fw-bold d-inline-flex align-items-center gap-1" style="border-radius:10px;padding:6px 12px;font-size:11.5px;color:#78350f;flex-shrink:0;">
          <i class="ph-fill ph-crown"></i> Upgrade
        </a>
      </div>
    <?php else: ?>

      <!-- PERSYARATAN SALDO MENGENDAP -->
      <?php if (!$has_enough_balance): ?>
        <?php
           $pct = $min_saldo_edit > 0 ? ((float)$user['balance_dep'] / $min_saldo_edit) * 100 : 0;
           $pct = min(100, max(0, $pct));
        ?>
        <div class="rek-card-active" style="border-color:#f59e0b;background:#fffbeb;margin-bottom:16px;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-bold d-flex align-items-center gap-2" style="color:#78350f;font-size:12.5px;">
              <i class="ph-bold ph-vault text-warning fs-5"></i> Syarat Saldo Beli Mengendap
            </div>
            <a href="/deposit" class="btn btn-sm btn-warning fw-bold d-inline-flex align-items-center gap-1" style="border-radius:8px;padding:4px 10px;font-size:11px;color:#78350f;">
              <i class="ph-bold ph-wallet"></i> Top Up Saldo
            </a>
          </div>
          <div style="font-size:11.5px;color:#92400e;line-height:1.4;margin-bottom:10px;">
            Untuk keamanan akun, perubahan rekening memerlukan Saldo Beli minimal <strong><?= format_rp($min_saldo_edit) ?></strong> yang mengendap di akunmu.
          </div>

          <!-- Progress Bar -->
          <div style="background:rgba(0,0,0,0.06); border-radius:10px; height:12px; overflow:hidden; border: 1px solid rgba(0,0,0,0.05); position:relative;">
            <div style="background:linear-gradient(90deg, #f59e0b, #10b981); height:100%; width:<?= $pct ?>%; transition:width 0.5s;"></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:10.5px; font-weight:800; color:#b45309; margin-top:5px;">
             <span>Saldo Kamu: <?= format_rp((float)$user['balance_dep']) ?></span>
             <span>Syarat: <?= format_rp($min_saldo_edit) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <!-- FORM PENGAJUAN REKENING BARU -->
      <?php if ($has_enough_balance): ?>
        <div class="rek-form-card">
          <div class="rek-form-title">
            <i class="ph-bold ph-pencil-simple-line text-warning fs-5"></i>
            <span>Formulir Pengajuan Rekening Baru</span>
          </div>

          <form method="POST" id="edit-rek-form">
            <?= csrf_field() ?>
            
            <div class="rek-input-grp">
              <label><i class="ph-bold ph-bank"></i> Pilih Bank atau E-Wallet Baru</label>
              <select class="custom-logo-select" name="bank_name" id="bank_name" required>
                <option value="" data-logo="">— Pilih Tujuan —</option>
                <?php if (!empty($banks)): ?>
                  <optgroup label="Transfer Bank">
                    <?php foreach($banks as $b): ?>
                      <option value="<?= htmlspecialchars($b['name']) ?>" data-logo="<?= htmlspecialchars($b['logo'] ?? '') ?>">
                        <?= htmlspecialchars($b['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
                <?php if (!empty($ewallets)): ?>
                  <optgroup label="E-Wallet">
                    <?php foreach($ewallets as $e): ?>
                      <option value="<?= htmlspecialchars($e['name']) ?>" data-logo="<?= htmlspecialchars($e['logo'] ?? '') ?>">
                        <?= htmlspecialchars($e['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              </select>
            </div>

            <div class="rek-input-grp">
              <label><i class="ph-bold ph-hash"></i> Nomor Rekening / No. HP E-Wallet</label>
              <input type="text" name="account_number" id="account_number" placeholder="Contoh: 0812xxxx / 512xxxx" required autocomplete="off">
            </div>
            
            <div class="rek-input-grp">
              <label><i class="ph-bold ph-user"></i> Nama Pemilik Sesuai Rekening</label>
              <input type="text" name="account_name" id="account_name" placeholder="Contoh: Budi Santoso" required autocomplete="off">
            </div>

            <button type="button" class="rek-submit-btn" onclick="showConfirm()">
              <i class="ph-bold ph-paper-plane-tilt" style="font-size:18px;"></i>
              <span>Ajukan Perubahan Rekening</span>
            </button>
          </form>
        </div>
      <?php endif; ?>

    <?php endif; ?>

  </div>
</div>

<!-- CONFIRM MODAL (BEE STYLE) -->
<div id="cg-modal" class="cg-modal">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">
      <i class="ph-bold ph-shield-check text-warning"></i>
      <span>Konfirmasi Perubahan Rekening</span>
    </div>
    <div class="cg-modal-bd">
      <div style="font-size:13px;font-weight:800;color:#78350f;margin-bottom:10px;line-height:1.4">
        Pastikan rincian data rekening baru sudah benar:
      </div>
      
      <div style="background:#fffbeb;border:1.5px solid #fed7aa;border-radius:12px;padding:12px;margin-bottom:12px;font-size:12px;">
        <div class="d-flex justify-content-between mb-1">
          <span style="color:#92400e;">Bank / E-Wallet:</span>
          <strong style="color:#78350f;" id="m-bank">-</strong>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span style="color:#92400e;">Nomor Rekening:</span>
          <strong style="color:#78350f;" id="m-accnum">-</strong>
        </div>
        <div class="d-flex justify-content-between">
          <span style="color:#92400e;">Nama Pemilik:</span>
          <strong style="color:#78350f;" id="m-accname">-</strong>
        </div>
      </div>

      <div style="font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;padding:10px;border-radius:10px;border:1px dashed #f59e0b;line-height:1.4;">
        <i class="ph-bold ph-info"></i> Pengajuan yang dikirim akan diverifikasi oleh Admin demi keamanan akun dan pencegahan salah transfer.
      </div>
    </div>
    <div class="cg-modal-actions">
      <button class="cg-btn-cancel" onclick="closeConfirm()">Batal</button>
      <button class="cg-btn-confirm" onclick="submitForm()">Ya, Kirim!</button>
    </div>
  </div>
</div>

<script>
// Custom Select Logic
document.addEventListener('DOMContentLoaded', () => {
    const selects = document.querySelectorAll('.custom-logo-select');
    selects.forEach(select => {
        const wrapper = document.createElement('div');
        wrapper.className = 'custom-select-wrap';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.style.display = 'none';

        const trigger = document.createElement('div');
        trigger.className = 'custom-select-trigger';
        trigger.innerHTML = '<div class="sel-val">— Pilih Bank / E-Wallet —</div><i class="ph-bold ph-caret-down text-warning"></i>';
        
        const optionsContainer = document.createElement('div');
        optionsContainer.className = 'custom-select-options';

        wrapper.appendChild(trigger);
        wrapper.appendChild(optionsContainer);

        Array.from(select.children).forEach(child => {
            if (child.tagName === 'OPTGROUP') {
                const groupLabel = document.createElement('div');
                groupLabel.className = 'custom-optgroup';
                groupLabel.textContent = child.label;
                optionsContainer.appendChild(groupLabel);
                
                Array.from(child.children).forEach(opt => {
                    const optDiv = document.createElement('div');
                    optDiv.className = 'custom-option';
                    optDiv.dataset.value = opt.value;
                    const logo = opt.dataset.logo;
                    if (logo) {
                        optDiv.innerHTML = `<img src="/assets/banks/${logo}" alt="${opt.value}"> <span>${opt.value}</span>`;
                    } else {
                        optDiv.innerHTML = `<span>${opt.value}</span>`;
                    }
                    optDiv.addEventListener('click', () => {
                        select.value = opt.value;
                        trigger.querySelector('.sel-val').innerHTML = optDiv.innerHTML;
                        optionsContainer.classList.remove('open');
                        trigger.classList.remove('open');
                    });
                    optionsContainer.appendChild(optDiv);
                });
            } else {
                if(child.value === '') return;
                const optDiv = document.createElement('div');
                optDiv.className = 'custom-option';
                optDiv.dataset.value = child.value;
                const logo = child.dataset.logo;
                if (logo) {
                    optDiv.innerHTML = `<img src="/assets/banks/${logo}" alt="${child.value}"> <span>${child.value}</span>`;
                } else {
                    optDiv.innerHTML = `<span>${child.value}</span>`;
                }
                optDiv.addEventListener('click', () => {
                    select.value = child.value;
                    trigger.querySelector('.sel-val').innerHTML = optDiv.innerHTML;
                    optionsContainer.classList.remove('open');
                    trigger.classList.remove('open');
                });
                optionsContainer.appendChild(optDiv);
            }
        });

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = optionsContainer.classList.contains('open');
            document.querySelectorAll('.custom-select-options').forEach(o => o.classList.remove('open'));
            document.querySelectorAll('.custom-select-trigger').forEach(t => t.classList.remove('open'));
            if (!isOpen) {
                optionsContainer.classList.add('open');
                trigger.classList.add('open');
            }
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.custom-select-options').forEach(o => o.classList.remove('open'));
        document.querySelectorAll('.custom-select-trigger').forEach(t => t.classList.remove('open'));
    });
});

function showConfirm() {
    const f = document.getElementById('edit-rek-form');
    if (!f.reportValidity()) return;
    
    const bankVal = f.querySelector('select[name="bank_name"]').value;
    const numVal  = f.querySelector('input[name="account_number"]').value.trim();
    const nameVal = f.querySelector('input[name="account_name"]').value.trim();

    if (!bankVal) {
        alert('Mohon pilih Bank atau E-Wallet tujuan terlebih dahulu.');
        return;
    }

    document.getElementById('m-bank').textContent    = bankVal;
    document.getElementById('m-accnum').textContent  = numVal;
    document.getElementById('m-accname').textContent = nameVal;

    document.getElementById('cg-modal').style.display = 'flex';
}

function closeConfirm() {
    document.getElementById('cg-modal').style.display = 'none';
}

function submitForm() {
    const btn = document.querySelector('.cg-btn-confirm');
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Mengirim...';
    document.getElementById('edit-rek-form').submit();
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
