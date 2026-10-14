<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$dep_id = (int)($_GET['id'] ?? 0);
if (!$dep_id) redirect('/deposit');

$dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND user_id=?");
$dep->execute([$dep_id, $user['id']]);
$dep = $dep->fetch();
if (!$dep || $dep['method'] !== 'qris') redirect('/deposit');

// ── AJAX: check_status — HARUS sebelum redirect confirmed ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pdo_reconnect($pdo);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdo->prepare("SELECT status FROM deposits WHERE id=? AND user_id=?");
    $st->execute([$dep_id, $user['id']]);
    $row = $st->fetch();
    echo json_encode(['confirmed' => ($row && $row['status'] === 'confirmed')]);
    exit;
}

// ── PHP Proxy: download QR image (avoid exposing external URL to browser) ──
if (($_GET['action'] ?? '') === 'dl_qr') {
    $qris_raw_dl = $_ENV['QRIS_RAW'] ?? '';
    $qris_str_dl = !empty($qris_raw_dl) ? qris_with_amount($qris_raw_dl, (int)(float)$dep['amount']) : '';
    if (!$qris_str_dl) { http_response_code(404); exit('QR not available'); }
    $remote = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str_dl);
    $img    = @file_get_contents($remote);
    if (!$img) { http_response_code(502); exit('Failed to generate QR'); }
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="QRIS-MancingCuan-dep' . $dep_id . '.png"');
    header('Content-Length: ' . strlen($img));
    header('Cache-Control: no-store');
    echo $img;
    exit;
}

if ($dep['status'] === 'confirmed') redirect('/history');

$qris_raw     = $_ENV['QRIS_RAW'] ?? '';
$confirm_mode = setting($pdo, 'deposit_confirm_mode', 'manual');
$amount       = (float)$dep['amount'];
$qris_str     = !empty($qris_raw) ? qris_with_amount($qris_raw, (int)$amount) : '';
$_favicon     = setting($pdo, 'favicon_path', '');
$fav_url      = $_favicon ? '/' . ltrim($_favicon, '/') : '';

// Upload bukti
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Pilih file bukti pembayaran.'; $flashType = 'error';
    } else {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $flash = 'Format harus JPG/PNG/WEBP.'; $flashType = 'error';
        } else {
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $pdo->prepare("UPDATE deposits SET proof_image=? WHERE id=?")->execute(['deposits/' . $fname, $dep_id]);
            $flash = 'Bukti pembayaran berhasil diunggah! Admin akan segera memverifikasi.';
            $flashType = 'success';
            
            // Telegram Notif
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'mancingcuan.com';
            $proofUrl = $scheme . '://' . $host . '/uploads/deposits/' . $fname;
            
            $msg = "<b>BUKTI DEPOSIT DIUPLOAD</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "User: <code>" . htmlspecialchars($user['username']) . "</code>\n";
            $msg .= "Amount: <code>" . format_rp((float)$dep['amount']) . "</code>\n";
            $msg .= "Bukti: <a href=\"{$proofUrl}\">Klik untuk lihat struk</a>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Silakan periksa gambar bukti di atas sebelum melakukan tindakan.</i>";
            
            $kb = [
                [['text'=>'Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
                [['text'=>'Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
            ];
            
            $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
            if ($tg_msg_id) {
                $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
            }
        }
    }
    $dep2 = $pdo->prepare("SELECT * FROM deposits WHERE id=?"); $dep2->execute([$dep_id]); $dep = $dep2->fetch();
}

// Cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_deposit') {
    if (time() - strtotime($dep['created_at']) >= 60) {
        $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Dibatalkan oleh Pengguna' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([$dep_id, $user['id']]);
        redirect('/deposit');
    } else {
        $flash = 'Harap tunggu 1 menit sejak deposit dibuat sebelum membatalkan.'; $flashType = 'error';
    }
}

// Countdown: 1 jam dari created_at, tidak reset saat refresh
$created_ts       = strtotime($dep['created_at']);
$expire_secs      = max(0, 3600 - (time() - $created_ts));   // sisa waktu 1 jam
$cancel_secs_left = max(0, 60   - (time() - $created_ts));   // sisa cooldown batal

$qr_url      = !empty($qris_str)
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str)
    : '';
$qr_dl_url   = '?id=' . $dep_id . '&action=dl_qr';

$pageTitle  = 'Kasir QRIS';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   COMPACT & MINIMALIST AMBER CHECKOUT
   ══════════════════════════════════════════════ */
:root {
  --honey-50:  #fffbeb;
  --honey-100: #fef3c7;
  --honey-200: #fde68a;
  --honey-500: #f59e0b;
  --honey-600: #d97706;
  --honey-700: #b45309;
  --honey-800: #92400e;
  --honey-900: #78350f;
  --honey-ink: #451a03;
}

body {
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.06) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
  color: var(--honey-900);
  font-family: 'Nunito', sans-serif;
}

.pay-container {
  max-width: 410px;
  margin: 0 auto;
  padding: 12px 14px 80px;
}

/* ── COMPACT TOP BAR ── */
.pay-top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.pay-back-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 10px;
  padding: 6px 10px;
  color: var(--honey-900);
  font-size: 11px;
  font-weight: 800;
  text-decoration: none;
  box-shadow: 0 2px 0 var(--honey-900);
  transition: transform 0.1s;
}
.pay-back-pill:active {
  transform: translateY(1.5px);
  box-shadow: 0 0.5px 0 var(--honey-900);
}

.pay-inv-badge {
  font-size: 11px;
  font-weight: 900;
  color: var(--honey-800);
  background: #fef3c7;
  border: 1.5px solid #fde68a;
  border-radius: 8px;
  padding: 4px 8px;
}

.pay-timer-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #fee2e2;
  border: 1.5px solid #fca5a5;
  border-radius: 8px;
  padding: 4px 8px;
  font-size: 11px;
  font-weight: 900;
  color: #dc2626;
}

/* ── COMPACT UNIFIED VAULT CARD ── */
.pay-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 18px;
  box-shadow: 0 4px 0 var(--honey-900);
  overflow: hidden;
  margin-bottom: 10px;
}

/* Card Amount Header */
.pay-card-amount {
  background: linear-gradient(135deg, #fffbeb, #fef3c7);
  padding: 12px 14px;
  border-bottom: 1.5px solid #fde68a;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pca-left {
  display: flex;
  flex-direction: column;
}
.pca-lbl {
  font-size: 9px;
  font-weight: 900;
  color: var(--honey-800);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.pca-val {
  font-size: 20px;
  font-weight: 900;
  color: #b45309;
  letter-spacing: -0.3px;
  line-height: 1.1;
}

.btn-copy-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 8px;
  padding: 5px 9px;
  font-size: 10.5px;
  font-weight: 800;
  color: var(--honey-900);
  cursor: pointer;
  box-shadow: 0 1.5px 0 var(--honey-900);
  font-family: inherit;
  transition: transform 0.1s;
}
.btn-copy-chip:active {
  transform: translateY(1px);
  box-shadow: 0 0.5px 0 var(--honey-900);
}

/* Card QR Section */
.pay-card-qr {
  padding: 16px 14px 12px;
  text-align: center;
}

.qr-box {
  width: 176px;
  height: 176px;
  margin: 0 auto 10px;
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 14px;
  padding: 8px;
  box-shadow: 0 2.5px 0 var(--honey-900);
  display: flex;
  align-items: center;
  justify-content: center;
}
.qr-box img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.qr-partners {
  font-size: 9px;
  font-weight: 800;
  color: #92400e;
  letter-spacing: 0.3px;
  margin-bottom: 12px;
  opacity: 0.85;
}

/* Action Buttons Grid */
.pay-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 4px;
}

.btn-mini-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 800;
  text-decoration: none;
  border: 1.5px solid var(--honey-900);
  box-shadow: 0 2px 0 var(--honey-900);
  font-family: inherit;
  transition: transform 0.1s;
}
.btn-mini-act:active {
  transform: translateY(1px);
  box-shadow: 0 0.5px 0 var(--honey-900);
}
.btn-mini-act.gold {
  background: #fde68a;
  color: var(--honey-900);
}
.btn-mini-act.amber {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
}

/* ── STATUS MONITOR BAR ── */
.pay-status-strip {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: 0 3px 0 var(--honey-900);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.pss-pulse {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 10.5px;
  font-weight: 800;
  color: #065f46;
}
.pss-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: miniPulse 1.8s infinite;
  flex-shrink: 0;
}
@keyframes miniPulse {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1.15); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.btn-check-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #10b981;
  color: #ffffff;
  border: 1.5px solid #064e3b;
  border-radius: 8px;
  padding: 5px 9px;
  font-size: 10.5px;
  font-weight: 800;
  cursor: pointer;
  box-shadow: 0 1.5px 0 #064e3b;
  font-family: inherit;
  transition: transform 0.1s;
}
.btn-check-pill:active {
  transform: translateY(1px);
}

/* ── MINIMALIST ACCORDIONS ── */
.pay-acc-card {
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 12px;
  margin-bottom: 8px;
  overflow: hidden;
  box-shadow: 0 2px 0 var(--honey-900);
}
.pay-acc-hdr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 12px;
  background: #fffbeb;
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-900);
  cursor: pointer;
  user-select: none;
}
.pay-acc-hdr i.ph-caret-down {
  transition: transform 0.2s;
  color: var(--honey-600);
}
.pay-acc-hdr.open i.ph-caret-down {
  transform: rotate(180deg);
}
.pay-acc-body {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.25s ease, padding 0.25s ease;
  padding: 0 12px;
}
.pay-acc-body.open {
  max-height: 240px;
  padding: 10px 12px 12px;
  border-top: 1px solid #fde68a;
}

.step-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 7px;
  font-size: 10.5px;
  font-weight: 700;
  color: #78350f;
  line-height: 1.35;
}
.step-row:last-child {
  margin-bottom: 0;
}
.step-n {
  width: 17px;
  height: 17px;
  border-radius: 5px;
  background: #fde68a;
  border: 1px solid var(--honey-900);
  font-size: 9.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* Upload input */
.dep-file-input {
  width: 100%;
  background: #fffbeb;
  border: 1.5px dashed var(--honey-600);
  border-radius: 8px;
  padding: 6px;
  font-size: 10px;
  font-weight: 700;
  box-sizing: border-box;
  margin-bottom: 8px;
  font-family: inherit;
}
.dep-file-input::file-selector-button {
  background: #fde047;
  border: 1px solid var(--honey-900);
  border-radius: 6px;
  padding: 3px 8px;
  font-size: 10px;
  font-weight: 800;
  margin-right: 6px;
  cursor: pointer;
}
.btn-upload-act {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.5px solid var(--honey-900);
  border-radius: 8px;
  padding: 7px;
  font-size: 11px;
  font-weight: 800;
  color: #ffffff;
  cursor: pointer;
  box-shadow: 0 1.5px 0 var(--honey-900);
  font-family: inherit;
}

/* Cancel Link */
.btn-cancel-flat {
  background: none;
  border: none;
  font-size: 11px;
  font-weight: 800;
  color: #b91c1c;
  cursor: pointer;
  padding: 6px;
  width: 100%;
  text-align: center;
  font-family: inherit;
  text-decoration: underline;
}
.btn-cancel-flat:disabled {
  color: #94a3b8;
  cursor: not-allowed;
  text-decoration: none;
}

/* Flash alert */
.pay-flash-mini {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 800;
  margin-bottom: 10px;
  border: 1.5px solid;
}
.pay-flash-mini.error {
  background: #fef2f2;
  border-color: #fca5a5;
  color: #b91c1c;
}
.pay-flash-mini.success {
  background: #ecfdf5;
  border-color: #86efac;
  color: #065f46;
}

/* Result cards */
.pay-res-box {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 18px;
  box-shadow: 0 4px 0 var(--honey-900);
  padding: 28px 16px;
  text-align: center;
}
.prb-icon {
  font-size: 44px;
  margin-bottom: 8px;
}
.prb-title {
  font-size: 17px;
  font-weight: 900;
  color: var(--honey-900);
  margin-bottom: 4px;
}
.prb-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: #92400e;
  line-height: 1.35;
  margin-bottom: 18px;
}
</style>

<div class="pay-container">
  <!-- 1. COMPACT TOP BAR -->
  <div class="pay-top-bar">
    <a href="/deposit" class="pay-back-pill">
      <i class="ph-bold ph-caret-left"></i>
      <span>Kembali</span>
    </a>
    <div class="pay-inv-badge">
      <span>#<?= $dep_id ?></span>
    </div>
    <div class="pay-timer-pill">
      <i class="ph-bold ph-hourglass-medium"></i>
      <span id="exp-timer">--:--</span>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="pay-flash-mini <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <!-- 2. STATE SUDAH TERKONFIRMASI -->
  <?php if ($dep['status'] === 'confirmed'): ?>
  <div class="pay-res-box">
    <div class="prb-icon" style="color:#10b981;">
      <i class="ph-fill ph-check-circle"></i>
    </div>
    <div class="prb-title">Pembayaran Sukses!</div>
    <div class="prb-desc">
      Saldo beli sebesar <strong><?= format_rp((float)$amount) ?></strong> telah masuk ke akunmu.
    </div>
    <a href="/home" class="btn-mini-act amber" style="width:100%;padding:10px;">
      <i class="ph-bold ph-house"></i> Masuk ke Beranda
    </a>
  </div>

  <!-- 3. STATE BUKTI TERUPLOAD MENUNGGU VERIFIKASI -->
  <?php elseif ($dep['proof_image']): ?>
  <div class="pay-res-box">
    <div class="prb-icon" style="color:#f59e0b;">
      <i class="ph-fill ph-clock-countdown"></i>
    </div>
    <div class="prb-title">Bukti Sedang Diverifikasi</div>
    <div class="prb-desc">
      Struk pembayaran telah diterima dan sedang diproses verifikasi oleh admin.
    </div>
    <a href="/history" class="btn-mini-act gold" style="width:100%;padding:10px;">
      <i class="ph-bold ph-receipt"></i> Cek Riwayat Transaksi
    </a>
  </div>

  <!-- 4. STATE UTAMA: MENUNGGU PEMBAYARAN QRIS -->
  <?php else: ?>

  <!-- UNIFIED PAYMENT VAULT CARD -->
  <div class="pay-card">
    <!-- Amount Header Bar -->
    <div class="pay-card-amount">
      <div class="pca-left">
        <span class="pca-lbl">Total Pembayaran</span>
        <span class="pca-val"><?= format_rp((float)$amount) ?></span>
      </div>
      <button type="button" class="btn-copy-chip" onclick="copyAmount()">
        <i class="ph-bold ph-copy"></i>
        <span>Salin</span>
      </button>
    </div>

    <!-- QR Section -->
    <div class="pay-card-qr">
      <?php if ($qr_url): ?>
      <!-- Official QRIS Logo -->
      <div style="display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
        <img src="/assets/qris.png?v=3" alt="QRIS" style="height:52px;max-width:180px;object-fit:contain;display:block;">
      </div>
      <div class="qr-box">
        <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS Code">
      </div>
      <div class="qr-partners">BCA · Mandiri · BRI · BNI · DANA · GoPay · OVO · ShopeePay</div>

      <!-- Quick Action Buttons -->
      <div class="pay-actions">
        <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="btn-mini-act gold">
          <i class="ph-bold ph-download-simple"></i>
          <span>Unduh QR</span>
        </a>
        <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="btn-mini-act amber">
          <i class="ph-bold ph-arrow-square-out"></i>
          <span>Perbesar QR</span>
        </a>
      </div>
      <?php else: ?>
      <div style="font-size:11px;color:#dc2626;padding:12px;font-weight:800;">
        <i class="ph-bold ph-warning-circle"></i> QRIS belum dikonfigurasi. Hubungi Admin.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- STATUS MONITOR STRIP -->
  <div class="pay-status-strip">
    <div class="pss-pulse">
      <div class="pss-dot"></div>
      <span>Cek otomatis setiap 5 detik</span>
    </div>
    <button id="btn-check-status" onclick="manualCheckStatus()" class="btn-check-pill" type="button">
      <i class="ph-bold ph-arrows-clockwise"></i>
      <span>Cek Status</span>
    </button>
  </div>

  <!-- ACCORDION 1: PANDUAN RINGKAS -->
  <div class="pay-acc-card">
    <div class="pay-acc-hdr" onclick="toggleAcc('steps')" id="hdr-steps">
      <span style="display:inline-flex;align-items:center;gap:5px;">
        <i class="ph-bold ph-info" style="color:var(--honey-600);"></i>
        <span>Cara Pembayaran QRIS</span>
      </span>
      <i class="ph-bold ph-caret-down"></i>
    </div>
    <div class="pay-acc-body" id="body-steps">
      <div class="step-row">
        <div class="step-n">1</div>
        <div>Buka m-Banking (BCA, Mandiri, BRI, BNI) atau E-Wallet (DANA, GoPay, OVO, ShopeePay).</div>
      </div>
      <div class="step-row">
        <div class="step-n">2</div>
        <div>Pilih menu <strong>Scan QRIS</strong> dan arahkan kamera ke kode di atas (atau pilih dari galeri).</div>
      </div>
      <div class="step-row">
        <div class="step-n">3</div>
        <div>Konfirmasi pembayaran. Saldo beli akan masuk secara otomatis dalam hitungan detik.</div>
      </div>
    </div>
  </div>

  <!-- ACCORDION 2: UPLOAD STRUK OPSIONAL -->
  <?php 
  $pending_secs = time() - $created_ts;
  $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
  ?>
  <div class="pay-acc-card" id="upload-acc-wrap" style="<?= $show_upload ? '' : 'display:none;' ?>">
    <div class="pay-acc-hdr" onclick="toggleAcc('upload')" id="hdr-upload">
      <span style="display:inline-flex;align-items:center;gap:5px;">
        <i class="ph-bold ph-file-arrow-up" style="color:var(--honey-600);"></i>
        <span>Upload Struk Pembayaran (Opsional)</span>
      </span>
      <i class="ph-bold ph-caret-down"></i>
    </div>
    <div class="pay-acc-body" id="body-upload">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <input class="dep-file-input" type="file" name="proof" accept="image/*" required>
        <button type="submit" class="btn-upload-act">
          <i class="ph-bold ph-paper-plane-tilt"></i> Kirim Bukti Struk
        </button>
      </form>
    </div>
  </div>

  <!-- CANCEL LINK -->
  <form method="POST" style="margin: 8px 0 0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_deposit">
    <button id="btn-cancel-dep" type="submit" class="btn-cancel-flat">Batalkan Deposit</button>
  </form>

  <script>
  const DEP_ID      = <?= $dep_id ?>;
  const CSRF_TOK    = '<?= csrf_token() ?>';
  const EXPIRE_SECS = <?= $expire_secs ?>;
  const RAW_AMOUNT  = <?= (int)$amount ?>;
  let isChecking    = false;

  function toggleAcc(id) {
    const b = document.getElementById('body-' + id);
    const h = document.getElementById('hdr-' + id);
    if (!b || !h) return;
    const isOpen = b.classList.contains('open');
    if (isOpen) {
      b.classList.remove('open');
      h.classList.remove('open');
    } else {
      b.classList.add('open');
      h.classList.add('open');
    }
  }

  function copyAmount() {
    const textToCopy = RAW_AMOUNT.toString();
    if (typeof nToast !== 'undefined' && nToast.copy) {
      nToast.copy(textToCopy, 'Nominal Transfer');
    } else {
      navigator.clipboard.writeText(textToCopy).then(() => {
        if (typeof nToast === 'function') {
          nToast('Nominal Rp ' + Number(textToCopy).toLocaleString('id-ID') + ' disalin!', 'success');
        }
      });
    }
  }

  let expSecs = EXPIRE_SECS;
  const timerEl = document.getElementById('exp-timer');

  function updateExpTimer() {
    if (expSecs <= 0) {
      if (timerEl) timerEl.textContent = 'Kedaluwarsa';
      return;
    }
    const m = Math.floor(expSecs / 60), s = expSecs % 60;
    if (timerEl) timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
  }
  updateExpTimer();
  const expTimer = setInterval(() => {
    expSecs--;
    updateExpTimer();
    
    const elapsed = 3600 - expSecs;
    if (elapsed >= 300) {
      const upWrap = document.getElementById('upload-acc-wrap');
      if (upWrap && upWrap.style.display === 'none') {
        upWrap.style.display = 'block';
      }
    }

    if (expSecs <= 0) clearInterval(expTimer);
  }, 1000);

  const pollStatus = () => {
    if (isChecking) return;
    fetch('?id=' + DEP_ID, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'_csrf=' + CSRF_TOK + '&action=check_status'
    }).then(r=>r.json()).then(d=>{
      if(d.confirmed) confirmAndRedirect();
    }).catch(()=>{});
  };
  const pollTimer = setInterval(pollStatus, 5000);

  function confirmAndRedirect() {
    clearInterval(pollTimer);
    clearInterval(expTimer);
    if (timerEl) timerEl.textContent = 'Sukses';
    if (typeof nToast === 'function') nToast('Pembayaran berhasil dikonfirmasi!', 'success');
    setTimeout(() => location.href = '/history?tab=deposit', 1000);
  }

  const manualCheckStatus = () => {
    if (isChecking) return;
    isChecking = true;
    const btn = document.getElementById('btn-check-status');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Cek...';

    fetch('?id=' + DEP_ID, {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'_csrf=' + CSRF_TOK + '&action=check_status'
    }).then(r=>r.json()).then(d=>{
      isChecking = false;
      btn.disabled = false;
      btn.innerHTML = orig;
      if (d.confirmed) {
        confirmAndRedirect();
      } else {
        if (typeof nToast === 'function') {
          nToast('Pembayaran belum terdeteksi. Silakan coba sesaat lagi.', 'warn');
        }
      }
    }).catch(()=>{
      isChecking = false;
      btn.disabled = false;
      btn.innerHTML = orig;
      if (typeof nToast === 'function') {
        nToast('Gagal menghubungi server.', 'error');
      }
    });
  };

  let cancelSecs = <?= $cancel_secs_left ?>;
  const cancelBtn = document.getElementById('btn-cancel-dep');
  if (cancelBtn && cancelSecs > 0) {
    cancelBtn.disabled = true;
    cancelBtn.textContent = 'Tunggu ' + cancelSecs + 's untuk membatalkan';
    const ci = setInterval(() => {
      cancelSecs--;
      cancelBtn.textContent = cancelSecs > 0 ? 'Tunggu ' + cancelSecs + 's untuk membatalkan' : 'Batalkan Deposit';
      if (cancelSecs <= 0) {
        clearInterval(ci);
        cancelBtn.disabled = false;
      }
    }, 1000);
  }
  </script>
  <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
