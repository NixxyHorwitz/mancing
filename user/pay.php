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
/* ══════════════════════════════════════════════════════════════
   OCEAN REEF QRIS CASHIER — DERMAGA PEMBAYARAN KASIR SAMUDRA
   Tema: Living Coral Reef Fantasy & 3D Tactile Claymorphism
   ══════════════════════════════════════════════════════════════ */

.pay-reef-stage {
  width: 100%;
  max-width: 430px;
  margin: 0 auto;
  padding: 4px 10px 28px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base */
.pay-clay {
  border: 2px solid #ffffff;
  border-radius: 22px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
  overflow: hidden;
}

/* ── 1. COMPACT TOP NAVIGATOR ── */
.pay-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.pay-back-btn {
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
.pay-back-btn:active { transform: scale(0.92); }

.pay-nav-title {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  padding: 5px 12px;
  border-radius: 999px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
  font-size: 12.5px;
  font-weight: 900;
  color: var(--reef-ink);
}

.pay-timer-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #fee2e2;
  border: 1.5px solid #fca5a5;
  color: #dc2626;
  font-size: 10.5px;
  font-weight: 900;
  padding: 4px 10px;
  border-radius: 999px;
  box-shadow: 0 2px 6px rgba(220, 38, 38, 0.15);
}

/* ── 2. OCEAN CARGO QRIS MAIN CARD ── */
.pay-qris-card {
  padding: 14px 16px;
  position: relative;
}

/* Floating micro bubbles inside card */
.pay-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.85), rgba(34, 211, 238, 0.25));
  pointer-events: none;
  animation: floatPayBubble 5s ease-in-out infinite;
}
.pay-bubble--1 { width: 14px; height: 14px; top: 12px; right: 16px; animation-duration: 5.5s; }
.pay-bubble--2 { width: 18px; height: 18px; bottom: 25px; left: 14px; animation-duration: 6.5s; animation-delay: -2s; }
@keyframes floatPayBubble {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-10px) scale(1.1); }
}

/* Amount Header Row */
.pay-amount-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: linear-gradient(135deg, #ffffff 0%, #fff7ed 60%, #ffedd5 100%);
  border: 1.5px solid #fde68a;
  border-radius: 16px;
  margin-bottom: 14px;
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.08);
}
.pay-amount-meta {
  display: flex;
  flex-direction: column;
}
.pay-amount-lbl {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.pay-amount-val {
  font-size: 21px;
  font-weight: 900;
  color: #b45309;
  letter-spacing: -0.4px;
  line-height: 1.15;
}
.pay-copy-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: var(--reef-sun-500);
  border: none;
  border-radius: 10px;
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 900;
  color: #ffffff;
  cursor: pointer;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.35);
  transition: all 0.15s ease;
  white-space: nowrap;
}
.pay-copy-btn:active { transform: scale(0.95); }
.pay-copy-btn.copied {
  background: #10b981;
}

/* Official QRIS Logo Bar */
.pay-qris-brand-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border: 1.5px solid #a7f3d0;
  border-radius: 14px;
  padding: 6px 12px;
  margin-bottom: 12px;
}
.pay-qris-brand-txt {
  font-size: 10.5px;
  font-weight: 900;
  color: #065f46;
  display: flex;
  align-items: center;
  gap: 5px;
}
.pay-qris-brand-img {
  height: 26px;
  max-width: 86px;
  object-fit: contain;
}

/* QR Code Display Frame */
.pay-qr-frame {
  width: 200px;
  height: 200px;
  margin: 0 auto 10px;
  background: #ffffff;
  border: 2.5px solid #ffffff;
  border-radius: 20px;
  padding: 10px;
  box-shadow:
    0 8px 24px rgba(30, 27, 46, 0.08),
    inset 2px 2px 5px rgba(255, 255, 255, 0.95);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}
.pay-qr-frame img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  border-radius: 8px;
}

/* Supported Apps List */
.pay-partners-pill {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
  letter-spacing: 0.2px;
  text-align: center;
  margin-bottom: 12px;
  line-height: 1.35;
}

/* Action Buttons Grid (Unduh & Perbesar) */
.pay-actions-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.pay-btn-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 9px;
  border-radius: 14px;
  font-size: 11.5px;
  font-weight: 900;
  text-decoration: none;
  border: 1.5px solid #ffffff;
  transition: all 0.15s ease;
  user-select: none;
}
.pay-btn-act:hover { transform: translateY(-1px); }
.pay-btn-act:active { transform: scale(0.96); }
.pay-btn-act.download {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  color: #78350f;
  box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
}
.pay-btn-act.zoom {
  background: linear-gradient(135deg, #a5f3fc 0%, #06b6d4 100%);
  color: #083344;
  box-shadow: 0 4px 12px rgba(6, 182, 212, 0.25);
}

/* ── 3. REALTIME RADAR MONITOR STRIP ── */
.pay-radar-strip {
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 9px 12px;
  box-shadow: 0 4px 14px rgba(30, 27, 46, 0.05);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.pay-radar-left {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 10.5px;
  font-weight: 800;
  color: #065f46;
}
.pay-radar-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: radarSonar 1.8s infinite;
  flex-shrink: 0;
}
@keyframes radarSonar {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1.15); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.pay-btn-check {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #10b981;
  color: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 10px;
  padding: 5px 11px;
  font-size: 10.5px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
  transition: transform 0.12s ease;
}
.pay-btn-check:active { transform: scale(0.95); }

/* ── 4. OCEAN ACCORDIONS (CARA BAYAR & UPLOAD STRUK) ── */
.pay-accordion {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
}
.pay-acc-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 12px;
  background: linear-gradient(135deg, #ffffff 0%, #fff7ed 100%);
  font-size: 11px;
  font-weight: 900;
  color: var(--reef-ink);
  cursor: pointer;
  user-select: none;
}
.pay-acc-header i.ph-caret-down {
  transition: transform 0.2s;
  color: var(--reef-coral-500);
}
.pay-acc-header.open i.ph-caret-down {
  transform: rotate(180deg);
}
.pay-acc-content {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.25s ease, padding 0.25s ease;
  padding: 0 12px;
}
.pay-acc-content.open {
  max-height: 260px;
  padding: 10px 12px 12px;
  border-top: 1px dashed rgba(251, 146, 60, 0.3);
}

.pay-step-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 7px;
  font-size: 10.5px;
  font-weight: 700;
  color: var(--reef-ink);
  line-height: 1.35;
}
.pay-step-row:last-child { margin-bottom: 0; }
.pay-step-badge {
  width: 18px;
  height: 18px;
  border-radius: 6px;
  background: #ffedd5;
  color: #ea580c;
  font-size: 9.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* Upload input */
.pay-file-input {
  width: 100%;
  background: #fff1f2;
  border: 1.5px dashed #f43f5e;
  border-radius: 10px;
  padding: 6px;
  font-size: 10px;
  font-weight: 700;
  margin-bottom: 8px;
  font-family: inherit;
  box-sizing: border-box;
}
.pay-file-input::file-selector-button {
  background: #ffffff;
  border: 1px solid #fecdd3;
  border-radius: 6px;
  padding: 3px 8px;
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-coral-500);
  margin-right: 6px;
  cursor: pointer;
}
.pay-btn-upload {
  width: 100%;
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 100%);
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  padding: 8px;
  font-size: 11.5px;
  font-weight: 900;
  color: #ffffff;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(244, 63, 94, 0.25);
  font-family: inherit;
}

/* Cancel Link */
.pay-btn-cancel {
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
  transition: opacity 0.15s ease;
}
.pay-btn-cancel:disabled {
  color: #94a3b8;
  cursor: not-allowed;
  text-decoration: none;
}

/* Flash alert */
.pay-flash-box {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 12px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 800;
  border: 1.5px solid #ffffff;
}
.pay-flash-box.error {
  background: #ffe4e6;
  color: #9f1239;
  border-color: #fecdd3;
}
.pay-flash-box.success {
  background: #d1fae5;
  color: #065f46;
  border-color: #a7f3d0;
}

/* Result Cards (Sukses & Menunggu Verifikasi) */
.pay-result-card {
  padding: 26px 18px;
  text-align: center;
}
.pay-res-icon {
  width: 64px;
  height: 64px;
  border-radius: 20px;
  margin: 0 auto 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
  border: 2px solid #ffffff;
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08);
}
.pay-res-icon.success {
  background: linear-gradient(135deg, #a7f3d0, #10b981);
  color: #ffffff;
}
.pay-res-icon.pending {
  background: linear-gradient(135deg, #fef08a, #f59e0b);
  color: #78350f;
}
.pay-res-title {
  font-size: 17px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 3px;
}
.pay-res-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.35;
  margin-bottom: 16px;
}
</style>

<div class="pay-reef-stage">

  <!-- ── 1. COMPACT TOP NAVIGATOR ── -->
  <div class="pay-top-nav">
    <a href="/deposit" class="pay-back-btn" title="Kembali ke Deposit">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="pay-nav-title">
      <i class="ph-fill ph-anchor" style="color:var(--reef-coral-500);"></i>
      <span>Kasir QRIS #<?= $dep_id ?></span>
    </div>
    <div class="pay-timer-badge">
      <i class="ph-bold ph-hourglass-medium"></i>
      <span id="exp-timer">--:--</span>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="pay-flash-box <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <!-- ── 2. STATE SUDAH TERKONFIRMASI ── -->
  <?php if ($dep['status'] === 'confirmed'): ?>
  <div class="pay-clay pay-result-card">
    <div class="pay-res-icon success">
      <i class="ph-fill ph-check-circle"></i>
    </div>
    <div class="pay-res-title">Pembayaran Berhasil!</div>
    <div class="pay-res-desc">
      Saldo beli sebesar <strong><?= format_rp((float)$amount) ?></strong> telah masuk ke akunmu dan siap dibelanjakan.
    </div>
    <a href="/home" class="pay-btn-act" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:11px;font-size:13px;">
      <i class="ph-bold ph-house"></i> Kembali ke Beranda
    </a>
  </div>

  <!-- ── 3. STATE BUKTI TERUPLOAD MENUNGGU VERIFIKASI ── -->
  <?php elseif ($dep['proof_image']): ?>
  <div class="pay-clay pay-result-card">
    <div class="pay-res-icon pending">
      <i class="ph-fill ph-clock-countdown"></i>
    </div>
    <div class="pay-res-title">Bukti Sedang Diverifikasi</div>
    <div class="pay-res-desc">
      Struk pembayaran telah diterima oleh dermaga kasir dan sedang dalam proses verifikasi admin.
    </div>
    <a href="/history" class="pay-btn-act download" style="padding:11px;font-size:13px;">
      <i class="ph-bold ph-receipt"></i> Cek Riwayat Transaksi
    </a>
  </div>

  <!-- ── 4. STATE UTAMA: MENUNGGU PEMBAYARAN QRIS ── -->
  <?php else: ?>

  <!-- UNIFIED PAYMENT VAULT CARD (THE OCEAN QRIS BERTH) -->
  <div class="pay-clay pay-qris-card">
    <div class="pay-bubble pay-bubble--1"></div>
    <div class="pay-bubble pay-bubble--2"></div>

    <!-- Amount Header Bar -->
    <div class="pay-amount-header">
      <div class="pay-amount-meta">
        <span class="pay-amount-lbl">Total Tagihan Pembayaran</span>
        <span class="pay-amount-val"><?= format_rp((float)$amount) ?></span>
      </div>
      <button type="button" class="pay-copy-btn" id="btn-copy-amt" onclick="copyAmount()">
        <i class="ph-bold ph-copy"></i>
        <span id="copy-amt-txt">Salin</span>
      </button>
    </div>

    <!-- Official QRIS Brand Bar -->
    <div class="pay-qris-brand-bar">
      <div class="pay-qris-brand-txt">
        <i class="ph-fill ph-shield-check" style="font-size:16px;color:#10b981;"></i>
        <span>Standar Pembayaran Nasional</span>
      </div>
      <img src="/assets/qris.png?v=3" alt="QRIS" class="pay-qris-brand-img">
    </div>

    <!-- QR Code Display Frame -->
    <?php if ($qr_url): ?>
    <div class="pay-qr-frame">
      <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS Code">
    </div>

    <div class="pay-partners-pill">
      DANA &bull; OVO &bull; GoPay &bull; ShopeePay &bull; BCA &bull; Mandiri &bull; BRI &bull; BNI
    </div>

    <!-- Quick Action Buttons (Unduh & Perbesar) -->
    <div class="pay-actions-grid">
      <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="pay-btn-act download">
        <i class="ph-bold ph-download-simple"></i>
        <span>Unduh Gambar QR</span>
      </a>
      <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="pay-btn-act zoom">
        <i class="ph-bold ph-arrows-out-simple"></i>
        <span>Perbesar QR</span>
      </a>
    </div>
    <?php else: ?>
    <div style="font-size:11px;color:#dc2626;padding:12px;font-weight:800;text-align:center;">
      <i class="ph-bold ph-warning-circle"></i> QRIS belum dikonfigurasi. Hubungi Admin.
    </div>
    <?php endif; ?>
  </div>

  <!-- ── 5. REALTIME RADAR MONITOR STRIP ── -->
  <div class="pay-radar-strip">
    <div class="pay-radar-left">
      <div class="pay-radar-dot"></div>
      <span>Cek otomatis setiap 5 detik</span>
    </div>
    <button id="btn-check-status" onclick="manualCheckStatus()" class="pay-btn-check" type="button">
      <i class="ph-bold ph-arrows-clockwise"></i>
      <span>Cek Status</span>
    </button>
  </div>

  <!-- ── 6. ACCORDION 1: PANDUAN RINGKAS ── -->
  <div class="pay-accordion">
    <div class="pay-acc-header" onclick="toggleAcc('steps')" id="hdr-steps">
      <span style="display:inline-flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-compass" style="color:var(--reef-coral-500);font-size:14px;"></i>
        <span>Cara Pembayaran QRIS Cepat</span>
      </span>
      <i class="ph-bold ph-caret-down"></i>
    </div>
    <div class="pay-acc-content" id="body-steps">
      <div class="pay-step-row">
        <div class="pay-step-badge">1</div>
        <div>Buka m-Banking (BCA, Mandiri, BRI, BNI) atau E-Wallet (DANA, GoPay, OVO, ShopeePay).</div>
      </div>
      <div class="pay-step-row">
        <div class="pay-step-badge">2</div>
        <div>Pilih menu <strong>Scan QRIS</strong> dan arahkan kamera ke barcode di atas (atau pilih dari galeri).</div>
      </div>
      <div class="pay-step-row">
        <div class="pay-step-badge">3</div>
        <div>Konfirmasi pembayaran. Saldo beli akan masuk secara otomatis dalam hitungan detik!</div>
      </div>
    </div>
  </div>

  <!-- ── 7. ACCORDION 2: UPLOAD STRUK CADANGAN (OPSIONAL) ── -->
  <?php 
  $pending_secs = time() - $created_ts;
  $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
  ?>
  <div class="pay-accordion" id="upload-acc-wrap" style="<?= $show_upload ? '' : 'display:none;' ?>">
    <div class="pay-acc-header" onclick="toggleAcc('upload')" id="hdr-upload">
      <span style="display:inline-flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-file-arrow-up" style="color:var(--reef-coral-500);font-size:14px;"></i>
        <span>Upload Struk Cadangan (Opsional)</span>
      </span>
      <i class="ph-bold ph-caret-down"></i>
    </div>
    <div class="pay-acc-content" id="body-upload">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <input class="pay-file-input" type="file" name="proof" accept="image/*" required>
        <button type="submit" class="pay-btn-upload">
          <i class="ph-bold ph-paper-plane-tilt"></i> Kirim Bukti Struk
        </button>
      </form>
    </div>
  </div>

  <!-- ── 8. CANCEL DEPOSIT FORM ── -->
  <form method="POST" style="margin: 4px 0 0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_deposit">
    <button id="btn-cancel-dep" type="submit" class="pay-btn-cancel">Batalkan Deposit</button>
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
    const btn = document.getElementById('btn-copy-amt');
    const txt = document.getElementById('copy-amt-txt');

    function onCopied() {
      if (btn) btn.classList.add('copied');
      if (txt) txt.textContent = '✓ Tersalin!';
      if (typeof nToast !== 'undefined' && nToast.copy) {
        nToast.copy(textToCopy, 'Nominal Transfer');
      } else if (typeof nToast === 'function') {
        nToast('Nominal Rp ' + Number(textToCopy).toLocaleString('id-ID') + ' disalin!', 'success');
      }
      setTimeout(() => {
        if (btn) btn.classList.remove('copied');
        if (txt) txt.textContent = 'Salin';
      }, 2000);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(textToCopy).then(onCopied).catch(onCopied);
    } else {
      onCopied();
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
