<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$cms_cfg = home_cms_config($pdo);
$fishing_cfg = $cms_cfg['fishing_engine'] ?? [];
$is_fishing_enabled = !empty($fishing_cfg['enabled']);

if (!$is_fishing_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Fitur Pemancingan Cuan sedang dinonaktifkan sementara oleh Administrator.';
    redirect('/home');
}

// Ensure table fishing_logs exists
$pdo->exec("CREATE TABLE IF NOT EXISTS fishing_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    fish_name VARCHAR(100) NOT NULL,
    fish_icon VARCHAR(50) NOT NULL DEFAULT '🐟',
    rarity VARCHAR(30) NOT NULL DEFAULT 'common',
    weight_kg DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    reward DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Refresh fresh user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

$daily_free_baits = max(0, (int)($fishing_cfg['daily_free_baits'] ?? 3));
$reward_min = max(10, (float)($fishing_cfg['reward_min'] ?? 100));
$reward_max = max(50, (float)($fishing_cfg['reward_max'] ?? 5000));

// Auto refill daily bait if below threshold on new day
$todayStr = date('Y-m-d');
$lastPlayedDate = $user['last_plinko_claim'] ?? ''; // or check last checkin
if ($lastPlayedDate !== $todayStr && (int)($user['fishing_baits'] ?? 0) < $daily_free_baits) {
    $pdo->prepare("UPDATE users SET fishing_baits = ?, last_plinko_claim = ? WHERE id = ?")
        ->execute([$daily_free_baits, $todayStr, $user['id']]);
    $user['fishing_baits'] = $daily_free_baits;
}

// ─────────────────────────────────────────────────────────────
// AJAX API HANDLER
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // 1. LEMPAR KAIL (CAST ROD)
    if ($action === 'cast_rod') {
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'msg' => 'Sesi keamanan kedaluwarsa. Silakan refresh halaman!']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Lock user row
            $s = $pdo->prepare("SELECT id, balance_wd, total_earned, fishing_baits FROM users WHERE id = ? FOR UPDATE");
            $s->execute([$user['id']]);
            $dbUser = $s->fetch(PDO::FETCH_ASSOC);

            if (!$dbUser) {
                throw new Exception('Pengguna tidak ditemukan.');
            }

            $current_baits = (int)($dbUser['fishing_baits'] ?? 0);
            if ($current_baits < 1) {
                $pdo->rollBack();
                echo json_encode([
                    'ok' => false,
                    'msg' => 'Umpan pancingmu habis! Tonton video atau beli paket umpan untuk melanjutkan.',
                    'out_of_baits' => true
                ]);
                exit;
            }

            // Deduct 1 bait
            $new_baits = $current_baits - 1;

            // Generate Fish Catch via Weighted Probability
            $roll = mt_rand(1, 100);
            $fish = null;

            if ($roll <= 45) {
                // Common: Ikan Mujair Cuan (45%)
                $weight = round(mt_rand(50, 140) / 100, 2);
                $reward = round($reward_min + ($weight / 1.4) * ($reward_min * 1.5));
                $fish = [
                    'name'   => 'Ikan Mujair Cuan',
                    'icon'   => '🐟',
                    'rarity' => 'Common',
                    'badge'  => 'biasa',
                    'color'  => '#0284c7',
                    'weight' => $weight,
                    'reward' => $reward,
                ];
            } elseif ($roll <= 75) {
                // Uncommon: Ikan Gurame Emas (30%)
                $weight = round(mt_rand(150, 290) / 100, 2);
                $reward = round($reward_min * 2.5 + ($weight / 2.9) * ($reward_min * 3));
                $fish = [
                    'name'   => 'Ikan Gurame Emas',
                    'icon'   => '🐠',
                    'rarity' => 'Uncommon',
                    'badge'  => 'bagus',
                    'color'  => '#d97706',
                    'weight' => $weight,
                    'reward' => $reward,
                ];
            } elseif ($roll <= 90) {
                // Rare: Ikan Kakap Merah VIP (15%)
                $weight = round(mt_rand(300, 480) / 100, 2);
                $reward = round($reward_min * 6.0 + ($weight / 4.8) * ($reward_min * 5));
                $fish = [
                    'name'   => 'Ikan Kakap Merah VIP',
                    'icon'   => '🐡',
                    'rarity' => 'Rare',
                    'badge'  => 'langka',
                    'color'  => '#e11d48',
                    'weight' => $weight,
                    'reward' => $reward,
                ];
            } elseif ($roll <= 97) {
                // Epic: Ikan Monster Barramundi (7%)
                $weight = round(mt_rand(500, 850) / 100, 2);
                $reward = round($reward_max * 0.75 + ($weight / 8.5) * ($reward_max * 0.5));
                $fish = [
                    'name'   => 'Monster Barramundi',
                    'icon'   => '🦈',
                    'rarity' => 'Epic',
                    'badge'  => 'super',
                    'color'  => '#7c3aed',
                    'weight' => $weight,
                    'reward' => $reward,
                ];
            } else {
                // Jackpot: Peti Harta Karun Lautan (3%)
                $weight = round(mt_rand(1000, 1500) / 100, 2);
                $reward = round($reward_max * 1.5 + mt_rand(1000, 3000));
                $fish = [
                    'name'   => 'Peti Harta Karun Lautan 👑',
                    'icon'   => '✨',
                    'rarity' => 'JACKPOT',
                    'badge'  => 'jackpot',
                    'color'  => '#10b981',
                    'weight' => $weight,
                    'reward' => $reward,
                ];
            }

            // Update user balance
            $upd = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ?, fishing_baits = ? WHERE id = ?");
            $upd->execute([$fish['reward'], $fish['reward'], $new_baits, $user['id']]);

            // Insert into fishing_logs
            $logIns = $pdo->prepare("
                INSERT INTO fishing_logs (user_id, fish_name, fish_icon, rarity, weight_kg, reward)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $logIns->execute([
                $user['id'],
                $fish['name'],
                $fish['icon'],
                $fish['rarity'],
                $fish['weight'],
                $fish['reward']
            ]);

            // Also log into minigame_logs
            $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'mancing', ?, ?)")
                ->execute([$user['id'], (int)($fish['weight'] * 10), $fish['reward']]);

            $pdo->commit();

            $new_balance_wd = (float)$dbUser['balance_wd'] + $fish['reward'];

            echo json_encode([
                'ok'             => true,
                'fish'           => $fish,
                'remaining_baits'=> $new_baits,
                'balance_wd'     => format_rp($new_balance_wd),
                'reward_rp'      => format_rp($fish['reward']),
            ]);
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'msg' => 'Gagal memancing: ' . $e->getMessage()]);
            exit;
        }
    }

    // 2. BELI PAKET UMPAN PAKAI SALDO BELI (DEPOSIT)
    if ($action === 'buy_baits') {
        if (!csrf_verify()) {
            echo json_encode(['ok' => false, 'msg' => 'Sesi keamanan kedaluwarsa.']);
            exit;
        }

        $pack_tier = (int)($_POST['pack_tier'] ?? 1);
        $packs = [
            1 => ['qty' => 5,  'price' => 5000,  'label' => 'Paket Cacing Tanah (5 Umpan)'],
            2 => ['qty' => 15, 'price' => 12000, 'label' => 'Paket Pelet Super (15 Umpan) - Diskon 20%'],
            3 => ['qty' => 35, 'price' => 25000, 'label' => 'Paket Udang Hidup VIP (35 Umpan) - Diskon 30%'],
        ];

        if (!isset($packs[$pack_tier])) {
            echo json_encode(['ok' => false, 'msg' => 'Pilihan paket tidak valid.']);
            exit;
        }

        $chosen = $packs[$pack_tier];

        try {
            $pdo->beginTransaction();

            $s = $pdo->prepare("SELECT id, balance_dep, fishing_baits FROM users WHERE id = ? FOR UPDATE");
            $s->execute([$user['id']]);
            $dbUser = $s->fetch(PDO::FETCH_ASSOC);

            if ((float)$dbUser['balance_dep'] < $chosen['price']) {
                $pdo->rollBack();
                echo json_encode([
                    'ok' => false,
                    'msg' => 'Saldo beli Anda tidak mencukupi (' . format_rp($chosen['price']) . '). Silakan lakukan Top Up terlebih dahulu!',
                    'need_deposit' => true
                ]);
                exit;
            }

            $new_dep = (float)$dbUser['balance_dep'] - $chosen['price'];
            $new_baits = (int)($dbUser['fishing_baits'] ?? 0) + $chosen['qty'];

            $pdo->prepare("UPDATE users SET balance_dep = ?, fishing_baits = ? WHERE id = ?")
                ->execute([$new_dep, $new_baits, $user['id']]);

            $pdo->commit();

            echo json_encode([
                'ok'          => true,
                'msg'         => "🎉 Berhasil membeli {$chosen['label']}!",
                'new_baits'   => $new_baits,
                'balance_dep' => format_rp($new_dep)
            ]);
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Gagal transaksi: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'msg' => 'Aksi tidak dikenal.']);
    exit;
}

// ─────────────────────────────────────────────────────────────
// DATA UNTUK TAMPILAN
// ─────────────────────────────────────────────────────────────
$todayCatches = [];
$todayCatchesTotal = 0.0;
try {
    $cStmt = $pdo->prepare("
        SELECT * FROM fishing_logs 
        WHERE user_id = ? AND DATE(created_at) = CURDATE()
        ORDER BY id DESC
        LIMIT 10
    ");
    $cStmt->execute([$user['id']]);
    $todayCatches = $cStmt->fetchAll(PDO::FETCH_ASSOC);

    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(reward), 0) FROM fishing_logs WHERE user_id = ? AND DATE(created_at) = CURDATE()");
    $sumStmt->execute([$user['id']]);
    $todayCatchesTotal = (float)$sumStmt->fetchColumn();
} catch (\Throwable) {}

$pageTitle = 'Mancing Cuan Nusantara';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   MANCINGCUAN — INTERACTIVE FISHING CLAYMORPHISM SYSTEM
   ══════════════════════════════════════════════════════════ */
:root {
  --water-deep: #0369a1;
  --water-light: #38bdf8;
  --sand-bg: #fef8ee;
  --wood-border: #78350f;
}

.fishing-page-wrap {
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 90px;
}

/* ── Top Dashboard Header ── */
.fishing-status-bar {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}

.f-stat-card {
  background: #ffffff;
  border: 2px solid rgba(255,255,255,0.95);
  border-radius: 20px;
  padding: 12px 14px;
  box-shadow: 0 10px 24px rgba(120,53,15,0.08), inset 2px 2px 5px #ffffff, inset -2px -2px 5px rgba(180,83,9,0.06);
}

.f-stat-card__lbl {
  font-size: 10.5px;
  font-weight: 800;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 2px;
}

.f-stat-card__val {
  font-size: 16px;
  font-weight: 900;
  color: #1e293b;
}

/* ── Fishing Lake Scene (Volumetric Clay Canvas) ── */
.fishing-lake-card {
  position: relative;
  height: 290px;
  background: linear-gradient(180deg, #67e8f9 0%, #0284c7 40%, #0369a1 100%);
  border-radius: 28px;
  border: 3.5px solid #ffffff;
  box-shadow: 0 16px 36px rgba(2,132,199,0.25), inset 0 4px 10px rgba(255,255,255,0.6), inset 0 -8px 16px rgba(3,105,161,0.5);
  overflow: hidden;
  margin-bottom: 16px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 16px;
}

/* Sun & Clouds */
.lake-sky {
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 2;
}

.lake-sun {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: radial-gradient(circle, #fde047 30%, #f59e0b 100%);
  box-shadow: 0 0 20px rgba(253,224,71,0.8);
}

.lake-cloud {
  background: rgba(255,255,255,0.85);
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 800;
  color: #0369a1;
  box-shadow: 0 4px 10px rgba(0,0,0,0.06);
}

/* Animated Water Waves */
.lake-waves {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 180px;
  pointer-events: none;
  z-index: 1;
}

.lake-wave {
  position: absolute;
  bottom: 0;
  left: -20%;
  width: 140%;
  height: 100%;
  opacity: 0.35;
  background-repeat: repeat-x;
  background-position: 0 bottom;
  transform-origin: center bottom;
}

.lake-wave--1 {
  background: radial-gradient(ellipse at 50% 100%, #38bdf8 0%, transparent 70%);
  animation: waveMove 4s ease-in-out infinite alternate;
}

.lake-wave--2 {
  background: radial-gradient(ellipse at 50% 100%, #0284c7 0%, transparent 60%);
  animation: waveMove 3s ease-in-out infinite alternate-reverse;
  opacity: 0.5;
}

@keyframes waveMove {
  0%   { transform: translateY(0) scaleY(1); }
  100% { transform: translateY(-8px) scaleY(1.05); }
}

/* Animated Bobber (Pelampung Pancing) */
.lake-bobber {
  position: absolute;
  top: 52%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 32px;
  height: 42px;
  z-index: 3;
  transition: all 0.3s ease;
}

.bobber-body {
  width: 22px;
  height: 32px;
  margin: 0 auto;
  border-radius: 50% 50% 40% 40%;
  background: linear-gradient(180deg, #ef4444 0%, #ef4444 50%, #ffffff 50%, #ffffff 100%);
  border: 2px solid rgba(0,0,0,0.25);
  box-shadow: 0 6px 12px rgba(0,0,0,0.25);
  animation: bobberIdle 2.5s ease-in-out infinite;
}

.bobber-antenna {
  width: 3px;
  height: 10px;
  background: #1e293b;
  margin: 0 auto;
  border-radius: 2px;
}

.lake-bobber.is-waiting .bobber-body {
  animation: bobberWaiting 0.8s ease-in-out infinite alternate;
}

.lake-bobber.is-strike .bobber-body {
  animation: bobberStrike 0.15s ease-in-out infinite;
  transform: scale(1.15);
}

@keyframes bobberIdle {
  0%, 100% { transform: translateY(0) rotate(-3deg); }
  50%      { transform: translateY(-6px) rotate(3deg); }
}

@keyframes bobberWaiting {
  0%   { transform: translateY(-3px) rotate(-6deg); }
  100% { transform: translateY(5px) rotate(6deg); }
}

@keyframes bobberStrike {
  0%   { transform: translateY(8px) rotate(-8deg); }
  50%  { transform: translateY(-4px) rotate(8deg); }
  100% { transform: translateY(12px) rotate(0deg); }
}

/* Water Ripple Effect */
.water-ripple {
  position: absolute;
  top: 60%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 60px;
  height: 20px;
  border: 2px solid rgba(255,255,255,0.6);
  border-radius: 50%;
  opacity: 0;
  z-index: 2;
  pointer-events: none;
}

.lake-bobber.is-strike + .water-ripple {
  animation: ripplePop 0.8s ease-out infinite;
}

@keyframes ripplePop {
  0%   { width: 30px; height: 10px; opacity: 0.9; }
  100% { width: 120px; height: 40px; opacity: 0; }
}

/* Fishing Controls */
.fishing-action-bar {
  margin-top: -6px;
  position: relative;
  z-index: 10;
}

.btn-fish-cast {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  padding: 16px 20px;
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  color: #ffffff;
  border: 3px solid #ffffff;
  border-radius: 22px;
  font-size: 16px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 12px 28px rgba(2,132,199,0.35), inset 2px 2px 4px rgba(255,255,255,0.6), inset -2px -2px 4px rgba(3,105,161,0.5);
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  text-shadow: 0 1px 2px rgba(3,105,161,0.6);
}

.btn-fish-cast:hover {
  transform: translateY(-2px);
  box-shadow: 0 14px 32px rgba(2,132,199,0.45);
}

.btn-fish-cast:active {
  transform: translateY(2px);
}

.btn-fish-cast.is-strike {
  background: linear-gradient(135deg, #e11d48 0%, #be123c 100%) !important;
  box-shadow: 0 12px 28px rgba(225,29,72,0.45), inset 2px 2px 4px rgba(255,255,255,0.6) !important;
  animation: strikePulse 0.4s ease-in-out infinite alternate;
}

@keyframes strikePulse {
  0%   { transform: scale(0.98); }
  100% { transform: scale(1.02); }
}

/* Fish Catch Modal */
.fish-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15,23,42,0.75);
  backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.25s ease;
}

.fish-modal-backdrop.is-open {
  opacity: 1;
  pointer-events: auto;
}

.fish-modal-card {
  width: 100%;
  max-width: 380px;
  background: #ffffff;
  border-radius: 28px;
  border: 3.5px solid #ffffff;
  box-shadow: 0 24px 48px rgba(0,0,0,0.3), inset 2px 2px 6px #ffffff;
  padding: 26px 22px;
  text-align: center;
  position: relative;
  transform: scale(0.9);
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.fish-modal-backdrop.is-open .fish-modal-card {
  transform: scale(1);
}

.fish-splash-icon {
  width: 88px;
  height: 88px;
  margin: 0 auto 14px;
  background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
  border: 3px solid #38bdf8;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 46px;
  box-shadow: 0 10px 24px rgba(56,189,248,0.3);
  animation: fishBounce 0.6s ease-in-out infinite alternate;
}

@keyframes fishBounce {
  0%   { transform: translateY(0) scale(0.95); }
  100% { transform: translateY(-8px) scale(1.05); }
}

.fish-name-txt {
  font-size: 20px;
  font-weight: 900;
  color: #0f172a;
  margin-bottom: 4px;
}

.fish-reward-tag {
  display: inline-block;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: #ffffff;
  font-size: 15px;
  font-weight: 900;
  padding: 6px 16px;
  border-radius: 999px;
  margin-bottom: 16px;
  box-shadow: 0 6px 14px rgba(16,185,129,0.35);
}

/* Catches History Table */
.catches-history-card {
  background: #ffffff;
  border: 2px solid rgba(255,255,255,0.95);
  border-radius: 22px;
  padding: 16px;
  box-shadow: 0 10px 24px rgba(120,53,15,0.06);
  margin-top: 16px;
}

.catch-item-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #f1f5f9;
}

.catch-item-row:last-child {
  border-bottom: none;
}
</style>

<div class="fishing-page-wrap">

  <!-- Top Title Bar -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
    <div>
      <div style="font-size:18px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-fish" style="color:#0284c7;font-size:24px;"></i>
        <span>Pemancingan Cuan Nusantara</span>
      </div>
      <div style="font-size:11px;font-weight:700;color:#92400e;">Lempar kail, strike ikan cuan, dan tarik rupiah instan!</div>
    </div>
    <a href="/home" style="width:34px;height:34px;border-radius:12px;background:#fff;border:2px solid #fed7aa;display:flex;align-items:center;justify-content:center;color:#b45309;text-decoration:none;font-weight:800;">
      <i class="ph-bold ph-x"></i>
    </a>
  </div>

  <!-- Status Bar (Sisa Umpan & Saldo WD) -->
  <div class="fishing-status-bar">
    <div class="f-stat-card">
      <div class="f-stat-card__lbl">
        <i class="ph-fill ph-sparkle" style="color:#f59e0b;"></i> Sisa Umpan Kamu
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;">
        <span class="f-stat-card__val" id="disp-baits-count">
          🪱 <?= (int)($user['fishing_baits'] ?? 0) ?> Umpan
        </span>
        <button type="button" onclick="openBaitShopModal()" style="background:#fef3c7;border:1.5px solid #f59e0b;color:#b45309;font-size:10px;font-weight:900;padding:3px 8px;border-radius:8px;cursor:pointer;">
          + Beli
        </button>
      </div>
    </div>

    <div class="f-stat-card">
      <div class="f-stat-card__lbl">
        <i class="ph-fill ph-trophy" style="color:#10b981;"></i> Cuan Mancing Hari Ini
      </div>
      <div class="f-stat-card__val" style="color:#059669;" id="disp-today-cuan">
        <?= format_rp($todayCatchesTotal) ?>
      </div>
    </div>
  </div>

  <!-- The Fishing Lake Canvas -->
  <div class="fishing-lake-card" id="lake-scene">
    <div class="lake-sky">
      <div class="lake-sun"></div>
      <div class="lake-cloud">
        <i class="ph-fill ph-waves"></i> Danau MancingCuan
      </div>
    </div>

    <!-- Bobber Pelampung -->
    <div class="lake-bobber" id="bobber-el">
      <div class="bobber-antenna"></div>
      <div class="bobber-body"></div>
    </div>
    <div class="water-ripple"></div>

    <div class="lake-waves">
      <div class="lake-wave lake-wave--1"></div>
      <div class="lake-wave lake-wave--2"></div>
    </div>

    <!-- Status Teks Game -->
    <div style="text-align:center;z-index:4;">
      <span id="lake-status-msg" style="background:rgba(0,0,0,0.45);color:#fff;font-size:11px;font-weight:800;padding:4px 14px;border-radius:999px;backdrop-filter:blur(4px);">
        Siap lempar kail! Tekan tombol di bawah untuk mulai.
      </span>
    </div>
  </div>

  <!-- Fishing Action Button -->
  <div class="fishing-action-bar">
    <button type="button" class="btn-fish-cast" id="btn-cast-action" onclick="handleCastClick()">
      <i class="ph-fill ph-fish" id="btn-cast-ico" style="font-size:22px;"></i>
      <span id="btn-cast-lbl">Lempar Kail (Pakai 1 Umpan)</span>
    </button>
  </div>

  <!-- How to Get Baits Banner -->
  <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:18px;padding:12px 14px;margin-top:14px;display:flex;align-items:center;justify-content:space-between;gap:8px;">
    <div style="font-size:11px;font-weight:700;color:#166534;line-height:1.4;">
      💡 <strong>Mau Umpan Gratis?</strong> Tonton video sampai selesai (+1 Umpan/video) atau login tiap hari!
    </div>
    <a href="/videos" style="background:#16a34a;color:#fff;font-size:10.5px;font-weight:900;padding:6px 12px;border-radius:10px;text-decoration:none;white-space:nowrap;flex-shrink:0;">
      Nonton Video &rarr;
    </a>
  </div>

  <!-- Today Catches History -->
  <div class="catches-history-card">
    <div style="font-size:13px;font-weight:900;color:#1e293b;display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <span>Riwayat Tangkapan Hari Ini</span>
      <span style="font-size:11px;color:#64748b;"><?= count($todayCatches) ?> Ikan Terkumpul</span>
    </div>

    <div id="catches-list-wrap">
      <?php if (empty($todayCatches)): ?>
        <div style="text-align:center;padding:18px 0;color:#94a3b8;font-size:12px;font-weight:700;" id="empty-catches-notice">
          Belum ada tangkapan hari ini. Yuk lempar kailmu sekarang!
        </div>
      <?php else: ?>
        <?php foreach ($todayCatches as $tc): ?>
          <div class="catch-item-row">
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="font-size:20px;"><?= htmlspecialchars($tc['fish_icon']) ?></span>
              <div>
                <div style="font-size:12px;font-weight:800;color:#1e293b;"><?= htmlspecialchars($tc['fish_name']) ?></div>
                <div style="font-size:10px;color:#64748b;"><?= $tc['weight_kg'] ?> Kg · <?= date('H:i', strtotime($tc['created_at'])) ?></div>
              </div>
            </div>
            <div style="font-size:12px;font-weight:900;color:#10b981;">
              +<?= format_rp((float)$tc['reward']) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ══════════════════════════════════════════════
     MODAL POPUP 1: IKAN TERTANGKAP (CELEBRATION)
     ══════════════════════════════════════════════ -->
<div class="fish-modal-backdrop" id="modal-catch-el">
  <div class="fish-modal-card">
    <div class="fish-splash-icon" id="mc-icon">🐟</div>
    <div style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:1px;color:#0284c7;margin-bottom:2px;" id="mc-rarity">
      STRIKE BERHASIL!
    </div>
    <div class="fish-name-txt" id="mc-name">Ikan Gurame Emas</div>
    <div style="font-size:12px;font-weight:700;color:#64748b;margin-bottom:12px;" id="mc-weight">
      Bobot: 2.45 Kg
    </div>
    <div class="fish-reward-tag" id="mc-reward">+Rp 1.500 Saldo Tarik</div>
    <p style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:18px;">
      Hadiah sudah otomatis ditambahkan ke <strong>Saldo Tarik (WD)</strong> akunmu!
    </p>
    <button type="button" class="btn-fish-cast" onclick="closeCatchModal()" style="padding:12px 20px;font-size:14px;">
      <i class="ph-bold ph-check"></i> Ambil Hadiah &amp; Lanjut Mancing
    </button>
  </div>
</div>

<!-- ══════════════════════════════════════════════
     MODAL POPUP 2: TOKO PAKET UMPAN
     ══════════════════════════════════════════════ -->
<div class="fish-modal-backdrop" id="modal-shop-el">
  <div class="fish-modal-card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
      <div style="font-size:16px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
        <span>🪱 Toko Umpan Cuan</span>
      </div>
      <button type="button" onclick="closeBaitShopModal()" style="border:none;background:transparent;font-size:20px;cursor:pointer;color:#64748b;">×</button>
    </div>

    <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:14px;padding:10px;margin-bottom:14px;font-size:11.5px;color:#475569;text-align:left;">
      Saldo Beli Kamu: <strong style="color:#0284c7;" id="disp-shop-dep"><?= format_rp((float)($user['balance_dep'] ?? 0)) ?></strong>
      <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Beli umpan menggunakan Saldo Beli (Deposit).</div>
    </div>

    <!-- Tier 1 -->
    <div style="background:#fff;border:2px solid #e2e8f0;border-radius:16px;padding:10px 12px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;text-align:left;">
      <div>
        <div style="font-size:12.5px;font-weight:800;color:#1e293b;">Paket Cacing Tanah</div>
        <div style="font-size:10.5px;color:#64748b;">5 Umpan · Rp 5.000</div>
      </div>
      <button type="button" onclick="buyBaitPack(1)" style="background:#0284c7;color:#fff;border:none;font-size:11px;font-weight:800;padding:6px 12px;border-radius:10px;cursor:pointer;">
        Beli
      </button>
    </div>

    <!-- Tier 2 -->
    <div style="background:#fff;border:2px solid #f59e0b;border-radius:16px;padding:10px 12px;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;text-align:left;">
      <div>
        <div style="font-size:12.5px;font-weight:800;color:#1e293b;">
          Paket Pelet Super <span style="font-size:9px;background:#fef3c7;color:#b45309;padding:1px 6px;border-radius:6px;">HEMAT 20%</span>
        </div>
        <div style="font-size:10.5px;color:#64748b;">15 Umpan · Rp 12.000</div>
      </div>
      <button type="button" onclick="buyBaitPack(2)" style="background:#f59e0b;color:#fff;border:none;font-size:11px;font-weight:800;padding:6px 12px;border-radius:10px;cursor:pointer;">
        Beli
      </button>
    </div>

    <!-- Tier 3 -->
    <div style="background:#fff;border:2px solid #10b981;border-radius:16px;padding:10px 12px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;text-align:left;">
      <div>
        <div style="font-size:12.5px;font-weight:800;color:#1e293b;">
          Paket Udang Hidup VIP <span style="font-size:9px;background:#d1fae5;color:#047857;padding:1px 6px;border-radius:6px;">HEMAT 30%</span>
        </div>
        <div style="font-size:10.5px;color:#64748b;">35 Umpan · Rp 25.000</div>
      </div>
      <button type="button" onclick="buyBaitPack(3)" style="background:#10b981;color:#fff;border:none;font-size:11px;font-weight:800;padding:6px 12px;border-radius:10px;cursor:pointer;">
        Beli
      </button>
    </div>

    <a href="/deposit" style="font-size:11px;font-weight:800;color:#0284c7;text-decoration:none;">
      Top Up Saldo Beli via QRIS / Bank &rarr;
    </a>
  </div>
</div>

<script>
let gameState = 'idle'; // 'idle', 'waiting', 'strike', 'reeling'
let currentStrikeFish = null;
let csrfToken = '<?= csrf_token() ?>';

function handleCastClick() {
  if (gameState === 'waiting') return;

  if (gameState === 'strike') {
    // User strikes/reels in!
    claimCatch();
    return;
  }

  // State is idle -> start casting
  castRod();
}

function castRod() {
  const btn = document.getElementById('btn-cast-action');
  const btnLbl = document.getElementById('btn-cast-lbl');
  const bobber = document.getElementById('bobber-el');
  const statusMsg = document.getElementById('lake-status-msg');

  gameState = 'waiting';
  btn.disabled = true;
  btn.style.opacity = '0.7';
  btnLbl.textContent = 'Kail di air... Menunggu ikan menyambar 🐟';
  bobber.className = 'lake-bobber is-waiting';
  statusMsg.textContent = 'Perhatikan pelampung... bersiap untuk menarik saat STRIKE!';

  // Delay random 1.8s - 3.2s for bite
  const waitMs = Math.floor(Math.random() * 1400) + 1800;
  setTimeout(() => {
    triggerStrike();
  }, waitMs);
}

function triggerStrike() {
  const btn = document.getElementById('btn-cast-action');
  const btnLbl = document.getElementById('btn-cast-lbl');
  const bobber = document.getElementById('bobber-el');
  const statusMsg = document.getElementById('lake-status-msg');

  gameState = 'strike';
  btn.disabled = false;
  btn.style.opacity = '1';
  btn.className = 'btn-fish-cast is-strike';
  btnLbl.textContent = '🔥 STRIKE! TARIK KAIL SEKARANG!';
  bobber.className = 'lake-bobber is-strike';
  statusMsg.textContent = '⚡ IKAN MENYAMBAR! KLIK TOMBOL SEBELUM LEPAS!';

  // Vibrate phone if supported
  if (navigator.vibrate) {
    navigator.vibrate([100, 50, 100]);
  }
}

function claimCatch() {
  const btn = document.getElementById('btn-cast-action');
  const btnLbl = document.getElementById('btn-cast-lbl');
  const bobber = document.getElementById('bobber-el');
  const statusMsg = document.getElementById('lake-status-msg');

  gameState = 'reeling';
  btn.disabled = true;
  btnLbl.textContent = 'Sedang mengangkat ikan ke permukaan...';
  statusMsg.textContent = 'Tarik pelan-pelan...';

  const fd = new FormData();
  fd.append('action', 'cast_rod');
  fd.append('csrf_token', csrfToken);

  fetch('/minigame', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(res => {
    resetLakeState();

    if (!res.ok) {
      if (res.out_of_baits) {
        openBaitShopModal();
      }
      if (typeof nToast === 'function') nToast(res.msg, 'error');
      else alert(res.msg);
      return;
    }

    // Success! Show Catch Modal
    currentStrikeFish = res.fish;
    showCatchModal(res.fish, res.reward_rp);

    // Update UI counters
    document.getElementById('disp-baits-count').textContent = '🪱 ' + res.remaining_baits + ' Umpan';
    
    // Add row to history
    prependCatchRow(res.fish);

    if (typeof nToast === 'function') {
      nToast('🎉 Menangkap ' + res.fish.name + ' (+Rp ' + res.reward_rp + ')!', 'success');
    }
  })
  .catch(err => {
    resetLakeState();
    console.error(err);
    alert('Terjadi kendala jaringan saat mengangkat kail.');
  });
}

function resetLakeState() {
  gameState = 'idle';
  const btn = document.getElementById('btn-cast-action');
  const btnLbl = document.getElementById('btn-cast-lbl');
  const bobber = document.getElementById('bobber-el');
  const statusMsg = document.getElementById('lake-status-msg');

  btn.disabled = false;
  btn.style.opacity = '1';
  btn.className = 'btn-fish-cast';
  btnLbl.textContent = 'Lempar Kail (Pakai 1 Umpan)';
  bobber.className = 'lake-bobber';
  statusMsg.textContent = 'Siap lempar kail! Tekan tombol untuk mulai.';
}

function showCatchModal(fish, rewardRp) {
  document.getElementById('mc-icon').textContent = fish.icon;
  document.getElementById('mc-rarity').textContent = fish.rarity + ' CATCH!';
  document.getElementById('mc-name').textContent = fish.name;
  document.getElementById('mc-weight').textContent = 'Bobot: ' + fish.weight + ' Kg';
  document.getElementById('mc-reward').textContent = '+' + rewardRp + ' Saldo Tarik';
  
  document.getElementById('modal-catch-el').classList.add('is-open');
}

function closeCatchModal() {
  document.getElementById('modal-catch-el').classList.remove('is-open');
}

function openBaitShopModal() {
  document.getElementById('modal-shop-el').classList.add('is-open');
}

function closeBaitShopModal() {
  document.getElementById('modal-shop-el').classList.remove('is-open');
}

function buyBaitPack(tier) {
  const fd = new FormData();
  fd.append('action', 'buy_baits');
  fd.append('pack_tier', tier);
  fd.append('csrf_token', csrfToken);

  fetch('/minigame', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(res => {
    if (!res.ok) {
      if (res.need_deposit) {
        if (confirm(res.msg + '\n\nBuka halaman Deposit sekarang?')) {
          window.location.href = '/deposit';
        }
      } else {
        alert(res.msg);
      }
      return;
    }

    // Success
    document.getElementById('disp-baits-count').textContent = '🪱 ' + res.new_baits + ' Umpan';
    document.getElementById('disp-shop-dep').textContent = res.balance_dep;
    closeBaitShopModal();
    if (typeof nToast === 'function') nToast(res.msg, 'success');
    else alert(res.msg);
  })
  .catch(err => {
    console.error(err);
    alert('Gagal memproses pembelian umpan.');
  });
}

function prependCatchRow(fish) {
  const list = document.getElementById('catches-list-wrap');
  const emptyNotice = document.getElementById('empty-catches-notice');
  if (emptyNotice) emptyNotice.remove();

  const row = document.createElement('div');
  row.className = 'catch-item-row';
  row.innerHTML = `
    <div style="display:flex;align-items:center;gap:8px;">
      <span style="font-size:20px;">${fish.icon}</span>
      <div>
        <div style="font-size:12px;font-weight:800;color:#1e293b;">${fish.name}</div>
        <div style="font-size:10px;color:#64748b;">${fish.weight} Kg · Baru saja</div>
      </div>
    </div>
    <div style="font-size:12px;font-weight:900;color:#10b981;">
      +Rp ${Number(fish.reward).toLocaleString('id-ID')}
    </div>
  `;
  list.insertBefore(row, list.firstChild);
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
