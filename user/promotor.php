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
    'target_deposits' => 0,
    'actual_deposits' => 0.0,
    'target_regs' => 0,
    'actual_regs' => 0,
    'percentage' => 0.0,
    'salary_rate' => 0.0,
    'is_paid' => 0
];
$today_earned = (float)round(($today_target['salary_rate'] * min(100.0, (float)$today_target['percentage'])) / 100.0);

// Rincian komisi hari ini (sync menyimpan: target_deposits = jumlah transaksi deposit, target_regs = komisi member dalam Rp)
$today_regs     = (int)$today_target['actual_regs'];
$today_reg_comm = (float)$today_target['target_regs'];
$today_dep_amt  = (float)$today_target['actual_deposits'];
$today_dep_comm = max(0.0, $today_earned - $today_reg_comm);

// Target harian dari admin (hanya tampilan progres; gaji tetap komisi)
$tg_regs = max(0, (int)$user['promotor_target_regs']);
$tg_dep  = max(0.0, (float)$user['promotor_target_deposits']);
$tg_pct = static function (float $actual, float $target): float {
    return $target > 0 ? min(100.0, $actual / $target * 100) : 0.0;
};
$pct_regs  = $tg_pct($today_regs, $tg_regs);
$pct_dep   = $tg_pct($today_dep_amt, $tg_dep);
$tg_parts  = array_filter([$tg_regs > 0 ? $pct_regs : null, $tg_dep > 0 ? $pct_dep : null], fn($v) => $v !== null);
$today_pct = $tg_parts ? array_sum($tg_parts) / count($tg_parts) : 0.0;

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

// Total gaji yang sudah dicairkan
$paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM promotor_daily_targets WHERE user_id=? AND is_paid=1");
$paid_stmt->execute([$user['id']]);
$total_paid = (float)$paid_stmt->fetchColumn();

$log_earned = static fn(array $l): float => (float)round(((float)$l['salary_rate'] * min(100.0, (float)$l['percentage'])) / 100.0);

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
        (SELECT name FROM memberships WHERE id = u.membership_id) as membership_name
    FROM users u
    WHERE u.referred_by = ?
    ORDER BY u.created_at DESC
    LIMIT 200
");
$downline_stmt->execute([$user['referral_code']]);
$downlines = $downline_stmt->fetchAll();

$dl_tot = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ?");
$dl_tot->execute([$user['referral_code']]);
$downline_total = (int)$dl_tot->fetchColumn();

$ref_url = base_url('register/' . $user['referral_code']);

// Bonus per member (keterangan kartu komisi)
$g_member = (float)setting($pdo, 'promotor_per_member_bonus', '0');

$pageTitle  = 'Promotor Hub';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ PROMOTOR HUB — compact · living coral reef clay ══ */
.pm { width: 100%; max-width: 460px; margin: 0 auto; padding: 6px 12px 30px; display: flex; flex-direction: column; gap: 10px; box-sizing: border-box; }
.pm *, .pm *::before, .pm *::after { box-sizing: border-box; }

.pm-top { display: flex; align-items: center; gap: 8px; }
.pm-top-ic { width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; background: linear-gradient(135deg, #fb7185, #f97316); border: 2px solid #fff; box-shadow: 0 3px 0 #c2410c; flex-shrink: 0; }
.pm-top-tx { flex: 1; min-width: 0; line-height: 1.2; }
.pm-top-tx h1 { margin: 0; font-size: 15px; font-weight: 900; color: var(--reef-ink); letter-spacing: -.2px; }
.pm-top-tx span { font-size: 10.5px; font-weight: 800; color: var(--reef-muted); }
.pm-chip { display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 900; color: #065f46; background: #d1fae5; border: 1.5px solid #fff; white-space: nowrap; }
.pm-chip i { width: 7px; height: 7px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }

.pm-card { border: 2px solid #fff; border-radius: 20px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }

/* Gaji hari ini */
.pm-pay { padding: 14px 15px 12px; background: linear-gradient(140deg, #fff7ed 0%, #ffffff 65%); display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: center; }
.pm-pay small { display: flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 900; color: var(--reef-muted); }
.pm-pay b { display: block; font-size: 27px; font-weight: 900; color: #9a3412; letter-spacing: -.7px; line-height: 1.15; margin: 2px 0 6px; }
.pm-pay b em { font-style: normal; font-size: 14px; color: #f97316; margin-right: 3px; }
.pm-tag { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 9.5px; font-weight: 900; border: 1.5px solid #fff; }
.pm-tag.run  { background: #e0f2fe; color: #0369a1; }
.pm-tag.paid { background: #d1fae5; color: #065f46; }
.pm-tag.grt  { background: #ede9fe; color: #5b21b6; }
.pm-tags { display: flex; flex-wrap: wrap; gap: 4px; }

.pm-ring { --p: 0; width: 82px; height: 82px; border-radius: 50%; background: conic-gradient(#f97316 calc(var(--p) * 1%), #ffedd5 0); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(249,115,22,.22); }
.pm-ring div { width: 64px; height: 64px; border-radius: 50%; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.05; }
.pm-ring strong { font-size: 17px; font-weight: 900; color: #9a3412; letter-spacing: -.4px; }
.pm-ring span { font-size: 8.5px; font-weight: 900; color: var(--reef-muted); text-transform: uppercase; letter-spacing: .3px; }

/* Meter target */
.pm-meters { padding: 10px 15px 12px; border-top: 1.5px dashed #fed7aa; display: grid; gap: 9px; }
.pm-m-head { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; font-size: 11px; font-weight: 900; color: var(--reef-ink); }
.pm-m-head span { display: inline-flex; align-items: center; gap: 4px; }
.pm-m-head em { font-style: normal; font-size: 10.5px; font-weight: 800; color: var(--reef-muted); white-space: nowrap; }
.pm-m-head em b { color: var(--reef-ink); }
.pm-bar { height: 9px; border-radius: 999px; background: #f1f5f9; overflow: hidden; margin-top: 4px; box-shadow: inset 0 1px 2px rgba(30,27,46,.08); }
.pm-bar i { display: block; height: 100%; border-radius: 999px; }
.pm-bar.mem i { background: linear-gradient(90deg, #34d399, #10b981); }
.pm-bar.dep i { background: linear-gradient(90deg, #38bdf8, #0284c7); }
.pm-m-sub { font-size: 9.5px; font-weight: 800; color: var(--reef-muted); margin-top: 3px; }

/* Perbandingan gaji target vs jaminan */
.pm-cmp { display: grid; grid-template-columns: 1fr 1fr; border-top: 1.5px dashed #fed7aa; }
.pm-cmp > div { padding: 9px 12px; position: relative; }
.pm-cmp > div + div { border-left: 1.5px dashed #fed7aa; }
.pm-cmp small { display: block; font-size: 9.5px; font-weight: 900; color: var(--reef-muted); }
.pm-cmp b { display: block; font-size: 14px; font-weight: 900; color: var(--reef-ink); margin-top: 1px; }
.pm-cmp span { display: block; font-size: 9px; font-weight: 800; color: var(--reef-muted); margin-top: 1px; }
.pm-cmp .on { background: #fffbeb; }
.pm-cmp .on b { color: #b45309; }
.pm-cmp .on::after { content: 'DIPAKAI'; position: absolute; top: 8px; right: 9px; font-size: 8px; font-weight: 900; color: #fff; background: #f59e0b; padding: 2px 5px; border-radius: 5px; }

/* Link referral */
.pm-link { display: flex; align-items: center; gap: 8px; padding: 9px 10px 9px 12px; border: 2px solid #fff; border-radius: 16px; background: linear-gradient(135deg, #ecfeff, #ffffff); box-shadow: 0 2px 8px rgba(30,27,46,.05); }
.pm-link-tx { flex: 1; min-width: 0; line-height: 1.25; }
.pm-link-tx small { display: block; font-size: 9.5px; font-weight: 900; color: #0e7490; }
.pm-link-tx code { display: block; font: 800 11px/1.3 inherit; font-family: inherit; color: var(--reef-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-copy { padding: 8px 11px; border-radius: 11px; border: 1.5px solid #fff; background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff; font: 900 11px/1 inherit; font-family: inherit; display: inline-flex; align-items: center; gap: 4px; cursor: pointer; box-shadow: 0 2px 0 #0e7490; flex-shrink: 0; }
.pm-copy:active { transform: translateY(2px); box-shadow: none; }

/* Ringkasan angka */
.pm-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
.pm-st { padding: 8px 4px; border-radius: 14px; border: 1.5px solid #fff; background: #fff; text-align: center; box-shadow: 0 2px 6px rgba(30,27,46,.05); min-width: 0; }
.pm-st b { display: block; font-size: 14px; font-weight: 900; letter-spacing: -.3px; }
.pm-st span { display: block; font-size: 9px; font-weight: 800; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-st.a b { color: #d97706; } .pm-st.b b { color: #0284c7; } .pm-st.c b { color: #059669; } .pm-st.d b { color: #7c3aed; }

/* Gaji pending */
.pm-pend { padding: 11px 13px; display: flex; align-items: center; gap: 10px; }
.pm-pend-ic { width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; background: #fef3c7; color: #b45309; }
.pm-pend-ic.ok { background: #d1fae5; color: #059669; }
.pm-pend-tx { flex: 1; min-width: 0; line-height: 1.25; }
.pm-pend-tx small { display: block; font-size: 10px; font-weight: 900; color: var(--reef-muted); }
.pm-pend-tx b { display: block; font-size: 17px; font-weight: 900; color: var(--reef-ink); letter-spacing: -.3px; }
.pm-pend-tx span { display: block; font-size: 9.5px; font-weight: 800; color: var(--reef-muted); }
.pm-btn-sm { padding: 7px 10px; border-radius: 10px; border: 1.5px solid #fde68a; background: #fffbeb; color: #92400e; font: 900 10.5px/1 inherit; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }
.pm-pend-list { border-top: 1.5px dashed #fde68a; }

/* Tab */
.pm-tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 4px; padding: 4px; border-radius: 15px; background: rgba(255,255,255,.65); border: 1.5px solid #fff; }
.pm-tab { padding: 8px 2px; border: 0; border-radius: 11px; background: transparent; font: 900 10.5px/1 inherit; font-family: inherit; color: var(--reef-muted); cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.pm-tab i { font-size: 15px; }
.pm-tab.active { background: #fff; color: var(--reef-coral-500); box-shadow: 0 2px 6px rgba(30,27,46,.08); }
.pm-pane { display: none; flex-direction: column; gap: 8px; }
.pm-pane.active { display: flex; }

/* Baris daftar */
.pm-list { border: 2px solid #fff; border-radius: 16px; background: #fff; box-shadow: 0 2px 8px rgba(30,27,46,.05); overflow: hidden; }
.pm-row { display: flex; align-items: center; gap: 9px; padding: 9px 12px; }
.pm-row + .pm-row { border-top: 1px solid #f1f5f9; }
.pm-row-ic { width: 32px; height: 32px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.pm-row-ic img { width: 22px; height: 22px; object-fit: contain; }
.ic-paid { background: #d1fae5; color: #059669; }
.ic-run  { background: #e0f2fe; color: #0284c7; }
.ic-wait { background: #fef3c7; color: #b45309; }
.ic-zero { background: #f1f5f9; color: #94a3b8; }
.ic-user { background: #ede9fe; color: #7c3aed; }
.pm-row-tx { flex: 1; min-width: 0; line-height: 1.3; }
.pm-row-tx b { display: block; font-size: 12px; font-weight: 900; color: var(--reef-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-row-tx span { display: block; font-size: 10px; font-weight: 750; color: var(--reef-muted); }
.pm-row-r { text-align: right; flex-shrink: 0; line-height: 1.3; }
.pm-row-r b { display: block; font-size: 12px; font-weight: 900; color: var(--reef-ink); }
.pm-pill { display: inline-block; margin-top: 2px; padding: 2px 6px; border-radius: 6px; font-size: 8.5px; font-weight: 900; letter-spacing: .2px; }
.pl-paid { background: #d1fae5; color: #065f46; }
.pl-run  { background: #e0f2fe; color: #0369a1; }
.pl-wait { background: #fef3c7; color: #92400e; }
.pl-zero { background: #f1f5f9; color: #64748b; }
.pl-off  { background: #fff1f2; color: #be123c; }
.pl-tier { background: #f5f3ff; color: #6d28d9; }
.pm-mini-bar { height: 4px; border-radius: 999px; background: #f1f5f9; margin-top: 4px; overflow: hidden; max-width: 160px; }
.pm-mini-bar i { display: block; height: 100%; background: #f97316; border-radius: 999px; }

.pm-empty { padding: 22px 12px; text-align: center; font-size: 11px; font-weight: 800; color: var(--reef-muted); border: 2px dashed #e2e8f0; border-radius: 16px; background: rgba(255,255,255,.6); }
.pm-empty i { display: block; font-size: 26px; margin-bottom: 5px; color: #cbd5e1; }

.pm-pager { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
.pm-pager a, .pm-pager button { padding: 7px 11px; border-radius: 10px; border: 1.5px solid #fff; background: #fff; font: 900 10.5px/1 inherit; font-family: inherit; color: var(--reef-ink); text-decoration: none; cursor: pointer; box-shadow: 0 2px 5px rgba(30,27,46,.05); }
.pm-pager .off { opacity: .45; pointer-events: none; }
.pm-pager span { font-size: 10.5px; font-weight: 800; color: var(--reef-muted); }

.pm-chart { padding: 11px 12px 8px; }
.pm-chart h3 { margin: 0 0 6px; font-size: 11px; font-weight: 900; color: var(--reef-ink); display: flex; align-items: center; gap: 5px; }
.pm-chart .cv { position: relative; height: 150px; }

/* Bukti WD (fitur lama, tampilan disederhanakan) */
.pm-note { padding: 8px 11px; border-radius: 13px; font-size: 11px; font-weight: 800; border: 1.5px solid #fff; }
.pm-note.success { background: #ecfdf5; color: #065f46; }
.pm-note.error { background: #fff1f2; color: #9f1239; }
.pm-form { padding: 12px; display: grid; gap: 8px; }
.pm-form label { font-size: 10px; font-weight: 900; color: var(--reef-muted); margin-bottom: -4px; }
.pm-form input, .pm-form select { width: 100%; padding: 8px 11px; border-radius: 11px; border: 1.5px solid #e2e8f0; background: #fff; font: 700 12px/1.2 inherit; font-family: inherit; color: var(--reef-ink); outline: none; }
.pm-form .acc { font-size: 11px; font-weight: 800; color: var(--reef-ink); padding: 8px 10px; border-radius: 11px; background: #f8fafc; }
.pm-form button { padding: 10px; border-radius: 12px; border: 1.5px solid #fff; background: #334155; color: #fff; font: 900 12px/1 inherit; font-family: inherit; cursor: pointer; }
.pm-sec { font-size: 11px; font-weight: 900; color: var(--reef-ink); padding: 2px 2px 0; }
</style>

<?php
$hist_status = static function (array $log) use ($log_earned): array {
    $earned = $log_earned($log);
    if ($log['date'] === date('Y-m-d')) return ['run', 'BERJALAN', 'ph-arrows-clockwise'];
    if ((int)$log['is_paid'] === 1)     return ['paid', 'DIBAYAR', 'ph-check-circle'];
    if ($earned > 0)                     return ['wait', 'PENDING', 'ph-hourglass-high'];
    return ['zero', 'NIHIL', 'ph-minus-circle'];
};
$dl_pages = max(1, (int)ceil(count($downlines) / 5));
?>

<div class="pm">

  <div class="pm-top">
    <div class="pm-top-ic"><i class="ph-fill ph-megaphone-simple"></i></div>
    <div class="pm-top-tx">
      <h1>Promotor Hub</h1>
      <span>ID #<?= (int)$user['id'] ?> &bull; <?= date('d M Y') ?></span>
    </div>
    <span class="pm-chip"><i></i> Aktif</span>
  </div>

  <!-- GAJI HARI INI -->
  <div class="pm-card">
    <div class="pm-pay">
      <div>
        <small><i class="ph-fill ph-coins"></i> Estimasi Gaji Hari Ini</small>
        <b><em>Rp</em><?= number_format($today_earned, 0, ',', '.') ?></b>
        <div class="pm-tags">
          <?php if (!empty($today_target['is_paid'])): ?>
            <span class="pm-tag paid"><i class="ph-bold ph-check"></i> Sudah cair</span>
          <?php else: ?>
            <span class="pm-tag run"><i class="ph-bold ph-clock"></i> Berjalan s/d 23:59</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="pm-ring" style="--p: <?= round($today_pct, 1) ?>;">
        <div><strong><?= number_format($today_pct, 0) ?>%</strong><span>Target</span></div>
      </div>
    </div>

    <div class="pm-meters">
      <div>
        <div class="pm-m-head">
          <span><i class="ph-fill ph-users-three" style="color:#10b981"></i> Target Member</span>
          <em><b><?= number_format($today_regs, 0, ',', '.') ?></b> / <?= $tg_regs > 0 ? number_format($tg_regs, 0, ',', '.') : '—' ?></em>
        </div>
        <div class="pm-bar mem"><i style="width: <?= round($pct_regs, 1) ?>%"></i></div>
      </div>
      <div>
        <div class="pm-m-head">
          <span><i class="ph-fill ph-wallet" style="color:#0284c7"></i> Target Deposit</span>
          <em><b><?= number_format($pct_dep, 0) ?>%</b><?= $tg_dep > 0 ? '' : ' &bull; belum diatur' ?></em>
        </div>
        <div class="pm-bar dep"><i style="width: <?= round($pct_dep, 1) ?>%"></i></div>
      </div>
    </div>

    <div class="pm-cmp">
      <div>
        <small><i class="ph-fill ph-users-three" style="color:#10b981"></i> Komisi Member</small>
        <b><?= format_rp($today_reg_comm) ?></b>
        <span><?= format_rp($g_member) ?> per member</span>
      </div>
      <div>
        <small><i class="ph-fill ph-wallet" style="color:#0284c7"></i> Komisi Deposit</small>
        <b><?= format_rp($today_dep_comm) ?></b>
        <span>dari deposit member</span>
      </div>
    </div>
  </div>

  <div class="pm-link">
    <div class="pm-link-tx">
      <small><i class="ph-bold ph-link"></i> Link Promosi &bull; <?= htmlspecialchars($user['referral_code']) ?></small>
      <code id="pm-ref"><?= htmlspecialchars($ref_url) ?></code>
    </div>
    <button type="button" class="pm-copy" onclick="pmCopy(this)"><i class="ph-bold ph-copy"></i> Salin</button>
  </div>

  <div class="pm-stats">
    <div class="pm-st a"><b><?= number_format($today_clicks, 0, ',', '.') ?></b><span>Klik hari ini</span></div>
    <div class="pm-st b"><b><?= number_format($total_clicks, 0, ',', '.') ?></b><span>Total klik</span></div>
    <div class="pm-st c"><b><?= number_format($downline_total, 0, ',', '.') ?></b><span>Total member</span></div>
    <div class="pm-st d"><b><?= $total_paid >= 1000000 ? number_format($total_paid / 1000000, 1, ',', '.') . 'jt' : ($total_paid >= 1000 ? number_format($total_paid / 1000, 0, ',', '.') . 'rb' : number_format($total_paid, 0, ',', '.')) ?></b><span>Gaji cair</span></div>
  </div>

  <!-- GAJI PENDING -->
  <div class="pm-card">
    <div class="pm-pend">
      <div class="pm-pend-ic <?= $pending_salary > 0 ? '' : 'ok' ?>"><i class="ph-fill <?= $pending_salary > 0 ? 'ph-hourglass-high' : 'ph-check-circle' ?>"></i></div>
      <div class="pm-pend-tx">
        <small>Gaji Menunggu Cair</small>
        <b><?= format_rp($pending_salary) ?></b>
        <span><?= $pending_days > 0 ? $pending_days . ' hari sebelumnya, dicairkan admin' : 'Semua gaji sebelumnya sudah cair' ?></span>
      </div>
      <?php if (!empty($pending_history_list)): ?>
        <button type="button" class="pm-btn-sm" onclick="togglePendingDetail()"><i class="ph-bold ph-list-dashes"></i> Rincian</button>
      <?php endif; ?>
    </div>
    <?php if (!empty($pending_history_list)): ?>
    <div id="pending-breakdown-box" class="pm-pend-list" style="display:none;">
      <?php foreach ($pending_history_list as $plog): ?>
      <div class="pm-row">
        <div class="pm-row-tx">
          <b><?= date('d M Y', strtotime($plog['date'])) ?></b>
          <span><?= number_format((int)$plog['actual_regs']) ?> member</span>
        </div>
        <div class="pm-row-r"><b style="color:#b45309">+<?= format_rp($log_earned($plog)) ?></b></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="pm-tabs">
    <button type="button" class="pm-tab active" data-tab="tab-stats" onclick="switchTab('tab-stats')"><i class="ph-bold ph-calendar-check"></i>Riwayat</button>
    <button type="button" class="pm-tab" data-tab="tab-chart" onclick="switchTab('tab-chart')"><i class="ph-bold ph-chart-line-up"></i>Grafik</button>
    <button type="button" class="pm-tab" data-tab="tab-dl" onclick="switchTab('tab-dl')"><i class="ph-bold ph-users-three"></i>Member</button>
    <button type="button" class="pm-tab" data-tab="tab-fwd" onclick="switchTab('tab-fwd')"><i class="ph-bold ph-receipt"></i>Bukti WD</button>
  </div>

  <!-- RIWAYAT -->
  <div id="tab-stats" class="pm-pane active">
    <?php if (empty($history_logs)): ?>
      <div class="pm-empty"><i class="ph-bold ph-calendar-blank"></i>Belum ada riwayat target harian.</div>
    <?php else: ?>
      <div class="pm-list">
        <?php foreach ($history_logs as $log):
          [$st, $st_lbl, $st_ic] = $hist_status($log);
          $amt = (int)$log['is_paid'] === 1 && (float)$log['paid_amount'] > 0 ? (float)$log['paid_amount'] : $log_earned($log);
        ?>
        <div class="pm-row">
          <div class="pm-row-ic ic-<?= $st ?>"><i class="ph-bold <?= $st_ic ?>"></i></div>
          <div class="pm-row-tx">
            <b><?= date('d M Y', strtotime($log['date'])) ?></b>
            <span><?= number_format((int)$log['actual_regs']) ?><?= $tg_regs > 0 ? '/' . number_format($tg_regs) : '' ?> member &bull; target depo <?= number_format($tg_pct((float)$log['actual_deposits'], $tg_dep), 0) ?>%</span>
          </div>
          <div class="pm-row-r">
            <b><?= format_rp($amt) ?></b>
            <span class="pm-pill pl-<?= $st ?>"><?= $st_lbl ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($total_pages > 1): ?>
      <div class="pm-pager">
        <a href="?page=<?= max(1, $page - 1) ?>" class="<?= $page <= 1 ? 'off' : '' ?>">← Baru</a>
        <span><?= $page ?> / <?= $total_pages ?></span>
        <a href="?page=<?= min($total_pages, $page + 1) ?>" class="<?= $page >= $total_pages ? 'off' : '' ?>">Lama →</a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- GRAFIK -->
  <div id="tab-chart" class="pm-pane">
    <div class="pm-card pm-chart">
      <h3><i class="ph-bold ph-cursor-click" style="color:#f59e0b"></i> Klik Link (7 hari)</h3>
      <div class="cv"><canvas id="clicksChart"></canvas></div>
    </div>
    <div class="pm-card pm-chart">
      <h3><i class="ph-bold ph-user-plus" style="color:#10b981"></i> Pendaftar Baru (7 hari)</h3>
      <div class="cv"><canvas id="regsChart"></canvas></div>
    </div>
  </div>

  <!-- MEMBER -->
  <div id="tab-dl" class="pm-pane">
    <?php if (empty($downlines)): ?>
      <div class="pm-empty"><i class="ph-bold ph-users"></i>Belum ada member yang daftar lewat linkmu.</div>
    <?php else: ?>
      <div class="pm-list">
        <?php foreach ($downlines as $idx => $dl): ?>
        <div class="pm-row dl-item-row" style="<?= $idx >= 5 ? 'display:none;' : '' ?>">
          <div class="pm-row-ic ic-user"><i class="ph-bold ph-user"></i></div>
          <div class="pm-row-tx">
            <b><?= htmlspecialchars($dl['username']) ?></b>
            <span><?= date('d M Y, H:i', strtotime($dl['created_at'])) ?></span>
          </div>
          <div class="pm-row-r">
            <span class="pm-pill pl-tier"><?= htmlspecialchars($dl['membership_name'] ?: 'Gratis') ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($downlines) > 5): ?>
      <div class="pm-pager">
        <button type="button" onclick="dlPrev()" id="dl-btn-prev" class="off">← Sebelumnya</button>
        <span id="dl-page-info">1/<?= $dl_pages ?></span>
        <button type="button" onclick="dlNext()" id="dl-btn-next">Berikutnya →</button>
      </div>
      <?php endif; ?>
      <?php if ($downline_total > count($downlines)): ?>
        <div class="pm-m-sub" style="text-align:center">Menampilkan <?= count($downlines) ?> member terbaru dari <?= number_format($downline_total, 0, ',', '.') ?>.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- BUKTI WD -->
  <div id="tab-fwd" class="pm-pane">
    <?php if ($fwd_flash): ?>
    <div class="pm-note <?= $fwd_flashType ?>"><?= htmlspecialchars($fwd_flash) ?></div>
    <?php endif; ?>
    <div class="pm-card">
      <form method="POST" id="fwd-form" class="pm-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fake_wd">
        <?php if (!empty($user['bank_name'])): ?>
          <div class="acc"><?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?> a/n <?= htmlspecialchars($user['account_name'] ?? '') ?></div>
        <?php else: ?>
          <div class="acc" style="color:#c2410c">Rekening belum diisi. <a href="/edit-rekening" style="color:#c2410c">Lengkapi &rarr;</a></div>
        <?php endif; ?>
        <label>Nominal (Rp)</label>
        <input type="number" name="fwd_amount" placeholder="250000" min="1000" step="1000" required>
        <label>Tanggal</label>
        <input type="date" name="fwd_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
        <label>Status</label>
        <select name="fwd_status">
          <option value="approved">Approved</option>
          <option value="pending">Pending</option>
        </select>
        <button type="submit">Simpan</button>
      </form>
    </div>
    <?php if (!empty($fake_wds)): ?>
    <div class="pm-list">
      <?php foreach ($fake_wds as $fw): $wl = $channel_logos[strtolower($fw['bank_name'])] ?? null; ?>
      <div class="pm-row">
        <div class="pm-row-ic ic-zero"><?php if ($wl): ?><img src="/assets/banks/<?= htmlspecialchars($wl) ?>" alt=""><?php else: ?><i class="ph-bold ph-money"></i><?php endif; ?></div>
        <div class="pm-row-tx">
          <b><?= format_rp((float)$fw['amount']) ?></b>
          <span><?= htmlspecialchars($fw['bank_name']) ?> · <?= date('d M Y, H:i', strtotime($fw['created_at'])) ?></span>
        </div>
        <div class="pm-row-r"><span class="pm-pill <?= $fw['status'] === 'approved' ? 'pl-paid' : 'pl-wait' ?>"><?= ucfirst($fw['status']) ?></span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function switchTab(tabId) {
  document.querySelectorAll('.pm-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
  document.querySelectorAll('.pm-pane').forEach(p => p.classList.toggle('active', p.id === tabId));
}

function togglePendingDetail() {
  const box = document.getElementById('pending-breakdown-box');
  if (box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
}

function pmCopy(btn) {
  const txt = document.getElementById('pm-ref').textContent.trim();
  const done = () => { btn.innerHTML = '<i class="ph-bold ph-check"></i> Tersalin'; setTimeout(() => { btn.innerHTML = '<i class="ph-bold ph-copy"></i> Salin'; }, 1600); };
  if (navigator.clipboard) navigator.clipboard.writeText(txt).then(done).catch(() => {});
  else { const t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
}

// Paginasi member di sisi klien
let dlCurrentPage = 1;
const dlLimit = 5;
const dlTotalPages = <?= $dl_pages ?>;
function updateDlPagination() {
  document.querySelectorAll('.dl-item-row').forEach((item, idx) => {
    item.style.display = (idx >= (dlCurrentPage - 1) * dlLimit && idx < dlCurrentPage * dlLimit) ? 'flex' : 'none';
  });
  const info = document.getElementById('dl-page-info');
  if (info) info.textContent = dlCurrentPage + '/' + dlTotalPages;
  document.getElementById('dl-btn-prev')?.classList.toggle('off', dlCurrentPage <= 1);
  document.getElementById('dl-btn-next')?.classList.toggle('off', dlCurrentPage >= dlTotalPages);
}
function dlPrev() { if (dlCurrentPage > 1) { dlCurrentPage--; updateDlPagination(); } }
function dlNext() { if (dlCurrentPage < dlTotalPages) { dlCurrentPage++; updateDlPagination(); } }

document.addEventListener('DOMContentLoaded', () => {
  if (typeof Chart === 'undefined') return;
  const tick = { font: { size: 9, family: 'Nunito', weight: '700' }, color: '#64748b' };
  const opts = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { ...tick, precision: 0 } },
      x: { grid: { display: false }, ticks: tick }
    }
  };
  const c1 = document.getElementById('clicksChart');
  if (c1) new Chart(c1, {
    type: 'bar',
    data: { labels: <?= json_encode($chart_labels) ?>, datasets: [{ data: <?= json_encode($chart_data) ?>, backgroundColor: '#fbbf24', borderRadius: 6 }] },
    options: opts
  });
  const c2 = document.getElementById('regsChart');
  if (c2) new Chart(c2, {
    type: 'line',
    data: { labels: <?= json_encode($chart_reg_labels) ?>, datasets: [{ data: <?= json_encode($chart_reg_data) ?>, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.15)', fill: true, tension: 0.35, borderWidth: 2.5, pointRadius: 3.5, pointBackgroundColor: '#10b981' }] },
    options: opts
  });
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
