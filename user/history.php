<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_wd_hold') {
    $wd_id = (int)($_POST['wd_id'] ?? 0);
    $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_wd_hold' AND status='pending' AND payload LIKE ?");
    $stmtPending->execute([$user['id'], '%"withdrawal_id":'.$wd_id.'%']);
    if ($stmtPending->fetchColumn()) {
        $flash = 'Permintaan pengembalian untuk penarikan ini sudah diajukan.';
        $flashType = 'error';
    } else {
        $w = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND user_id=? AND status='hold'");
        $w->execute([$wd_id, $user['id']]);
        $wData = $w->fetch();
        if (!$wData) {
            $flash = 'Withdraw tidak ditemukan atau bukan berstatus Hold.';
            $flashType = 'error';
        } else {
            $payload = json_encode(['withdrawal_id' => $wd_id]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'refund_wd_hold', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "💰 <b>REQUEST PENGEMBALIAN WD HOLD</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💸 Jumlah WD: <b>" . format_rp((float)$wData['amount']) . "</b>\n";
            $msg .= "🏦 Tujuan Awal: {$wData['bank_name']} - {$wData['account_number']}\n\n";
            $msg .= "⚠️ <i>Refund ini akan mengembalikan saldo WD yang ditahan (Hold) ke Saldo Tarik user secara utuh.</i>\n";
            $kb = [
                [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'permintaan');
            
            $flash = 'Permintaan refund telah dikirim ke admin untuk diverifikasi.';
            $flashType = 'success';
        }
    }
    $_GET['tab'] = 'withdraw';
}

$tab = $_GET['tab'] ?? 'all';

// Payment Channels Logos
$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

// ── 1. Withdrawals ──
$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll(PDO::FETCH_ASSOC);

// Pending Refund Requests for Hold WDs
$pending_refunds = $pdo->prepare("SELECT payload FROM admin_requests WHERE user_id=? AND type='refund_wd_hold' AND status='pending'");
$pending_refunds->execute([$user['id']]);
$requested_wds = [];
foreach ($pending_refunds->fetchAll() as $pr) {
    $p = json_decode((string)$pr['payload'], true);
    if (isset($p['withdrawal_id'])) {
        $requested_wds[] = (int)$p['withdrawal_id'];
    }
}

// ── 2. Deposits ──
$deposits = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$deposits->execute([$user['id']]);
$deposits = $deposits->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Watch Video Rewards ──
$rewards = $pdo->prepare("
    SELECT wh.*, v.title as video_title 
    FROM watch_history wh 
    LEFT JOIN videos v ON v.id = wh.video_id 
    WHERE wh.user_id = ? 
    ORDER BY wh.watched_at DESC LIMIT 50
");
$rewards->execute([$user['id']]);
$rewards = $rewards->fetchAll(PDO::FETCH_ASSOC);

// ── 5. User Surveys ──
$surveys = $pdo->prepare("SELECT * FROM user_surveys WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$surveys->execute([$user['id']]);
$surveys = $surveys->fetchAll(PDO::FETCH_ASSOC);

// ── 6. User Missions Claimed ──
$missions = $pdo->prepare("
    SELECT um.*, m.title as mission_title, m.reward_amount, m.icon as mission_icon 
    FROM user_missions um 
    JOIN missions m ON m.slug = um.mission_slug 
    WHERE um.user_id=? AND um.claimed_at IS NOT NULL 
    ORDER BY um.claimed_at DESC LIMIT 50
");
$missions->execute([$user['id']]);
$missions = $missions->fetchAll(PDO::FETCH_ASSOC);

// ── 7. Referral Commissions ──
$commissions = $pdo->prepare("
    SELECT rc.*, u.username as from_username 
    FROM referral_commissions rc 
    LEFT JOIN users u ON u.id = rc.from_user_id 
    WHERE rc.user_id=? 
    ORDER BY rc.created_at DESC LIMIT 50
");
$commissions->execute([$user['id']]);
$commissions = $commissions->fetchAll(PDO::FETCH_ASSOC);

// ── 8. User Redeems ──
$redeems = $pdo->prepare("
    SELECT ur.*, rc.code, rc.reward_wd, rc.reward_dep 
    FROM user_redeems ur 
    JOIN redeem_codes rc ON rc.id = ur.code_id 
    WHERE ur.user_id=? 
    ORDER BY ur.claimed_at DESC LIMIT 20
");
$redeems->execute([$user['id']]);
$redeems = $redeems->fetchAll(PDO::FETCH_ASSOC);

// ── 9. Upgrade Orders ──
$upgrades = $pdo->prepare("
    SELECT uo.*, m.name as membership_name 
    FROM upgrade_orders uo 
    LEFT JOIN memberships m ON m.id = uo.membership_id 
    WHERE uo.user_id=? 
    ORDER BY uo.created_at DESC LIMIT 20
");
$upgrades->execute([$user['id']]);
$upgrades = $upgrades->fetchAll(PDO::FETCH_ASSOC);

// ── Summary Totals ──
$total_earned = (float)($user['total_earned'] ?? 0);
$balance_wd   = (float)($user['balance_wd'] ?? 0);

$stmtDepSum = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE user_id=? AND status='confirmed'");
$stmtDepSum->execute([$user['id']]);
$total_dep = (float)$stmtDepSum->fetchColumn();

$stmtWdSum = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id=? AND status='approved'");
$stmtWdSum->execute([$user['id']]);
$total_wd = (float)$stmtWdSum->fetchColumn();

// ── Generate Unified "Semua" Timeline ──
$unified = [];

foreach ($wds as $w) {
    $unified[] = [
        'type'        => 'withdraw',
        'sub_type'    => 'Penarikan Dana',
        'title'       => 'Tarik ke ' . $w['bank_name'],
        'sub'         => $w['account_number'] . ' · a.n. ' . $w['account_name'],
        'amount'      => (float)$w['amount'],
        'is_income'   => false,
        'status'      => $w['status'],
        'admin_note'  => $w['admin_note'],
        'time'        => $w['created_at'],
        'icon'        => 'ph-arrow-up-right',
        'logo'        => $channel_logos[strtolower((string)$w['bank_name'])] ?? null,
        'raw'         => $w
    ];
}

foreach ($deposits as $d) {
    $unified[] = [
        'type'        => 'deposit',
        'sub_type'    => 'Top Up Saldo',
        'title'       => 'Top Up ' . strtoupper((string)$d['method']),
        'sub'         => 'Metode ' . strtoupper((string)$d['method']),
        'amount'      => (float)$d['amount'],
        'is_income'   => true,
        'status'      => $d['status'],
        'admin_note'  => $d['admin_note'],
        'time'        => $d['created_at'],
        'icon'        => strtolower((string)$d['method']) === 'qris' ? 'ph-qr-code' : 'ph-wallet',
        'logo'        => $channel_logos[strtolower((string)$d['method'])] ?? null,
        'raw'         => $d
    ];
}

foreach ($rewards as $r) {
    $unified[] = [
        'type'        => 'reward',
        'sub_type'    => 'Nonton Video',
        'title'       => $r['video_title'] ?: ('Video #' . $r['video_id']),
        'sub'         => 'Reward Menonton Video',
        'amount'      => (float)$r['reward_given'],
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $r['watched_at'],
        'icon'        => 'ph-play-circle',
        'logo'        => null,
        'raw'         => $r
    ];
}

foreach ($surveys as $sv) {
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Survei Pengguna',
        'title'       => 'Hadiah Survei MancingCuan',
        'sub'         => 'Rating: ' . $sv['satisfaction_rating'] . '/5 · Sumber: ' . $sv['source_info'],
        'amount'      => (float)$sv['reward_amount'],
        'is_income'   => $sv['status'] !== 'revoked',
        'status'      => $sv['status'],
        'admin_note'  => $sv['revoke_reason'],
        'time'        => $sv['created_at'],
        'icon'        => 'ph-clipboard-text',
        'logo'        => null,
        'raw'         => $sv
    ];
}

foreach ($missions as $m) {
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Misi Harian',
        'title'       => $m['mission_title'],
        'sub'         => 'Klaim Hadiah Misi Selesai',
        'amount'      => (float)$m['reward_amount'],
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $m['claimed_at'],
        'icon'        => $m['mission_icon'] ?: 'ph-target',
        'logo'        => null,
        'raw'         => $m
    ];
}

foreach ($commissions as $cm) {
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Komisi Referral',
        'title'       => 'Komisi dari ' . ($cm['from_username'] ?: 'Teman'),
        'sub'         => 'Bonus Afiliasi Akun',
        'amount'      => (float)$cm['amount'],
        'is_income'   => true,
        'status'      => $cm['status'] ?? 'approved',
        'admin_note'  => null,
        'time'        => $cm['claimed_at'] ?: $cm['created_at'],
        'icon'        => 'ph-users-three',
        'logo'        => null,
        'raw'         => $cm
    ];
}

foreach ($redeems as $rd) {
    $rdAmt = (float)$rd['reward_wd'] > 0 ? (float)$rd['reward_wd'] : (float)$rd['reward_dep'];
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Voucher Redeem',
        'title'       => 'Kode Voucher: ' . $rd['code'],
        'sub'         => 'Klaim Kode Promo',
        'amount'      => $rdAmt,
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $rd['claimed_at'],
        'icon'        => 'ph-ticket',
        'logo'        => null,
        'raw'         => $rd
    ];
}

foreach ($upgrades as $upg) {
    $unified[] = [
        'type'        => 'upgrade',
        'sub_type'    => 'Upgrade Membership',
        'title'       => 'Upgrade Level ' . ($upg['membership_name'] ?: 'VIP'),
        'sub'         => 'Pembelian Paket Level Akun',
        'amount'      => (float)$upg['amount'],
        'is_income'   => false,
        'status'      => $upg['status'],
        'admin_note'  => $upg['admin_note'],
        'time'        => $upg['created_at'],
        'icon'        => 'ph-crown',
        'logo'        => null,
        'raw'         => $upg
    ];
}

// Sort unified by time descending
usort($unified, function(array $a, array $b): int {
    return strtotime((string)$b['time']) <=> strtotime((string)$a['time']);
});

// Group counts for tabs
$counts = [
    'all'      => count($unified),
    'withdraw' => count($wds),
    'deposit'  => count($deposits),
    'reward'   => count($rewards),
    'bonus'    => count($surveys) + count($missions) + count($commissions) + count($redeems),
];

$pageTitle  = 'Riwayat Keuangan';
$activePage = 'history';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   HISTORY PAGE — BEE HONEY AMBER THEME (COMPACT)
   ══════════════════════════════════════════════ */
.hist-page-wrap {
  min-height: 100vh;
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 50px;
  background: #fffdf5;
}

/* ── TOP HERO BANNER ── */
.hist-top-banner {
  background: linear-gradient(135deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  padding: 16px 14px 24px;
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 6px 20px rgba(120,53,15,0.25);
  position: relative;
  overflow: hidden;
}
.hist-top-banner::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 20px 20px;
  background-position: 0 0, 10px 10px;
  opacity: 0.18;
  pointer-events: none;
}
.hist-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  position: relative;
  z-index: 2;
}
.hist-back-btn {
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
.hist-back-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.hist-title-pill {
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
.hist-wd-shortcut {
  background: #10b981;
  border: 2.5px solid #064e3b;
  border-radius: 12px;
  padding: 6px 12px;
  color: #ffffff;
  font-size: 11px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 0 #064e3b;
  display: flex;
  align-items: center;
  gap: 4px;
  transition: transform 0.1s;
}
.hist-wd-shortcut:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #064e3b;
}

/* Dialogue Box */
.hist-notice-box {
  display: flex;
  align-items: center;
  gap: 10px;
  background: rgba(255,255,255,0.96);
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 10px 12px;
  box-shadow: 0 3px 0 #78350f;
  position: relative;
  z-index: 2;
}
.hist-notice-avatar {
  width: 42px;
  height: 42px;
  flex-shrink: 0;
}
.hist-notice-avatar img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.hist-notice-text {
  font-size: 11px;
  font-weight: 800;
  color: #78350f;
  line-height: 1.4;
}

/* ── MAIN BODY CONTAINER ── */
.hist-body {
  padding: 16px 14px;
}

/* ── 4 COMPACT STATS GRID ── */
.hist-stats-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 16px;
}
.h-stat-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: 0 3px 0 #78350f;
  display: flex;
  align-items: center;
  gap: 10px;
}
.h-stat-ico {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  flex-shrink: 0;
  border: 1.5px solid;
}
.h-stat-ico.green  { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
.h-stat-ico.amber  { background: #fef3c7; color: #d97706; border-color: #fde68a; }
.h-stat-ico.blue   { background: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
.h-stat-ico.orange { background: #ffedd5; color: #ea580c; border-color: #fed7aa; }

.h-stat-info {
  flex: 1;
  min-width: 0;
}
.h-stat-lbl {
  font-size: 9.5px;
  font-weight: 800;
  color: #92400e;
  text-transform: uppercase;
  margin-bottom: 2px;
}
.h-stat-val {
  font-size: 13.5px;
  font-weight: 900;
  color: #78350f;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── TABS SCROLLBAR ── */
.hist-tabs-wrap {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 4px;
  margin-bottom: 14px;
  -webkit-overflow-scrolling: touch;
}
.hist-tabs-wrap::-webkit-scrollbar {
  display: none;
}
.h-tab-btn {
  white-space: nowrap;
  padding: 8px 12px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 900;
  text-decoration: none;
  border: 2px solid #78350f;
  background: #ffffff;
  color: #78350f;
  box-shadow: 0 2.5px 0 #78350f;
  display: flex;
  align-items: center;
  gap: 5px;
  transition: transform 0.1s;
}
.h-tab-btn:active {
  transform: translateY(1.5px);
  box-shadow: 0 1px 0 #78350f;
}
.h-tab-btn--active {
  background: #78350f;
  color: #ffffff;
  box-shadow: 0 2.5px 0 #451a03;
}
.h-tab-count {
  background: rgba(0,0,0,0.08);
  border-radius: 10px;
  padding: 1px 6px;
  font-size: 9px;
  font-weight: 900;
}
.h-tab-btn--active .h-tab-count {
  background: rgba(255,255,255,0.25);
  color: #ffffff;
}

/* ── COMPACT LIST ITEMS ── */
.hist-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.c-item-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: 0 3px 0 #78350f;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.c-item-top {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
}
.c-item-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  border: 1.5px solid #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  box-shadow: 0 2px 0 #78350f;
}
.c-item-icon.income { background: #dcfce7; color: #15803d; }
.c-item-icon.expense { background: #fee2e2; color: #dc2626; }
.c-item-icon.neutral { background: #fef3c7; color: #d97706; }
.c-item-icon.logo-wrap { background: #ffffff; padding: 4px; }
.c-item-icon.logo-wrap img { width: 100%; height: 100%; object-fit: contain; }

.c-item-body {
  flex: 1;
  min-width: 0;
}
.c-item-title-row {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 2px;
}
.c-item-type-badge {
  font-size: 8.5px;
  font-weight: 900;
  padding: 1.5px 5px;
  border-radius: 6px;
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fde68a;
  text-transform: uppercase;
}
.c-item-title {
  font-size: 12px;
  font-weight: 900;
  color: #78350f;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.c-item-sub {
  font-size: 10px;
  font-weight: 700;
  color: #b45309;
  display: flex;
  align-items: center;
  gap: 4px;
}

.c-item-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: center;
}
.c-item-amt {
  font-size: 13.5px;
  font-weight: 900;
  line-height: 1.2;
}
.c-item-amt.income { color: #059669; }
.c-item-amt.expense { color: #dc2626; }

/* Status Badges */
.c-badge {
  font-size: 8.5px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  text-transform: uppercase;
  border: 1.5px solid;
  margin-top: 2px;
}
.c-badge.succ { background: #dcfce7; color: #14532d; border-color: #16a34a; }
.c-badge.warn { background: #fef3c7; color: #78350f; border-color: #f59e0b; }
.c-badge.err  { background: #fee2e2; color: #7f1d1d; border-color: #dc2626; }
.c-badge.hold { background: #f1f5f9; color: #475569; border-color: #94a3b8; }
.c-badge.info { background: #e0f2fe; color: #0369a1; border-color: #38bdf8; }

/* Alert Notes for Rejections / Info */
.c-item-note {
  background: #fff1f2;
  border: 1.5px dashed #f43f5e;
  border-radius: 8px;
  padding: 6px 8px;
  font-size: 10.5px;
  color: #9f1239;
  font-weight: 700;
  display: flex;
  align-items: flex-start;
  gap: 6px;
  line-height: 1.35;
}
.c-item-note i {
  font-size: 13px;
  color: #e11d48;
  flex-shrink: 0;
  margin-top: 1px;
}
.c-item-note-lbl {
  font-size: 9px;
  font-weight: 900;
  text-transform: uppercase;
  color: #e11d48;
  display: block;
}

/* Action Buttons inside cards */
.c-action-row {
  display: flex;
  justify-content: flex-end;
  gap: 6px;
  margin-top: 2px;
}
.c-btn-action {
  font-size: 9.5px;
  font-weight: 900;
  padding: 3px 8px;
  border-radius: 8px;
  border: 1.5px solid;
  text-decoration: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: transform 0.1s;
}
.c-btn-action:active {
  transform: translateY(1.5px);
}
.c-btn-action.pay {
  background: #fde047;
  color: #854d0e;
  border-color: #ca8a04;
  box-shadow: 0 2px 0 #ca8a04;
}
.c-btn-action.refund {
  background: #e0f2fe;
  color: #0369a1;
  border-color: #0284c7;
  box-shadow: 0 2px 0 #0284c7;
}

/* Empty State */
.h-empty-state {
  text-align: center;
  padding: 32px 16px;
  background: #ffffff;
  border: 2.5px dashed #fde68a;
  border-radius: 18px;
  margin-top: 10px;
}
.h-empty-icon {
  font-size: 38px;
  margin-bottom: 6px;
  color: #d97706;
}
.h-empty-title {
  font-size: 13.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
}
.h-empty-sub {
  font-size: 11px;
  font-weight: 700;
  color: #92400e;
}

/* Flash */
.h-flash-msg {
  background: #ecfdf5;
  border: 2px solid #059669;
  border-radius: 12px;
  padding: 10px 12px;
  color: #064e3b;
  font-weight: 800;
  font-size: 12px;
  margin-bottom: 12px;
  box-shadow: 0 3px 0 #059669;
}
.h-flash-msg.error {
  background: #fef2f2;
  border-color: #dc2626;
  color: #7f1d1d;
  box-shadow: 0 3px 0 #dc2626;
}

/* Modal */
.cg-modal {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0,0,0,0.65);
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(4px);
  padding: 20px;
}
.cg-modal-card {
  background: #ffffff;
  border-radius: 20px;
  border: 3px solid #78350f;
  width: 100%;
  max-width: 320px;
  box-shadow: 0 6px 0 #78350f;
  padding: 20px;
  text-align: center;
  animation: popIn .25s cubic-bezier(.175,.885,.32,1.275);
}
@keyframes popIn {
  0% { transform: scale(0.85); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.cg-mc-hdr {
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.cg-mc-sub {
  font-size: 11.5px;
  font-weight: 700;
  color: #92400e;
  margin-bottom: 14px;
}
.cg-btn-row {
  display: flex;
  gap: 8px;
}
.cg-btn {
  flex: 1;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 900;
  padding: 9px;
  box-shadow: 0 3px 0 #78350f;
  cursor: pointer;
  text-align: center;
  transition: transform 0.1s;
}
.cg-btn:active {
  transform: translateY(2px);
  box-shadow: none;
}
.cg-btn--cancel {
  background: #f1f5f9;
  color: #475569;
  border-color: #94a3b8;
  box-shadow: 0 3px 0 #94a3b8;
}
.cg-btn--submit {
  background: linear-gradient(180deg, #f59e0b, #d97706);
  color: #ffffff;
  border-color: #78350f;
  box-shadow: 0 3px 0 #78350f;
}
</style>

<div class="hist-page-wrap">
  
  <!-- ── TOP HERO BANNER ── -->
  <div class="hist-top-banner">
    <div class="hist-top-nav">
      <a href="/home" class="hist-back-btn" title="Kembali ke Beranda">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="hist-title-pill">
        <i class="ph-fill ph-clock-counter-clockwise" style="color:#d97706;"></i>
        <span>Riwayat Keuangan</span>
      </div>
      <a href="/withdraw" class="hist-wd-shortcut" title="Tarik Saldo">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik
      </a>
    </div>

    <!-- Dialogue Box Mascot -->
    <div class="hist-notice-box">
      <div class="hist-notice-avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;border-radius:12px;color:#fff;font-size:20px;">
        <i class="ph-fill ph-wallet"></i>
      </div>
      <div class="hist-notice-text">
        Halo <strong><?= htmlspecialchars($user['username']) ?></strong>! Catatan cuan nonton video, reward survei, misi tantangan, top up, dan penarikan saldomu tercatat lengkap di sini.
      </div>
    </div>
  </div>

  <div class="hist-body">

    <?php if ($flash): ?>
      <div class="h-flash-msg <?= $flashType==='error'?'error':'' ?>">
        <i class="ph-bold <?= $flashType==='error'?'ph-warning-circle':'ph-check-circle' ?>"></i>
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- ── 4 COMPACT STATS GRID ── -->
    <div class="hist-stats-grid">
      
      <!-- 1. Saldo Tarik -->
      <div class="h-stat-card">
        <div class="h-stat-ico green">
          <i class="ph-fill ph-wallet"></i>
        </div>
        <div class="h-stat-info">
          <div class="h-stat-lbl">Saldo Siap WD</div>
          <div class="h-stat-val" style="color:#059669;">Rp <?= number_format($balance_wd, 0, ',', '.') ?></div>
        </div>
      </div>

      <!-- 2. Total Cuan -->
      <div class="h-stat-card">
        <div class="h-stat-ico amber">
          <i class="ph-fill ph-sparkle"></i>
        </div>
        <div class="h-stat-info">
          <div class="h-stat-lbl">Total Cuan</div>
          <div class="h-stat-val" style="color:#d97706;">Rp <?= number_format($total_earned, 0, ',', '.') ?></div>
        </div>
      </div>

      <!-- 3. Total Top Up -->
      <div class="h-stat-card">
        <div class="h-stat-ico blue">
          <i class="ph-fill ph-arrow-down-left"></i>
        </div>
        <div class="h-stat-info">
          <div class="h-stat-lbl">Total Top Up</div>
          <div class="h-stat-val" style="color:#0284c7;">Rp <?= number_format($total_dep, 0, ',', '.') ?></div>
        </div>
      </div>

      <!-- 4. Total Tarik -->
      <div class="h-stat-card">
        <div class="h-stat-ico orange">
          <i class="ph-fill ph-hand-coins"></i>
        </div>
        <div class="h-stat-info">
          <div class="h-stat-lbl">Total Ditarik</div>
          <div class="h-stat-val" style="color:#ea580c;">Rp <?= number_format($total_wd, 0, ',', '.') ?></div>
        </div>
      </div>

    </div>

    <!-- ── HORIZONTAL SCROLLABLE TABS ── -->
    <div class="hist-tabs-wrap">
      <a href="?tab=all" class="h-tab-btn <?= $tab==='all'?'h-tab-btn--active':'' ?>">
        <i class="ph-bold ph-squares-four"></i>
        <span>Semua</span>
        <span class="h-tab-count"><?= $counts['all'] ?></span>
      </a>

      <a href="?tab=withdraw" class="h-tab-btn <?= $tab==='withdraw'?'h-tab-btn--active':'' ?>">
        <i class="ph-bold ph-paper-plane-tilt"></i>
        <span>Tarik</span>
        <span class="h-tab-count"><?= $counts['withdraw'] ?></span>
      </a>

      <a href="?tab=deposit" class="h-tab-btn <?= $tab==='deposit'?'h-tab-btn--active':'' ?>">
        <i class="ph-bold ph-wallet"></i>
        <span>Top Up</span>
        <span class="h-tab-count"><?= $counts['deposit'] ?></span>
      </a>

      <a href="?tab=reward" class="h-tab-btn <?= $tab==='reward'?'h-tab-btn--active':'' ?>">
        <i class="ph-bold ph-play-circle"></i>
        <span>Nonton</span>
        <span class="h-tab-count"><?= $counts['reward'] ?></span>
      </a>

      <a href="?tab=bonus" class="h-tab-btn <?= $tab==='bonus'?'h-tab-btn--active':'' ?>">
        <i class="ph-bold ph-gift"></i>
        <span>Bonus &amp; Misi</span>
        <span class="h-tab-count"><?= $counts['bonus'] ?></span>
      </a>
    </div>

    <!-- ── LISTS DISPLAY ── -->
    <div class="hist-list">

      <!-- ══════════════════════════════════════
           TAB 1: SEMUA (ALL UNIFIED TIMELINE)
           ══════════════════════════════════════ -->
      <?php if ($tab === 'all'): ?>
        <?php if (empty($unified)): ?>
          <div class="h-empty-state">
            <div class="h-empty-icon"><i class="ph-bold ph-clock-counter-clockwise"></i></div>
            <div class="h-empty-title">Belum Ada Transaksi</div>
            <div class="h-empty-sub">Mulai tonton video atau selesaikan misi untuk mengumpulkan cuan pertamamu!</div>
          </div>
        <?php else: ?>
          <?php foreach ($unified as $item): ?>
            <?php
            $stLower = strtolower((string)$item['status']);
            $bclass = 'succ';
            $stText = 'Sukses';
            if ($stLower === 'pending') { $bclass = 'warn'; $stText = 'Diproses'; }
            elseif ($stLower === 'rejected') { $bclass = 'err'; $stText = 'Ditolak'; }
            elseif ($stLower === 'hold') { $bclass = 'hold'; $stText = 'Ditahan'; }
            elseif ($stLower === 'refunded') { $bclass = 'info'; $stText = 'Dikembalikan'; }
            elseif ($stLower === 'revoked') { $bclass = 'err'; $stText = 'Dibatalkan'; }
            ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <?php if (!empty($item['logo'])): ?>
                  <div class="c-item-icon logo-wrap">
                    <img src="/assets/banks/<?= htmlspecialchars($item['logo']) ?>" alt="<?= htmlspecialchars($item['title']) ?>">
                  </div>
                <?php else: ?>
                  <div class="c-item-icon <?= $item['is_income'] ? 'income' : 'expense' ?>">
                    <i class="ph-bold <?= $item['icon'] ?>"></i>
                  </div>
                <?php endif; ?>

                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge"><?= htmlspecialchars((string)$item['sub_type']) ?></span>
                    <div class="c-item-title"><?= htmlspecialchars((string)$item['title']) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$item['time'])) ?> WIB</span>
                    <?php if (!empty($item['sub'])): ?>
                      <span>&bull;</span>
                      <span class="text-truncate" style="max-width:140px;"><?= htmlspecialchars((string)$item['sub']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="c-item-right">
                  <div class="c-item-amt <?= $item['is_income'] ? 'income' : 'expense' ?>">
                    <?= ($item['is_income'] ? '+' : '-') . format_rp((float)$item['amount']) ?>
                  </div>
                  <span class="c-badge <?= $bclass ?>"><?= $stText ?></span>
                </div>
              </div>

              <?php if (!empty($item['admin_note'])): ?>
                <div class="c-item-note">
                  <i class="ph-bold ph-warning-circle"></i>
                  <div>
                    <span class="c-item-note-lbl">
                      <?= $stLower === 'rejected' ? 'Alasan Penolakan:' : ($stLower === 'revoked' ? 'Alasan Pembatalan:' : 'Catatan Admin:') ?>
                    </span>
                    <?= htmlspecialchars((string)$item['admin_note']) ?>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($item['type'] === 'withdraw' && $stLower === 'hold'): ?>
                <div class="c-action-row">
                  <?php if (in_array((int)$item['raw']['id'], $requested_wds, true)): ?>
                    <span class="badge bg-secondary small">Refund Diajukan</span>
                  <?php else: ?>
                    <button type="button" class="c-btn-action refund" onclick="openRefundModal(<?= (int)$item['raw']['id'] ?>)">
                      <i class="ph-bold ph-arrow-u-up-left"></i> Ajukan Refund Saldo
                    </button>
                  <?php endif; ?>
                </div>
              <?php elseif ($item['type'] === 'deposit' && $stLower === 'pending' && strtolower((string)$item['raw']['method']) === 'qris'): ?>
                <div class="c-action-row">
                  <a href="/pay?id=<?= (int)$item['raw']['id'] ?>" class="c-btn-action pay">
                    <i class="ph-bold ph-qr-code"></i> Bayar Sekarang
                  </a>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      <!-- ══════════════════════════════════════
           TAB 2: TARIK SALDO (WITHDRAWALS)
           ══════════════════════════════════════ -->
      <?php elseif ($tab === 'withdraw'): ?>
        <?php if (empty($wds)): ?>
          <div class="h-empty-state">
            <div class="h-empty-icon"><i class="ph-bold ph-paper-plane-tilt"></i></div>
            <div class="h-empty-title">Belum Ada Penarikan</div>
            <div class="h-empty-sub">Kumpulkan saldo dan lakukan penarikan ke DANA atau rekening bankmu!</div>
          </div>
        <?php else: ?>
          <?php foreach ($wds as $w): ?>
            <?php
            $st = strtolower((string)$w['status']);
            $bclass = 'hold';
            $stLabel = ucfirst($st);
            $iconI = 'ph-arrow-u-up-left';
            $iconStyle = 'background:#f1f5f9;color:#475569;';

            if ($st === 'pending') { 
                $bclass = 'warn'; $stLabel = 'Diproses'; 
                $iconI = 'ph-clock'; $iconStyle = 'background:#fef3c7;color:#d97706;';
            } elseif ($st === 'approved' || $st === 'confirmed') { 
                $bclass = 'succ'; $stLabel = 'Sukses'; 
                $iconI = 'ph-check'; $iconStyle = 'background:#dcfce7;color:#15803d;';
            } elseif ($st === 'rejected') { 
                $bclass = 'err'; $stLabel = 'Ditolak'; 
                $iconI = 'ph-x'; $iconStyle = 'background:#fee2e2;color:#dc2626;';
            } elseif ($st === 'hold') {
                $bclass = 'hold'; $stLabel = 'Ditahan'; 
                $iconI = 'ph-pause'; $iconStyle = 'background:#f1f5f9;color:#64748b;';
            } elseif ($st === 'refunded') {
                $bclass = 'info'; $stLabel = 'Dikembalikan'; 
                $iconI = 'ph-arrow-u-up-left'; $iconStyle = 'background:#e0f2fe;color:#0284c7;';
            }
            $wl = $channel_logos[strtolower((string)$w['bank_name'])] ?? null;
            ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <?php if ($wl): ?>
                  <div class="c-item-icon logo-wrap">
                    <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" alt="<?= htmlspecialchars((string)$w['bank_name']) ?>">
                  </div>
                <?php else: ?>
                  <div class="c-item-icon" style="<?= $iconStyle ?>border-color:#78350f;">
                    <i class="ph-bold <?= $iconI ?>"></i>
                  </div>
                <?php endif; ?>

                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge">Tarik Dana</span>
                    <div class="c-item-title"><?= htmlspecialchars((string)$w['bank_name']) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-calendar"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$w['created_at'])) ?> WIB</span>
                    <span>&bull;</span>
                    <span><?= htmlspecialchars((string)$w['account_number']) ?> (<?= htmlspecialchars((string)$w['account_name']) ?>)</span>
                  </div>
                </div>

                <div class="c-item-right">
                  <div class="c-item-amt expense">-<?= format_rp((float)$w['amount']) ?></div>
                  <span class="c-badge <?= $bclass ?>"><?= $stLabel ?></span>
                </div>
              </div>

              <?php if (!empty($w['admin_note'])): ?>
                <div class="c-item-note">
                  <i class="ph-bold ph-warning-circle"></i>
                  <div>
                    <span class="c-item-note-lbl"><?= $st === 'rejected' ? 'Alasan Penolakan:' : ($st === 'hold' ? 'Catatan Hold:' : 'Catatan Admin:') ?></span>
                    <?= htmlspecialchars((string)$w['admin_note']) ?>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($st === 'hold'): ?>
                <div class="c-action-row">
                  <?php if (in_array((int)$w['id'], $requested_wds, true)): ?>
                    <span class="badge bg-secondary small">Refund Diajukan</span>
                  <?php else: ?>
                    <button type="button" class="c-btn-action refund" onclick="openRefundModal(<?= (int)$w['id'] ?>)">
                      <i class="ph-bold ph-arrow-u-up-left"></i> Ajukan Refund Saldo
                    </button>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      <!-- ══════════════════════════════════════
           TAB 3: TOP UP SALDO (DEPOSITS)
           ══════════════════════════════════════ -->
      <?php elseif ($tab === 'deposit'): ?>
        <?php if (empty($deposits)): ?>
          <div class="h-empty-state">
            <div class="h-empty-icon"><i class="ph-bold ph-wallet"></i></div>
            <div class="h-empty-title">Belum Ada Top Up</div>
            <div class="h-empty-sub">Top up saldo untuk upgrade paket VIP atau investasi cuanmu!</div>
          </div>
        <?php else: ?>
          <?php foreach ($deposits as $d): ?>
            <?php
            $dl = $channel_logos[strtolower((string)$d['method'])] ?? null;
            $dStatus = strtolower((string)$d['status']);
            $bclass = match($dStatus) {
                'confirmed' => 'succ',
                'pending'   => 'warn',
                'rejected'  => 'err',
                default     => 'err'
            };
            $stText = match($dStatus) {
                'confirmed' => 'Sukses',
                'pending'   => 'Menunggu',
                'rejected'  => 'Ditolak',
                default     => ucfirst($dStatus)
            };
            ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <?php if ($dl): ?>
                  <div class="c-item-icon logo-wrap">
                    <img src="/assets/banks/<?= htmlspecialchars($dl) ?>" alt="<?= htmlspecialchars((string)$d['method']) ?>">
                  </div>
                <?php else: ?>
                  <div class="c-item-icon income">
                    <i class="ph-bold <?= strtolower((string)$d['method']) === 'qris' ? 'ph-qr-code' : 'ph-bank' ?>"></i>
                  </div>
                <?php endif; ?>

                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge">Top Up</span>
                    <div class="c-item-title">Top Up <?= strtoupper((string)$d['method']) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$d['created_at'])) ?> WIB</span>
                  </div>
                </div>

                <div class="c-item-right">
                  <div class="c-item-amt income">+<?= format_rp((float)$d['amount']) ?></div>
                  <span class="c-badge <?= $bclass ?>"><?= $stText ?></span>
                </div>
              </div>

              <?php if (!empty($d['admin_note'])): ?>
                <div class="c-item-note">
                  <i class="ph-bold ph-warning-circle"></i>
                  <div>
                    <span class="c-item-note-lbl">Catatan Admin:</span>
                    <?= htmlspecialchars((string)$d['admin_note']) ?>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($dStatus === 'pending' && strtolower((string)$d['method']) === 'qris'): ?>
                <div class="c-action-row">
                  <a href="/pay?id=<?= (int)$d['id'] ?>" class="c-btn-action pay">
                    <i class="ph-bold ph-qr-code"></i> Bayar QRIS Sekarang
                  </a>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      <!-- ══════════════════════════════════════
           TAB 4: REWARD NONTON VIDEO
           ══════════════════════════════════════ -->
      <?php elseif ($tab === 'reward'): ?>
        <?php if (empty($rewards)): ?>
          <div class="h-empty-state">
            <div class="h-empty-icon"><i class="ph-bold ph-play-circle"></i></div>
            <div class="h-empty-title">Belum Ada Riwayat Nonton</div>
            <div class="h-empty-sub">Mulai tonton video di galeri untuk mendapatkan rupiah setiap video!</div>
          </div>
        <?php else: ?>
          <?php foreach ($rewards as $r): ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <div class="c-item-icon income">
                  <i class="ph-fill ph-play-circle"></i>
                </div>
                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge">Video Cuan</span>
                    <div class="c-item-title"><?= htmlspecialchars((string)($r['video_title'] ?: ('Video #' . $r['video_id']))) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$r['watched_at'])) ?> WIB</span>
                  </div>
                </div>
                <div class="c-item-right">
                  <div class="c-item-amt income">+<?= format_rp((float)$r['reward_given']) ?></div>
                  <span class="c-badge succ">Masuk</span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      <!-- ══════════════════════════════════════
           TAB 5: BONUS, SURVEI & MISI
           ══════════════════════════════════════ -->
      <?php elseif ($tab === 'bonus'): ?>
        <?php 
        $bonusItems = array_merge($surveys, $missions, $commissions, $redeems);
        if (empty($bonusItems)): 
        ?>
          <div class="h-empty-state">
            <div class="h-empty-icon"><i class="ph-bold ph-gift"></i></div>
            <div class="h-empty-title">Belum Ada Bonus Masuk</div>
            <div class="h-empty-sub">Selesaikan survei berhadiah Rp 15.000 atau klaim misi harianmu!</div>
          </div>
        <?php else: ?>
          <!-- Sub-section: Survei -->
          <?php foreach ($surveys as $sv): ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <div class="c-item-icon income" style="background:#ecfdf5;color:#059669;">
                  <i class="ph-bold ph-clipboard-text"></i>
                </div>
                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge">Survei</span>
                    <div class="c-item-title">Hadiah Survei Pengguna</div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$sv['created_at'])) ?> WIB</span>
                    <span>&bull;</span>
                    <span>Rating: <?= (int)$sv['satisfaction_rating'] ?>/5</span>
                  </div>
                </div>
                <div class="c-item-right">
                  <div class="c-item-amt <?= $sv['status'] === 'revoked' ? 'expense' : 'income' ?>">
                    <?= $sv['status'] === 'revoked' ? '-' : '+' ?><?= format_rp((float)$sv['reward_amount']) ?>
                  </div>
                  <span class="c-badge <?= $sv['status'] === 'revoked' ? 'err' : 'succ' ?>">
                    <?= $sv['status'] === 'revoked' ? 'Dibatalkan' : 'Klaim Sukses' ?>
                  </span>
                </div>
              </div>
              <?php if (!empty($sv['revoke_reason'])): ?>
                <div class="c-item-note">
                  <i class="ph-bold ph-warning-circle"></i>
                  <div>
                    <span class="c-item-note-lbl">Alasan Takeback:</span>
                    <?= htmlspecialchars((string)$sv['revoke_reason']) ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <!-- Sub-section: Misi Harian -->
          <?php foreach ($missions as $ms): ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <div class="c-item-icon income" style="background:#ede9fe;color:#7c3aed;">
                  <i class="ph-bold <?= $ms['mission_icon'] ?: 'ph-target' ?>"></i>
                </div>
                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge" style="background:#ede9fe;color:#6d28d9;">Misi</span>
                    <div class="c-item-title"><?= htmlspecialchars((string)$ms['mission_title']) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$ms['claimed_at'])) ?> WIB</span>
                  </div>
                </div>
                <div class="c-item-right">
                  <div class="c-item-amt income">+<?= format_rp((float)$ms['reward_amount']) ?></div>
                  <span class="c-badge succ">Klaim</span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <!-- Sub-section: Komisi Referral -->
          <?php foreach ($commissions as $cm): ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <div class="c-item-icon income" style="background:#dcfce7;color:#15803d;">
                  <i class="ph-bold ph-users-three"></i>
                </div>
                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge">Referral</span>
                    <div class="c-item-title">Komisi dari <?= htmlspecialchars((string)($cm['from_username'] ?: 'Teman')) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)($cm['claimed_at'] ?: $cm['created_at']))) ?> WIB</span>
                  </div>
                </div>
                <div class="c-item-right">
                  <div class="c-item-amt income">+<?= format_rp((float)$cm['amount']) ?></div>
                  <span class="c-badge succ">Bonus</span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <!-- Sub-section: Kode Redeem -->
          <?php foreach ($redeems as $rd): ?>
            <?php $rdAmt = (float)$rd['reward_wd'] > 0 ? (float)$rd['reward_wd'] : (float)$rd['reward_dep']; ?>
            <div class="c-item-card">
              <div class="c-item-top">
                <div class="c-item-icon income" style="background:#fef3c7;color:#b45309;">
                  <i class="ph-bold ph-ticket"></i>
                </div>
                <div class="c-item-body">
                  <div class="c-item-title-row">
                    <span class="c-item-type-badge">Voucher</span>
                    <div class="c-item-title">Kode: <?= htmlspecialchars((string)$rd['code']) ?></div>
                  </div>
                  <div class="c-item-sub">
                    <i class="ph-bold ph-clock"></i>
                    <span><?= date('d M Y, H:i', strtotime((string)$rd['claimed_at'])) ?> WIB</span>
                  </div>
                </div>
                <div class="c-item-right">
                  <div class="c-item-amt income">+<?= format_rp($rdAmt) ?></div>
                  <span class="c-badge succ">Redeem</span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

        <?php endif; ?>
      <?php endif; ?>

    </div>

  </div>
</div>

<!-- ── MODAL PENGAJUAN REFUND WD HOLD ── -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-mc-hdr">
      <i class="ph-bold ph-arrow-u-up-left" style="color:#d97706;"></i>
      <span>Ajukan Refund Saldo?</span>
    </div>
    <div class="cg-mc-sub">
      Kamu yakin ingin mengembalikan saldo dari penarikan yang ditahan ini?
    </div>
    <div style="background:#fef3c7;border:2px dashed #f59e0b;border-radius:12px;padding:10px;margin-bottom:14px;font-size:11px;font-weight:700;color:#92400e;line-height:1.4;text-align:left;">
      <i class="ph-bold ph-shield-check"></i> Saldo akan dikembalikan utuh ke <strong>Saldo Tarik (WD)</strong> setelah diverifikasi oleh tim admin.
    </div>
    <form method="POST" id="refund-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_wd_hold">
      <input type="hidden" name="wd_id" id="refund-wd-id" value="">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="closeRefundModal()">Batal</button>
        <button type="submit" class="cg-btn cg-btn--submit" onclick="this.disabled=true;this.textContent='Memproses...';document.getElementById('refund-form').submit();">
          Ajukan Refund
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openRefundModal(wdId) {
  document.getElementById('refund-wd-id').value = wdId;
  document.getElementById('refund-modal').style.display = 'flex';
}
function closeRefundModal() {
  document.getElementById('refund-modal').style.display = 'none';
}
document.getElementById('refund-modal').addEventListener('click', function(e) {
  if (e.target === this) closeRefundModal();
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
