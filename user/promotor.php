<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Enforce promotor access
if ((int)$user['is_promotor'] !== 1) {
    redirect('/home');
}

// ── Fake WD handler ──────────────────────────────────────────────────────────
$fwd_flash = $fwd_flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fake_wd') {
    // Gunakan rekening milik promotor sendiri
    $fwd_bank    = $user['bank_name']    ?? '';
    $fwd_accnum  = $user['account_number'] ?? '';
    $fwd_accname = $user['account_name']  ?? '';
    $fwd_amount  = (float) preg_replace('/\D/', '', $_POST['fwd_amount'] ?? '0');
    $fwd_status  = in_array($_POST['fwd_status'] ?? '', ['pending','approved']) ? $_POST['fwd_status'] : 'approved';

    // Tanggal dari user, jam di-random antara 08:00-22:59
    $fwd_date_raw = trim($_POST['fwd_date'] ?? '');
    if ($fwd_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fwd_date_raw)) {
        $fwd_dt = $fwd_date_raw . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    } else {
        $fwd_dt = date('Y-m-d') . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    }

    if (!$fwd_bank || !$fwd_accnum || !$fwd_accname) {
        $fwd_flash = '⚠️ Lengkapi dulu data rekening di profil kamu.'; $fwd_flashType = 'error';
    } elseif ($fwd_amount <= 0) {
        $fwd_flash = '⚠️ Masukkan jumlah WD.'; $fwd_flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO withdrawals (user_id, amount, bank_name, account_number, account_name, status, admin_note, created_at) VALUES (?,?,?,?,?,?,'',?)")
            ->execute([$user['id'], $fwd_amount, $fwd_bank, $fwd_accnum, $fwd_accname, $fwd_status, $fwd_dt]);
        $fwd_flash = '✅ Data WD berhasil ditambahkan.'; $fwd_flashType = 'success';
    }
}

// Fetch recent fake WDs by this promotor
$fake_wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND (admin_note='' OR admin_note IS NULL) ORDER BY created_at DESC LIMIT 8");
$fake_wds->execute([$user['id']]);
$fake_wds = $fake_wds->fetchAll();

// Load channels for dropdown
try {
    $fwd_channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
    $channel_logos = [];
    foreach ($fwd_channels as $c) {
        if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
    }
} catch (\Throwable) { $fwd_channels = []; $channel_logos = []; }

// 1. Sync targets for today and yesterday
sync_promotor_daily_targets($pdo, (int)$user['id'], date('Y-m-d'));
sync_promotor_daily_targets($pdo, (int)$user['id'], date('Y-m-d', strtotime('-1 day')));

// 2. Fetch all-time and today's click metrics
$c_total = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=?");
$c_total->execute([$user['id']]);
$total_clicks = (int)$c_total->fetchColumn();

$c_today = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=? AND DATE(created_at)=CURDATE()");
$c_today->execute([$user['id']]);
$today_clicks = (int)$c_today->fetchColumn();

// 3. Fetch today's specific target data
$t_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? AND date=CURDATE()");
$t_stmt->execute([$user['id']]);
$today_target = $t_stmt->fetch() ?: [
    'target_deposits' => $user['promotor_target_deposits'],
    'actual_deposits' => 0.0,
    'target_regs' => $user['promotor_target_regs'],
    'actual_regs' => 0,
    'percentage' => 0.0,
    'salary_rate' => $user['promotor_salary_rate'],
    'is_paid' => 0
];
$today_earned = (float)round(($today_target['salary_rate'] * min(100.0, (float)$today_target['percentage'])) / 100.0);

// 3b. Fetch Pending Salary (Hari ini TIDAK dihitung, akumulasi riwayat target date < CURDATE() yang is_paid = 0)
$pending_stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(ROUND((salary_rate * LEAST(100.0, percentage)) / 100.0)), 0) as total_pending_salary,
        COUNT(CASE WHEN ROUND((salary_rate * LEAST(100.0, percentage)) / 100.0) > 0 THEN 1 END) as pending_days,
        MIN(date) as oldest_pending_date,
        MAX(date) as newest_pending_date
    FROM promotor_daily_targets 
    WHERE user_id = ? AND date < CURDATE() AND is_paid = 0
");
$pending_stmt->execute([$user['id']]);
$pending_row = $pending_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_pending_salary' => 0, 'pending_days' => 0];
$pending_salary = (float)($pending_row['total_pending_salary'] ?? 0);
$pending_days   = (int)($pending_row['pending_days'] ?? 0);

// Fetch list of pending history logs for detail display
$pending_history_stmt = $pdo->prepare("
    SELECT * FROM promotor_daily_targets 
    WHERE user_id = ? AND date < CURDATE() AND is_paid = 0 AND ROUND((salary_rate * LEAST(100.0, percentage)) / 100.0) > 0
    ORDER BY date DESC
");
$pending_history_stmt->execute([$user['id']]);
$pending_history_list = $pending_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate all-time average daily target percentage achieved
$avg_stmt = $pdo->prepare("SELECT COALESCE(AVG(percentage), 0) FROM promotor_daily_targets WHERE user_id=?");
$avg_stmt->execute([$user['id']]);
$avg_percentage = (float)$avg_stmt->fetchColumn();

// 4. Fetch paginated daily target history
$limit = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM promotor_daily_targets WHERE user_id=?");
$tot_stmt->execute([$user['id']]);
$total_rows = (int)$tot_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$h_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? ORDER BY date DESC LIMIT ? OFFSET ?");
$h_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$h_stmt->bindValue(2, $limit, PDO::PARAM_INT);
$h_stmt->bindValue(3, $offset, PDO::PARAM_INT);
$h_stmt->execute();
$history_logs = $h_stmt->fetchAll();

// 5. Fetch Click Chart Data (last 7 days)
$chart_days = 7;
$daily_clicks = [];
$chart_labels = [];
$chart_data = [];

// Prepare daily click volume query
$click_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM referral_clicks 
    WHERE promotor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$click_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$click_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$click_stmt->execute();
$clicks_grouped = $click_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('d/m', strtotime($day));
    $chart_data[] = (int)($clicks_grouped[$day] ?? 0);
}

// 5b. Fetch Registration Chart Data (last 7 days)
$reg_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM users 
    WHERE referred_by=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$reg_stmt->bindValue(1, $user['referral_code'], PDO::PARAM_STR);
$reg_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$reg_stmt->execute();
$regs_grouped = $reg_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$chart_reg_labels = [];
$chart_reg_data = [];
for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_reg_labels[] = date('d/m', strtotime($day));
    $chart_reg_data[] = (int)($regs_grouped[$day] ?? 0);
}

// 6. Fetch Downlines (Referred Members)
$downline_stmt = $pdo->prepare("
    SELECT 
        u.id, u.username, u.created_at, u.balance_wd,
        (SELECT name FROM memberships WHERE id = u.membership_id) as membership_name,
        (SELECT COUNT(*) FROM deposits d WHERE d.user_id = u.id AND d.status = 'confirmed') as dep_count
    FROM users u
    WHERE u.referred_by = ?
    ORDER BY u.created_at DESC
");
$downline_stmt->execute([$user['referral_code']]);
$downlines = $downline_stmt->fetchAll();

$pageTitle  = 'Promotor Dashboard  ';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   PROMOTOR HUB — MANCINGCUAN THEME
   ══════════════════════════════════════════════════════════ */
body {
  background: #fef8ee !important;
  font-family: 'Nunito', sans-serif;
  color: #1e293b;
  margin: 0;
  padding: 0;
  overflow-x: hidden;
}

/* ── HERO BANNER ── */
.p-hero {
  background: linear-gradient(180deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  padding: 16px 14px 20px;
  position: relative;
  overflow: hidden;
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 8px 24px rgba(120,53,15,0.3);
}
.p-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 22px 22px;
  background-position: 0 0, 11px 11px;
  opacity: 0.16;
  pointer-events: none;
}
.p-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
  position: relative;
  z-index: 2;
}
.p-badge-status {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(16,185,129,0.22);
  border: 1.5px solid rgba(52,211,153,0.6);
  color: #a7f3d0;
  font-size: 10px;
  font-weight: 900;
  padding: 3px 9px;
  border-radius: 12px;
  letter-spacing: 0.3px;
}
.p-dot-pulse {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #34d399;
  box-shadow: 0 0 6px #34d399;
  animation: pulseDot 1.8s infinite;
}
@keyframes pulseDot {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(1.3); }
}
.p-user-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(255,255,255,0.15);
  border: 1.5px solid rgba(255,255,255,0.25);
  color: #ffffff;
  font-size: 10.5px;
  font-weight: 800;
  padding: 3px 9px;
  border-radius: 12px;
}
.p-hero-title {
  position: relative;
  z-index: 2;
  font-size: 20px;
  font-weight: 900;
  color: #ffffff;
  text-shadow: 0 2px 0 #78350f;
  margin-bottom: 2px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.p-hero-sub {
  position: relative;
  z-index: 2;
  font-size: 11px;
  font-weight: 700;
  color: #fef08a;
  line-height: 1.35;
}

/* ── BODY WRAPPER ── */
.p-body {
  padding: 14px 14px 90px;
  max-width: 640px;
  margin: 0 auto;
}

/* ── VIP INCOME CARD (FLEX TARGET) ── */
.p-card-income {
  background: linear-gradient(145deg, #ffffff 0%, #fffbeb 40%, #fef3c7 100%);
  border: 3px solid #78350f;
  border-radius: 20px;
  padding: 16px 14px 14px;
  box-shadow: 0 6px 0 #78350f, 0 12px 24px rgba(120,53,15,0.18);
  margin-bottom: 14px;
  position: relative;
  overflow: hidden;
}
.p-card-income::after {
  content: '';
  position: absolute;
  right: -10px;
  bottom: -10px;
  width: 90px;
  height: 90px;
  background: transparent;
}
.p-income-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.p-income-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10px;
  font-weight: 900;
  color: #78350f;
  background: #fde68a;
  border: 1px solid #f59e0b;
  padding: 2px 8px;
  border-radius: 8px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.p-income-payout-status {
  font-size: 10.5px;
  font-weight: 800;
  padding: 2px 8px;
  border-radius: 8px;
}
.p-income-payout-status.paid {
  background: #dcfce7;
  color: #15803d;
  border: 1px solid #86efac;
}
.p-income-payout-status.pending {
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fde68a;
}

.p-income-val {
  font-size: 28px;
  font-weight: 900;
  color: #b45309;
  line-height: 1;
  letter-spacing: -0.5px;
  margin-bottom: 10px;
  text-shadow: 0 1px 0 rgba(255,255,255,0.7);
}
.p-income-val span.curr {
  font-size: 16px;
  color: #78350f;
  font-weight: 800;
}

/* Progress bar target */
.p-target-box {
  background: rgba(255,255,255,0.85);
  border: 1.5px solid #fde68a;
  border-radius: 12px;
  padding: 10px 12px;
}
.p-target-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 800;
  color: #78350f;
  margin-bottom: 6px;
}
.p-target-bar {
  height: 8px;
  background: #f1f5f9;
  border-radius: 8px;
  overflow: hidden;
  position: relative;
  border: 1px solid #e2e8f0;
}
.p-target-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #10b981);
  border-radius: 8px;
  transition: width 0.4s ease;
}
.p-target-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10px;
  font-weight: 800;
  color: #64748b;
  margin-top: 5px;
}

/* ── VIP PENDING SALARY CARD ── */
.p-card-pending-salary {
  background: linear-gradient(145deg, #fffdf8 0%, #fef3c7 40%, #fde68a 100%);
  border: 3px solid #b45309;
  border-radius: 20px;
  padding: 16px 14px 14px;
  box-shadow: 0 6px 0 #92400e, 0 12px 24px rgba(180,83,9,0.18);
  margin-bottom: 14px;
  position: relative;
  overflow: hidden;
}
.p-card-pending-salary::after {
  content: '';
  position: absolute;
  right: -10px;
  bottom: -10px;
  width: 85px;
  height: 85px;
  background: transparent;
}
.p-pending-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  position: relative;
  z-index: 2;
}
.p-pending-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10.5px;
  font-weight: 900;
  color: #78350f;
  background: rgba(255,255,255,0.75);
  border: 1.5px solid #d97706;
  padding: 3px 9px;
  border-radius: 10px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.p-pending-badge {
  font-size: 10px;
  font-weight: 900;
  padding: 3px 9px;
  border-radius: 10px;
}
.p-pending-badge.active {
  background: #fef08a;
  color: #854d0e;
  border: 1.5px solid #ca8a04;
  box-shadow: 0 0 8px rgba(234,179,8,0.3);
}
.p-pending-badge.settled {
  background: #dcfce7;
  color: #15803d;
  border: 1.5px solid #86efac;
}
.p-pending-val-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
  position: relative;
  z-index: 2;
}
.p-pending-val {
  font-size: 27px;
  font-weight: 900;
  color: #92400e;
  line-height: 1;
  letter-spacing: -0.5px;
  text-shadow: 0 1px 0 rgba(255,255,255,0.8);
}
.p-pending-val span.curr {
  font-size: 16px;
  color: #78350f;
  font-weight: 800;
}
.p-btn-pending-detail {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #ffffff;
  border: 2px solid #b45309;
  color: #92400e;
  font-size: 10.5px;
  font-weight: 900;
  padding: 5px 11px;
  border-radius: 10px;
  cursor: pointer;
  box-shadow: 0 2px 0 #92400e;
  transition: all 0.12s ease;
}
.p-btn-pending-detail:active {
  transform: translateY(2px);
  box-shadow: 0 0 0 #92400e;
}
.p-pending-desc {
  font-size: 11px;
  font-weight: 700;
  color: #78350f;
  line-height: 1.45;
  background: rgba(255,255,255,0.7);
  border: 1.5px solid rgba(217,119,6,0.35);
  border-radius: 12px;
  padding: 9px 12px;
  position: relative;
  z-index: 2;
}
.p-pending-note {
  display: block;
  font-size: 10px;
  font-weight: 800;
  color: #b45309;
  margin-top: 5px;
  padding-top: 5px;
  border-top: 1px dashed rgba(217,119,6,0.3);
}
.p-pending-breakdown {
  margin-top: 10px;
  background: #ffffff;
  border: 2px solid #b45309;
  border-radius: 14px;
  padding: 11px 12px;
  box-shadow: 0 3px 0 #b45309;
  position: relative;
  z-index: 2;
}
.p-breakdown-title {
  font-size: 10.5px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.p-breakdown-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.p-breakdown-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 10px;
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-radius: 10px;
  font-size: 11px;
}
.p-b-date {
  font-weight: 900;
  color: #78350f;
}
.p-b-sub {
  font-size: 9.5px;
  font-weight: 700;
  color: #92400e;
}
.p-b-amt {
  font-weight: 900;
  color: #059669;
  font-size: 12px;
  text-align: right;
}

/* ── QUICK METRICS GRID ── */
.p-stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 14px;
}
.p-stat-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px 8px;
  text-align: center;
  box-shadow: 0 3px 0 #78350f;
}
.p-stat-val {
  font-size: 15px;
  font-weight: 900;
  line-height: 1.2;
}
.p-stat-val.amber { color: #d97706; }
.p-stat-val.emerald { color: #059669; }
.p-stat-val.purple { color: #7c3aed; }
.p-stat-lbl {
  font-size: 9px;
  font-weight: 900;
  color: #64748b;
  margin-top: 3px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

/* ── SEGMENTED TAB BAR ── */
.p-tab-bar {
  display: flex;
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 4px;
  gap: 4px;
  box-shadow: 0 4px 0 #78350f;
  margin-bottom: 14px;
}
.p-tab-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 8px 4px;
  font-size: 11px;
  font-weight: 900;
  color: #64748b;
  border-radius: 10px;
  cursor: pointer;
  border: none;
  background: transparent;
  transition: all 0.15s ease;
  white-space: nowrap;
}
.p-tab-btn i { font-size: 14px; }
.p-tab-btn.active {
  background: linear-gradient(180deg, #f59e0b, #d97706);
  color: #ffffff;
  box-shadow: 0 2px 0 #78350f;
  text-shadow: 0 1px 1px #78350f;
}

/* Tab panes */
.p-pane { display: none; }
.p-pane.active { display: block; animation: paneFade 0.2s ease; }
@keyframes paneFade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

/* ── MODERN LIST ITEMS ── */
.p-card-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 12px;
}
.p-list-item {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s ease;
}
.p-list-ico {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1.5px solid #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  flex-shrink: 0;
}
.p-list-ico.amber { background: #fef3c7; color: #d97706; }
.p-list-ico.emerald { background: #dcfce7; color: #059669; }
.p-list-ico.purple { background: #ede9fe; color: #7c3aed; }
.p-list-ico.bank { background: #f8fafc; overflow: hidden; padding: 4px; }
.p-list-ico.bank img { width: 100%; height: 100%; object-fit: contain; }

.p-list-main {
  flex: 1;
  min-width: 0;
}
.p-list-title {
  font-size: 12px;
  font-weight: 900;
  color: #78350f;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 2px;
}
.p-list-sub {
  font-size: 10px;
  font-weight: 700;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 4px;
}
.p-list-right {
  text-align: right;
  flex-shrink: 0;
}
.p-list-amt {
  font-size: 12.5px;
  font-weight: 900;
  color: #059669;
  margin-bottom: 2px;
}
.p-pill-status {
  display: inline-block;
  font-size: 8.5px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  text-transform: uppercase;
}
.p-pill-status.success {
  background: #dcfce7;
  color: #166534;
  border: 1px solid #86efac;
}
.p-pill-status.warn {
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fde68a;
}
.p-pill-status.free {
  background: #e0f2fe;
  color: #0369a1;
  border: 1px solid #bae6fd;
}

/* ── CHARTS CONTAINER ── */
.p-chart-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 16px;
  padding: 14px 12px;
  box-shadow: 0 4px 0 #78350f;
  margin-bottom: 14px;
}
.p-chart-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 10px;
  text-transform: uppercase;
}
.p-chart-head i { font-size: 16px; color: #d97706; }
.p-chart-card canvas { width: 100% !important; height: 165px !important; }

/* ── PAGINATION BAR ── */
.p-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 10px;
  padding: 0 2px;
}
.p-btn-page {
  padding: 6px 12px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  text-decoration: none;
  cursor: pointer;
  box-shadow: 0 2px 0 #78350f;
  transition: all 0.1s ease;
}
.p-btn-page:active {
  transform: translateY(2px);
  box-shadow: 0 0 0 #78350f;
}
.p-btn-page:disabled,
.p-btn-page.disabled {
  opacity: 0.4;
  pointer-events: none;
}
.p-page-info {
  font-size: 11px;
  font-weight: 800;
  color: #78350f;
}

/* ── FAKE WD FORM ── */
.p-fwd-card {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 16px 14px;
  box-shadow: 0 5px 0 #78350f;
  margin-bottom: 16px;
}
.p-account-box {
  background: linear-gradient(135deg, #fffbeb, #fef3c7);
  border: 1.5px solid #fde68a;
  border-radius: 12px;
  padding: 10px 12px;
  margin-bottom: 14px;
}
.p-account-lbl {
  font-size: 9.5px;
  font-weight: 900;
  color: #92400e;
  text-transform: uppercase;
  margin-bottom: 2px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.p-account-val {
  font-size: 11.5px;
  font-weight: 800;
  color: #78350f;
  line-height: 1.4;
}

.p-form-group {
  margin-bottom: 12px;
}
.p-form-label {
  display: block;
  font-size: 10.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.p-form-input {
  width: 100%;
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  border-radius: 10px;
  padding: 9px 12px;
  font-size: 12.5px;
  font-weight: 800;
  color: #1e293b;
  font-family: 'Nunito', sans-serif;
  box-sizing: border-box;
  transition: all 0.15s ease;
}
.p-form-input:focus {
  outline: none;
  border-color: #d97706;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(217,119,6,0.15);
}
.p-btn-submit {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 12px;
  border-radius: 12px;
  font-size: 13px;
  font-weight: 900;
  color: #ffffff;
  background: linear-gradient(180deg, #f59e0b, #d97706);
  border: 2.5px solid #78350f;
  box-shadow: 0 4px 0 #78350f;
  cursor: pointer;
  text-shadow: 0 1px 2px #78350f;
  transition: transform 0.1s ease;
}
.p-btn-submit:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 #78350f;
}

/* Empty State */
.p-empty-state {
  text-align: center;
  padding: 24px 16px;
  background: #ffffff;
  border: 2px dashed #fcd34d;
  border-radius: 16px;
  margin-bottom: 12px;
}
.p-empty-icon {
  font-size: 36px;
  color: #d97706;
  margin-bottom: 6px;
}
.p-empty-text {
  font-size: 11.5px;
  font-weight: 800;
  color: #78350f;
}

/* Section Title */
.p-sec-heading {
  font-size: 12px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  margin: 16px 0 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.p-sec-heading i { color: #d97706; font-size: 16px; }

/* Alert Flash */
.p-alert {
  padding: 10px 12px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 800;
  margin-bottom: 12px;
  border: 2px solid;
}
.p-alert.success {
  background: #dcfce7;
  color: #166534;
  border-color: #4ade80;
}
.p-alert.error {
  background: #fee2e2;
  color: #991b1b;
  border-color: #f87171;
}
</style>

<!-- HERO TOP BANNER -->
<div class="p-hero">
  <div class="p-hero-top">
    <div class="p-badge-status">
      <span class="p-dot-pulse"></span>
      <span>MITRA PROMOTOR AKTIF</span>
    </div>
    <div class="p-user-chip">
      <i class="ph-bold ph-identification-badge"></i>
      <span>ID #<?= $user['id'] ?></span>
    </div>
  </div>
  <div class="p-hero-title">
    <i class="ph-fill ph-megaphone-simple" style="color:#fef08a;"></i>
    <span>Promotor Hub MancingCuan</span>
  </div>
  <div class="p-hero-sub">
    Analisis traffic, target harian & manajemen bukti pencairan komisi.
  </div>
</div>

<div class="p-body">
  <!-- PENDAPATAN HARI INI -->
  <div class="p-card-income">
    <div class="p-income-head">
      <div class="p-income-tag">
        <i class="ph-fill ph-coins"></i> Gaji Target Hari Ini
      </div>
      <div class="p-income-payout-status <?= !empty($today_target['is_paid']) ? 'paid' : 'pending' ?>">
        <?= !empty($today_target['is_paid']) ? '✓ Sudah Cair ke WD' : '⏳ Menunggu Validasi' ?>
      </div>
    </div>
    
    <div class="p-income-val">
      <span class="curr">Rp</span> <?= number_format((float)$today_target['salary_rate'], 0, ',', '.') ?>
    </div>

    <!-- Progress Capaian Target -->
    <div class="p-target-box">
      <div class="p-target-head">
        <span><i class="ph-fill ph-users-three"></i> Target Member Terdaftar</span>
        <span><strong><?= number_format((int)$today_target['actual_regs']) ?></strong> / <?= number_format((int)$today_target['target_regs']) ?> Member</span>
      </div>
      <div class="p-target-bar">
        <div class="p-target-fill" style="width: <?= min(100.0, (float)$today_target['percentage']) ?>%;"></div>
      </div>
      <div class="p-target-foot">
        <span>Capaian: <strong><?= number_format((float)$today_target['percentage'], 1) ?>%</strong></span>
        <span>Estimasi: <strong><?= format_rp($today_earned) ?></strong></span>
      </div>
    </div>
  </div>

  <!-- QUICK METRICS GRID -->
  <div class="p-stats-grid">
    <div class="p-stat-card">
      <div class="p-stat-val amber"><?= number_format($today_clicks) ?></div>
      <div class="p-stat-lbl">Klik Hari Ini</div>
    </div>
    <div class="p-stat-card">
      <div class="p-stat-val emerald"><?= number_format($total_clicks) ?></div>
      <div class="p-stat-lbl">Total Klik</div>
    </div>
    <div class="p-stat-card">
      <div class="p-stat-val purple"><?= number_format($avg_percentage, 1) ?>%</div>
      <div class="p-stat-lbl">Rata-rata Target</div>
    </div>
  </div>

  <!-- KARTU GAJI PENDING (HARI INI TIDAK DIHITUNG) -->
  <div class="p-card-pending-salary">
    <div class="p-pending-head">
      <div class="p-pending-tag">
        <i class="ph-fill ph-hourglass-high"></i> Total Gaji Pending
      </div>
      <?php if ($pending_days > 0): ?>
        <span class="p-pending-badge active">⏳ <?= $pending_days ?> Hari Tertunda</span>
      <?php else: ?>
        <span class="p-pending-badge settled">✓ Lunas Semua</span>
      <?php endif; ?>
    </div>

    <div class="p-pending-val-row">
      <div class="p-pending-val">
        <span class="curr">Rp</span> <?= number_format($pending_salary, 0, ',', '.') ?>
      </div>
      <?php if (!empty($pending_history_list)): ?>
        <button type="button" class="p-btn-pending-detail" onclick="togglePendingDetail()">
          <i class="ph-bold ph-list-dashes"></i> Rincian Hari
        </button>
      <?php endif; ?>
    </div>
    
    <div class="p-pending-desc">
      <?php if ($pending_salary > 0): ?>
        Akumulasi komisi dari <strong><?= $pending_days ?> hari</strong> target sebelumnya yang telah selesai dan sedang menunggu pencairan oleh Admin ke Saldo Penarikan.
      <?php else: ?>
        Tidak ada gaji pending dari hari-hari sebelumnya. Semua riwayat target telah lunas dicairkan ke Saldo Penarikan.
      <?php endif; ?>
      <span class="p-pending-note">
        <i class="ph-bold ph-info"></i> Catatan: Hari ini (<?= date('d M Y') ?>) tidak dihitung dalam gaji pending karena target harian masih berlangsung.
      </span>
    </div>

    <?php if (!empty($pending_history_list)): ?>
    <div id="pending-breakdown-box" class="p-pending-breakdown" style="display:none;">
      <div class="p-breakdown-title">
        <i class="ph-bold ph-clock-counter-clockwise"></i> Riwayat Tanggal Gaji Pending (Belum Cair):
      </div>
      <div class="p-breakdown-list">
        <?php foreach ($pending_history_list as $plog): 
          $plog_earned = (float)round(($plog['salary_rate'] * min(100.0, (float)$plog['percentage'])) / 100.0);
        ?>
        <div class="p-breakdown-item">
          <div>
            <div class="p-b-date"><?= date('d M Y', strtotime($plog['date'])) ?></div>
            <div class="p-b-sub">Capaian: <?= number_format((float)$plog['percentage'], 1) ?>% &bull; <?= number_format((int)$plog['actual_regs']) ?> Reg &bull; <?= format_rp((float)$plog['actual_deposits']) ?> Depo</div>
          </div>
          <div class="p-b-amt">+<?= format_rp($plog_earned) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- SEGMENTED TAB BAR -->
  <div class="p-tab-bar">
    <button type="button" class="p-tab-btn active" onclick="switchTab('tab-stats')">
      <i class="ph-bold ph-calendar-check"></i> Riwayat
    </button>
    <button type="button" class="p-tab-btn" onclick="switchTab('tab-chart')">
      <i class="ph-bold ph-chart-line-up"></i> Grafik
    </button>
    <button type="button" class="p-tab-btn" onclick="switchTab('tab-dl')">
      <i class="ph-bold ph-users-three"></i> Downline
    </button>
    <button type="button" class="p-tab-btn" onclick="switchTab('tab-fwd')">
      <i class="ph-bold ph-receipt"></i> Bukti WD
    </button>
  </div>

  <!-- TAB 1: RIWAYAT TARGET -->
  <div id="tab-stats" class="p-pane active">
    <?php if (empty($history_logs)): ?>
      <div class="p-empty-state">
        <div class="p-empty-icon"><i class="ph-bold ph-calendar-blank"></i></div>
        <div class="p-empty-text">Belum ada catatan riwayat target harian.</div>
      </div>
    <?php else: ?>
      <div class="p-card-list">
        <?php foreach ($history_logs as $log): 
          $is_today_log = ($log['date'] === date('Y-m-d'));
          $log_earned = (float)round(($log['salary_rate'] * min(100.0, (float)$log['percentage'])) / 100.0);
        ?>
        <div class="p-list-item">
          <div class="p-list-ico <?= $log['is_paid'] ? 'emerald' : ($is_today_log ? 'purple' : ($log_earned > 0 ? 'amber' : 'purple')) ?>">
            <i class="ph-bold <?= $log['is_paid'] ? 'ph-check-circle' : ($is_today_log ? 'ph-arrows-clockwise' : ($log_earned > 0 ? 'ph-hourglass-high' : 'ph-x-circle')) ?>"></i>
          </div>
          <div class="p-list-main">
            <div class="p-list-title">
              <?= date('d M Y', strtotime($log['date'])) ?>
              <?php if ($is_today_log): ?>
                <span style="font-size:9px;color:#0284c7;background:#e0f2fe;border:1px solid #bae6fd;padding:1px 5px;border-radius:5px;margin-left:4px;">HARI INI</span>
              <?php endif; ?>
            </div>
            <div class="p-list-sub">
              Capaian: <?= number_format((float)$log['percentage'], 1) ?>% &bull; <?= number_format((int)$log['actual_regs']) ?>/<?= number_format((int)$log['target_regs']) ?> Member
            </div>
          </div>
          <div class="p-list-right">
            <div class="p-list-amt" style="color:<?= $log['is_paid'] ? '#059669' : ($log_earned > 0 ? '#b45309' : '#64748b') ?>;">
              <?= format_rp($log['is_paid'] && (float)$log['paid_amount'] > 0 ? (float)$log['paid_amount'] : $log_earned) ?>
            </div>
            <?php if ($is_today_log): ?>
              <span class="p-pill-status" style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;">BERJALAN</span>
            <?php elseif ($log['is_paid']): ?>
              <span class="p-pill-status success">DIBAYAR</span>
            <?php elseif ($log_earned > 0): ?>
              <span class="p-pill-status warn">PENDING</span>
            <?php else: ?>
              <span class="p-pill-status" style="background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;">0% TERCAPAI</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="p-pagination">
        <a href="?page=<?= max(1, $page-1) ?>" class="p-btn-page <?= $page <= 1 ? 'disabled' : '' ?>">
          ← Sebelumnya
        </a>
        <span class="p-page-info">Halaman <strong><?= $page ?></strong> dari <?= $total_pages ?></span>
        <a href="?page=<?= min($total_pages, $page+1) ?>" class="p-btn-page <?= $page >= $total_pages ? 'disabled' : '' ?>">
          Berikutnya →
        </a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- TAB 2: GRAFIK ANALISIS -->
  <div id="tab-chart" class="p-pane">
    <div class="p-chart-card">
      <div class="p-chart-head">
        <i class="ph-bold ph-cursor-click"></i> Volume Klik Referral (7 Hari Terakhir)
      </div>
      <canvas id="clicksChart"></canvas>
    </div>
    <div class="p-chart-card">
      <div class="p-chart-head">
        <i class="ph-bold ph-user-plus"></i> Registrasi Member Baru (7 Hari Terakhir)
      </div>
      <canvas id="regsChart"></canvas>
    </div>
  </div>

  <!-- TAB 3: DOWNLINE / MEMBER -->
  <div id="tab-dl" class="p-pane">
    <?php if (empty($downlines)): ?>
      <div class="p-empty-state">
        <div class="p-empty-icon"><i class="ph-bold ph-users"></i></div>
        <div class="p-empty-text">Belum ada member yang mendaftar melalui referralmu.</div>
      </div>
    <?php else: ?>
      <div class="p-card-list">
        <?php foreach ($downlines as $idx => $dl): ?>
        <div class="p-list-item dl-item-row" style="<?= $idx >= 5 ? 'display:none;' : '' ?>">
          <div class="p-list-ico purple"><i class="ph-bold ph-user"></i></div>
          <div class="p-list-main">
            <div class="p-list-title"><?= htmlspecialchars($dl['username']) ?></div>
            <div class="p-list-sub">
              Bergabung: <?= date('d M Y', strtotime($dl['created_at'])) ?>
            </div>
          </div>
          <div class="p-list-right">
            <span class="p-pill-status free">
              <?= htmlspecialchars($dl['membership_name'] ?: 'Member Gratis') ?>
            </span>
            <?php if ((int)$dl['dep_count'] > 0): ?>
            <div style="font-size:9px;font-weight:800;color:#059669;margin-top:2px;">
              ✓ <?= $dl['dep_count'] ?>x Deposit
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($downlines) > 5): ?>
      <div class="p-pagination">
        <button type="button" onclick="dlPrev()" id="dl-btn-prev" class="p-btn-page" style="opacity:0.5;pointer-events:none;">
          ← Sebelumnya
        </button>
        <span id="dl-page-info" class="p-page-info">1/<?= ceil(count($downlines) / 5) ?></span>
        <button type="button" onclick="dlNext()" id="dl-btn-next" class="p-btn-page">
          Berikutnya →
        </button>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- TAB 4: BUAT BUKTI WD FAKE -->
  <div id="tab-fwd" class="p-pane">
    <?php if ($fwd_flash): ?>
    <div class="p-alert <?= $fwd_flashType ?>"><?= htmlspecialchars($fwd_flash) ?></div>
    <?php endif; ?>

    <!-- Form Input Bukti WD -->
    <div class="p-fwd-card">
      <form method="POST" id="fwd-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fake_wd">

        <!-- Rekening Promotor -->
        <div class="p-account-box">
          <div class="p-account-lbl">
            <i class="ph-bold ph-bank"></i> Rekening Penerima Pencairan
          </div>
          <?php if (!empty($user['bank_name'])): ?>
          <div class="p-account-val">
            <strong><?= htmlspecialchars($user['bank_name']) ?></strong> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?><br>
            a/n <?= htmlspecialchars($user['account_name'] ?? '') ?>
          </div>
          <?php else: ?>
          <div style="font-size:11px;font-weight:800;color:#ea580c;">
            ⚠️ Rekening belum diisi. <a href="/edit-rekening" style="color:#c2410c;font-weight:900;">Lengkapi sekarang &rarr;</a>
          </div>
          <?php endif; ?>
        </div>

        <div class="p-form-group">
          <label class="p-form-label">Nominal Penarikan (Rp)</label>
          <input class="p-form-input" type="number" name="fwd_amount" placeholder="Contoh: 250000" min="1000" step="1000" required>
        </div>

        <div class="p-form-group">
          <label class="p-form-label">Tanggal Penarikan</label>
          <input class="p-form-input" type="date" name="fwd_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="p-form-group">
          <label class="p-form-label">Status Bukti</label>
          <select class="p-form-input" name="fwd_status">
            <option value="approved">✅ Berhasil / Disetujui (Approved)</option>
            <option value="pending">⏳ Menunggu Diproses (Pending)</option>
          </select>
        </div>

        <button type="submit" class="p-btn-submit">
          <i class="ph-bold ph-floppy-disk"></i> Buat & Simpan Bukti WD
        </button>
      </form>
    </div>

    <!-- Riwayat WD Fake -->
    <?php if (!empty($fake_wds)): ?>
    <div class="p-sec-heading">
      <i class="ph-bold ph-clock-counter-clockwise"></i> Riwayat Bukti WD Dibuat
    </div>
    <div class="p-card-list">
      <?php foreach ($fake_wds as $fw): ?>
      <?php $wl = $channel_logos[strtolower($fw['bank_name'])] ?? null; ?>
      <div class="p-list-item">
        <?php if ($wl): ?>
        <div class="p-list-ico bank"><img src="/assets/banks/<?= htmlspecialchars($wl) ?>" alt="<?= htmlspecialchars($fw['bank_name']) ?>"></div>
        <?php else: ?>
        <div class="p-list-ico amber"><i class="ph-bold ph-money"></i></div>
        <?php endif; ?>
        <div class="p-list-main">
          <div class="p-list-amt"><?= format_rp((float)$fw['amount']) ?></div>
          <div class="p-list-sub"><?= htmlspecialchars($fw['bank_name']) ?> · <?= date('d M Y, H:i', strtotime($fw['created_at'])) ?></div>
        </div>
        <div class="p-list-right">
          <span class="p-pill-status <?= $fw['status'] === 'approved' ? 'success' : 'warn' ?>">
            <?= ucfirst($fw['status']) ?>
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Switch Tabs
function switchTab(tabId) {
  document.querySelectorAll('.p-tab-btn').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.p-pane').forEach(c => c.classList.remove('active'));
  const activeBtn = document.querySelector(`.p-tab-btn[onclick="switchTab('${tabId}')"]`);
  if (activeBtn) activeBtn.classList.add('active');
  const targetPane = document.getElementById(tabId);
  if (targetPane) targetPane.classList.add('active');
}

// Toggle Pending Breakdown Box
function togglePendingDetail() {
  const box = document.getElementById('pending-breakdown-box');
  if (box) {
    box.style.display = box.style.display === 'none' ? 'block' : 'none';
  }
}

// Downline Client Pagination
let dlCurrentPage = 1;
const dlLimit = 5;
const dlTotal = <?= count($downlines) ?>;
const dlTotalPages = Math.max(1, Math.ceil(dlTotal / dlLimit));

function updateDlPagination() {
  const items = document.querySelectorAll('.dl-item-row');
  items.forEach((item, idx) => {
    item.style.display = (idx >= (dlCurrentPage - 1) * dlLimit && idx < dlCurrentPage * dlLimit) ? 'flex' : 'none';
  });
  
  const info = document.getElementById('dl-page-info');
  if (info) info.textContent = dlCurrentPage + '/' + dlTotalPages;
  
  const prevBtn = document.getElementById('dl-btn-prev');
  if (prevBtn) {
    prevBtn.style.opacity = dlCurrentPage <= 1 ? '0.5' : '1';
    prevBtn.style.pointerEvents = dlCurrentPage <= 1 ? 'none' : 'auto';
  }
  const nextBtn = document.getElementById('dl-btn-next');
  if (nextBtn) {
    nextBtn.style.opacity = dlCurrentPage >= dlTotalPages ? '0.5' : '1';
    nextBtn.style.pointerEvents = dlCurrentPage >= dlTotalPages ? 'none' : 'auto';
  }
}
function dlPrev() { if (dlCurrentPage > 1) { dlCurrentPage--; updateDlPagination(); } }
function dlNext() { if (dlCurrentPage < dlTotalPages) { dlCurrentPage++; updateDlPagination(); } }

// Charts Initialization
document.addEventListener('DOMContentLoaded', () => {
  const commonOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { 
        beginAtZero: true, 
        grid: { color: 'rgba(0,0,0,0.05)' },
        ticks: { font: { size: 9, family: 'Nunito', weight: '700' }, color: '#64748b' } 
      },
      x: { 
        grid: { display: false },
        ticks: { font: { size: 9, family: 'Nunito', weight: '700' }, color: '#64748b' } 
      }
    }
  };

  const ctxClicks = document.getElementById('clicksChart');
  if (ctxClicks) {
    new Chart(ctxClicks.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
          data: <?= json_encode($chart_data) ?>,
          backgroundColor: '#f59e0b',
          borderRadius: 6
        }]
      },
      options: commonOptions
    });
  }

  const ctxRegs = document.getElementById('regsChart');
  if (ctxRegs) {
    new Chart(ctxRegs.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($chart_reg_labels) ?>,
        datasets: [{
          data: <?= json_encode($chart_reg_data) ?>,
          borderColor: '#10b981',
          backgroundColor: 'rgba(16,185,129,0.18)',
          fill: true,
          tension: 0.35,
          borderWidth: 2.5,
          pointRadius: 4,
          pointBackgroundColor: '#10b981'
        }]
      },
      options: commonOptions
    });
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
