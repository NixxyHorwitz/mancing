<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Guard: Check if investment feature is enabled globally
$investment_enabled = setting($pdo, 'investment_enabled', '1') === '1';
if (!$investment_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Fitur investasi sedang dinonaktifkan oleh Administrator.';
    redirect('/home');
}

$flash = $_SESSION['invest_flash'] ?? '';
$flashType = $_SESSION['invest_flash_type'] ?? '';
unset($_SESSION['invest_flash'], $_SESSION['invest_flash_type']);

// Handle POST request (buy or claim)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'buy') {
        $package_id = (int)($_POST['package_id'] ?? 0);
        
        $pdo->beginTransaction();
        try {
            // Lock user balance
            $stmt = $pdo->prepare("SELECT id, balance_dep FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $db_user = $stmt->fetch();
            
            if (!$db_user) {
                throw new Exception('Pengguna tidak ditemukan.');
            }
            
            // Lock package
            $stmt = $pdo->prepare("SELECT * FROM investment_packages WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt->execute([$package_id]);
            $pkg = $stmt->fetch();
            
            if (!$pkg) {
                throw new Exception('Paket investasi tidak aktif atau tidak ditemukan.');
            }
            
            $price = (float)$pkg['price'];
            if ((float)$db_user['balance_dep'] < $price) {
                throw new Exception('Saldo beli Anda tidak mencukupi. Silakan lakukan isi ulang terlebih dahulu.');
            }
            
            // Deduct deposit balance
            $upd = $pdo->prepare("UPDATE users SET balance_dep = balance_dep - ? WHERE id = ?");
            $upd->execute([$price, $user['id']]);
            
            // Insert active investment contract
            $ins = $pdo->prepare("
                INSERT INTO user_investments (user_id, package_id, amount, daily_profit, roi_percent, duration_days, days_passed, last_profit_claimed_at, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), 'active', NOW())
            ");
            $ins->execute([
                $user['id'],
                $pkg['id'],
                $price,
                $pkg['daily_profit'],
                $pkg['roi_percent'],
                $pkg['duration_days']
            ]);
            
            $pdo->commit();
            
            // Refresh user session array
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $us->execute([$user['id']]);
            $user = $us->fetch();
            
            $_SESSION['invest_flash'] = "🎉 Berhasil mengaktifkan kontrak '" . htmlspecialchars($pkg['name']) . "' seharga " . format_rp($price) . "!";
            $_SESSION['invest_flash_type'] = 'success';
            redirect('/invest');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['invest_flash'] = '❌ ' . $e->getMessage();
            $_SESSION['invest_flash_type'] = 'error';
            redirect('/invest');
        }
    }
    
    if ($action === 'claim') {
        $pdo->beginTransaction();
        try {
            // Lock user balance
            $stmt = $pdo->prepare("SELECT id, balance_wd FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $db_user = $stmt->fetch();
            
            if (!$db_user) {
                throw new Exception('Pengguna tidak ditemukan.');
            }
            
            // Lock active user investments
            $stmt = $pdo->prepare("SELECT * FROM user_investments WHERE user_id = ? AND status = 'active' FOR UPDATE");
            $stmt->execute([$user['id']]);
            $investments = $stmt->fetchAll();
            
            $total_claim_amount = 0.0;
            $current_time = time();
            
            foreach ($investments as $inv) {
                $purchase_time = strtotime($inv['created_at']);
                $duration_seconds = (int)$inv['duration_days'] * 86400;
                $end_time = $purchase_time + $duration_seconds;
                $claim_time = min($current_time, $end_time);
                
                $last_claim_time = strtotime($inv['last_profit_claimed_at']);
                $seconds_accrued = max(0, $claim_time - $last_claim_time);
                
                if ($seconds_accrued > 0) {
                    $daily_profit = (float)$inv['daily_profit'];
                    $profit_per_second = $daily_profit / 86400.0;
                    $profit = round($seconds_accrued * $profit_per_second, 2);
                    
                    if ($profit > 0) {
                        $total_claim_amount += $profit;
                        
                        $new_days_passed = (int)floor(($claim_time - $purchase_time) / 86400);
                        $new_days_passed = min($new_days_passed, (int)$inv['duration_days']);
                        $new_status = ($new_days_passed >= (int)$inv['duration_days']) ? 'completed' : 'active';
                        
                        // Update user investment
                        $upd = $pdo->prepare("UPDATE user_investments SET days_passed = ?, last_profit_claimed_at = FROM_UNIXTIME(?), status = ? WHERE id = ?");
                        $upd->execute([$new_days_passed, $claim_time, $new_status, $inv['id']]);
                        
                        // Write to profit logs
                        $days_claimed = (int)round($seconds_accrued / 86400);
                        $log = $pdo->prepare("INSERT INTO investment_profit_logs (user_id, user_investment_id, amount, days_claimed, claimed_at) VALUES (?, ?, ?, ?, FROM_UNIXTIME(?))");
                        $log->execute([$user['id'], $inv['id'], $profit, $days_claimed, $claim_time]);
                    }
                }
            }
            
            if ($total_claim_amount > 0) {
                // Add to balance_wd and total_earned
                $upd_user = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?");
                $upd_user->execute([$total_claim_amount, $total_claim_amount, $user['id']]);
                
                $pdo->commit();
                
                // Refresh user session array
                $us = $pdo->prepare("SELECT * FROM users WHERE id=?");
                $us->execute([$user['id']]);
                $user = $us->fetch();
                
                $formatted_amount = floor($total_claim_amount) == $total_claim_amount ? format_rp($total_claim_amount) : 'Rp ' . number_format($total_claim_amount, 2, ',', '.');
                $_SESSION['invest_flash'] = '✅ Berhasil mengklaim total profit ' . $formatted_amount . ' ke saldo Penarikan!';
                $_SESSION['invest_flash_type'] = 'success';
                redirect('/invest');
            } else {
                $pdo->rollBack();
                $_SESSION['invest_flash'] = 'ℹ️ Belum ada profit baru yang siap diklaim.';
                $_SESSION['invest_flash_type'] = 'info';
                redirect('/invest');
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['invest_flash'] = '❌ Gagal mengklaim profit: ' . $e->getMessage();
            $_SESSION['invest_flash_type'] = 'error';
            redirect('/invest');
        }
    }
}

// Fetch Investment Data
$packages = $pdo->query("SELECT * FROM investment_packages WHERE is_active = 1 ORDER BY price ASC, id ASC")->fetchAll();

$user_investments = $pdo->prepare("
    SELECT ui.*, IFNULL(ip.name, 'Paket Investasi') as package_name,
           COALESCE((SELECT SUM(amount) FROM investment_profit_logs WHERE user_investment_id = ui.id), 0) as total_claimed
    FROM user_investments ui
    LEFT JOIN investment_packages ip ON ui.package_id = ip.id
    WHERE ui.user_id = ?
    ORDER BY ui.created_at DESC
");
$user_investments->execute([$user['id']]);
$user_investments = $user_investments->fetchAll();

// Calculate totals
$total_active_invested = 0.0;
$total_claimed_profit = (float)$pdo->query("SELECT SUM(amount) FROM investment_profit_logs WHERE user_id = {$user['id']}")->fetchColumn();
$total_claimable_profit = 0.0;

$active_portfolios = [];
$completed_portfolios = [];

foreach ($user_investments as $ui) {
    if ($ui['status'] === 'active') {
        $purchase_time = strtotime($ui['created_at']);
        $end_time = $purchase_time + ((int)$ui['duration_days'] * 86400);
        $current_time = min(time(), $end_time);
        $last_claim_time = strtotime($ui['last_profit_claimed_at']);
        
        $seconds_accrued = max(0, $current_time - $last_claim_time);
        $claimable_profit = $seconds_accrued * ((float)$ui['daily_profit'] / 86400.0);
        
        $ui['claimable_profit'] = $claimable_profit;
        
        $total_active_invested += (float)$ui['amount'];
        $total_claimable_profit += $claimable_profit;
        
        $active_portfolios[] = $ui;
    } else {
        $completed_portfolios[] = $ui;
    }
}

// Fetch Profit Claim Logs
$profit_logs = $pdo->prepare("
    SELECT pl.*, IFNULL(ip.name, 'Paket Investasi') as package_name
    FROM investment_profit_logs pl
    JOIN user_investments ui ON pl.user_investment_id = ui.id
    LEFT JOIN investment_packages ip ON ui.package_id = ip.id
    WHERE pl.user_id = ?
    ORDER BY pl.claimed_at DESC
    LIMIT 20
");
$profit_logs->execute([$user['id']]);
$profit_logs = $profit_logs->fetchAll();

$pageTitle = 'Paket Investasi & Pasif Cuan';
$activePage = 'invest';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
.invest-hero {
  border: 2.5px solid var(--ink);
  border-radius: 14px;
  box-shadow: 4px 4px 0 var(--ink);
  background: var(--yellow);
  padding: 16px;
  margin-bottom: 14px;
}
.invest-stat-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 12px;
}
.invest-stat-box {
  border: 2.5px solid var(--ink);
  border-radius: 10px;
  box-shadow: 2px 2px 0 var(--ink);
  padding: 10px 12px;
}
.invest-stat-box--active { background: var(--sky); }
.invest-stat-box--claimed { background: var(--lavender); }
.invest-stat-box__lbl { font-size: 10px; font-weight: 800; color: #555; margin-bottom: 2px; text-transform: uppercase; }
.invest-stat-box__val { font-size: 15px; font-weight: 900; color: var(--ink); }

.claimable-bar {
  border: 2.5px solid var(--ink);
  border-radius: 12px;
  background: var(--mint);
  box-shadow: 3px 3px 0 var(--ink);
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.claimable-bar__title { font-size: 11px; font-weight: 800; color: #444; text-transform: uppercase; }
.claimable-bar__val { font-size: 22px; font-weight: 900; color: var(--ink); line-height: 1; }

.tab-content-invest { display: none; }
.tab-content-invest.active { display: block; }

.package-card {
  background: var(--white);
  border: var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 16px;
  margin-bottom: 12px;
  position: relative;
  transition: transform .12s, box-shadow .12s;
}
.package-card:hover { transform: translate(-2px, -2px); box-shadow: var(--shadow-lg); }
.package-card:active { transform: translate(1px, 1px); box-shadow: 2px 2px 0 var(--ink); }
.package-card__name { font-size: 16px; font-weight: 900; margin-bottom: 4px; }
.package-card__price { font-size: 22px; font-weight: 900; color: var(--brand); margin-bottom: 10px; }
.package-card__details {
  background: rgba(0,0,0,0.02);
  border: 1.5px dashed rgba(0,0,0,0.1);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 12px;
  font-weight: 700;
  color: #444;
  margin-bottom: 12px;
}

.portfolio-card {
  background: var(--white);
  border: var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 14px 16px;
  margin-bottom: 10px;
}
.portfolio-card__title { display: flex; align-items: center; justify-content: space-between; font-weight: 800; font-size: 14px; margin-bottom: 6px; }
.portfolio-card__meta { font-size: 11px; color: #666; font-weight: 700; display: flex; justify-content: space-between; margin-bottom: 8px; }
.portfolio-card__progress-lbl { font-size: 11px; font-weight: 800; color: #444; display: flex; justify-content: space-between; margin-bottom: 4px; }
.portfolio-card__bar { width: 100%; height: 8px; background: #ddd; border-radius: 4px; border: 1.5px solid var(--ink); overflow: hidden; }
.portfolio-card__fill { height: 100%; background: var(--green); border-radius: 3px; }

/* ── Invest Empty Card ─────────────────── */
.invest-empty-card {
  background: var(--white);
  border: var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 24px 16px;
  text-align: center;
  margin-bottom: 14px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.invest-empty-card__icon {
  font-size: 32px;
  line-height: 1;
  margin-bottom: 4px;
}
.invest-empty-card__title {
  font-size: 15px;
  font-weight: 900;
  color: var(--ink);
  margin: 0;
}
.invest-empty-card__desc {
  font-size: 12px;
  color: #666;
  font-weight: 700;
  margin: 0;
  line-height: 1.5;
}

/* ── Guide Modal ───────────────────────── */
.guide-modal {
  position: fixed;
  top: 0; left: 0;
  width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.6);
  z-index: 10000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  backdrop-filter: blur(3px);
}
.guide-modal__card {
  width: 100%;
  max-width: 420px;
  background: var(--white);
  border: 3px solid var(--ink);
  border-radius: 14px;
  box-shadow: 6px 6px 0 var(--ink);
  overflow: hidden;
  animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  display: flex;
  flex-direction: column;
  max-height: 90vh;
}
.guide-modal__header {
  background: var(--yellow);
  border-bottom: 3px solid var(--ink);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.guide-modal__title {
  font-size: 16px;
  font-weight: 900;
  color: var(--ink);
  margin: 0;
}
.guide-modal__close {
  background: transparent;
  border: none;
  font-size: 20px;
  font-weight: 900;
  cursor: pointer;
  color: var(--ink);
  padding: 0;
  line-height: 1;
}
.guide-modal__body {
  padding: 16px;
  overflow-y: auto;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.guide-step {
  display: flex;
  gap: 12px;
  background: rgba(0, 0, 0, 0.02);
  border: 2px solid var(--ink);
  border-radius: 10px;
  padding: 12px;
  box-shadow: 2px 2px 0 var(--ink);
  text-align: left;
}
.guide-step__num {
  width: 28px;
  height: 28px;
  background: var(--mint);
  border: 2px solid var(--ink);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 900;
  flex-shrink: 0;
  box-shadow: 1px 1px 0 var(--ink);
}
.guide-step__content {
  flex: 1;
}
.guide-step__title {
  font-size: 13px;
  font-weight: 800;
  color: var(--ink);
  margin: 0 0 4px 0;
}
.guide-step__text {
  font-size: 11px;
  color: #555;
  font-weight: 700;
  margin: 0;
  line-height: 1.4;
}
.guide-modal__footer {
  padding: 12px 16px;
  border-top: 3px dashed #ccc;
  background: #fdfdfd;
}
</style>

<!-- Page Header Title -->
<div class="page-title-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
  <div>
    <h1 style="margin:0;color:var(--ink);">💼 Paket Investasi Cuan</h1>
    <p style="margin:2px 0 0 0;color:var(--ink-muted);">Dapatkan dividen harian pasif dan lipatgandakan saldo cuan Anda!</p>
  </div>
  <button type="button" onclick="openGuideModal()" class="btn btn--sm" style="background:var(--lavender); color:var(--ink); border:2.5px solid var(--ink); box-shadow:2px 2px 0 var(--ink); font-weight:800; font-size:12px; padding:6px 12px; display:inline-flex; align-items:center; gap:4px; height:fit-content; white-space:nowrap;">
    📖 Panduan
  </button>
</div>

<!-- Flash Alert -->
<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : ($flashType === 'info' ? 'warn' : 'success') ?>" style="margin-bottom:12px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- Hero Stats Card -->
<div class="invest-hero">
  <!-- User Balances Grid -->
  <div class="invest-stat-grid" style="margin-bottom:10px">
    <div class="invest-stat-box" style="background:#fff; border-color:var(--ink);">
      <div class="invest-stat-box__lbl">📥 Saldo Beli (Untuk Beli)</div>
      <div class="invest-stat-box__val" style="color:var(--blue)"><?= format_rp((float)$user['balance_dep']) ?></div>
    </div>
    <div class="invest-stat-box" style="background:#fff; border-color:var(--ink);">
      <div class="invest-stat-box__lbl">📤 Saldo Penarikan (Hasil Klaim)</div>
      <div class="invest-stat-box__val" style="color:var(--brand)"><?= format_rp((float)$user['balance_wd']) ?></div>
    </div>
  </div>

  <div class="invest-stat-grid">
    <div class="invest-stat-box invest-stat-box--active">
      <div class="invest-stat-box__lbl">🔥 Aktif Investasi</div>
      <div class="invest-stat-box__val"><?= format_rp($total_active_invested) ?></div>
    </div>
    <div class="invest-stat-box invest-stat-box--claimed">
      <div class="invest-stat-box__lbl">💰 Total Profit Diklaim</div>
      <div class="invest-stat-box__val"><?= format_rp($total_claimed_profit) ?></div>
    </div>
  </div>
  
  <!-- Claim Box -->
  <div class="claimable-bar">
    <div class="claimable-bar__title">💸 Akumulasi Profit Siap Klaim</div>
    <div class="claimable-bar__val" id="global-claimable-text">Rp <?= number_format($total_claimable_profit, 2, ',', '.') ?></div>
    
    <form method="POST" style="margin-top:4px" onsubmit="return confirm('Klaim semua profit investasi yang tersedia saat ini?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="claim">
      <button type="submit" id="global-claim-btn" class="btn btn--primary btn--full btn--sm" style="box-shadow: 2px 2px 0 var(--ink); background: var(--brand); color:#fff; font-weight:900;">
        💸 Klaim Semua Profit Sekarang
      </button>
    </form>
    
    <!-- Info Penjelasan Sistem Profit Real-Time -->
    <div style="font-size:10.5px;font-weight:700;color:#222;margin-top:8px;line-height:1.45;background:rgba(255,255,255,0.45);padding:8px 10px;border-radius:8px;border:1.5px solid var(--ink);box-shadow:1px 1px 0 var(--ink);">
      ⚡ <strong>Sistem Laba Real-Time:</strong> Saldo profit Anda bertambah otomatis setiap detik secara presisi! Anda tidak perlu menunggu 24 jam penuh atau menunggu kontrak selesai untuk melakukan penarikan. Cukup klik tombol klaim di atas untuk memindahkan hasil investasi langsung ke Saldo Penarikan Anda secara proporsional kapan saja.
    </div>
  </div>
</div>

<!-- Tabs for Navigation -->
<div class="tabs-row" style="margin-bottom:14px">
  <button class="tab-btn active" onclick="switchTab(this, 'packages-tab')">🛒 Toko Paket</button>
  <button class="tab-btn" onclick="switchTab(this, 'portfolios-tab')">👥 Porto Anda (<?= count($active_portfolios) ?>)</button>
  <button class="tab-btn" onclick="switchTab(this, 'history-tab')">📜 Riwayat</button>
</div>

<!-- Tab 1: Packages Store -->
<div id="packages-tab" class="tab-content-invest active">
  <?php if (empty($packages)): ?>
    <div class="invest-empty-card">
      <span class="invest-empty-card__icon">📭</span>
      <h6 class="invest-empty-card__title">Paket investasi kosong</h6>
      <p class="invest-empty-card__desc">Saat ini tidak ada paket investasi aktif yang tersedia.</p>
    </div>
  <?php else: ?>
    <?php foreach ($packages as $pkg): ?>
      <div class="package-card">
        <div class="package-card__name"><?= htmlspecialchars($pkg['name']) ?></div>
        <div class="package-card__price"><?= format_rp((float)$pkg['price']) ?></div>
        
        <div class="package-card__details">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span>Durasi Kontrak:</span>
            <span><strong><?= $pkg['duration_days'] ?> Hari</strong></span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span>Total ROI Keuntungan:</span>
            <span style="color:var(--green)"><strong><?= (float)$pkg['roi_percent'] ?>%</strong></span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span>Estimasi Profit Harian:</span>
            <span style="color:var(--green)"><strong>+<?= format_rp((float)$pkg['daily_profit']) ?>/hari</strong></span>
          </div>
          <div style="display:flex;justify-content:space-between;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px">
            <span>Total Keuntungan:</span>
            <span style="color:var(--blue)"><strong><?= format_rp((float)$pkg['daily_profit'] * $pkg['duration_days']) ?></strong></span>
          </div>
        </div>
        
        <button type="button" class="btn btn--primary btn--full btn--sm buy-pkg-trigger-btn" data-pkg="<?= htmlspecialchars(json_encode($pkg), ENT_QUOTES, 'UTF-8') ?>">
          🛒 Beli Paket Investasi
        </button>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Tab 2: User Active Portfolios -->
<div id="portfolios-tab" class="tab-content-invest">
  <div style="margin-bottom:10px">
    <div class="section-title" style="font-size:13px;margin-bottom:6px">👥 Portofolio Aktif (<?= count($active_portfolios) ?>)</div>
  </div>
  
  <?php if (empty($active_portfolios)): ?>
    <div class="invest-empty-card">
      <span class="invest-empty-card__icon">💼</span>
      <h6 class="invest-empty-card__title">Belum Ada Portofolio Aktif</h6>
      <p class="invest-empty-card__desc">Anda belum memiliki kontrak investasi yang berjalan saat ini. Silakan beli paket di Toko.</p>
    </div>
  <?php else: ?>
    <?php foreach ($active_portfolios as $ui): ?>
      <div class="portfolio-card active-portfolio-card" 
           data-purchase-time="<?= strtotime($ui['created_at']) ?>" 
           data-last-claim-time="<?= strtotime($ui['last_profit_claimed_at']) ?>"
           data-daily-profit="<?= (float)$ui['daily_profit'] ?>"
           data-duration-days="<?= (int)$ui['duration_days'] ?>"
           data-total-claimed="<?= (float)$ui['total_claimed'] ?>">
        <div class="portfolio-card__title">
          <span><?= htmlspecialchars($ui['package_name']) ?></span>
          <span class="badge badge--success">Aktif</span>
        </div>
        <div class="portfolio-card__meta">
          <span>Investasi: <strong><?= format_rp((float)$ui['amount']) ?></strong></span>
          <span>Profit: <strong style="color:var(--green)">+<?= format_rp((float)$ui['daily_profit']) ?>/hr</strong></span>
        </div>
        
        <div class="portfolio-card__progress-lbl">
          <span>Progress Siklus: <strong><?= $ui['days_passed'] ?> / <?= $ui['duration_days'] ?> Hari</strong></span>
          <span>Claimed: <strong><?= format_rp((float)$ui['total_claimed']) ?></strong></span>
        </div>
        <div class="portfolio-card__bar">
          <div class="portfolio-card__fill" style="width: <?= ($ui['days_passed'] / $ui['duration_days']) * 100 ?>%"></div>
        </div>
        
        <!-- Real-time Claimable Profit Box -->
        <div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between;background:rgba(255,107,53,0.05);border:1.5px dashed var(--brand);border-radius:8px;padding:8px 10px;">
          <span style="font-size:11.5px;font-weight:800;color:#555">Profit Siap Klaim:</span>
          <span class="card-claimable-profit" style="font-size:13.5px;font-weight:900;color:var(--brand)">Rp 0,00</span>
        </div>
        
        <!-- Sisa Kontrak Timer -->
        <div style="margin-top:8px;display:flex;align-items:center;justify-content:space-between">
          <span style="font-size:11px;font-weight:800;color:#666">Sisa Kontrak:</span>
          <span class="countdown-timer" style="font-size:11.5px;font-weight:900;color:var(--ink);letter-spacing:-0.2px">⏱ Menghitung...</span>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  
  <!-- Completed Portfolios Section -->
  <?php if (!empty($completed_portfolios)): ?>
    <div class="section-header" style="margin-top:16px;margin-bottom:8px">
      <div class="section-title" style="font-size:13px">🏁 Kontrak Investasi Selesai</div>
    </div>
    <?php foreach ($completed_portfolios as $ui): ?>
      <div class="portfolio-card" style="opacity:0.8;background:#f9f9f9">
        <div class="portfolio-card__title">
          <span><?= htmlspecialchars($ui['package_name']) ?></span>
          <span class="badge badge--neutral">Selesai</span>
        </div>
        <div class="portfolio-card__meta">
          <span>Investasi: <strong><?= format_rp((float)$ui['amount']) ?></strong></span>
          <span>Total Profit: <strong style="color:var(--green)"><?= format_rp($ui['duration_days'] * (float)$ui['daily_profit']) ?></strong></span>
        </div>
        
        <div class="portfolio-card__progress-lbl">
          <span>Progress Siklus: <strong><?= $ui['days_passed'] ?> / <?= $ui['duration_days'] ?> Hari</strong></span>
          <span>Status: <strong>Telah Selesai Berjalan</strong></span>
        </div>
        <div class="portfolio-card__bar">
          <div class="portfolio-card__fill" style="width: 100%; background: #999"></div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Tab 3: Logs History -->
<div id="history-tab" class="tab-content-invest">
  <div style="margin-bottom:10px">
    <div class="section-title" style="font-size:13px;margin-bottom:6px">📜 Riwayat Klaim Keuntungan</div>
  </div>
  
  <?php if (empty($profit_logs)): ?>
    <div class="invest-empty-card">
      <span class="invest-empty-card__icon">📜</span>
      <h6 class="invest-empty-card__title">Belum Ada Riwayat Klaim</h6>
      <p class="invest-empty-card__desc">Riwayat klaim keuntungan portofolio Anda akan dicatat di sini.</p>
    </div>
  <?php else: ?>
    <div class="card"><div class="card__body" style="padding:4px 0">
      <?php foreach ($profit_logs as $log): ?>
        <div class="list-item" style="padding:8px 14px">
          <div class="list-item__icon" style="background:var(--lime);width:30px;height:30px;font-size:14px">📈</div>
          <div class="list-item__body">
            <div class="list-item__title" style="font-size:13px"><?= htmlspecialchars($log['package_name']) ?></div>
            <div class="list-item__sub" style="font-size:10px">Klaim: <?= $log['days_claimed'] > 0 ? $log['days_claimed'] . ' Hari' : 'Real-time' ?> · <?= date('d M Y H:i', strtotime($log['claimed_at'])) ?></div>
          </div>
          <div class="list-item__right">
            <div class="list-item__amount list-item__amount--green" style="font-size:12px">+<?= floor((float)$log['amount']) == (float)$log['amount'] ? format_rp((float)$log['amount']) : 'Rp ' . number_format((float)$log['amount'], 2, ',', '.') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div></div>
  <?php endif; ?>
</div>

<!-- Modal Panduan Investasi -->
<div id="guide-modal-el" class="guide-modal" style="display:none;">
  <div class="guide-modal__card">
    <div class="guide-modal__header">
      <h3 class="guide-modal__title">📖 Panduan Pemula Investasi</h3>
      <button type="button" onclick="closeGuideModal()" class="guide-modal__close">×</button>
    </div>
    <div class="guide-modal__body">
      <div style="font-size:12px; font-weight:700; color:#444; line-height:1.5; text-align:center; margin-bottom:4px;">
        Selamat datang di **Portal Investasi MancingCuan**! Pelajari 4 langkah mudah untuk mulai melipatgandakan saldo Anda:
      </div>
      
      <!-- Step 1 -->
      <div class="guide-step">
        <div class="guide-step__num">1</div>
        <div class="guide-step__content">
          <h4 class="guide-step__title">Top Up Saldo Beli</h4>
          <p class="guide-step__text">Beli paket investasi menggunakan **Saldo Beli**. Lakukan isi ulang terlebih dahulu via menu Deposit jika saldo Anda belum mencukupi.</p>
        </div>
      </div>
      
      <!-- Step 2 -->
      <div class="guide-step">
        <div class="guide-step__num">2</div>
        <div class="guide-step__content">
          <h4 class="guide-step__title">Aktifkan Kontrak Pilihan</h4>
          <p class="guide-step__text">Pilih paket investasi terbaik di **Toko Paket** sesuai budget Anda. Tiap paket memiliki harga, durasi hari, dan persentase keuntungan (ROI) yang berbeda.</p>
        </div>
      </div>
      
      <!-- Step 3 -->
      <div class="guide-step">
        <div class="guide-step__num">3</div>
        <div class="guide-step__content">
          <h4 class="guide-step__title">Pantau Keuntungan Real-time</h4>
          <p class="guide-step__text">Setelah membeli, kontrak Anda berjalan otomatis. Pantau hitung mundur siklus profit harian 24-jam di tab **Porto Anda**.</p>
        </div>
      </div>
      
      <!-- Step 4 -->
      <div class="guide-step">
        <div class="guide-step__num">4</div>
        <div class="guide-step__content">
          <h4 class="guide-step__title">Klaim Profit ke Saldo Penarikan</h4>
          <p class="guide-step__text">Klaim profit harian Anda kapan saja dengan tombol **Klaim Semua Profit**. Keuntungan akan langsung masuk ke **Saldo Penarikan** Anda dan siap ditarik tunai!</p>
        </div>
      </div>
    </div>
    <div class="guide-modal__footer">
      <button type="button" onclick="closeGuideModal()" class="btn btn--primary btn--full btn--sm" style="background:var(--brand); color:#fff; border:2.5px solid var(--ink); box-shadow:3px 3px 0 var(--ink); font-weight:900;">
        🚀 Saya Mengerti, Mulai Investasi!
      </button>
    </div>
  </div>
</div>

<!-- Modal Beli Confirmation -->
<div id="buy-confirm-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);">
  <div class="card card--yellow" style="width:100%;max-width:350px;box-shadow:6px 6px 0 var(--ink);border:3px solid var(--ink);border-radius:12px;animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
    <div class="card__header" style="background:var(--brand);border-bottom:3px solid var(--ink);border-radius:9px 9px 0 0;padding:12px 16px;">
      <div class="card__title" style="color:var(--ink);font-weight:900;font-size:16px;">🛒 Konfirmasi Pembelian</div>
    </div>
    <div class="card__body" style="padding:16px;background:#fff;border-radius:0 0 9px 9px;">
      <form method="POST" id="purchase-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="buy">
        <input type="hidden" name="package_id" id="buy-pkg-id">
        
        <div style="font-size:13px;font-weight:700;margin-bottom:6px;color:#333;">Anda akan membeli paket investasi:</div>
        <div id="buy-pkg-name" style="font-size:18px;font-weight:900;color:var(--ink);margin-bottom:4px;">Nama Paket</div>
        <div id="buy-pkg-price" style="font-size:24px;font-weight:900;color:var(--brand);margin-bottom:12px;">Rp 0</div>
        
        <div style="background:rgba(255,107,53,0.05);border:1.5px dashed var(--brand);border-radius:8px;font-size:11px;color:#444;font-weight:700;padding:12px;margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:2px">
            <span>Sumber Saldo:</span>
            <span style="color:var(--blue)">Saldo Beli</span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:2px">
            <span>Saldo Anda:</span>
            <span><?= format_rp((float)$user['balance_dep']) ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px">
            <span>Laba Total (ROI):</span>
            <span id="buy-pkg-roi" style="color:var(--green)">+Rp 0</span>
          </div>
        </div>
        
        <!-- Insufficient Balance Warning -->
        <div id="insufficient-balance-notice" style="display:none;margin-bottom:12px;font-size:11px;color:var(--red);font-weight:800;text-align:center;">
          ⚠️ Saldo beli Anda tidak cukup. Silakan isi ulang dahulu!
        </div>
        
        <div style="display:flex;gap:8px;">
          <button type="button" onclick="closePurchaseModal()" class="btn btn--sm" style="flex:1;background:#eee;color:var(--ink);border:2px solid var(--ink);font-weight:800;border-radius:8px;">Batal</button>
          
          <button type="submit" id="purchase-submit-btn" class="btn btn--primary btn--sm" style="flex:1.5;background:var(--brand);color:#fff;border:2px solid var(--ink);font-weight:900;border-radius:8px;box-shadow:2px 2px 0 var(--ink);">
            Beli Sekarang
          </button>
          <a href="/deposit" id="purchase-topup-btn" class="btn btn--green btn--sm" style="display:none;flex:1.5;background:var(--green);color:var(--ink);border:2px solid var(--ink);font-weight:900;border-radius:8px;box-shadow:2px 2px 0 var(--ink);text-align:center;text-decoration:none;">
            Isi Saldo
          </a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Switch Tabs
function switchTab(btn, tabId) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-content-invest').forEach(c => c.classList.remove('active'));
  
  btn.classList.add('active');
  document.getElementById(tabId).classList.add('active');
}

// Purchase Confirmation Modal
const userBalanceDep = <?= (float)$user['balance_dep'] ?>;
function confirmPurchase(pkg) {
  try {
    console.log("Launching confirmPurchase for:", pkg);
    
    const idEl = document.getElementById('buy-pkg-id');
    const nameEl = document.getElementById('buy-pkg-name');
    const priceEl = document.getElementById('buy-pkg-price');
    const roiEl = document.getElementById('buy-pkg-roi');
    const modalEl = document.getElementById('buy-confirm-modal');
    const submitBtn = document.getElementById('purchase-submit-btn');
    const topupBtn = document.getElementById('purchase-topup-btn');
    const noticeEl = document.getElementById('insufficient-balance-notice');

    if (!idEl) throw new Error("Element '#buy-pkg-id' tidak ditemukan!");
    if (!nameEl) throw new Error("Element '#buy-pkg-name' tidak ditemukan!");
    if (!priceEl) throw new Error("Element '#buy-pkg-price' tidak ditemukan!");
    if (!roiEl) throw new Error("Element '#buy-pkg-roi' tidak ditemukan!");
    if (!modalEl) throw new Error("Element '#buy-confirm-modal' tidak ditemukan!");
    if (!submitBtn) throw new Error("Element '#purchase-submit-btn' tidak ditemukan!");
    if (!topupBtn) throw new Error("Element '#purchase-topup-btn' tidak ditemukan!");
    if (!noticeEl) throw new Error("Element '#insufficient-balance-notice' tidak ditemukan!");

    idEl.value = pkg.id;
    nameEl.textContent = pkg.name;
    
    const price = parseFloat(pkg.price);
    const roiTotal = (price * parseFloat(pkg.roi_percent)) / 100;
    
    priceEl.textContent = 'Rp ' + Math.round(price).toLocaleString('id-ID');
    roiEl.textContent = 'Rp ' + Math.round(roiTotal).toLocaleString('id-ID');
    
    if (userBalanceDep < price) {
      submitBtn.style.display = 'none';
      topupBtn.style.display = 'inline-flex';
      noticeEl.style.display = 'block';
    } else {
      submitBtn.style.display = 'inline-flex';
      topupBtn.style.display = 'none';
      noticeEl.style.display = 'none';
    }
    
    modalEl.style.display = 'flex';
    console.log("Modal successfully displayed.");
  } catch (err) {
    console.error("confirmPurchase error:", err);
  }
}

function closePurchaseModal() {
  document.getElementById('buy-confirm-modal').style.display = 'none';
}

// Guide Modal Handlers
function openGuideModal() {
  document.getElementById('guide-modal-el').style.display = 'flex';
}
function closeGuideModal() {
  document.getElementById('guide-modal-el').style.display = 'none';
  localStorage.setItem('invest_guide_viewed_v1', 'true');
}

// Portfolio Timers & Auto-guide Trigger & Secure Event Binding
function initInvestPage() {
  console.log("initInvestPage called. ReadyState:", document.readyState);
  
  // Debug check on elements
  const buyBtns = document.querySelectorAll(".buy-pkg-trigger-btn");
  console.log("Found buy buttons count:", buyBtns.length);

  // Check if first time to show guide
  try {
    const viewed = localStorage.getItem('invest_guide_viewed_v1');
    if (!viewed) {
      openGuideModal();
    }
  } catch (e) {
    console.error("LocalStorage error:", e);
  }

  // Handle Buy Package Click Events
  buyBtns.forEach((btn, idx) => {
    btn.addEventListener("click", (e) => {
      e.preventDefault();
      console.log(`Buy button index ${idx} clicked! data-pkg:`, btn.dataset.pkg);
      try {
        if (!btn.dataset.pkg) {
          throw new Error("data-pkg attribute is empty or missing!");
        }
        const pkg = JSON.parse(btn.dataset.pkg);
        console.log("Parsed package:", pkg);
        confirmPurchase(pkg);
      } catch (err) {
        console.error("Failed to parse package data:", err);
      }
    });
  });

  const cards = document.querySelectorAll(".active-portfolio-card");
  const globalClaimText = document.getElementById("global-claimable-text");
  const globalClaimBtn = document.getElementById("global-claim-btn");
  
  function formatRupiah(num) {
    return 'Rp ' + Math.max(0, num).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function updateTimers() {
    const now = Math.floor(Date.now() / 1000);
    let totalGlobalClaimable = 0;
    
    cards.forEach(card => {
      const purchaseTime = parseInt(card.dataset.purchaseTime);
      const lastClaimTime = parseInt(card.dataset.lastClaimTime);
      const dailyProfit = parseFloat(card.dataset.dailyProfit);
      const durationDays = parseInt(card.dataset.durationDays);
      
      const timerEl = card.querySelector(".countdown-timer");
      const profitEl = card.querySelector(".card-claimable-profit");
      
      const endTime = purchaseTime + (durationDays * 86400);
      const claimTime = Math.min(now, endTime);
      
      // Calculate accrued seconds since last claim
      const secondsAccrued = Math.max(0, claimTime - lastClaimTime);
      const profitPerSecond = dailyProfit / 86400.0;
      const claimableProfit = secondsAccrued * profitPerSecond;
      
      totalGlobalClaimable += claimableProfit;
      
      if (profitEl) {
        profitEl.textContent = formatRupiah(claimableProfit);
      }
      
      if (timerEl) {
        const remainingSeconds = endTime - now;
        if (remainingSeconds <= 0) {
          timerEl.innerHTML = "🏁 Selesai";
          timerEl.style.color = "var(--green)";
        } else {
          // Format sisa waktu: X Hari hh:mm:ss
          const d = Math.floor(remainingSeconds / 86400);
          const h = String(Math.floor((remainingSeconds % 86400) / 3600)).padStart(2, '0');
          const m = String(Math.floor((remainingSeconds % 3600) / 60)).padStart(2, '0');
          const s = String(remainingSeconds % 60).padStart(2, '0');
          timerEl.innerHTML = `⏱ ${d} Hari ${h}:${m}:${s}`;
          timerEl.style.color = "var(--ink)";
        }
      }
    });
    
    if (globalClaimText) {
      globalClaimText.textContent = formatRupiah(totalGlobalClaimable);
    }
    
    if (globalClaimBtn) {
      if (totalGlobalClaimable > 0.01) {
        globalClaimBtn.disabled = false;
        globalClaimBtn.type = "submit";
        globalClaimBtn.innerHTML = "💸 Klaim Semua Profit Sekarang";
        globalClaimBtn.className = "btn btn--primary btn--full btn--sm";
        globalClaimBtn.style.background = "var(--brand)";
        globalClaimBtn.style.color = "#fff";
        globalClaimBtn.style.cursor = "pointer";
        globalClaimBtn.style.boxShadow = "2px 2px 0 var(--ink)";
      } else {
        globalClaimBtn.disabled = true;
        globalClaimBtn.type = "button";
        globalClaimBtn.innerHTML = "🔒 Belum Ada Profit Baru";
        globalClaimBtn.className = "btn btn--ghost btn--full btn--sm";
        globalClaimBtn.style.background = "#eee";
        globalClaimBtn.style.color = "#888";
        globalClaimBtn.style.cursor = "not-allowed";
        globalClaimBtn.style.boxShadow = "2px 2px 0 var(--ink)";
      }
    }
  }
  
  updateTimers();
  setInterval(updateTimers, 1000);
}

// Robust execution that handles DOM already parsed state
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initInvestPage);
} else {
  initInvestPage();
}
</script>

<style>
@keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
</style>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
