<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Inisialisasi token CSRF session di awal
$csrf_token   = csrf_token();
$checkin_min  = max(1, (float) setting($pdo, 'checkin_reward_min', '500'));
$checkin_max  = max($checkin_min, (float) setting($pdo, 'checkin_reward_max', '2000'));
$today        = date('Y-m-d');
$last_checkin = $user['last_checkin'] ?? null;
$already      = ($last_checkin === $today);

// Hitung streak check-in / aktivitas
$streak = 0;
if ($last_checkin) {
    $diff = (int)((strtotime($today) - strtotime($last_checkin)) / 86400);
    if ($diff <= 1) {
        $sq = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $sq->execute([$user['id']]);
        $streak = max(1, (int)$sq->fetchColumn());
    }
}

$flash = $flashType = '';
$reward_given = 0;

// Ambil reward dari session jika ada (untuk display setelah form submit / redirect)
if (!empty($_SESSION['checkin_reward_display']) && $already) {
    $reward_given = (int)$_SESSION['checkin_reward_display'];
    unset($_SESSION['checkin_reward_display']);
    $flash = 'checkin_ok';
    $flashType = 'success';
}

// ── PROSES KLAIM CHECK-IN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
               || isset($_POST['ajax']);

    // Validasi token CSRF (menerima _csrf, csrf_token, atau HTTP_X_CSRF_TOKEN)
    $submitted_token = (string)($_POST['_csrf'] ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    $is_valid_csrf = !empty($submitted_token) && hash_equals($csrf_token, $submitted_token);

    // Keamanan: Pastikan user login valid. Jika auth_user/guard valid, proses secara aman & idempotent
    if (!$is_valid_csrf && empty($user['id'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login kembali.']);
            exit;
        }
        $flash = 'Sesi tidak valid. Silakan coba lagi.';
        $flashType = 'error';
    } elseif ($already && $flash !== 'checkin_ok') {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Kamu sudah membuka kotak hadiah hari ini. Kembali besok!']);
            exit;
        }
        $flash = 'Kamu sudah check-in hari ini. Kembali besok!';
        $flashType = 'warn';
    } else {
        // Generate reward acak server-side
        $reward_given = rand((int)$checkin_min, (int)$checkin_max);
        try {
            $pdo->beginTransaction();
            // CRITICAL: Hadiah uang tunai langsung masuk ke Saldo Tarik (balance_wd) dan total_earned
            $stmt = $pdo->prepare(
                "UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ?, last_checkin = CURDATE()
                 WHERE id = ? AND (last_checkin IS NULL OR last_checkin < CURDATE())"
            );
            $stmt->execute([$reward_given, $reward_given, $user['id']]);

            if ($stmt->rowCount() > 0) {
                $pdo->commit();

                // Refresh saldo lokal
                $user['balance_wd'] = (float)($user['balance_wd'] ?? 0) + $reward_given;
                $user['total_earned'] = (float)($user['total_earned'] ?? 0) + $reward_given;
                $user['last_checkin'] = $today;
                $already = true;
                $streak = max(1, $streak + 1);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'reward' => $reward_given,
                        'reward_formatted' => format_rp((float)$reward_given),
                        'new_balance_wd' => $user['balance_wd'],
                        'new_balance_formatted' => format_rp((float)$user['balance_wd']),
                        'streak' => $streak
                    ]);
                    exit;
                }

                $_SESSION['checkin_reward_display'] = $reward_given;
                header('Location: /checkin');
                exit;
            } else {
                $pdo->rollBack();
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Kamu sudah membuka sarang hari ini!']);
                    exit;
                }
                $flash = 'Kamu sudah check-in hari ini!';
                $flashType = 'warn';
                $already = true;
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
                exit;
            }
            $flash = 'Terjadi kesalahan sistem.';
            $flashType = 'error';
        }
    }
}

$pageTitle  = 'Hadiah Check-in Harian';
$activePage = 'checkin';
require dirname(__DIR__) . '/partials/header.php';

$completed_days = $already ? ($streak % 7 ?: 7) : ($streak % 7);
if ($streak == 0 && $already) $completed_days = 1;
?>

<style>
/* ══════════════════════════════════════════════════════════
   HADIAH CHECK-IN HARIAN — MANCINGCUAN REWARD SYSTEM
   ══════════════════════════════════════════════════════════ */
body {
  background: #fef8ee !important;
  color: #1e293b;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
}

/* ── TOP BANNER ── */
.ci-top-banner {
  background: linear-gradient(180deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  padding: 16px 14px 24px;
  border-bottom: 3.5px solid #78350f;
  position: relative;
  text-align: center;
  overflow: hidden;
}
.ci-top-banner::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(#fbbf24 1px, transparent 1px);
  background-size: 16px 16px;
  opacity: 0.2;
  pointer-events: none;
}
.ci-top-title {
  position: relative;
  font-size: 20px;
  font-weight: 900;
  color: #fff;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
  margin-bottom: 2px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.ci-top-sub {
  position: relative;
  font-size: 11.5px;
  font-weight: 800;
  color: #fef3c7;
}

/* ── BODY CONTAINER ── */
.ci-page-wrap {
  padding: 16px 14px 100px;
  position: relative;
  max-width: 480px;
  margin: 0 auto;
}

/* ── DUAL VAULT CAPSULE (SALDO TARIK & STREAK) ── */
.ci-vault-capsule {
  display: grid;
  grid-template-columns: 1.2fr 0.8fr;
  gap: 8px;
  margin-bottom: 16px;
}
.ci-vault-tile {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 10px 12px;
  box-shadow: 0 4px 0 #78350f;
  display: flex;
  align-items: center;
  gap: 10px;
}
.ci-vault-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
  border: 2px solid #78350f;
  box-shadow: 0 2px 0 #78350f;
}
.ci-vault-icon--wd {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff;
}
.ci-vault-icon--streak {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff;
}
.ci-vault-info {
  flex: 1;
  min-width: 0;
}
.ci-vault-lbl {
  font-size: 9.5px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.ci-vault-val {
  font-size: 15px;
  font-weight: 900;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── STREAK PROGRESS PILLS (NO CARD WRAPPER) ── */
.ci-streak-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-bottom: 20px;
  padding: 4px 2px;
}
.ci-streak-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.ci-streak-dot {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  border: 2.5px solid #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 900;
  transition: all 0.2s;
  box-shadow: 0 3px 0 #78350f;
}
.ci-streak-dot.done {
  background: linear-gradient(135deg, #34d399, #10b981);
  color: #fff;
}
.ci-streak-dot.today {
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  color: #78350f;
  animation: pulse-active-hex 1.8s infinite ease-in-out;
}
.ci-streak-dot.future {
  background: #ffffff;
  color: #94a3b8;
  opacity: 0.75;
}
.ci-streak-lbl {
  font-size: 9px;
  font-weight: 900;
  color: #78350f;
}
@keyframes pulse-active-hex {
  0%, 100% { transform: scale(1); box-shadow: 0 3px 0 #78350f; }
  50% { transform: scale(1.08); box-shadow: 0 5px 0 #78350f, 0 0 10px rgba(245, 158, 11, 0.5); }
}

/* ══════════════════════════════════════════════════════════
   ORGANIC HEXAGON REWARD CLUSTER (NO CARD BOX WRAPPER)
   ══════════════════════════════════════════════════════════ */
.honeycomb-section-header {
  text-align: center;
  margin-bottom: 14px;
}
.honeycomb-heading {
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.honeycomb-subtext {
  font-size: 11px;
  font-weight: 700;
  color: #92400e;
  margin-top: 2px;
}

/* The open organic hive stage */
.honeycomb-hive-stage {
  position: relative;
  width: 100%;
  padding: 10px 0 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  user-select: none;
}
/* Natural honey background radial glow */
.honeycomb-hive-stage::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 290px;
  height: 290px;
  background: radial-gradient(circle, rgba(251, 191, 36, 0.25) 0%, rgba(245, 158, 11, 0.08) 50%, transparent 75%);
  pointer-events: none;
  z-index: 1;
}

/* Hexagon Row Interlocking */
.hex-row {
  display: flex;
  justify-content: center;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.hex-row:not(:first-child) {
  margin-top: -24px; /* Exact vertical overlap for pointy-top hexagon tessellation */
}

/* The Pointy-topped Hexagon Cell */
.hex-cell {
  width: 86px;
  height: 98px;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  background: #78350f; /* Wax Outline */
  padding: 3px;
  cursor: pointer;
  position: relative;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), filter 0.25s ease, opacity 0.3s ease;
  filter: drop-shadow(0 5px 6px rgba(120, 53, 15, 0.3));
}
.hex-cell-inner {
  width: 100%;
  height: 100%;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  background: linear-gradient(180deg, #fef3c7 0%, #fde68a 30%, #f59e0b 75%, #d97706 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
  transition: all 0.3s;
}

/* Ambient Honey Droplet shine reflection */
.hex-cell-inner::after {
  content: '';
  position: absolute;
  top: 4px;
  left: 18px;
  right: 18px;
  height: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.65) 0%, rgba(255, 255, 255, 0) 100%);
  border-radius: 50%;
  pointer-events: none;
}

/* Unopened Hexagon Icons & Details */
.hex-icon-box {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  color: #78350f;
  margin-top: 2px;
  filter: drop-shadow(0 1px 1px rgba(255,255,255,0.6));
  transition: transform 0.2s;
}
.hex-label {
  font-size: 9px;
  font-weight: 900;
  color: #78350f;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  margin-top: 1px;
}

/* Hover & Active States (Only if eligible to play) */
.hex-cell.active-play:hover {
  transform: scale(1.08) translateY(-4px);
  filter: drop-shadow(0 8px 12px rgba(245, 158, 11, 0.7));
  z-index: 10;
}
.hex-cell.active-play:hover .hex-icon-box {
  transform: scale(1.15);
}
.hex-cell.active-play:active {
  transform: scale(0.96);
}

/* Shimmer pulse for available cells */
.hex-cell.active-play {
  animation: cell-float 3s infinite ease-in-out;
}
.hex-cell:nth-child(1) { animation-delay: 0s; }
.hex-cell:nth-child(2) { animation-delay: 0.5s; }
.hex-cell:nth-child(3) { animation-delay: 1s; }
@keyframes cell-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

/* Selected & Opening Animation */
.hex-cell.picking {
  animation: hex-burst 0.7s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards !important;
  z-index: 30;
}
@keyframes hex-burst {
  0% { transform: scale(1) rotate(0deg); }
  40% { transform: scale(1.25) rotate(-6deg); filter: drop-shadow(0 0 20px #f59e0b); }
  70% { transform: scale(1.15) rotate(4deg); }
  100% { transform: scale(1.2) rotate(0deg); filter: drop-shadow(0 0 25px #10b981); }
}

/* Opened / Won Hexagon State */
.hex-cell.opened {
  background: #064e3b !important;
  z-index: 25;
  filter: drop-shadow(0 6px 12px rgba(16, 185, 129, 0.4)) !important;
}
.hex-cell.opened .hex-cell-inner {
  background: linear-gradient(180deg, #ecfdf5 0%, #a7f3d0 30%, #34d399 75%, #10b981 100%) !important;
}
.hex-cell.opened .hex-icon-box {
  color: #065f46 !important;
  font-size: 24px;
}
.hex-cell.opened .hex-label {
  color: #065f46 !important;
  font-size: 9.5px;
  font-weight: 900;
}

/* Dimmed other cells during or after pick */
.hex-cell.dimmed {
  opacity: 0.45;
  pointer-events: none;
  filter: grayscale(0.3) drop-shadow(0 2px 4px rgba(0,0,0,0.1));
}

/* ── STATUS NOTICE BELOW HONEYCOMB ── */
.ci-status-box {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 20px;
  padding: 14px 16px;
  box-shadow: 0 4px 0 #78350f;
  text-align: center;
  margin-top: 16px;
}
.ci-status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 900;
  margin-bottom: 8px;
}
.ci-status-badge--ready {
  background: #fef3c7;
  color: #b45309;
  border: 2px solid #d97706;
}
.ci-status-badge--done {
  background: #dcfce7;
  color: #065f46;
  border: 2px solid #059669;
}
.ci-status-title {
  font-size: 14px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
}
.ci-status-desc {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
  line-height: 1.4;
}

/* ── DIRECT ACTION BUTTONS ── */
.ci-action-btns {
  display: flex;
  gap: 8px;
  margin-top: 14px;
}
.ci-btn-action {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 11px;
  border-radius: 14px;
  font-size: 12px;
  font-weight: 900;
  text-decoration: none;
  border: 2px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s;
}
.ci-btn-action:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.ci-btn-action--wd {
  background: linear-gradient(180deg, #10b981, #059669);
  color: #fff;
  text-shadow: 0 1px 2px #064e3b;
}
.ci-btn-action--farm {
  background: linear-gradient(180deg, #f59e0b, #d97706);
  color: #fff;
  text-shadow: 0 1px 2px #78350f;
}

/* ── FLASH MESSAGE ── */
.ci-flash {
  background: #fee2e2;
  border: 2.5px solid #dc2626;
  border-radius: 14px;
  padding: 10px 14px;
  color: #991b1b;
  font-weight: 800;
  font-size: 11.5px;
  margin-bottom: 14px;
  box-shadow: 0 3px 0 #dc2626;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── CELEBRATION MODAL OVERLAY ── */
.ci-win-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.8);
  backdrop-filter: blur(6px);
  z-index: 100000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.ci-win-box {
  background: #ffffff;
  border: 4px solid #78350f;
  border-radius: 28px;
  box-shadow: 0 10px 0 #78350f, 0 25px 40px rgba(0,0,0,0.4);
  padding: 26px 20px 22px;
  max-width: 320px;
  width: 100%;
  text-align: center;
  position: relative;
  transform: scale(0.85);
  opacity: 0;
  transition: all 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.ci-win-icon {
  width: 72px;
  height: 72px;
  border-radius: 24px;
  background: linear-gradient(135deg, #10b981, #059669);
  border: 3px solid #78350f;
  box-shadow: 0 5px 0 #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 36px;
  color: #fff;
  margin: -56px auto 14px;
  animation: win-icon-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes win-icon-pop {
  0% { transform: scale(0.4) rotate(-20deg); }
  100% { transform: scale(1) rotate(0deg); }
}
.ci-win-title {
  font-size: 18px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
}
.ci-win-sub {
  font-size: 11.5px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 12px;
}
.ci-win-reward {
  font-size: 30px;
  font-weight: 900;
  color: #10b981;
  text-shadow: 0 2px 0 rgba(16, 185, 129, 0.25);
  letter-spacing: -0.5px;
  margin-bottom: 4px;
}
.ci-win-dest {
  font-size: 10px;
  font-weight: 900;
  color: #047857;
  background: #dcfce7;
  border: 1.5px solid #059669;
  border-radius: 8px;
  padding: 3px 8px;
  display: inline-block;
  margin-bottom: 18px;
}
</style>

<!-- TOP BANNER -->
<div class="ci-top-banner">
  <div class="ci-top-title">
    <i class="ph-fill ph-gift" style="color:#fde047;"></i>
    <span>Hadiah Check-in Harian</span>
  </div>
  <div class="ci-top-sub">Buka Kotak Keberuntungan & Dapatkan Saldo Tarik Tunai</div>
</div>

<div class="ci-page-wrap">

  <?php if ($flash && $flash !== 'checkin_ok'): ?>
    <div class="ci-flash">
      <i class="ph-bold ph-warning-circle" style="font-size:18px;"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- DUAL VAULT CAPSULE (SALDO TARIK & HARI AKTIF) -->
  <div class="ci-vault-capsule">
    <div class="ci-vault-tile">
      <div class="ci-vault-icon ci-vault-icon--wd">
        <i class="ph-bold ph-wallet"></i>
      </div>
      <div class="ci-vault-info">
        <div class="ci-vault-lbl">Saldo Tarik</div>
        <div class="ci-vault-val" id="display-user-balance-wd"><?= format_rp((float)$user['balance_wd']) ?></div>
      </div>
    </div>

    <div class="ci-vault-tile">
      <div class="ci-vault-icon ci-vault-icon--streak">
        <i class="ph-fill ph-fire"></i>
      </div>
      <div class="ci-vault-info">
        <div class="ci-vault-lbl">Streak</div>
        <div class="ci-vault-val"><span id="display-user-streak"><?= $streak ?></span> Hari</div>
      </div>
    </div>
  </div>

  <!-- 7-DAY STREAK TRACKER (NO CARD CONTAINER) -->
  <div class="ci-streak-strip">
    <?php for ($i = 1; $i <= 7; $i++):
      $is_done   = $i < $completed_days || ($i == $completed_days && $already);
      $is_today  = !$already && $i == $completed_days + 1;
      $cls = $is_done ? 'done' : ($is_today ? 'today' : 'future');
    ?>
      <div class="ci-streak-item">
        <div class="ci-streak-dot <?= $cls ?>" id="streak-dot-<?= $i ?>">
          <?php if ($is_done): ?>
            <i class="ph-bold ph-check"></i>
          <?php elseif ($is_today): ?>
            <i class="ph-fill ph-star"></i>
          <?php else: ?>
            <?= $i ?>
          <?php endif; ?>
        </div>
        <span class="ci-streak-lbl">H<?= $i ?></span>
      </div>
    <?php endfor; ?>
  </div>

  <!-- HONEYCOMB SECTION HEADER -->
  <div class="honeycomb-section-header">
    <div class="honeycomb-heading">
      <i class="ph-fill ph-sparkle" style="color:#f59e0b;"></i>
      <span><?= $already ? 'Kotak Hadiah Terbuka' : 'Pilih Kotak Keberuntunganmu' ?></span>
    </div>
    <div class="honeycomb-subtext">
      <?= $already ? 'Kamu telah membuka hadiah hari ini. Kembali besok untuk hadiah baru!' : 'Ketuk salah satu kotak untuk membuka hadiah dan ambil uang tunai!' ?>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       THE ORGANIC HEXAGON REWARD CLUSTER (NO CARD BOX WRAPPER)
       Row 1: 2 Hexagons
       Row 2: 3 Hexagons (Interlocking)
       Row 3: 2 Hexagons (Interlocking)
       Total 7 Authentic Hexagon Cells
       ══════════════════════════════════════════════════════════ -->
  <div class="honeycomb-hive-stage" id="hive-stage">
    
    <!-- Row 1: 2 Cells -->
    <div class="hex-row">
      <!-- Hex 1 -->
      <div class="hex-cell <?= !$already ? 'active-play' : ($reward_given > 0 ? 'opened' : 'dimmed') ?>" data-hex-index="1" onclick="handleHexPick(1, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-drop"></i>
          </div>
          <span class="hex-label">Hex 1</span>
        </div>
      </div>
      <!-- Hex 2 -->
      <div class="hex-cell <?= !$already ? 'active-play' : 'dimmed' ?>" data-hex-index="2" onclick="handleHexPick(2, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-sparkle"></i>
          </div>
          <span class="hex-label">Hex 2</span>
        </div>
      </div>
    </div>

    <!-- Row 2: 3 Cells -->
    <div class="hex-row">
      <!-- Hex 3 -->
      <div class="hex-cell <?= !$already ? 'active-play' : 'dimmed' ?>" data-hex-index="3" onclick="handleHexPick(3, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-drop"></i>
          </div>
          <span class="hex-label">Hex 3</span>
        </div>
      </div>
      <!-- Hex 4 (Center Queen Hive) -->
      <div class="hex-cell <?= !$already ? 'active-play' : 'dimmed' ?>" data-hex-index="4" onclick="handleHexPick(4, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-crown"></i>
          </div>
          <span class="hex-label">Royal</span>
        </div>
      </div>
      <!-- Hex 5 -->
      <div class="hex-cell <?= !$already ? 'active-play' : 'dimmed' ?>" data-hex-index="5" onclick="handleHexPick(5, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-sparkle"></i>
          </div>
          <span class="hex-label">Hex 5</span>
        </div>
      </div>
    </div>

    <!-- Row 3: 2 Cells -->
    <div class="hex-row">
      <!-- Hex 6 -->
      <div class="hex-cell <?= !$already ? 'active-play' : 'dimmed' ?>" data-hex-index="6" onclick="handleHexPick(6, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-drop"></i>
          </div>
          <span class="hex-label">Hex 6</span>
        </div>
      </div>
      <!-- Hex 7 -->
      <div class="hex-cell <?= !$already ? 'active-play' : 'dimmed' ?>" data-hex-index="7" onclick="handleHexPick(7, this)">
        <div class="hex-cell-inner">
          <div class="hex-icon-box">
            <i class="ph-fill ph-sparkle"></i>
          </div>
          <span class="hex-label">Hex 7</span>
        </div>
      </div>
    </div>

  </div>

  <!-- STATUS BOX BELOW HONEYCOMB -->
  <div class="ci-status-box">
    <?php if (!$already): ?>
      <div class="ci-status-badge ci-status-badge--ready">
        <i class="ph-fill ph-gift"></i> Kotak Hadiah Siap Dibuka
      </div>
      <div class="ci-status-title">Pilih Salah Satu Kotak di Atas</div>
      <div class="ci-status-desc">
        Setiap kotak menyimpan hadiah uang tunai acak berkisar <strong><?= format_rp($checkin_min) ?></strong> hingga <strong><?= format_rp($checkin_max) ?></strong> yang langsung masuk ke <strong>Saldo Tarik</strong>.
      </div>
    <?php else: ?>
      <div class="ci-status-badge ci-status-badge--done">
        <i class="ph-bold ph-check-circle"></i> Sudah Klaim Hari Ini
      </div>
      <div class="ci-status-title">Hadiah Berhasil Disimpan</div>
      <div class="ci-status-desc">
        Hadiah keberuntungan sedang disiapkan kembali. Kotak baru akan siap dibuka besok pagi pukul 00:00 WIB!
      </div>
    <?php endif; ?>

    <!-- Action Shortcuts -->
    <div class="ci-action-btns">
      <a href="/withdraw" class="ci-btn-action ci-btn-action--wd">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik Saldo
      </a>
      <a href="/minigame" class="ci-btn-action ci-btn-action--farm">
        <i class="ph-fill ph-game-controller"></i> Buka Minigame
      </a>
    </div>
  </div>

</div>

<!-- HIDDEN CSRF & FORM FOR FALLBACK -->
<form method="POST" id="checkin-fallback-form" style="display:none;">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="action" value="checkin">
</form>

<!-- ══════════════════════════════════════════════════════════
     CELEBRATION WIN MODAL OVERLAY
     ══════════════════════════════════════════════════════════ -->
<div id="ci-win-modal" class="ci-win-overlay">
  <div class="ci-win-box">
    <div class="ci-win-icon">
      <i class="ph-fill ph-coins"></i>
    </div>
    <div class="ci-win-title">Klaim Hadiah Berhasil!</div>
    <div class="ci-win-sub">Hadiah uang tunai keberuntungan harian:</div>
    <div class="ci-win-reward" id="win-modal-amt">+Rp 0</div>
    <div class="ci-win-dest">
      <i class="ph-bold ph-check"></i> Masuk Langsung ke Saldo Tarik
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="/withdraw" class="ci-btn-action ci-btn-action--wd" style="padding:12px;font-size:13px;">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik Tunai Sekarang
      </a>
      <button type="button" onclick="closeWinModal()" class="ci-btn-action ci-btn-action--farm" style="padding:10px;font-size:12px;background:#f8fafc;color:#475569;border-color:#cbd5e1;box-shadow:0 3px 0 #94a3b8;text-shadow:none;">
        Tutup & Lanjut Nonton
      </button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>';
let isProcessing = false;
let alreadyClaimed = <?= $already ? 'true' : 'false' ?>;

function handleHexPick(index, cellEl) {
  if (alreadyClaimed || isProcessing) return;
  isProcessing = true;

  // 1. Berikan efek burst/pop pada hexagon yang dipilih
  cellEl.classList.add('picking');
  
  // Redupkan hexagon lainnya
  document.querySelectorAll('.hex-cell').forEach(c => {
    if (c !== cellEl) c.classList.add('dimmed');
  });

  // 2. Kirim request AJAX ke halaman checkin saat ini
  const formData = new FormData();
  formData.append('action', 'checkin');
  formData.append('_csrf', CSRF_TOKEN);
  formData.append('csrf_token', CSRF_TOKEN);
  formData.append('ajax', '1');

  fetch(window.location.pathname, {
    method: 'POST',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': CSRF_TOKEN,
      'Accept': 'application/json'
    },
    body: formData
  })
  .then(res => {
    return res.text().then(text => {
      try {
        return JSON.parse(text);
      } catch (e) {
        console.error('Invalid JSON response:', text);
        throw new Error('Respons server bukan JSON');
      }
    });
  })
  .then(data => {
    if (data.success) {
      alreadyClaimed = true;
      
      // Tunggu animasi pop selesai
      setTimeout(() => {
        // Ganti konten inner cell menjadi ikon koin & nominal hadiah
        cellEl.classList.remove('picking');
        cellEl.classList.add('opened');
        
        const inner = cellEl.querySelector('.hex-cell-inner');
        if (inner) {
          inner.innerHTML = `
            <div class="hex-icon-box" style="color:#065f46;">
              <i class="ph-fill ph-coins"></i>
            </div>
            <div class="hex-label" style="color:#065f46;font-size:9.5px;font-weight:900;">${data.reward_formatted}</div>
          `;
        }

        // Update display Saldo Tarik dan Streak di halaman secara dinamis
        const balEl = document.getElementById('display-user-balance-wd');
        if (balEl && data.new_balance_formatted) {
          balEl.innerText = data.new_balance_formatted;
        }
        const streakEl = document.getElementById('display-user-streak');
        if (streakEl && data.streak) {
          streakEl.innerText = data.streak;
        }

        // Tampilkan Modal Pemenang
        showWinModal(data.reward_formatted);
      }, 700);

    } else {
      isProcessing = false;
      cellEl.classList.remove('picking');
      document.querySelectorAll('.hex-cell').forEach(c => c.classList.remove('dimmed'));
      alert(data.message || 'Gagal klaim check-in.');
    }
  })
  .catch(err => {
    console.warn('Checkin AJAX fallback ke form submit:', err);
    // Submit fallback form jika AJAX bermasalah
    const fallbackForm = document.getElementById('checkin-fallback-form');
    if (fallbackForm) {
      fallbackForm.submit();
    } else {
      isProcessing = false;
      cellEl.classList.remove('picking');
      document.querySelectorAll('.hex-cell').forEach(c => c.classList.remove('dimmed'));
    }
  });
}

function showWinModal(amtFormatted) {
  const modal = document.getElementById('ci-win-modal');
  const amtEl = document.getElementById('win-modal-amt');
  if (amtEl) amtEl.innerText = '+' + amtFormatted;
  if (!modal) return;

  modal.style.display = 'flex';
  setTimeout(() => {
    const box = modal.querySelector('.ci-win-box');
    if (box) { box.style.transform = 'scale(1)'; box.style.opacity = '1'; }
  }, 40);
}

function closeWinModal() {
  const modal = document.getElementById('ci-win-modal');
  if (modal) {
    const box = modal.querySelector('.ci-win-box');
    if (box) { box.style.transform = 'scale(0.85)'; box.style.opacity = '0'; }
    setTimeout(() => { modal.style.display = 'none'; }, 280);
  }
}

// Jika ada reward dari session (misal setelah redirect biasa)
<?php if ($flash === 'checkin_ok' && $reward_given > 0): ?>
document.addEventListener('DOMContentLoaded', () => {
  showWinModal('<?= format_rp((float)$reward_given) ?>');
});
<?php endif; ?>
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
