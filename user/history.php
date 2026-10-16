<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_wd_hold' && !csrf_verify()) {
    $flash = 'Sesi keamanan kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    $flashType = 'error';
    $_GET['tab'] = 'withdraw';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_wd_hold') {
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
            tg_store_msg_id($pdo, 'admin_requests', (int)$req_id, send_telegram_notif($pdo, $msg, $kb, 'permintaan'));
            
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

// ── 10. Penjualan Ikan (Pasar Ikan) ──
require_once dirname(__DIR__) . '/lib/fishing.php';
$fish_sales = [];
$fish_sales_total = 0.0;
try {
    $fs = $pdo->prepare("SELECT id, fish_count, total, created_at FROM fishing_sales WHERE user_id = ? ORDER BY id DESC LIMIT 50");
    $fs->execute([$user['id']]);
    $fish_sales = $fs->fetchAll(PDO::FETCH_ASSOC);
    $fst = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM fishing_sales WHERE user_id = ?");
    $fst->execute([$user['id']]);
    $fish_sales_total = (float)$fst->fetchColumn();
    if ($fish_sales) {
        $ids = array_column($fish_sales, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $fi  = $pdo->prepare("SELECT sale_id, rarity FROM fishing_logs WHERE sale_id IN ($in)");
        $fi->execute($ids);
        $bySale = [];
        foreach ($fi as $row) {
            $b = fishing_badge_of((string)$row['rarity']);
            $bySale[(int)$row['sale_id']][$b] = ($bySale[(int)$row['sale_id']][$b] ?? 0) + 1;
        }
        $rank = array_flip(array_reverse(FISHING_BADGES)); // jackpot dulu, lalu super, ...
        foreach ($fish_sales as &$sale) {
            $mix = $bySale[(int)$sale['id']] ?? [];
            uksort($mix, fn($a, $b) => ($rank[$a] ?? 9) <=> ($rank[$b] ?? 9));
            $sale['mix'] = $mix;
        }
        unset($sale);
    }
} catch (\Throwable $e) {
    $fish_sales = [];
}

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
        'admin_note'  => $d['status'] === 'confirmed' ? null : $d['admin_note'], // deposit sukses tanpa keterangan
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

foreach ($fish_sales as $sale) {
    $parts = [];
    foreach ($sale['mix'] as $b => $n) $parts[] = (FISHING_SPECIES[$b] ?? $b) . ' ×' . $n;
    $unified[] = [
        'type'        => 'fish',
        'sub_type'    => 'Jual Ikan',
        'title'       => 'Jual ' . (int)$sale['fish_count'] . ' ikan di Pasar',
        'sub'         => implode(' · ', $parts),
        'amount'      => (float)$sale['total'],
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $sale['created_at'],
        'icon'        => 'ph-fish-simple',
        'logo'        => null,
        'fish'        => array_keys($sale['mix']),
        'raw'         => $sale
    ];
}

// Sort unified by time descending
usort($unified, function(array $a, array $b): int {
    return strtotime((string)$b['time']) <=> strtotime((string)$a['time']);
});

// Group counts for tabs
$counts = [
    'all'      => count($unified),
    'fish'     => count($fish_sales),
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
/* ══════════════════════════════════════════════════════════
   RIWAYAT — "BUKU KAS KAPAL" (Living Coral Reef, padat)
   Token warna & latar laut dari partials/header.php (--reef-*)
   ══════════════════════════════════════════════════════════ */
.hx-wrap { position: relative; z-index: 1; max-width: 480px; margin: 0 auto; padding: 8px 12px 96px; color: var(--reef-ink); }

/* Kartu buku kas */
.hx-ledger { position: relative; overflow: hidden; padding: 11px 12px 10px; border: 2px solid #fff; border-radius: 22px; color: #fff;
  background: linear-gradient(150deg, #0e7490 0%, #0891b2 55%, #22d3ee 100%);
  box-shadow: 0 12px 26px rgba(8, 145, 178, 0.26), inset 3px 3px 6px rgba(255, 255, 255, 0.3), inset -4px -6px 12px rgba(8, 51, 68, 0.3); }
.hx-ledger::after { content: ''; position: absolute; right: -30px; top: -40px; width: 140px; height: 140px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.22), transparent 70%); pointer-events: none; }
.hx-led-top { position: relative; display: flex; align-items: center; gap: 10px; }
.hx-led-top small { display: block; font-size: 9.5px; font-weight: 900; letter-spacing: 0.6px; text-transform: uppercase; opacity: 0.9; }
.hx-led-top b { display: block; font-size: 22px; font-weight: 900; line-height: 1.1; text-shadow: 0 2px 4px rgba(8, 51, 68, 0.35); }
.hx-led-bal { flex: 1; min-width: 0; }
.hx-wd { display: inline-flex; align-items: center; gap: 5px; padding: 8px 13px; border: 2px solid #fff; border-radius: 14px; text-decoration: none; color: #fff; font-size: 12px; font-weight: 900;
  background: linear-gradient(145deg, #6ee7b7, #059669); box-shadow: 0 6px 12px rgba(4, 120, 87, 0.35), inset 2px 2px 3px rgba(255, 255, 255, 0.45); }
.hx-wd:active { transform: scale(0.96); }
.hx-mini { position: relative; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 5px; margin-top: 9px; }
.hx-mini > div { padding: 6px 5px; border-radius: 12px; background: rgba(255, 255, 255, 0.17); border: 1.5px solid rgba(255, 255, 255, 0.35); text-align: center; min-width: 0; }
.hx-mini i { font-size: 13px; color: #fde68a; }
.hx-mini b { display: block; font-size: 10.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-mini small { display: block; font-size: 7.5px; font-weight: 900; letter-spacing: 0.4px; text-transform: uppercase; opacity: 0.85; }

/* Flash */
.hx-flash { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; padding: 8px 11px; border: 2px solid #fff; border-radius: 16px; font-size: 11.5px; font-weight: 800; box-shadow: var(--reef-clay-shadow); }
.hx-flash i { font-size: 17px; flex-shrink: 0; }
.hx-flash.success { background: linear-gradient(145deg, #ecfdf5, #d1fae5); color: #065f46; }
.hx-flash.error { background: linear-gradient(145deg, #fff1f2, #ffe4e6); color: #9f1239; }

/* Tab chip */
.hx-tabs { display: flex; gap: 5px; overflow-x: auto; margin: 10px -12px 8px; padding: 2px 12px 4px; scrollbar-width: none; }
.hx-tabs::-webkit-scrollbar { display: none; }
.hx-tab { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border: 2px solid #fff; border-radius: 999px; text-decoration: none; font-size: 11px; font-weight: 900;
  color: var(--reef-muted); background: rgba(255, 255, 255, 0.75); box-shadow: 0 3px 8px rgba(8, 145, 178, 0.08), inset 1.5px 1.5px 3px #fff; }
.hx-tab i { font-size: 13px; }
.hx-tab em { font-style: normal; min-width: 17px; padding: 0 5px; border-radius: 999px; font-size: 9.5px; text-align: center; background: rgba(8, 145, 178, 0.12); color: #0e7490; }
.hx-tab.on { color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 5px 12px rgba(225, 29, 72, 0.28), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
.hx-tab.on em { background: #fff; color: #e11d48; }
.hx-tab.fish.on { background: linear-gradient(145deg, #67e8f9, #0891b2); box-shadow: 0 5px 12px rgba(8, 145, 178, 0.3), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
.hx-tab.fish.on em { color: #0891b2; }

/* Grup per tanggal */
.hx-day { margin-bottom: 8px; }
.hx-day-lbl { display: flex; align-items: center; gap: 6px; margin: 0 4px 4px; font-size: 10px; font-weight: 900; color: var(--reef-muted); text-transform: uppercase; letter-spacing: 0.5px; }
.hx-day-lbl::after { content: ''; flex: 1; height: 1.5px; border-radius: 2px; background: linear-gradient(90deg, rgba(8, 145, 178, 0.25), transparent); }
.hx-day-lbl b { color: #0e7490; font-weight: 900; text-transform: none; letter-spacing: 0; }
.hx-card { border: 2px solid #fff; border-radius: 18px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }

/* Baris transaksi */
.hx-row { padding: 7px 10px; }
.hx-row + .hx-row { border-top: 1.5px dashed rgba(251, 146, 60, 0.22); }
.hx-main { display: flex; align-items: center; gap: 9px; }
.hx-ico { position: relative; width: 34px; height: 34px; flex-shrink: 0; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #fff;
  box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -2px -2px 4px rgba(0, 0, 0, 0.14); }
.hx-ico.t-withdraw { background: linear-gradient(145deg, #fda4af, #e11d48); }
.hx-ico.t-deposit { background: linear-gradient(145deg, #7dd3fc, #0284c7); }
.hx-ico.t-reward { background: linear-gradient(145deg, #fcd34d, #f59e0b); }
.hx-ico.t-bonus { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.hx-ico.t-upgrade { background: linear-gradient(145deg, #fde68a, #d97706); }
.hx-ico.logo { background: #fff; padding: 4px; box-shadow: inset 0 0 0 1.5px rgba(8, 145, 178, 0.15); }
.hx-ico.logo img { width: 100%; height: 100%; object-fit: contain; }
/* Ikon ikan 3D bertumpuk */
.hx-ico.fish { width: 46px; background: radial-gradient(ellipse at 50% 60%, #cffafe 0%, #e0f2fe 55%, #fff 100%); box-shadow: inset 0 0 0 1.5px rgba(8, 145, 178, 0.18); overflow: visible; }
.hx-ico.fish img { position: absolute; width: 30px; height: 30px; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(8, 51, 68, 0.25)); }
.hx-ico.fish img:nth-child(1) { left: 1px; top: 2px; z-index: 3; }
.hx-ico.fish img:nth-child(2) { left: 10px; top: 6px; z-index: 2; opacity: 0.95; }
.hx-ico.fish img:nth-child(3) { left: 17px; top: 0; z-index: 1; opacity: 0.9; }
.hx-ico.fish.n1 img:nth-child(1) { left: 8px; top: 2px; width: 32px; height: 32px; }
.hx-ico.fish.n2 img:nth-child(1) { left: 2px; } .hx-ico.fish.n2 img:nth-child(2) { left: 14px; top: 4px; }
.hx-body { flex: 1; min-width: 0; }
.hx-title { font-size: 12px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-meta { margin-top: 1px; font-size: 9.5px; font-weight: 700; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-meta b { font-weight: 900; color: #0e7490; }
.hx-right { flex-shrink: 0; text-align: right; }
.hx-amt { font-size: 12.5px; font-weight: 900; line-height: 1.2; }
.hx-amt.in { color: #059669; } .hx-amt.out { color: #e11d48; }
.hx-st { display: inline-flex; align-items: center; gap: 3px; margin-top: 1px; font-size: 8.5px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.3px; }
.hx-st::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.hx-st.succ { color: #059669; } .hx-st.warn { color: #d97706; } .hx-st.err { color: #e11d48; } .hx-st.hold { color: #64748b; } .hx-st.info { color: #0284c7; }
/* Fish mix chips */
.hx-mix { display: flex; flex-wrap: wrap; gap: 3px; margin-top: 3px; }
.hx-mix span { display: inline-flex; align-items: center; gap: 2px; padding: 0 6px 0 2px; border-radius: 999px; font-size: 9px; font-weight: 900; background: rgba(8, 145, 178, 0.09); color: #0e7490; }
.hx-mix img { width: 16px; height: 16px; object-fit: contain; }
/* Catatan & aksi */
.hx-note { display: flex; gap: 5px; margin: 5px 0 0 43px; padding: 5px 8px; border-radius: 10px; background: #fff1f2; font-size: 10px; font-weight: 700; color: #9f1239; line-height: 1.35; }
.hx-note i { font-size: 12px; color: #e11d48; flex-shrink: 0; margin-top: 1px; }
.hx-note b { font-weight: 900; }
.hx-act { display: flex; justify-content: flex-end; gap: 5px; margin-top: 5px; }
.hx-btn { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border: 2px solid #fff; border-radius: 999px; font-size: 10px; font-weight: 900; text-decoration: none; cursor: pointer; font-family: inherit;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.12), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5); }
.hx-btn.pay { background: linear-gradient(145deg, #fde68a, #f59e0b); color: #78350f; }
.hx-btn.refund { background: linear-gradient(145deg, #bae6fd, #0284c7); color: #fff; }
.hx-btn.done { background: #f1f5f9; color: #64748b; cursor: default; }

/* Muat lagi & kosong */
.hx-more { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 4px; padding: 9px; border: 2px solid #fff; border-radius: 16px; cursor: pointer; font-family: inherit;
  background: rgba(255, 255, 255, 0.8); color: #0e7490; font-size: 11.5px; font-weight: 900; box-shadow: var(--reef-clay-shadow); }
.hx-more[hidden], .hx-row[hidden], .hx-day[hidden] { display: none !important; }
.hx-empty { text-align: center; padding: 22px 16px; border-radius: 20px; border: 2px dashed rgba(8, 145, 178, 0.35); background: rgba(255, 255, 255, 0.65); }
.hx-empty .ic { width: 52px; height: 52px; margin: 0 auto 7px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #fff; background: linear-gradient(145deg, #67e8f9, #0891b2); border: 3px solid #fff; box-shadow: 0 6px 14px rgba(8, 145, 178, 0.25); }
.hx-empty .ic img { width: 40px; height: 40px; object-fit: contain; }
.hx-empty b { display: block; font-size: 12.5px; font-weight: 900; }
.hx-empty small { display: block; margin-top: 3px; font-size: 10.5px; font-weight: 700; color: var(--reef-muted); line-height: 1.4; }
.hx-empty a { display: inline-flex; align-items: center; gap: 5px; margin-top: 9px; padding: 7px 14px; border-radius: 999px; border: 2px solid #fff; text-decoration: none; font-size: 11px; font-weight: 900; color: #fff; background: linear-gradient(145deg, #67e8f9, #0891b2); box-shadow: 0 5px 12px rgba(8, 145, 178, 0.28); }

/* Modal refund */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 9999; align-items: center; justify-content: center; padding: 20px; background: rgba(30, 27, 46, 0.55); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
.cg-modal-card { width: 100%; max-width: 330px; padding: 18px 16px 16px; text-align: center; border: 2px solid #fff; border-radius: 24px; background: var(--reef-card); box-shadow: 0 24px 50px rgba(30, 27, 46, 0.3), inset 3px 3px 6px #fff; animation: hxPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes hxPop { from { transform: scale(0.88); opacity: 0; } to { transform: none; opacity: 1; } }
.cg-ic { width: 52px; height: 52px; margin: 0 auto 8px; border-radius: 17px; display: flex; align-items: center; justify-content: center; font-size: 25px; color: #fff; background: linear-gradient(145deg, #7dd3fc, #0284c7); border: 2.5px solid #fff; box-shadow: 0 6px 14px rgba(2, 132, 199, 0.3); }
.cg-mc-hdr { font-size: 15px; font-weight: 900; }
.cg-mc-sub { margin-top: 4px; font-size: 11.5px; font-weight: 700; color: var(--reef-muted); line-height: 1.45; }
.cg-info { display: flex; gap: 6px; margin: 11px 0 13px; padding: 8px 10px; border-radius: 13px; background: #ecfeff; font-size: 10.5px; font-weight: 700; color: #0e7490; text-align: left; line-height: 1.4; }
.cg-info i { font-size: 15px; flex-shrink: 0; }
.cg-btn-row { display: flex; gap: 8px; }
.cg-btn { flex: 1; padding: 10px; border: 2px solid #fff; border-radius: 15px; cursor: pointer; font-family: inherit; font-size: 12px; font-weight: 900; }
.cg-btn--cancel { background: #fff; color: var(--reef-muted); box-shadow: inset 1.5px 1.5px 3px #fff, 0 3px 8px rgba(30, 27, 46, 0.08); }
.cg-btn--submit { color: #fff; background: linear-gradient(145deg, #7dd3fc, #0284c7); box-shadow: 0 6px 14px rgba(2, 132, 199, 0.3), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
</style>

<?php
/* ── Helper tampilan ── */
$hx_status = static function (array $it): array {
    $st = strtolower((string)$it['status']);
    return match (true) {
        $st === 'pending'  => ['warn', $it['type'] === 'deposit' ? 'Menunggu' : 'Diproses'],
        $st === 'rejected' => ['err', 'Ditolak'],
        $st === 'hold'     => ['hold', 'Ditahan'],
        $st === 'refunded' => ['info', 'Dikembalikan'],
        $st === 'revoked'  => ['err', 'Dibatalkan'],
        $st === 'locked'   => ['info', 'Beku'],
        $st === 'claimed'  => ['succ', 'Dicairkan'],
        default            => ['succ', $it['type'] === 'fish' ? 'Terjual' : 'Sukses'],
    };
};
$hx_day = static function (string $time): string {
    $d = date('Y-m-d', strtotime($time));
    if ($d === date('Y-m-d')) return 'Hari ini';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
    return date('d M Y', strtotime($time));
};
$hx_tabs = [
    'all'      => ['Semua', 'ph-squares-four'],
    'fish'     => ['Jual Ikan', 'ph-fish-simple'],
    'withdraw' => ['Tarik', 'ph-paper-plane-tilt'],
    'deposit'  => ['Top Up', 'ph-wallet'],
    'reward'   => ['Nonton', 'ph-play-circle'],
    'bonus'    => ['Bonus & Misi', 'ph-gift'],
];
if (!isset($hx_tabs[$tab])) $tab = 'all';
$hx_items = $tab === 'all' ? $unified : array_values(array_filter($unified, fn($it) => $it['type'] === $tab));
$hx_empty = [
    'all'      => ['ph-clock-counter-clockwise', 'Belum ada transaksi', 'Tonton video, mancing, atau selesaikan misi untuk mengisi buku kasmu!', '/home', 'Mulai Cuan'],
    'fish'     => ['', 'Belum ada ikan terjual', 'Tangkap ikan lalu jual di Pasar Ikan (tombol Ember) untuk mencairkannya jadi Saldo Tarik.', '/minigame', 'Mulai Mancing'],
    'withdraw' => ['ph-paper-plane-tilt', 'Belum ada penarikan', 'Kumpulkan saldo lalu tarik ke e-wallet atau rekening bankmu.', '/withdraw', 'Tarik Saldo'],
    'deposit'  => ['ph-wallet', 'Belum ada top up', 'Top up saldo untuk upgrade paket atau beli umpan mancing.', '/deposit', 'Top Up'],
    'reward'   => ['ph-play-circle', 'Belum ada riwayat nonton', 'Tonton video untuk mendapatkan rupiah di setiap video.', '/home', 'Nonton Sekarang'],
    'bonus'    => ['ph-gift', 'Belum ada bonus masuk', 'Selesaikan survei, misi harian, atau ajak kawan untuk dapat bonus.', '/referral', 'Ajak Kawan'],
][$tab];
$HX_PAGE = 25;
?>
<div class="hx-wrap">

  <?php if ($flash): ?>
    <div class="hx-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-fill <?= $flashType === 'error' ? 'ph-warning-circle' : 'ph-check-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- BUKU KAS -->
  <section class="hx-ledger">
    <div class="hx-led-top">
      <div class="hx-led-bal">
        <small><i class="ph-fill ph-book-open-text"></i> Buku Kas · Saldo Siap WD</small>
        <b><?= format_rp($balance_wd) ?></b>
      </div>
      <a href="/withdraw" class="hx-wd"><i class="ph-bold ph-arrow-up-right"></i> Tarik</a>
    </div>
    <div class="hx-mini">
      <div><i class="ph-fill ph-sparkle"></i><b><?= format_rp($total_earned) ?></b><small>Total Cuan</small></div>
      <div><i class="ph-fill ph-fish-simple"></i><b><?= format_rp($fish_sales_total) ?></b><small>Jual Ikan</small></div>
      <div><i class="ph-fill ph-arrow-down-left"></i><b><?= format_rp($total_dep) ?></b><small>Top Up</small></div>
      <div><i class="ph-fill ph-hand-coins"></i><b><?= format_rp($total_wd) ?></b><small>Ditarik</small></div>
    </div>
  </section>

  <!-- TAB -->
  <nav class="hx-tabs" aria-label="Filter riwayat">
    <?php foreach ($hx_tabs as $key => [$lbl, $ic]): ?>
      <a href="?tab=<?= $key ?>" class="hx-tab <?= $key === 'fish' ? 'fish' : '' ?> <?= $tab === $key ? 'on' : '' ?>"><i class="ph-bold <?= $ic ?>"></i><?= htmlspecialchars($lbl) ?><em><?= (int)($counts[$key] ?? 0) ?></em></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$hx_items): ?>
    <div class="hx-empty">
      <div class="ic"><?php if ($tab === 'fish'): ?><img src="/assets/img/fish/bagus.png" alt=""><?php else: ?><i class="ph-fill <?= $hx_empty[0] ?>"></i><?php endif; ?></div>
      <b><?= $hx_empty[1] ?></b>
      <small><?= $hx_empty[2] ?></small>
      <a href="<?= $hx_empty[3] ?>"><i class="ph-bold ph-arrow-right"></i> <?= $hx_empty[4] ?></a>
    </div>
  <?php else: ?>
    <div id="hx-list">
      <?php
      $curDay = null; $open = false;
      foreach ($hx_items as $i => $item):
        $day = $hx_day((string)$item['time']);
        [$stCls, $stTxt] = $hx_status($item);
        $stLower = strtolower((string)$item['status']);
        if ($day !== $curDay):
          if ($open) echo '</div></div>';
          $curDay = $day; $open = true;
      ?>
        <div class="hx-day" data-day>
          <div class="hx-day-lbl"><b><?= $day ?></b></div>
          <div class="hx-card">
      <?php endif; ?>
            <div class="hx-row" data-i="<?= $i ?>" <?= $i >= $HX_PAGE ? 'hidden' : '' ?>>
              <div class="hx-main">
                <?php if ($item['type'] === 'fish'): $fb = array_slice($item['fish'] ?? [], 0, 3); ?>
                  <div class="hx-ico fish n<?= count($fb) ?>">
                    <?php foreach ($fb as $b): ?><img src="/assets/img/fish/<?= htmlspecialchars($b) ?>.png" alt="<?= htmlspecialchars(FISHING_SPECIES[$b] ?? $b) ?>" loading="lazy"><?php endforeach; ?>
                  </div>
                <?php elseif (!empty($item['logo'])): ?>
                  <div class="hx-ico logo"><img src="/assets/banks/<?= htmlspecialchars($item['logo']) ?>" alt="" loading="lazy"></div>
                <?php else: ?>
                  <div class="hx-ico t-<?= htmlspecialchars($item['type']) ?>"><i class="ph-fill <?= htmlspecialchars((string)$item['icon']) ?>"></i></div>
                <?php endif; ?>
                <div class="hx-body">
                  <div class="hx-title"><?= htmlspecialchars((string)$item['title']) ?></div>
                  <div class="hx-meta"><b><?= htmlspecialchars((string)$item['sub_type']) ?></b> · <?= date('H:i', strtotime((string)$item['time'])) ?><?php if ($item['type'] !== 'fish' && !empty($item['sub'])): ?> · <?= htmlspecialchars((string)$item['sub']) ?><?php endif; ?></div>
                  <?php if ($item['type'] === 'fish' && !empty($item['raw']['mix'])): ?>
                    <div class="hx-mix">
                      <?php foreach ($item['raw']['mix'] as $b => $n): ?><span><img src="/assets/img/fish/<?= htmlspecialchars($b) ?>.png" alt="" loading="lazy"><?= ['biasa' => 'Mujair', 'bagus' => 'Gurame', 'langka' => 'Kakap', 'super' => 'Barramundi', 'jackpot' => 'Peti'][$b] ?? htmlspecialchars($b) ?> ×<?= (int)$n ?></span><?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="hx-right">
                  <div class="hx-amt <?= $item['is_income'] ? 'in' : 'out' ?>"><?= ($item['is_income'] ? '+' : '-') . format_rp((float)$item['amount']) ?></div>
                  <span class="hx-st <?= $stCls ?>"><?= $stTxt ?></span>
                </div>
              </div>

              <?php if (!empty($item['admin_note'])): ?>
                <div class="hx-note"><i class="ph-fill ph-warning-circle"></i><span><b><?= $stLower === 'rejected' ? 'Alasan penolakan:' : ($stLower === 'revoked' ? 'Alasan pembatalan:' : ($stLower === 'hold' ? 'Catatan hold:' : 'Catatan admin:')) ?></b> <?= htmlspecialchars((string)$item['admin_note']) ?></span></div>
              <?php endif; ?>

              <?php if ($item['type'] === 'withdraw' && $stLower === 'hold'): ?>
                <div class="hx-act">
                  <?php if (in_array((int)$item['raw']['id'], $requested_wds, true)): ?>
                    <span class="hx-btn done"><i class="ph-bold ph-hourglass-medium"></i> Refund Diajukan</span>
                  <?php else: ?>
                    <button type="button" class="hx-btn refund" onclick="openRefundModal(<?= (int)$item['raw']['id'] ?>)"><i class="ph-bold ph-arrow-u-up-left"></i> Ajukan Refund Saldo</button>
                  <?php endif; ?>
                </div>
              <?php elseif ($item['type'] === 'deposit' && $stLower === 'pending' && strtolower((string)$item['raw']['method']) === 'qris'): ?>
                <div class="hx-act"><a href="/pay?id=<?= (int)$item['raw']['id'] ?>" class="hx-btn pay"><i class="ph-bold ph-qr-code"></i> Bayar QRIS Sekarang</a></div>
              <?php endif; ?>
            </div>
      <?php endforeach; if ($open) echo '</div></div>'; ?>
    </div>
    <?php if (count($hx_items) > $HX_PAGE): ?>
      <button type="button" class="hx-more" id="hx-more" onclick="hxMore()"><i class="ph-bold ph-caret-down"></i> Muat lagi <span id="hx-more-n"></span></button>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- MODAL PENGAJUAN REFUND WD HOLD -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-ic"><i class="ph-bold ph-arrow-u-up-left"></i></div>
    <div class="cg-mc-hdr">Ajukan Refund Saldo?</div>
    <div class="cg-mc-sub">Kamu yakin ingin mengembalikan saldo dari penarikan yang ditahan ini?</div>
    <div class="cg-info"><i class="ph-fill ph-shield-check"></i><span>Saldo dikembalikan utuh ke <strong>Saldo Tarik (WD)</strong> setelah diverifikasi tim admin.</span></div>
    <form method="POST" id="refund-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_wd_hold">
      <input type="hidden" name="wd_id" id="refund-wd-id" value="">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="closeRefundModal()">Batal</button>
        <button type="submit" class="cg-btn cg-btn--submit" onclick="this.disabled=true;this.textContent='Memproses...';document.getElementById('refund-form').submit();">Ajukan Refund</button>
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
document.getElementById('refund-modal').addEventListener('click', function (e) {
  if (e.target === this) closeRefundModal();
});
/* Muat lagi: tampilkan 25 baris berikutnya, sembunyikan grup tanggal yang kosong */
const HX_PAGE = <?= (int)$HX_PAGE ?>;
let hxShown = HX_PAGE;
function hxSync() {
  const rows = document.querySelectorAll('#hx-list .hx-row');
  document.querySelectorAll('#hx-list [data-day]').forEach((g) => { g.hidden = !g.querySelector('.hx-row:not([hidden])'); });
  const left = rows.length - hxShown, btn = document.getElementById('hx-more');
  if (btn) { btn.hidden = left <= 0; const n = document.getElementById('hx-more-n'); if (n) n.textContent = '(' + Math.max(0, left) + ')'; }
}
function hxMore() {
  hxShown += HX_PAGE;
  document.querySelectorAll('#hx-list .hx-row').forEach((r) => { if (+r.dataset.i < hxShown) r.hidden = false; });
  hxSync();
}
if (document.getElementById('hx-list')) hxSync();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
