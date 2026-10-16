<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');
csrf_enforce();

$flash = $flashType = '';
$tab = $_GET['tab'] ?? 'list';

promotor_targets_ensure_schema($pdo);
foreach ($pdo->query("SELECT id FROM users WHERE is_promotor = 1")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
    sync_promotor_daily_targets($pdo, (int)$pid, date('Y-m-d', strtotime('-1 day')));
    sync_promotor_daily_targets($pdo, (int)$pid, date('Y-m-d'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Save or Edit Promotor
    if ($action === 'save_promotor') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $target_dep = (float)($_POST['target_deposits'] ?? 0.00);
        $target_reg = (int)($_POST['target_regs'] ?? 0);
        $salary_rate = (float)($_POST['salary_rate'] ?? 0.00);
        
        if ($user_id > 0) {
            $pdo->prepare("
                UPDATE users 
                SET is_promotor = 1, 
                    promotor_target_deposits = ?, 
                    promotor_target_regs = ?, 
                    promotor_salary_rate = ? 
                WHERE id = ?
            ")->execute([$target_dep, $target_reg, $salary_rate, $user_id]);
            
            // Sync today's target snapshot immediately
            sync_promotor_daily_targets($pdo, $user_id, date('Y-m-d'));
            
            $flash = "Pengaturan promotor berhasil disimpan!";
        } else {
            $flash = "Pilih user terlebih dahulu."; $flashType = 'error';
        }
    }
    
    // Remove Promotor Role
    if ($action === 'remove_promotor') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $pdo->prepare("UPDATE users SET is_promotor = 0 WHERE id = ?")->execute([$user_id]);
            $flash = "Peran promotor berhasil dicabut.";
        }
    }
    
    // Toggle Referral Status
    if ($action === 'toggle_referral') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_val = (int)($_POST['new_val'] ?? 1);
        if ($user_id > 0) {
            $pdo->prepare("UPDATE users SET is_referral_active = ? WHERE id = ?")->execute([$new_val, $user_id]);
            $flash = $new_val ? "Referral berhasil diaktifkan." : "Referral berhasil dihentikan.";
        }
    }
    
    // Payout Promotor Salary
    if ($action === 'pay_salary') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $log = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE id=? FOR UPDATE");
            $log->execute([$log_id]);
            $log = $log->fetch();
            
            if ($log && !$log['is_paid']) {
                // Gaji = MAX(gaji target, jaminan komisi), dihitung di sync_promotor_daily_targets
                $pay_amount = (float)round((float)$log['earned_amount']);
                
                if ($pay_amount <= 0) {
                    throw new \Exception("Jumlah gaji yang diperoleh Rp 0 (tidak ada capaian maupun komisi).");
                }
                
                // 1. Mark target log as paid and save actual paid amount
                $pdo->prepare("UPDATE promotor_daily_targets SET is_paid = 1, paid_amount = ? WHERE id = ?")->execute([$pay_amount, $log_id]);
                // 2. Credit salary to promotor's balance_wd
                $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$pay_amount, $log['user_id']]);
                
                // Get username for notification logs
                $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $u_stmt->execute([$log['user_id']]);
                $username = $u_stmt->fetchColumn() ?: 'Promotor';
                
                $pdo->commit();
                
                // Send Telegram Notification
                $msg = "<b>💸 GAJI PROMOTOR DICAIRKAN</b>\n"
                     . "👤 Promotor: <b>@{$username}</b>\n"
                     . "📅 Tanggal Target: " . date('d M Y', strtotime($log['date'])) . "\n"
                     . "🎯 Pencapaian: <b>" . number_format((float)$log['percentage'], 1) . "%</b>\n"
                     . "💰 Gaji Diperoleh: <b>" . format_rp($pay_amount) . "</b> (gaji penuh " . format_rp((float)$log['salary_rate']) . ", jaminan " . format_rp((float)$log['guarantee_amount']) . ")\n"
                     . "✅ Status: Berhasil dicairkan langsung ke Saldo Penarikan.";
                send_telegram_notif($pdo, $msg, [], 'log');
                
                $flash = "Gaji promotor @{$username} sebesar " . format_rp($pay_amount) . " (" . number_format((float)$log['percentage'], 1) . "% pencapaian) berhasil dicairkan!";
            } else {
                $pdo->rollBack();
                $flash = "Target log tidak ditemukan atau sudah dibayar."; $flashType = 'error';
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = "Gagal mencairkan gaji: " . $e->getMessage(); $flashType = 'error';
        }
    }
    
    // Payout All Pending Salaries for a Promotor (date < CURDATE() AND is_paid = 0)
    // Hari ini TIDAK dihitung dalam gaji pending
    if ($action === 'pay_pending_salaries') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $admin_note = trim($_POST['admin_note'] ?? '');
        
        if ($user_id <= 0) {
            $flash = "User promotor tidak valid."; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            try {
                // Lock pending rows strictly before today
                $stmt = $pdo->prepare("
                    SELECT * FROM promotor_daily_targets 
                    WHERE user_id = ? AND date < CURDATE() AND is_paid = 0
                    FOR UPDATE
                ");
                $stmt->execute([$user_id]);
                $pending_targets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($pending_targets)) {
                    throw new \Exception("Tidak ada riwayat target gaji pending sebelum hari ini untuk promotor tersebut.");
                }
                
                $total_pay = 0.0;
                $paid_count = 0;
                $paid_dates = [];
                
                $update_stmt = $pdo->prepare("UPDATE promotor_daily_targets SET is_paid = 1, paid_amount = ? WHERE id = ?");
                
                foreach ($pending_targets as $pt) {
                    $earned = (float)round((float)$pt['earned_amount']);
                    if ($earned > 0) {
                        $total_pay += $earned;
                        $paid_count++;
                        $paid_dates[] = date('d/m/Y', strtotime($pt['date']));
                        $update_stmt->execute([$earned, $pt['id']]);
                    } else {
                        // Selesaikan target dengan earned 0 agar tidak tertahan pending terus
                        $update_stmt->execute([0.00, $pt['id']]);
                    }
                }
                
                if ($total_pay <= 0) {
                    throw new \Exception("Total akumulasi gaji pending Rp 0.");
                }
                
                // Credit to promotor's balance_wd
                $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$total_pay, $user_id]);
                
                // Fetch username & account for logging
                $u_stmt = $pdo->prepare("SELECT username, bank_name, account_number, account_name FROM users WHERE id = ?");
                $u_stmt->execute([$user_id]);
                $p_user = $u_stmt->fetch(PDO::FETCH_ASSOC);
                $username = $p_user['username'] ?? 'Promotor';
                
                $pdo->commit();
                
                // Send Telegram Notification
                $dates_str = implode(', ', $paid_dates);
                $msg = "<b>💸 GABUNGAN GAJI PENDING PROMOTOR DICAIRKAN</b>\n"
                     . "👤 Promotor: <b>@{$username}</b>\n"
                     . "📅 Periode ({$paid_count} Hari): {$dates_str}\n"
                     . "💰 Total Gaji Dicairkan: <b>" . format_rp($total_pay) . "</b>\n"
                     . "🏦 Rekening: " . ($p_user['bank_name'] ?? '-') . " (" . ($p_user['account_number'] ?? '-') . " a/n " . ($p_user['account_name'] ?? '-') . ")\n"
                     . ($admin_note !== '' ? "📝 Catatan Admin: {$admin_note}\n" : "")
                     . "✅ Status: Berhasil ditambahkan langsung ke Saldo Penarikan.";
                send_telegram_notif($pdo, $msg, [], 'log');
                
                $flash = "Berhasil mencairkan total gaji pending sebesar " . format_rp($total_pay) . " (" . $paid_count . " hari target) untuk promotor @{$username} ke Saldo Penarikan!";
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $flash = "Gagal mencairkan gaji pending: " . $e->getMessage(); $flashType = 'error';
            }
        }
    }
    
    // Save Global Flat Rates
    if ($action === 'save_global_rates') {
        if (isset($_POST['promotor_per_member_bonus'])) {
            setting_set($pdo, 'promotor_per_member_bonus', clean_input($_POST['promotor_per_member_bonus']));
        }
        if (isset($_POST['promotor_per_deposit_bonus'])) {
            setting_set($pdo, 'promotor_per_deposit_bonus', clean_input($_POST['promotor_per_deposit_bonus']));
        }
        if (isset($_POST['promotor_deposit_scheme'])) {
            setting_set($pdo, 'promotor_deposit_scheme', clean_input($_POST['promotor_deposit_scheme']));
        }
        if (isset($_POST['promotor_deposit_percent'])) {
            setting_set($pdo, 'promotor_deposit_percent', clean_input($_POST['promotor_deposit_percent']));
        }
        $flash = "Pengaturan Skenario Komisi berhasil disimpan!";
    }
}

// Fetch active promotors with banking info
$promotors = $pdo->query("
    SELECT id, username, email, whatsapp, bank_name, account_number, account_name,
           referral_code, balance_wd, balance_dep, total_earned, 
           promotor_target_deposits, promotor_target_regs, promotor_salary_rate, 
           is_referral_active, created_at 
    FROM users 
    WHERE is_promotor=1 
    ORDER BY username ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch pending salary summary per promotor (date < CURDATE() AND is_paid = 0)
// Hari ini TIDAK dihitung dalam gaji pending
$pending_stmt = $pdo->query("
    SELECT 
        user_id,
        COALESCE(SUM(earned_amount), 0) as total_pending,
        COUNT(CASE WHEN earned_amount > 0 THEN 1 END) as pending_days,
        MIN(date) as oldest_pending_date,
        MAX(date) as newest_pending_date
    FROM promotor_daily_targets
    WHERE date < CURDATE() AND is_paid = 0
    GROUP BY user_id
");
$pending_by_user = [];
foreach ($pending_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pending_by_user[(int)$row['user_id']] = [
        'total' => (float)$row['total_pending'],
        'days' => (int)$row['pending_days'],
        'oldest_date' => $row['oldest_pending_date'],
        'newest_date' => $row['newest_pending_date'],
    ];
}

// Fetch detailed list of pending targets for modal display
$pending_logs_stmt = $pdo->query("
    SELECT pt.*, u.username, u.bank_name, u.account_number, u.account_name
    FROM promotor_daily_targets pt
    JOIN users u ON u.id = pt.user_id
    WHERE pt.date < CURDATE() AND pt.is_paid = 0 AND pt.earned_amount > 0
    ORDER BY pt.date DESC
");
$pending_logs_by_user = [];
foreach ($pending_logs_stmt->fetchAll(PDO::FETCH_ASSOC) as $pl) {
    $uid = (int)$pl['user_id'];
    $earned = (float)$pl['earned_amount'];
    $pl['earned'] = $earned;
    $pending_logs_by_user[$uid][] = $pl;
}

// Global metrics for header cards
$global_pending_total = 0.0;
$global_pending_count = 0;
$global_promotor_wd_total = 0.0;
foreach ($promotors as $p) {
    $p_id = (int)$p['id'];
    $global_promotor_wd_total += (float)$p['balance_wd'];
    if (isset($pending_by_user[$p_id]) && $pending_by_user[$p_id]['total'] > 0) {
        $global_pending_total += $pending_by_user[$p_id]['total'];
        $global_pending_count++;
    }
}
$all_time_paid_total = (float)$pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM promotor_daily_targets WHERE is_paid = 1")->fetchColumn();

// Fetch potential promotors (non-promotors) for selection
$eligible_users = $pdo->query("SELECT id, username FROM users WHERE is_promotor=0 ORDER BY username ASC")->fetchAll();

// Fetch daily target performance logs
$logs = $pdo->query("
    SELECT pt.*, u.username 
    FROM promotor_daily_targets pt 
    JOIN users u ON u.id = pt.user_id 
    ORDER BY pt.date DESC, pt.percentage DESC
")->fetchAll();

// Helper: Fast Deep Network & Commission Extraction
function get_promotor_deep_network_fast(PDO $pdo, int $promotor_id): array {
    $stmt = $pdo->prepare("SELECT id, username, email, whatsapp, referral_code, balance_wd, balance_dep, total_earned, created_at, is_promotor, is_referral_active FROM users WHERE id = ?");
    $stmt->execute([$promotor_id]);
    $promotor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$promotor) {
        return ['promotor' => null, 'members' => [], 'tree' => [], 'levels' => [], 'deposits' => [], 'direct_commissions' => [], 'network_commissions' => [], 'stats' => []];
    }

    // 1. Recursive level traversal
    $all_members = []; // keyed by id
    $levels = []; // level => [user_ids]
    $current_level = 1;
    $current_ref_codes = [$promotor['referral_code'] => ['id' => $promotor['id'], 'username' => $promotor['username']]];

    while (!empty($current_ref_codes) && $current_level <= 20) {
        $in_placeholders = implode(',', array_fill(0, count($current_ref_codes), '?'));
        $sql = "SELECT u.id, u.username, u.email, u.whatsapp, u.referral_code, u.referred_by, u.balance_wd, u.balance_dep, u.total_earned, u.created_at, u.is_active,
                       COALESCE(m.name, 'Free') as membership_name
                FROM users u
                LEFT JOIN memberships m ON u.membership_id = m.id
                WHERE u.referred_by IN ($in_placeholders)
                ORDER BY u.created_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_keys($current_ref_codes));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            break;
        }

        $next_ref_codes = [];
        $levels[$current_level] = [];

        foreach ($rows as $row) {
            $upline_info = $current_ref_codes[$row['referred_by']] ?? ['id' => 0, 'username' => 'Unknown'];
            $row['level'] = $current_level;
            $row['is_direct'] = ($current_level === 1);
            $row['upline_id'] = (int)$upline_info['id'];
            $row['upline_username'] = $upline_info['username'];
            $row['children'] = [];
            $row['total_deposit_confirmed'] = 0.0;
            $row['total_deposit_pending'] = 0.0;
            $row['total_deposit_count'] = 0;
            $row['total_wd_approved'] = 0.0;
            $row['is_suspect_branch'] = false;

            $all_members[$row['id']] = $row;
            $levels[$current_level][] = $row['id'];

            if (!empty($row['referral_code'])) {
                $next_ref_codes[$row['referral_code']] = ['id' => $row['id'], 'username' => $row['username']];
            }
        }

        $current_ref_codes = $next_ref_codes;
        $current_level++;
    }

    $member_ids = array_keys($all_members);
    $deposits = [];

    // 2. Batch aggregation of deposits and withdrawals
    if (!empty($member_ids)) {
        $in_ids = implode(',', array_fill(0, count($member_ids), '?'));
        
        // Sum deposits by status
        $dep_sum_stmt = $pdo->prepare("
            SELECT user_id, status, SUM(amount) as total_amount, COUNT(*) as dep_count
            FROM deposits
            WHERE user_id IN ($in_ids)
            GROUP BY user_id, status
        ");
        $dep_sum_stmt->execute($member_ids);
        $dep_sums = $dep_sum_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dep_sums as $ds) {
            $uid = (int)$ds['user_id'];
            if (isset($all_members[$uid])) {
                $all_members[$uid]['total_deposit_count'] += (int)$ds['dep_count'];
                if ($ds['status'] === 'confirmed') {
                    $all_members[$uid]['total_deposit_confirmed'] += (float)$ds['total_amount'];
                } elseif ($ds['status'] === 'pending') {
                    $all_members[$uid]['total_deposit_pending'] += (float)$ds['total_amount'];
                }
            }
        }

        // Sum withdrawals
        $wd_sum_stmt = $pdo->prepare("
            SELECT user_id, SUM(amount) as total_amount
            FROM withdrawals
            WHERE status = 'approved' AND user_id IN ($in_ids)
            GROUP BY user_id
        ");
        $wd_sum_stmt->execute($member_ids);
        $wd_sums = $wd_sum_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($wd_sums as $ws) {
            $uid = (int)$ws['user_id'];
            if (isset($all_members[$uid])) {
                $all_members[$uid]['total_wd_approved'] = (float)$ws['total_amount'];
            }
        }

        // All deposits in network
        $d_stmt = $pdo->prepare("
            SELECT d.*, u.username, u.email, u.whatsapp, u.referral_code, u.referred_by
            FROM deposits d
            JOIN users u ON u.id = d.user_id
            WHERE d.user_id IN ($in_ids)
            ORDER BY d.created_at DESC
        ");
        $d_stmt->execute($member_ids);
        $deposits_raw = $d_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deposits_raw as $dr) {
            $uid = (int)$dr['user_id'];
            $mem = $all_members[$uid] ?? null;
            $dr['level'] = $mem ? $mem['level'] : 1;
            $dr['is_direct'] = $mem ? $mem['is_direct'] : false;
            $dr['upline_username'] = $mem ? $mem['upline_username'] : '-';
            $deposits[] = $dr;
        }
    }

    // 3. Build hierarchical tree & link children
    $tree = [];
    foreach ($all_members as $id => &$member) {
        if ($member['level'] === 1) {
            $tree[$id] = &$member;
        } else {
            $parent_id = $member['upline_id'];
            if (isset($all_members[$parent_id])) {
                $all_members[$parent_id]['children'][$id] = &$member;
            }
        }
    }
    unset($member);

    // 4. Calculate stats & detect self-referral/tuyul pattern
    $total_direct_members = count($levels[1] ?? []);
    $total_indirect_members = count($all_members) - $total_direct_members;
    $total_all_members = count($all_members);

    $direct_deposit_confirmed = 0;
    $indirect_deposit_confirmed = 0;
    $direct_deposit_pending = 0;
    $indirect_deposit_pending = 0;
    $direct_deposit_count = 0;
    $indirect_deposit_count = 0;
    $branched_suspect_count = 0;

    foreach ($all_members as $id => &$m) {
        if ($m['is_direct']) {
            $direct_deposit_confirmed += (float)$m['total_deposit_confirmed'];
            $direct_deposit_pending += (float)$m['total_deposit_pending'];
            $direct_deposit_count += (int)$m['total_deposit_count'];
            
            // Suspect check: L1 has 0 deposit but has children with confirmed deposit
            $has_child_dep = false;
            if (!empty($m['children'])) {
                foreach ($m['children'] as $child) {
                    if ((float)$child['total_deposit_confirmed'] > 0) {
                        $has_child_dep = true;
                        break;
                    }
                }
            }
            if ((float)$m['total_deposit_confirmed'] <= 0 && $has_child_dep) {
                $m['is_suspect_branch'] = true;
                $branched_suspect_count++;
            }
        } else {
            $indirect_deposit_confirmed += (float)$m['total_deposit_confirmed'];
            $indirect_deposit_pending += (float)$m['total_deposit_pending'];
            $indirect_deposit_count += (int)$m['total_deposit_count'];
        }
    }
    unset($m);

    $total_deposit_confirmed = $direct_deposit_confirmed + $indirect_deposit_confirmed;

    // 5. Direct Commissions received by promotor
    $c_stmt = $pdo->prepare("
        SELECT rc.*, u.username as from_username
        FROM referral_commissions rc
        JOIN users u ON u.id = rc.from_user_id
        WHERE rc.user_id = ?
        ORDER BY rc.created_at DESC
    ");
    $c_stmt->execute([$promotor_id]);
    $direct_commissions = $c_stmt->fetchAll(PDO::FETCH_ASSOC);
    $direct_comm_total = array_sum(array_column($direct_commissions, 'amount'));

    // 6. Network Commissions across downlines
    $network_commissions = [];
    $network_comm_total = 0;
    if (!empty($member_ids)) {
        $in_ids = implode(',', array_fill(0, count($member_ids), '?'));
        $nc_stmt = $pdo->prepare("
            SELECT rc.*, u_to.username as to_username, u_from.username as from_username
            FROM referral_commissions rc
            JOIN users u_to ON u_to.id = rc.user_id
            JOIN users u_from ON u_from.id = rc.from_user_id
            WHERE rc.user_id IN ($in_ids) OR rc.from_user_id IN ($in_ids)
            ORDER BY rc.created_at DESC
        ");
        $nc_stmt->execute(array_merge($member_ids, $member_ids));
        $network_commissions = $nc_stmt->fetchAll(PDO::FETCH_ASSOC);
        $network_comm_total = array_sum(array_column($network_commissions, 'amount'));
    }

    return [
        'mode' => 'single',
        'promotor' => $promotor,
        'members' => $all_members,
        'tree' => $tree,
        'levels' => $levels,
        'deposits' => $deposits,
        'direct_commissions' => $direct_commissions,
        'network_commissions' => $network_commissions,
        'stats' => [
            'total_all_members' => $total_all_members,
            'total_root_members' => 0,
            'total_direct_members' => $total_direct_members,
            'total_indirect_members' => $total_indirect_members,
            'total_deposit_confirmed' => $total_deposit_confirmed,
            'root_deposit_confirmed' => 0.0,
            'direct_deposit_confirmed' => $direct_deposit_confirmed,
            'indirect_deposit_confirmed' => $indirect_deposit_confirmed,
            'direct_deposit_pending' => $direct_deposit_pending,
            'indirect_deposit_pending' => $indirect_deposit_pending,
            'direct_deposit_count' => $direct_deposit_count,
            'indirect_deposit_count' => $indirect_deposit_count,
            'direct_comm_total' => $direct_comm_total,
            'network_comm_total' => $network_comm_total,
            'max_depth' => count($levels),
            'branched_suspect_count' => $branched_suspect_count,
        ]
    ];
}

// Helper: Global Network Extraction for Mode ALL (All users + Level 0 roots)
function get_all_users_network(PDO $pdo): array {
    $sql = "SELECT u.id, u.username, u.email, u.whatsapp, u.referral_code, u.referred_by, u.balance_wd, u.balance_dep, u.total_earned, u.created_at, u.is_active, u.is_promotor,
                   COALESCE(m.name, 'Free') as membership_name
            FROM users u
            LEFT JOIN memberships m ON u.membership_id = m.id
            ORDER BY u.created_at ASC";
    $users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $by_id = [];
    $by_code = [];
    foreach ($users as &$u) {
        $u['level'] = 0;
        $u['is_direct'] = false;
        $u['upline_id'] = 0;
        $u['upline_username'] = 'Root / Organik';
        $u['children'] = [];
        $u['total_deposit_confirmed'] = 0.0;
        $u['total_deposit_pending'] = 0.0;
        $u['total_deposit_count'] = 0;
        $u['total_wd_approved'] = 0.0;
        $u['is_suspect_branch'] = false;

        $by_id[$u['id']] = &$u;
        if (!empty($u['referral_code'])) {
            $by_code[$u['referral_code']] = &$u;
        }
    }
    unset($u);

    // Build hierarchy links
    $roots = [];
    foreach ($by_id as $id => &$u) {
        $ref = $u['referred_by'];
        if (!empty($ref) && isset($by_code[$ref]) && (int)$by_code[$ref]['id'] !== (int)$id) {
            $parent = &$by_code[$ref];
            $u['upline_id'] = (int)$parent['id'];
            $u['upline_username'] = $parent['username'];
            $parent['children'][$id] = &$u;
        } else {
            $roots[$id] = &$u;
        }
    }
    unset($u);

    // Assign levels recursively (Level 0 = root/organik, Level 1 = direct sponsor, etc.)
    $assign_levels = function(&$node, $lvl) use (&$assign_levels) {
        $node['level'] = $lvl;
        $node['is_direct'] = ($lvl === 1);
        foreach ($node['children'] as &$child) {
            $assign_levels($child, $lvl + 1);
        }
    };

    foreach ($roots as &$r) {
        $assign_levels($r, 0);
    }
    unset($r);

    // Batch deposits
    $dep_sums = $pdo->query("
        SELECT user_id, status, SUM(amount) as total_amount, COUNT(*) as dep_count
        FROM deposits
        GROUP BY user_id, status
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dep_sums as $ds) {
        $uid = (int)$ds['user_id'];
        if (isset($by_id[$uid])) {
            $by_id[$uid]['total_deposit_count'] += (int)$ds['dep_count'];
            if ($ds['status'] === 'confirmed') {
                $by_id[$uid]['total_deposit_confirmed'] += (float)$ds['total_amount'];
            } elseif ($ds['status'] === 'pending') {
                $by_id[$uid]['total_deposit_pending'] += (float)$ds['total_amount'];
            }
        }
    }

    // Batch withdrawals
    $wd_sums = $pdo->query("
        SELECT user_id, SUM(amount) as total_amount
        FROM withdrawals
        WHERE status = 'approved'
        GROUP BY user_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($wd_sums as $ws) {
        $uid = (int)$ws['user_id'];
        if (isset($by_id[$uid])) {
            $by_id[$uid]['total_wd_approved'] = (float)$ws['total_amount'];
        }
    }

    // Calculate stats & suspect branches
    $total_direct_members = 0;
    $total_indirect_members = 0;
    $total_root_members = count($roots);
    $direct_deposit_confirmed = 0;
    $indirect_deposit_confirmed = 0;
    $root_deposit_confirmed = 0;
    $direct_deposit_pending = 0;
    $indirect_deposit_pending = 0;
    $direct_deposit_count = 0;
    $indirect_deposit_count = 0;
    $branched_suspect_count = 0;
    $max_depth = 0;

    foreach ($by_id as &$m) {
        if ($m['level'] > $max_depth) $max_depth = $m['level'];
        if ($m['level'] === 0) {
            $root_deposit_confirmed += (float)$m['total_deposit_confirmed'];
        } elseif ($m['level'] === 1) {
            $total_direct_members++;
            $direct_deposit_confirmed += (float)$m['total_deposit_confirmed'];
            $direct_deposit_pending += (float)$m['total_deposit_pending'];
            $direct_deposit_count += (int)$m['total_deposit_count'];
        } else {
            $total_indirect_members++;
            $indirect_deposit_confirmed += (float)$m['total_deposit_confirmed'];
            $indirect_deposit_pending += (float)$m['total_deposit_pending'];
            $indirect_deposit_count += (int)$m['total_deposit_count'];
        }

        // Suspect branch check
        if ((float)$m['total_deposit_confirmed'] <= 0 && !empty($m['children'])) {
            $has_dep = false;
            foreach ($m['children'] as $c) {
                if ((float)$c['total_deposit_confirmed'] > 0) {
                    $has_dep = true;
                    break;
                }
            }
            if ($has_dep) {
                $m['is_suspect_branch'] = true;
                $branched_suspect_count++;
            }
        }
    }
    unset($m);

    $total_deposit_confirmed = $root_deposit_confirmed + $direct_deposit_confirmed + $indirect_deposit_confirmed;

    // All deposits
    $deposits = $pdo->query("
        SELECT d.*, u.username, u.email, u.whatsapp, u.referral_code, u.referred_by
        FROM deposits d
        JOIN users u ON u.id = d.user_id
        ORDER BY d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($deposits as &$dr) {
        $uid = (int)$dr['user_id'];
        $mem = $by_id[$uid] ?? null;
        $dr['level'] = $mem ? $mem['level'] : 0;
        $dr['is_direct'] = $mem ? $mem['is_direct'] : false;
        $dr['upline_username'] = $mem ? $mem['upline_username'] : 'Root';
    }
    unset($dr);

    // All referral commissions
    $all_commissions = $pdo->query("
        SELECT rc.*, u_to.username as to_username, u_from.username as from_username
        FROM referral_commissions rc
        JOIN users u_to ON u_to.id = rc.user_id
        JOIN users u_from ON u_from.id = rc.from_user_id
        ORDER BY rc.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $total_comm_amount = array_sum(array_column($all_commissions, 'amount'));

    return [
        'mode' => 'all',
        'promotor' => [
            'id' => 'all',
            'username' => 'Semua Pengguna (Global Network)',
            'email' => 'Seluruh Database',
            'referral_code' => 'GLOBAL-ALL',
            'balance_wd' => array_sum(array_column($by_id, 'balance_wd')),
            'balance_dep' => array_sum(array_column($by_id, 'balance_dep')),
            'total_earned' => array_sum(array_column($by_id, 'total_earned')),
            'is_referral_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ],
        'members' => $by_id,
        'tree' => $roots,
        'deposits' => $deposits,
        'direct_commissions' => [],
        'network_commissions' => $all_commissions,
        'stats' => [
            'total_all_members' => count($by_id),
            'total_root_members' => $total_root_members,
            'total_direct_members' => $total_direct_members,
            'total_indirect_members' => $total_indirect_members,
            'total_deposit_confirmed' => $total_deposit_confirmed,
            'root_deposit_confirmed' => $root_deposit_confirmed,
            'direct_deposit_confirmed' => $direct_deposit_confirmed,
            'indirect_deposit_confirmed' => $indirect_deposit_confirmed,
            'direct_deposit_pending' => $direct_deposit_pending,
            'indirect_deposit_pending' => $indirect_deposit_pending,
            'direct_deposit_count' => $direct_deposit_count,
            'indirect_deposit_count' => $indirect_deposit_count,
            'direct_comm_total' => $total_comm_amount,
            'network_comm_total' => $total_comm_amount,
            'max_depth' => $max_depth,
            'branched_suspect_count' => $branched_suspect_count,
        ]
    ];
}

// Tree view renderer
function render_network_tree_html(array $nodes, int $max_depth = 10, int $current_depth = 1): string {
    if (empty($nodes)) return '';
    $html = '<ul class="tree-root-list list-unstyled mb-0" style="padding-left:' . ($current_depth > 1 ? '24px' : '0') . '">';
    foreach ($nodes as $node) {
        $level = (int)$node['level'];
        $has_children = !empty($node['children']);
        $is_suspect = !empty($node['is_suspect_branch']);
        
        $level_badge_style = 'background:rgba(99,102,241,0.2);color:#818cf8;border:1px solid rgba(99,102,241,0.4);';
        $level_label = 'Level ' . $level;
        if ($level === 0) {
            $level_badge_style = 'background:rgba(148,163,184,0.15);color:#cbd5e1;border:1px solid rgba(148,163,184,0.3);';
            $level_label = 'Level 0 (Root / Organik)';
        } elseif ($level === 1) {
            $level_badge_style = 'background:rgba(59,130,246,0.2);color:#60a5fa;border:1px solid rgba(59,130,246,0.4);';
            $level_label = 'Level 1 (Direct)';
        } elseif ($level === 2) {
            $level_badge_style = 'background:rgba(168,85,247,0.2);color:#c084fc;border:1px solid rgba(168,85,247,0.4);';
        } elseif ($level === 3) {
            $level_badge_style = 'background:rgba(245,158,11,0.2);color:#fbbf24;border:1px solid rgba(245,158,11,0.4);';
        } elseif ($level >= 4) {
            $level_badge_style = 'background:rgba(239,68,68,0.2);color:#f87171;border:1px solid rgba(239,68,68,0.4);';
        }
        
        $dep_amount = (float)$node['total_deposit_confirmed'];
        $dep_color = $dep_amount > 0 ? '#4CAF82' : '#666';
        
        $html .= '<li class="tree-item position-relative mb-2">';
        $html .= '<div class="tree-node-box p-2 px-3 rounded d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#151824;border:1px solid ' . ($is_suspect ? '#d97706' : '#23283c') . ';transition:all .2s;">';
        
        // Left info
        $html .= '<div class="d-flex align-items-center gap-2 flex-wrap">';
        if ($has_children) {
            $html .= '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 tree-toggle-btn" onclick="toggleTreeNode(this)" style="font-size:10px;line-height:1.2;height:20px;border-color:#3a405c;">▼</button>';
        } else {
            $html .= '<span style="display:inline-block;width:16px;text-align:center;color:#444;font-size:10px;">•</span>';
        }
        $html .= '<span class="badge rounded-pill" style="font-size:10.5px;padding:3px 8px;' . $level_badge_style . '">' . $level_label . '</span>';
        $html .= '<a href="users.php?search=' . urlencode($node['username']) . '" class="fw-bold text-white text-decoration-none" target="_blank" style="font-size:13.5px;">@' . htmlspecialchars($node['username']) . '</a>';
        if (!empty($node['is_promotor'])) {
            $html .= '<span class="badge" style="background:#6366f1;color:#fff;font-size:10px;">Promotor 👑</span>';
        }
        if (!empty($node['whatsapp'])) {
            $html .= '<a href="https://wa.me/' . preg_replace('/\D/', '', $node['whatsapp']) . '" target="_blank" class="badge text-decoration-none" style="background:#1e382b;color:#4ade80;font-size:10.5px;border:1px solid #166534">📱 ' . htmlspecialchars($node['whatsapp']) . '</a>';
        }
        $html .= '<span class="badge" style="background:#1f2233;color:#94a3b8;font-size:10px;border:1px solid #2e344d;">' . htmlspecialchars($node['membership_name']) . '</span>';
        
        if ($is_suspect) {
            $html .= '<span class="badge bg-warning text-dark fw-bold" style="font-size:10px;" title="Akun ini 0 depo namun downline-nya melakukan deposit"><i class="ph-bold ph-warning"></i> Indikasi Akun Bercabang / Tuyul</span>';
        }
        $html .= '</div>';
        
        // Right info
        $html .= '<div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:12px;">';
        $html .= '<div><span style="color:#71717a;">Total Depo: </span><strong style="color:' . $dep_color . ';font-size:13px;">' . format_rp($dep_amount) . '</strong></div>';
        if ((float)$node['total_wd_approved'] > 0) {
            $html .= '<div><span style="color:#71717a;">WD: </span><strong style="color:#38bdf8;">' . format_rp((float)$node['total_wd_approved']) . '</strong></div>';
        }
        if ($has_children) {
            $html .= '<span class="badge" style="background:#27273a;color:#cbd5e1;font-size:11px;"><i class="ph-bold ph-users-three"></i> ' . count($node['children']) . ' Downline</span>';
        }
        $html .= '<span style="color:#52525b;font-size:11px;">' . date('d/m/Y H:i', strtotime($node['created_at'])) . '</span>';
        $html .= '</div>';
        
        $html .= '</div>';
        
        // Children branch
        if ($has_children && $current_depth < $max_depth) {
            $html .= '<div class="tree-sub-branch ps-3 border-start mt-2" style="border-color:#2a3048 !important;margin-left:14px;">';
            $html .= render_network_tree_html($node['children'], $max_depth, $current_depth + 1);
            $html .= '</div>';
        }
        
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

// Data fetching for Commission Panel tab
$net_data = null;
$selected_promotor_id_param = '';
$promotor_all_time_stats = null;

if ($tab === 'commission_panel') {
    $selected_promotor_id_param = $_GET['promotor_id'] ?? '';
    
    // Default selection
    if ($selected_promotor_id_param === '') {
        if (!empty($promotors)) {
            $selected_promotor_id_param = (string)$promotors[0]['id'];
        } else {
            $selected_promotor_id_param = 'all';
        }
    }

    if ($selected_promotor_id_param === 'all') {
        $net_data = get_all_users_network($pdo);
    } elseif (is_numeric($selected_promotor_id_param) && (int)$selected_promotor_id_param > 0) {
        $selected_id = (int)$selected_promotor_id_param;
        $net_data = get_promotor_deep_network_fast($pdo, $selected_id);
        
        // Fetch All-Time Commission & Salary Performance for this specific Promotor
        $promotor_all_time_stats = [
            'referral_comm_sum' => 0.0,
            'referral_comm_count' => 0,
            'target_salary_paid' => 0.0,
            'target_salary_paid_count' => 0,
            'target_salary_pending' => 0.0,
            'target_salary_pending_count' => 0,
            'target_100_count' => 0,
        ];

        // 1. Referral commissions received by promotor
        $rc_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total_comm, COUNT(*) as cnt FROM referral_commissions WHERE user_id = ?");
        $rc_stmt->execute([$selected_id]);
        $rc_res = $rc_stmt->fetch(PDO::FETCH_ASSOC);
        if ($rc_res) {
            $promotor_all_time_stats['referral_comm_sum'] = (float)$rc_res['total_comm'];
            $promotor_all_time_stats['referral_comm_count'] = (int)$rc_res['cnt'];
        }

        // 2. Daily target salary logs
        $sal_stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN is_paid = 1 THEN paid_amount ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END), 0) as count_paid,
                COALESCE(SUM(CASE WHEN is_paid = 0 AND date < CURDATE() THEN earned_amount ELSE 0 END), 0) as total_pending,
                COALESCE(SUM(CASE WHEN is_paid = 0 AND date < CURDATE() AND earned_amount > 0 THEN 1 ELSE 0 END), 0) as count_pending,
                COALESCE(SUM(CASE WHEN percentage >= 100 THEN 1 ELSE 0 END), 0) as count_100
            FROM promotor_daily_targets 
            WHERE user_id = ?
        ");
        $sal_stmt->execute([$selected_id]);
        $sal_res = $sal_stmt->fetch(PDO::FETCH_ASSOC);
        if ($sal_res) {
            $promotor_all_time_stats['target_salary_paid'] = (float)$sal_res['total_paid'];
            $promotor_all_time_stats['target_salary_paid_count'] = (int)$sal_res['count_paid'];
            $promotor_all_time_stats['target_salary_pending'] = (float)$sal_res['total_pending'];
            $promotor_all_time_stats['target_salary_pending_count'] = (int)$sal_res['count_pending'];
            $promotor_all_time_stats['target_100_count'] = (int)$sal_res['count_100'];
        }
    }
}

// Fetch referred members if tab is members
$referred_members = [];
if ($tab === 'members') {
    $filter_promotor_id = (int)($_GET['promotor_id'] ?? 0);
    $whereClause = "WHERE p.is_promotor = 1";
    $params = [];
    
    if ($filter_promotor_id > 0) {
        $whereClause .= " AND p.id = ?";
        $params[] = $filter_promotor_id;
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.created_at, p.username as promotor_name,
               COALESCE((SELECT SUM(amount) FROM deposits WHERE user_id = u.id AND status = 'confirmed'), 0) as total_deposit,
               COALESCE(m.name, '" . get_free_tier_name($pdo) . "') as membership_name
        FROM users u 
        JOIN users p ON u.referred_by = p.referral_code 
        LEFT JOIN memberships m ON u.membership_id = m.id
        $whereClause
        ORDER BY u.created_at DESC
    ");
    $stmt->execute($params);
    $referred_members = $stmt->fetchAll();
}

$pageTitle  = 'Kelola Promotor';
$activePage = 'promotors';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">🚀 Manajemen Promotor &amp; Analisis Komisi</h5>
    <div style="font-size:12px;color:#888;margin-top:2px">Kelola target, komisi jaringan mengakar (multi-tier), dan deteksi akun bercabang</div>
  </div>
  <?php if ($tab === 'list'): ?>
  <button class="btn btn-sm btn-primary text-white" onclick="openAddModal()" style="background:var(--brand);border-color:var(--brand)">
    + Tambah Promotor
  </button>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- Tabs navigation -->
<div class="d-flex gap-2 mb-4 flex-wrap">
  <a href="?tab=list" class="btn btn-sm <?= $tab==='list'?'text-white':'btn-secondary' ?>" style="<?= $tab==='list'?'background:var(--brand);border-color:var(--brand)':'' ?>">
    🧑‍💼 Daftar Promotor
  </a>
  <a href="?tab=commission_panel<?= !empty($selected_promotor_id_param) ? '&promotor_id='.$selected_promotor_id_param : '' ?>" class="btn btn-sm <?= $tab==='commission_panel'?'text-white':'btn-secondary' ?>" style="<?= $tab==='commission_panel'?'background:linear-gradient(135deg,#6366f1,#8b5cf6);border-color:#6366f1;box-shadow:0 0 12px rgba(99,102,241,0.4)':'' ?>">
    🌳 Panel Komisi &amp; Jaringan Mengakar
  </a>
  <a href="?tab=scheme" class="btn btn-sm <?= $tab==='scheme'?'text-white':'btn-secondary' ?>" style="<?= $tab==='scheme'?'background:var(--brand);border-color:var(--brand)':'' ?>">
    ⚙️ Skenario Komisi
  </a>
  <a href="?tab=logs" class="btn btn-sm <?= $tab==='logs'?'text-white':'btn-secondary' ?>" style="<?= $tab==='logs'?'background:var(--brand);border-color:var(--brand)':'' ?>">
    📜 Riwayat Target &amp; Payout
  </a>
  <a href="?tab=members" class="btn btn-sm <?= $tab==='members'?'text-white':'btn-secondary' ?>" style="<?= $tab==='members'?'background:var(--brand);border-color:var(--brand)':'' ?>">
    👥 Member Promotor
  </a>
</div>

<?php if ($tab === 'list'): ?>
<!-- LIST TAB -->

<!-- 4 KPI SUMMARY CARDS FOR PROMOTORS -->
<div class="row g-3 mb-4">
  <div class="col-xl-3 col-md-6">
    <div class="c-card p-3 h-100" style="background:#131522;border:1px solid #282c4b;border-radius:12px;">
      <div style="font-size:11px;color:#a5b4fc;font-weight:700;text-transform:uppercase;margin-bottom:4px;">👥 Total Promotor Aktif</div>
      <div style="font-size:22px;font-weight:800;color:#fff;"><?= count($promotors) ?> <span style="font-size:13px;color:#888;">Akun</span></div>
      <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Mitra promotor resmi MancingCuan</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="c-card p-3 h-100" style="background:#131522;border:1.5px solid <?= $global_pending_total > 0 ? '#d97706' : '#282c4b' ?>;border-radius:12px;box-shadow:<?= $global_pending_total > 0 ? '0 0 16px rgba(245,158,11,0.2)' : 'none' ?>;">
      <div style="font-size:11px;color:#fbbf24;font-weight:700;text-transform:uppercase;margin-bottom:4px;">⏳ Total Gaji Pending (Sebelum Hari Ini)</div>
      <div style="font-size:22px;font-weight:800;color:#fbbf24;"><?= format_rp($global_pending_total) ?></div>
      <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
        <?php if ($global_pending_count > 0): ?>
          <span class="badge" style="background:rgba(245,158,11,0.2);color:#fbbf24;border:1px solid rgba(245,158,11,0.4);">Menunggu dicairkan (<?= $global_pending_count ?> promotor)</span>
        <?php else: ?>
          <span class="badge" style="background:rgba(16,185,129,0.2);color:#4ade80;border:1px solid rgba(16,185,129,0.4);">Semua riwayat telah lunas</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="c-card p-3 h-100" style="background:#131522;border:1px solid #282c4b;border-radius:12px;">
      <div style="font-size:11px;color:#6ee7b7;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💸 Total Gaji Telah Dibayar</div>
      <div style="font-size:22px;font-weight:800;color:#4CAF82;"><?= format_rp($all_time_paid_total) ?></div>
      <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Akumulasi pencairan target all-time</div>
    </div>
  </div>
  <div class="col-xl-3 col-md-6">
    <div class="c-card p-3 h-100" style="background:#131522;border:1px solid #282c4b;border-radius:12px;">
      <div style="font-size:11px;color:#93c5fd;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💼 Saldo WD Promotor (Total)</div>
      <div style="font-size:22px;font-weight:800;color:#38bdf8;"><?= format_rp($global_promotor_wd_total) ?></div>
      <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Saldo siap ditarik oleh promotor</div>
    </div>
  </div>
</div>

<div class="c-card">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <span class="c-card-title">Daftar Promotor Aktif &amp; Status Payout Gaji</span>
      <div style="font-size:11.5px;color:#888;margin-top:1px;">Pantau performa target, rekening bank, saldo, serta eksekusi pembayaran gaji pending</div>
    </div>
    <div class="d-flex align-items-center gap-2">
      <span class="badge" style="background:rgba(245,158,11,0.15);color:#fbbf24;border:1px solid rgba(245,158,11,0.3);font-size:11px;padding:5px 9px;">
        💡 Hari ini (<?= date('d M Y') ?>) target masih berjalan &amp; tidak masuk pending
      </span>
    </div>
  </div>
  <div class="c-card-body p-0">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" style="font-size: 13px;">
        <thead>
          <tr>
            <th>Promotor &amp; Rekening</th>
            <th>Referral</th>
            <th>Target Harian</th>
            <th>Rate Harian</th>
            <th>Gaji Pending (Sebelum Hari Ini)</th>
            <th>Saldo WD</th>
            <th class="text-end">Aksi Pembayaran &amp; Kelola</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($promotors)): ?>
            <?php foreach ($promotors as $p): 
              $p_id = (int)$p['id'];
              $p_pending = $pending_by_user[$p_id] ?? ['total' => 0.0, 'days' => 0, 'oldest_date' => null, 'newest_date' => null];
              $p_history = $pending_logs_by_user[$p_id] ?? [];
              $has_pending = ($p_pending['total'] > 0);
              $pending_modal_payload = [
                  'user_id' => $p['id'],
                  'username' => $p['username'],
                  'bank_name' => $p['bank_name'] ?: 'Belum diisi',
                  'account_number' => $p['account_number'] ?: '-',
                  'account_name' => $p['account_name'] ?: '-',
                  'balance_wd' => (float)$p['balance_wd'],
                  'total_pending' => (float)$p_pending['total'],
                  'pending_days' => (int)$p_pending['days'],
                  'history' => $p_history
              ];
            ?>
              <tr style="vertical-align: middle;">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:34px;height:34px;background:linear-gradient(135deg,#f59e0b,#b45309);font-size:13.5px;flex-shrink:0;">
                      <?= strtoupper(substr($p['username'], 0, 1)) ?>
                    </div>
                    <div>
                      <a href="users.php?search=<?= urlencode($p['username']) ?>" target="_blank" class="fw-bold text-white text-decoration-none" style="font-size:13.5px;">
                        @<?= htmlspecialchars($p['username']) ?>
                      </a>
                      <div style="font-size: 11px; color: #888;">ID #<?= $p['id'] ?> &bull; <?= htmlspecialchars($p['email']) ?></div>
                      <?php if (!empty($p['bank_name'])): ?>
                        <div style="font-size: 11px; color: #4ade80; margin-top:2px;">
                          🏦 <?= htmlspecialchars($p['bank_name']) ?>: <strong><?= htmlspecialchars($p['account_number']) ?></strong> (a/n <?= htmlspecialchars($p['account_name']) ?>)
                        </div>
                      <?php else: ?>
                        <div style="font-size: 10.5px; color: #f87171; margin-top:2px;">⚠️ Rekening belum dilengkapi</div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td>
                  <div><code><?= htmlspecialchars($p['referral_code']) ?></code></div>
                  <span class="badge <?= $p['is_referral_active'] ? 'bg-success' : 'bg-danger' ?>" style="font-size:10px;margin-top:3px;">
                    <?= $p['is_referral_active'] ? 'Aktif ✅' : 'Nonaktif 🛑' ?>
                  </span>
                </td>
                <td>
                  <div style="color: #4CAF82; font-weight: 700; font-size:12px;">Depo: <?= format_rp((float)$p['promotor_target_deposits']) ?></div>
                  <div style="color: #aaa; font-size: 11.5px;">Reg: <?= number_format((int)$p['promotor_target_regs']) ?> member</div>
                </td>
                <td style="color: #FF6B35; font-weight: 700;">
                  <?= format_rp((float)$p['promotor_salary_rate']) ?>
                  <div style="font-size:10px;color:#888">per 100% target</div>
                </td>
                <td>
                  <?php if ($has_pending): ?>
                    <div style="color: #fbbf24; font-weight: 800; font-size: 14.5px; letter-spacing: -0.3px;">
                      <?= format_rp($p_pending['total']) ?>
                    </div>
                    <div style="margin-top: 3px;">
                      <span class="badge" style="background:rgba(245,158,11,0.25);color:#fbbf24;border:1px solid rgba(245,158,11,0.45);font-size:10px;padding:3px 7px;">
                        ⏳ <?= $p_pending['days'] ?> Hari Tertunda
                      </span>
                    </div>
                    <div style="font-size: 9.5px; color:#94a3b8; margin-top:2px;">
                      *Sebelum hari ini
                    </div>
                  <?php else: ?>
                    <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.3);font-size:11px;padding:4px 8px;">
                      ✓ Rp 0 (Lunas)
                    </span>
                    <div style="font-size: 9.5px; color:#666; margin-top:2px;">
                      Tidak ada pending
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <strong style="color: #38bdf8; font-size:13px;"><?= format_rp((float)$p['balance_wd']) ?></strong>
                  <div style="font-size:10.5px;color:#888;">Earned: <?= format_rp((float)$p['total_earned']) ?></div>
                </td>
                <td class="text-end">
                  <div class="d-flex flex-column align-items-end gap-1">
                    <?php if ($has_pending): ?>
                      <button class="btn btn-sm text-dark fw-bold w-100" 
                              style="background:linear-gradient(135deg,#f59e0b,#fbbf24);border:none;border-radius:6px;font-size:11.5px;box-shadow:0 0 10px rgba(245,158,11,0.4);padding:5px 9px;"
                              onclick='openPendingModal(<?= json_encode($pending_modal_payload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                        💸 Bayar Gaji Pending (<?= format_rp($p_pending['total']) ?>)
                      </button>
                    <?php endif; ?>
                    <div class="d-flex gap-1 flex-wrap justify-content-end">
                      <?php if ($has_pending): ?>
                        <button class="btn btn-sm btn-outline-warning" style="font-size:10.5px;padding:3px 7px;border-radius:6px;"
                                title="Lihat list rincian riwayat pending"
                                onclick='openPendingModal(<?= json_encode($pending_modal_payload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                          📜 Rincian (<?= $p_pending['days'] ?>)
                        </button>
                      <?php endif; ?>
                      <a href="?tab=commission_panel&promotor_id=<?= $p['id'] ?>" class="btn btn-sm text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;border-radius:6px;font-size:10.5px;padding:3px 7px;">
                        🌳 Jaringan
                      </a>
                      <a href="?tab=members&promotor_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary text-white" style="border:none;border-radius:6px;font-size:10.5px;padding:3px 7px;">
                        👥 Downline
                      </a>
                      <button class="btn btn-sm btn-info text-white" style="border:none;border-radius:6px;font-size:10.5px;padding:3px 7px;"
                              onclick="openEditModal(<?= htmlspecialchars(json_encode($p)) ?>)">
                        ✏️ Edit
                      </button>
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin ' + (<?= $p['is_referral_active'] ? "'menghentikan'" : "'mengaktifkan'" ?>) + ' referral promotor ini?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle_referral">
                        <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="new_val" value="<?= $p['is_referral_active'] ? 0 : 1 ?>">
                        <button type="submit" class="btn btn-sm <?= $p['is_referral_active'] ? 'btn-warning' : 'btn-success' ?> text-white" style="border:none;border-radius:6px;font-size:10.5px;padding:3px 7px;">
                          <?= $p['is_referral_active'] ? '🛑 Stop' : '✅ Aktif' ?>
                        </button>
                      </form>
                      <button class="btn btn-sm btn-danger" style="border:none;border-radius:6px;font-size:10.5px;padding:3px 7px;"
                              onclick="confirmRemove(<?= $p['id'] ?>, '<?= htmlspecialchars($p['username']) ?>')">
                        ❌ Cabut
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center py-4 text-muted">
                Belum ada promotor aktif yang terdaftar.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($tab === 'commission_panel'): ?>
<!-- COMMISSION PANEL & DEEP NETWORK TAB -->
<div class="row g-3 mb-4">
  <!-- Promotor Selector & Profile Card -->
  <div class="col-12">
    <div class="c-card p-3" style="background:linear-gradient(135deg,rgba(26,29,45,0.9),rgba(20,22,34,0.95));border:1px solid #2e3352;border-radius:12px;">
      <div class="row align-items-center g-3">
        <div class="col-lg-5 col-md-6">
          <label class="c-label mb-1" style="font-size:11.5px;color:#a5b4fc;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">
            🔍 Pilih Mode / Promotor yang Ingin Dianalisis:
          </label>
          <select class="form-select form-select-sm" style="background:#0f111a;color:#fff;border-color:#4f46e5;font-weight:600;font-size:13.5px;padding:8px 12px;border-radius:8px;"
                  onchange="location.href='?tab=commission_panel&promotor_id=' + this.value">
            <option value="all" <?= $selected_promotor_id_param === 'all' ? 'selected' : '' ?>>
              🌐 Semua Pengguna (Global All Network - Termasuk Level 0 Organik)
            </option>
            <optgroup label="👤 Promotor Spesifik">
              <?php if (!empty($promotors)): ?>
                <?php foreach ($promotors as $pr): ?>
                  <option value="<?= $pr['id'] ?>" <?= $selected_promotor_id_param === (string)$pr['id'] ? 'selected' : '' ?>>
                    👤 @<?= htmlspecialchars($pr['username']) ?> (Kode: <?= htmlspecialchars($pr['referral_code']) ?>)
                  </option>
                <?php endforeach; ?>
              <?php endif; ?>
            </optgroup>
          </select>
        </div>
        
        <?php if ($net_data && $net_data['promotor']): 
          $p_info = $net_data['promotor'];
          $is_global_all = ($selected_promotor_id_param === 'all');
        ?>
        <div class="col-lg-7 col-md-6">
          <div class="d-flex align-items-center justify-content-lg-end gap-3 flex-wrap" style="font-size:12.5px;">
            <div class="p-2 px-3 rounded" style="background:#131522;border:1px solid #232742;">
              <span class="text-muted d-block" style="font-size:10.5px;"><?= $is_global_all ? 'Mode Jaringan' : 'Kode Referral' ?></span>
              <strong class="text-white" style="font-family:monospace;letter-spacing:1px;"><?= htmlspecialchars($p_info['referral_code']) ?></strong>
            </div>
            <div class="p-2 px-3 rounded" style="background:#131522;border:1px solid #232742;">
              <span class="text-muted d-block" style="font-size:10.5px;"><?= $is_global_all ? 'Total Saldo WD User' : 'Saldo WD Promotor' ?></span>
              <strong style="color:#4CAF82;"><?= format_rp((float)$p_info['balance_wd']) ?></strong>
            </div>
            <div class="p-2 px-3 rounded" style="background:#131522;border:1px solid #232742;">
              <span class="text-muted d-block" style="font-size:10.5px;">Total Earned</span>
              <strong style="color:#FF6B35;"><?= format_rp((float)$p_info['total_earned']) ?></strong>
            </div>
            <?php if (!$is_global_all): ?>
            <div class="p-2 px-3 rounded" style="background:#131522;border:1px solid #232742;">
              <span class="text-muted d-block" style="font-size:10.5px;">Status Referral</span>
              <span class="badge <?= $p_info['is_referral_active'] ? 'bg-success' : 'bg-danger' ?>" style="font-size:10px;">
                <?= $p_info['is_referral_active'] ? 'Aktif ✅' : 'Nonaktif 🛑' ?>
              </span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$net_data || !$net_data['promotor']): ?>
<div class="c-card p-5 text-center text-muted">
  <i class="ph-bold ph-user-circle-gear" style="font-size:48px;color:#555;margin-bottom:12px;display:block;"></i>
  <h6>Belum ada Data Jaringan yang dipilih.</h6>
  <p style="font-size:13px;">Silakan pilih <strong>Semua Pengguna</strong> atau salah satu <strong>Promotor</strong> pada dropdown di atas.</p>
</div>
<?php else: 
  $stats = $net_data['stats'];
  $is_all_mode = ($selected_promotor_id_param === 'all');
?>

<!-- DISPLAY KOMISI PROMOTOR SELAMA INI (Khusus saat Select Promotor Tertentu) -->
<?php if (!$is_all_mode && $promotor_all_time_stats !== null): 
  $p_info = $net_data['promotor'];
?>
<div class="c-card mb-4" style="background:linear-gradient(135deg, #13172e, #1e1b4b);border:1px solid #6366f1;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(99,102,241,0.15);">
  <div class="p-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:rgba(99,102,241,0.15);border-bottom:1px solid rgba(99,102,241,0.25);">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:22px;">💎</span>
      <div>
        <h6 class="mb-0 fw-bold text-white">Display Komisi &amp; Pendapatan Promotor Selama Ini: @<?= htmlspecialchars($p_info['username']) ?></h6>
        <div style="font-size:11.5px;color:#cbd5e1;">Histori akumulasi seluruh pendapatan komisi referral downline, pencairan gaji target harian, dan saldo siap tarik.</div>
      </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <span class="badge" style="background:rgba(34,197,94,0.2);color:#4ade80;border:1px solid rgba(34,197,94,0.4);font-size:11px;padding:5px 9px;">
        ID Promotor: #<?= $p_info['id'] ?>
      </span>
      <span class="badge" style="background:rgba(99,102,241,0.25);color:#a5b4fc;border:1px solid rgba(99,102,241,0.5);font-size:11px;padding:5px 9px;">
        Kode: <?= htmlspecialchars($p_info['referral_code']) ?>
      </span>
    </div>
  </div>
  <div class="p-3 px-4">
    <div class="row g-3">
      <!-- 1. Total Komisi Referral All-Time -->
      <div class="col-xl-3 col-md-6">
        <div class="p-3 rounded h-100" style="background:#0f111a;border:1px solid #282c4b;">
          <div style="font-size:11px;color:#fdba74;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💰 Komisi Referral Diterima</div>
          <div style="font-size:22px;font-weight:800;color:#FF6B35;"><?= format_rp($promotor_all_time_stats['referral_comm_sum']) ?></div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Dari <strong><?= $promotor_all_time_stats['referral_comm_count'] ?>x</strong> transaksi komisi downline</div>
        </div>
      </div>
      <!-- 2. Gaji Target Dicairkan -->
      <div class="col-xl-3 col-md-6">
        <div class="p-3 rounded h-100" style="background:#0f111a;border:1px solid #282c4b;">
          <div style="font-size:11px;color:#6ee7b7;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💸 Gaji Target Dibayar (Paid)</div>
          <div style="font-size:22px;font-weight:800;color:#4CAF82;"><?= format_rp($promotor_all_time_stats['target_salary_paid']) ?></div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Total <strong><?= $promotor_all_time_stats['target_salary_paid_count'] ?> hari</strong> pencairan gaji target</div>
        </div>
      </div>
      <!-- 3. Gaji Target Pending / Ready -->
      <div class="col-xl-3 col-md-6">
        <div class="p-3 rounded h-100" style="background:#0f111a;border:1px solid #282c4b;">
          <div style="font-size:11px;color:#fde047;font-weight:700;text-transform:uppercase;margin-bottom:4px;">⏳ Gaji Target Ready / Pending</div>
          <div style="font-size:22px;font-weight:800;color:#fbbf24;"><?= format_rp($promotor_all_time_stats['target_salary_pending']) ?></div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><strong><?= $promotor_all_time_stats['target_salary_pending_count'] ?> hari</strong> target siap/belum dicairkan</div>
        </div>
      </div>
      <!-- 4. Total Earned & Balance WD -->
      <div class="col-xl-3 col-md-6">
        <div class="p-3 rounded h-100" style="background:#0f111a;border:1px solid #282c4b;">
          <div style="font-size:11px;color:#93c5fd;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💼 Saldo Penarikan Saat Ini</div>
          <div style="font-size:22px;font-weight:800;color:#38bdf8;"><?= format_rp((float)$p_info['balance_wd']) ?></div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">Total Akumulasi Earned: <strong class="text-white"><?= format_rp((float)$p_info['total_earned']) ?></strong></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php elseif ($is_all_mode): ?>
<!-- BANNER INFO GLOBAL ALL NETWORK -->
<div class="c-card mb-4" style="background:linear-gradient(135deg, #13172e, #1a223e);border:1px solid #3b82f6;border-radius:14px;overflow:hidden;box-shadow:0 8px 24px rgba(59,130,246,0.15);">
  <div class="p-3 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:rgba(59,130,246,0.15);border-bottom:1px solid rgba(59,130,246,0.25);">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:22px;">🌐</span>
      <div>
        <h6 class="mb-0 fw-bold text-white">Mode Global All Network: Memetakan Seluruh Pengguna &amp; Level Akar</h6>
        <div style="font-size:11.5px;color:#cbd5e1;">Membaca seluruh <strong><?= number_format($stats['total_all_members']) ?> akun user</strong> dalam database dari <strong>Level 0 (Organik/Root tanpa upline)</strong>, Level 1 (Direct), hingga Level <?= $stats['max_depth'] ?> (Bercabang).</div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <span class="badge" style="background:rgba(59,130,246,0.2);color:#93c5fd;border:1px solid rgba(59,130,246,0.4);font-size:11.5px;padding:6px 10px;">
        🌳 <?= number_format($stats['total_root_members']) ?> Root User (Level 0)
      </span>
      <span class="badge" style="background:rgba(139,92,246,0.2);color:#c084fc;border:1px solid rgba(139,92,246,0.4);font-size:11.5px;padding:6px 10px;">
        🎯 Max Depth: Level <?= $stats['max_depth'] ?>
      </span>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Insight & Tuyul Alert Banner -->
<?php if ($stats['branched_suspect_count'] > 0): ?>
<div class="alert mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:rgba(217,119,6,0.15);border:1px solid rgba(245,158,11,0.5);color:#fef3c7;border-radius:10px;font-size:13px;">
  <div class="d-flex align-items-center gap-2">
    <span style="font-size:20px;">⚠️</span>
    <div>
      <strong>Deteksi Akun Bercabang / Tuyul Ditemukan!</strong> Terdapat <strong><?= $stats['branched_suspect_count'] ?> member</strong> yang memiliki deposit Rp 0 (atau tidak deposit), namun akun turunan di bawahnya aktif melakukan deposit sebesar total <strong><?= format_rp((float)$stats['indirect_deposit_confirmed']) ?></strong>.
    </div>
  </div>
  <a href="#sec-members" class="btn btn-sm btn-warning text-dark fw-bold" onclick="$('#tab-members-btn').tab('show');">
    Lihat Akun Terindikasi
  </a>
</div>
<?php endif; ?>

<!-- 6 KPI Stat Summary Cards -->
<div class="row g-3 mb-4">
  <!-- Total Omset Jaringan -->
  <div class="col-xl-4 col-md-6">
    <div class="c-card p-3 h-100" style="background:linear-gradient(135deg,#171926,#1b1f35);border:1px solid rgba(99,102,241,0.35);border-radius:12px;">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span style="font-size:11.5px;color:#a5b4fc;font-weight:700;text-transform:uppercase;">🌟 Grand Total Omset Jaringan</span>
        <span class="badge" style="background:rgba(99,102,241,0.2);color:#818cf8;border:1px solid rgba(99,102,241,0.4);"><?= $is_all_mode ? 'L0 s/d L'.$stats['max_depth'] : 'L1 s/d L'.$stats['max_depth'] ?></span>
      </div>
      <div class="fw-bold mb-1" style="font-size:24px;color:#fff;">
        <?= format_rp((float)$stats['total_deposit_confirmed']) ?>
      </div>
      <div style="font-size:11.5px;color:#94a3b8;">
        <?php if ($is_all_mode): ?>
          Organik (L0): <strong class="text-white"><?= format_rp((float)$stats['root_deposit_confirmed']) ?></strong> &bull; L1: <strong style="color:#4CAF82;"><?= format_rp((float)$stats['direct_deposit_confirmed']) ?></strong> &bull; L2+: <strong style="color:#c084fc;"><?= format_rp((float)$stats['indirect_deposit_confirmed']) ?></strong>
        <?php else: ?>
          Langsung: <strong style="color:#4CAF82;"><?= format_rp((float)$stats['direct_deposit_confirmed']) ?></strong> &bull; Bercabang: <strong style="color:#c084fc;"><?= format_rp((float)$stats['indirect_deposit_confirmed']) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Deposit Langsung (Tier 1) -->
  <div class="col-xl-4 col-md-6">
    <div class="c-card p-3 h-100" style="background:linear-gradient(135deg,#171926,#162723);border:1px solid rgba(168,185,129,0.3);border-radius:12px;">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span style="font-size:11.5px;color:#6ee7b7;font-weight:700;text-transform:uppercase;">🎯 Deposit Langsung (Tier 1)</span>
        <span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;border:1px solid rgba(16,185,129,0.4);">Level 1</span>
      </div>
      <div class="fw-bold mb-1" style="font-size:24px;color:#4CAF82;">
        <?= format_rp((float)$stats['direct_deposit_confirmed']) ?>
      </div>
      <div style="font-size:11.5px;color:#94a3b8;">
        Total: <strong><?= $stats['direct_deposit_count'] ?> transaksi</strong> confirmed
        <?php if ($stats['direct_deposit_pending'] > 0): ?>
          <span style="color:#fbbf24;">(Pending: <?= format_rp((float)$stats['direct_deposit_pending']) ?>)</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Deposit Bercabang (Tier 2+) -->
  <div class="col-xl-4 col-md-6">
    <div class="c-card p-3 h-100" style="background:linear-gradient(135deg,#171926,#231835);border:1px solid rgba(168,85,247,0.35);border-radius:12px;">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span style="font-size:11.5px;color:#d8b4fe;font-weight:700;text-transform:uppercase;">🌿 Deposit Bercabang (Tier 2+)</span>
        <span class="badge" style="background:rgba(168,85,247,0.2);color:#c084fc;border:1px solid rgba(168,85,247,0.4);">Level 2+</span>
      </div>
      <div class="fw-bold mb-1" style="font-size:24px;color:#c084fc;">
        <?= format_rp((float)$stats['indirect_deposit_confirmed']) ?>
      </div>
      <div style="font-size:11.5px;color:#94a3b8;">
        Total: <strong><?= $stats['indirect_deposit_count'] ?> transaksi</strong> dari akun cabang / sub-downline
      </div>
    </div>
  </div>

  <!-- Total Anggota Jaringan -->
  <div class="col-xl-4 col-md-6">
    <div class="c-card p-3 h-100" style="background:linear-gradient(135deg,#171926,#1b2032);border:1px solid rgba(59,130,246,0.3);border-radius:12px;">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span style="font-size:11.5px;color:#93c5fd;font-weight:700;text-transform:uppercase;">👥 Total Anggota Jaringan</span>
        <span class="badge" style="background:rgba(59,130,246,0.2);color:#60a5fa;border:1px solid rgba(59,130,246,0.4);"><?= $stats['max_depth'] ?> Kedalaman Level</span>
      </div>
      <div class="fw-bold mb-1" style="font-size:24px;color:#60a5fa;">
        <?= number_format($stats['total_all_members']) ?> <span style="font-size:14px;color:#94a3b8;">Member</span>
      </div>
      <div style="font-size:11.5px;color:#94a3b8;">
        <?php if ($is_all_mode): ?>
          Root L0: <strong class="text-white"><?= number_format($stats['total_root_members']) ?></strong> &bull; Direct L1: <strong style="color:#60a5fa;"><?= number_format($stats['total_direct_members']) ?></strong> &bull; Cabang L2+: <strong style="color:#c084fc;"><?= number_format($stats['total_indirect_members']) ?></strong>
        <?php else: ?>
          Direct L1: <strong class="text-white"><?= number_format($stats['total_direct_members']) ?></strong> &bull; Bercabang L2+: <strong style="color:#c084fc;"><?= number_format($stats['total_indirect_members']) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Komisi Referral -->
  <div class="col-xl-4 col-md-6">
    <div class="c-card p-3 h-100" style="background:linear-gradient(135deg,#171926,#291d17);border:1px solid rgba(255,107,53,0.3);border-radius:12px;">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span style="font-size:11.5px;color:#fdba74;font-weight:700;text-transform:uppercase;">💰 Komisi Referral</span>
        <span class="badge" style="background:rgba(255,107,53,0.2);color:#FF6B35;border:1px solid rgba(255,107,53,0.4);">Histori Komisi</span>
      </div>
      <div class="fw-bold mb-1" style="font-size:24px;color:#FF6B35;">
        <?= format_rp((float)$stats['direct_comm_total']) ?>
      </div>
      <div style="font-size:11.5px;color:#94a3b8;">
        <?php if ($is_all_mode): ?>
          Total perputaran komisi referral se-sistem: <strong><?= format_rp((float)$stats['network_comm_total']) ?></strong>
        <?php else: ?>
          Diterima Promotor: <strong><?= format_rp((float)$stats['direct_comm_total']) ?></strong> &bull; Sub-Jaringan: <strong><?= format_rp((float)$stats['network_comm_total']) ?></strong>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Deteksi Akun Tuyul / Sub-Branch -->
  <div class="col-xl-4 col-md-6">
    <div class="c-card p-3 h-100" style="background:linear-gradient(135deg,#171926,<?= $stats['branched_suspect_count'] > 0 ? '#2c1b12' : '#181b28' ?>);border:1px solid <?= $stats['branched_suspect_count'] > 0 ? 'rgba(245,158,11,0.4)' : '#2e3352' ?>;border-radius:12px;">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span style="font-size:11.5px;color:#fcd34d;font-weight:700;text-transform:uppercase;">⚠️ Akun Bercabang / Tuyul</span>
        <span class="badge <?= $stats['branched_suspect_count'] > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?>">
          <?= $stats['branched_suspect_count'] > 0 ? 'Terdeteksi' : 'Aman' ?>
        </span>
      </div>
      <div class="fw-bold mb-1" style="font-size:24px;color:<?= $stats['branched_suspect_count'] > 0 ? '#fbbf24' : '#94a3b8' ?>;">
        <?= $stats['branched_suspect_count'] ?> <span style="font-size:14px;color:#94a3b8;">Akun</span>
      </div>
      <div style="font-size:11.5px;color:#94a3b8;">
        Member deposit 0 tapi downline di bawahnya aktif deposit.
      </div>
    </div>
  </div>
</div>

<!-- Sub Tabs / Section Navigation -->
<ul class="nav nav-pills gap-2 mb-3" id="nav-pills-panel" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active btn-sm" id="tab-deposits-btn" data-bs-toggle="pill" data-bs-target="#sec-deposits" type="button" role="tab" style="font-size:12.5px;border-radius:8px;">
      💳 Seluruh Transaksi Deposit (<?= count($net_data['deposits']) ?>)
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link btn-sm" id="tab-members-btn" data-bs-toggle="pill" data-bs-target="#sec-members" type="button" role="tab" style="font-size:12.5px;border-radius:8px;">
      👥 Daftar Seluruh Anggota Member (<?= count($net_data['members']) ?>)
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link btn-sm" id="tab-tree-btn" data-bs-toggle="pill" data-bs-target="#sec-tree" type="button" role="tab" style="font-size:12.5px;border-radius:8px;">
      🌳 Struktur Pohon Mengakar (Tree View)
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link btn-sm" id="tab-comm-btn" data-bs-toggle="pill" data-bs-target="#sec-comm" type="button" role="tab" style="font-size:12.5px;border-radius:8px;">
      💵 Riwayat Komisi Referral
    </button>
  </li>
</ul>

<div class="tab-content" id="panelTabContent">
  <!-- 1. DEPOSITS TAB -->
  <div class="tab-pane fade show active" id="sec-deposits" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="c-card-title">💳 Seluruh Transaksi Deposit di Jaringan (Total: <?= count($net_data['deposits']) ?> Transaksi)</span>
        <div class="d-flex gap-2 flex-wrap">
          <?php if ($is_all_mode): ?>
            <span class="badge" style="background:rgba(148,163,184,0.2);color:#cbd5e1;border:1px solid rgba(148,163,184,0.4)">Organik: <?= format_rp((float)$stats['root_deposit_confirmed']) ?></span>
          <?php endif; ?>
          <span class="badge" style="background:rgba(59,130,246,0.2);color:#60a5fa;border:1px solid rgba(59,130,246,0.4)">Langsung: <?= format_rp((float)$stats['direct_deposit_confirmed']) ?></span>
          <span class="badge" style="background:rgba(168,85,247,0.2);color:#c084fc;border:1px solid rgba(168,85,247,0.4)">Bercabang: <?= format_rp((float)$stats['indirect_deposit_confirmed']) ?></span>
        </div>
      </div>
      <div class="c-card-body p-3">
        <div class="table-responsive">
          <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "desc"]]' style="font-size: 13px;">
            <thead>
              <tr>
                <th>ID</th>
                <th>Member (User)</th>
                <th>Jalur / Hierarki</th>
                <th>Nominal Deposit</th>
                <th>Metode</th>
                <th>Status</th>
                <th>Waktu Transaksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($net_data['deposits'])): ?>
                <?php foreach ($net_data['deposits'] as $dep): 
                  $is_dir = (bool)$dep['is_direct'];
                  $lvl = (int)$dep['level'];
                ?>
                  <tr style="vertical-align: middle;">
                    <td>#<?= $dep['id'] ?></td>
                    <td>
                      <a href="users.php?search=<?= urlencode($dep['username']) ?>" target="_blank" class="fw-bold text-white text-decoration-none">
                        @<?= htmlspecialchars($dep['username']) ?>
                      </a>
                      <?php if (!empty($dep['whatsapp'])): ?>
                        <div style="font-size:11px;color:#888;">📱 <?= htmlspecialchars($dep['whatsapp']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($lvl === 0): ?>
                        <span class="badge" style="background:rgba(148,163,184,0.2);color:#cbd5e1;border:1px solid rgba(148,163,184,0.4);padding:4px 8px;">
                          🌱 Organik (Level 0)
                        </span>
                      <?php elseif ($is_dir): ?>
                        <span class="badge" style="background:rgba(59,130,246,0.2);color:#60a5fa;border:1px solid rgba(59,130,246,0.4);padding:4px 8px;">
                          🎯 Langsung (Level 1)
                        </span>
                      <?php else: ?>
                        <span class="badge" style="background:rgba(168,85,247,0.2);color:#c084fc;border:1px solid rgba(168,85,247,0.4);padding:4px 8px;">
                          🌿 Bercabang (Level <?= $lvl ?> via @<?= htmlspecialchars($dep['upline_username']) ?>)
                        </span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong style="color: <?= $dep['status'] === 'confirmed' ? '#4CAF82' : ($dep['status'] === 'pending' ? '#FF6B35' : '#888') ?>;font-size:13.5px;">
                        <?= format_rp((float)$dep['amount']) ?>
                      </strong>
                    </td>
                    <td><span class="badge b-neutral" style="font-size:11px;"><?= htmlspecialchars(strtoupper($dep['method'] ?? '-')) ?></span></td>
                    <td>
                      <?php if ($dep['status'] === 'confirmed'): ?>
                        <span class="badge b-success" style="padding:4px 8px;">Confirmed ✅</span>
                      <?php elseif ($dep['status'] === 'pending'): ?>
                        <span class="badge b-warn" style="padding:4px 8px;">Pending ⏳</span>
                      <?php else: ?>
                        <span class="badge b-danger" style="padding:4px 8px;">Rejected ❌</span>
                      <?php endif; ?>
                    </td>
                    <td style="color:#ccc;font-size:12px;"><?= date('d M Y H:i', strtotime($dep['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- 2. MEMBERS DIRECTORY TAB -->
  <div class="tab-pane fade" id="sec-members" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="c-card-title">👥 Direktori Seluruh Anggota Member Mengakar (Total: <?= count($net_data['members']) ?> Member)</span>
      </div>
      <div class="c-card-body p-3">
        <div class="table-responsive">
          <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "asc"]]' style="font-size: 13px;">
            <thead>
              <tr>
                <th>Level</th>
                <th>Member</th>
                <th>Upline Sponsor</th>
                <th>Membership</th>
                <th>Total Depo (Confirmed)</th>
                <th>Saldo WD</th>
                <th>Status / Indikasi</th>
                <th>Tgl Daftar</th>
                <th class="text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($net_data['members'])): ?>
                <?php foreach ($net_data['members'] as $mem): 
                  $lvl = (int)$mem['level'];
                  $is_suspect = !empty($mem['is_suspect_branch']);
                ?>
                  <tr style="vertical-align: middle;">
                    <td>
                      <span class="badge <?= $lvl === 0 ? 'bg-secondary' : ($lvl === 1 ? 'bg-primary' : ($lvl === 2 ? 'bg-info text-dark' : ($lvl === 3 ? 'bg-warning text-dark' : 'bg-danger'))) ?>" style="padding:4px 8px;">
                        <?= $lvl === 0 ? 'L0 (Root)' : 'L'.$lvl ?>
                      </span>
                    </td>
                    <td>
                      <strong style="color:#fff;">@<?= htmlspecialchars($mem['username']) ?></strong>
                      <?php if (!empty($mem['is_promotor'])): ?>
                        <span class="badge" style="background:#6366f1;color:#fff;font-size:9.5px;padding:2px 5px;">Promotor 👑</span>
                      <?php endif; ?>
                      <div style="font-size:11px;color:#888;">
                        <?= htmlspecialchars($mem['email']) ?>
                        <?php if (!empty($mem['whatsapp'])): ?> &bull; 📱 <?= htmlspecialchars($mem['whatsapp']) ?><?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <?php if ($lvl === 0): ?>
                        <span class="text-muted" style="font-size:12px;">Root / Organik</span>
                      <?php else: ?>
                        <strong style="color:var(--brand);">@<?= htmlspecialchars($mem['upline_username']) ?></strong>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge b-neutral" style="font-size:11px;"><?= htmlspecialchars($mem['membership_name']) ?></span>
                    </td>
                    <td>
                      <strong style="color: <?= (float)$mem['total_deposit_confirmed'] > 0 ? '#4CAF82' : '#777' ?>;">
                        <?= format_rp((float)$mem['total_deposit_confirmed']) ?>
                      </strong>
                      <?php if ($mem['total_deposit_count'] > 0): ?>
                        <div style="font-size:10.5px;color:#888;"><?= $mem['total_deposit_count'] ?>x deposit</div>
                      <?php endif; ?>
                    </td>
                    <td style="color:#38bdf8;font-weight:600;"><?= format_rp((float)$mem['balance_wd']) ?></td>
                    <td>
                      <?php if ($is_suspect): ?>
                        <span class="badge bg-warning text-dark fw-bold" style="font-size:10.5px;" title="Akun ini 0 depo namun memiliki downline yang melakukan deposit">
                          ⚠️ Akun Tuyul / Sub-Branch
                        </span>
                      <?php elseif ((float)$mem['total_deposit_confirmed'] > 0): ?>
                        <span class="badge bg-success" style="font-size:10.5px;">Aktif Depo ✅</span>
                      <?php else: ?>
                        <span class="badge bg-secondary" style="font-size:10.5px;">Belum Depo</span>
                      <?php endif; ?>
                    </td>
                    <td style="color:#ccc;font-size:12px;"><?= date('d M Y H:i', strtotime($mem['created_at'])) ?></td>
                    <td class="text-end">
                      <a href="users.php?search=<?= urlencode($mem['username']) ?>" target="_blank" class="btn btn-sm btn-outline-info py-0 px-2" style="font-size:11.5px;border-radius:6px;">
                        Detail ↗
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. TREE VIEW TAB -->
  <div class="tab-pane fade" id="sec-tree" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="c-card-title">🌳 Visualisasi Struktur Pohon Mengakar: <?= htmlspecialchars($net_data['promotor']['username']) ?></span>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-outline-light" onclick="expandAllTree(true)" style="font-size:11px;border-radius:6px;">Buka Semua Cabang</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="expandAllTree(false)" style="font-size:11px;border-radius:6px;">Tutup Cabang</button>
        </div>
      </div>
      <div class="c-card-body p-3">
        <!-- Root node representation -->
        <div class="p-3 mb-3 rounded" style="background:linear-gradient(135deg,#1e1b4b,#312e81);border:1px solid #4f46e5;">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-primary fs-6 px-3 py-1"><?= $is_all_mode ? '🌐 GLOBAL ROOT' : '👑 ROOT PROMOTOR' ?></span>
              <strong class="text-white fs-5"><?= $is_all_mode ? 'Seluruh Jaringan Sistem' : '@'.htmlspecialchars($net_data['promotor']['username']) ?></strong>
              <code><?= htmlspecialchars($net_data['promotor']['referral_code']) ?></code>
            </div>
            <div class="d-flex align-items-center gap-3" style="font-size:13px;">
              <div><span class="text-muted">Total Omset: </span><strong style="color:#4CAF82;"><?= format_rp((float)$stats['total_deposit_confirmed']) ?></strong></div>
              <div><span class="text-muted">Total Anggota: </span><strong class="text-white"><?= number_format($stats['total_all_members']) ?> Member</strong></div>
            </div>
          </div>
        </div>

        <?php if (empty($net_data['tree'])): ?>
          <div class="text-center py-4 text-muted">Belum ada akun atau downline yang terhubung.</div>
        <?php else: ?>
          <div class="tree-container p-2 rounded" style="background:#0f111a;border:1px solid #1e2235;max-height:750px;overflow-y:auto;">
            <?= render_network_tree_html($net_data['tree']) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 4. COMMISSIONS TAB -->
  <div class="tab-pane fade" id="sec-comm" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="c-card-title">💵 Riwayat Komisi Referral di Jaringan</span>
        <span class="badge bg-success">Total: <?= format_rp((float)$stats['direct_comm_total'] + (float)$stats['network_comm_total']) ?></span>
      </div>
      <div class="c-card-body p-3">
        <div class="table-responsive">
          <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "desc"]]' style="font-size: 13px;">
            <thead>
              <tr>
                <th>ID</th>
                <th>Penerima Komisi</th>
                <th>Dari Member (Sumber)</th>
                <th>Nominal Komisi</th>
                <th>Kategori Komisi</th>
                <th>Waktu</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $all_comms = [];
              foreach ($net_data['direct_commissions'] as $dc) {
                  $dc['type'] = 'direct';
                  $dc['to_username'] = $net_data['promotor']['username'];
                  $all_comms[] = $dc;
              }
              foreach ($net_data['network_commissions'] as $nc) {
                  $nc['type'] = 'network';
                  $all_comms[] = $nc;
              }
              ?>
              <?php if (!empty($all_comms)): ?>
                <?php foreach ($all_comms as $c): ?>
                  <tr style="vertical-align: middle;">
                    <td>#<?= $c['id'] ?></td>
                    <td>
                      <strong style="color:var(--brand);">@<?= htmlspecialchars($c['to_username']) ?></strong>
                    </td>
                    <td>
                      <strong style="color:#fff;">@<?= htmlspecialchars($c['from_username']) ?></strong>
                    </td>
                    <td>
                      <strong style="color:#4CAF82;font-size:13.5px;"><?= format_rp((float)$c['amount']) ?></strong>
                    </td>
                    <td>
                      <?php if ($is_all_mode): ?>
                        <span class="badge bg-info text-dark" style="padding:4px 8px;">Komisi Referral 🌿</span>
                      <?php elseif ($c['type'] === 'direct'): ?>
                        <span class="badge bg-success" style="padding:4px 8px;">Direct Promotor ✅</span>
                      <?php else: ?>
                        <span class="badge bg-info text-dark" style="padding:4px 8px;">Jaringan Turunan 🌿</span>
                      <?php endif; ?>
                    </td>
                    <td style="color:#ccc;font-size:12px;"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function toggleTreeNode(btn) {
  const item = btn.closest('.tree-item');
  const subBranch = item.querySelector('.tree-sub-branch');
  if (subBranch) {
    if (subBranch.style.display === 'none') {
      subBranch.style.display = 'block';
      btn.textContent = '▼';
    } else {
      subBranch.style.display = 'none';
      btn.textContent = '▶';
    }
  }
}

function expandAllTree(open) {
  document.querySelectorAll('.tree-sub-branch').forEach(function(el) {
    el.style.display = open ? 'block' : 'none';
  });
  document.querySelectorAll('.tree-toggle-btn').forEach(function(btn) {
    btn.textContent = open ? '▼' : '▶';
  });
}

// Adjust DataTables layout on Bootstrap tab switch
document.addEventListener('DOMContentLoaded', function () {
  $('button[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
    if ($.fn.dataTable) {
      $.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
    }
  });
});
</script>
<?php endif; ?>

<?php elseif ($tab === 'scheme'): ?>
<!-- SCHEME & SIMULATION TAB -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">⚙️ Jaminan Komisi (Gaji Minimum Harian)</span></div>
      <div class="c-card-body p-3">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_global_rates">
          
          <div class="c-form-group">
            <label class="c-label">Skenario Komisi Deposit</label>
            <select name="promotor_deposit_scheme" id="cfg_scheme" class="c-form-control" onchange="runSim()">
              <?php $cur_scheme = setting($pdo, 'promotor_deposit_scheme', 'flat'); ?>
              <option value="flat" <?= $cur_scheme==='flat'?'selected':'' ?>>1️⃣ Flat Rate (Pasti per Transaksi)</option>
              <option value="percent" <?= $cur_scheme==='percent'?'selected':'' ?>>2️⃣ Persentase dari Nominal Deposit</option>
              <option value="hybrid" <?= $cur_scheme==='hybrid'?'selected':'' ?>>3️⃣ Hybrid (Flat + Persentase)</option>
            </select>
          </div>
          
          <div class="c-form-group">
            <label class="c-label">Bonus Per-Member Valid (Rp)</label>
            <input type="number" name="promotor_per_member_bonus" id="cfg_reg" class="c-form-control" value="<?= setting($pdo, 'promotor_per_member_bonus', '0') ?>" min="0" oninput="runSim()">
          </div>
          
          <div class="row g-2">
            <div class="col-md-6">
              <div class="c-form-group mb-0">
                <label class="c-label">Bonus Deposit Flat (Rp)</label>
                <input type="number" name="promotor_per_deposit_bonus" id="cfg_dep_flat" class="c-form-control" value="<?= setting($pdo, 'promotor_per_deposit_bonus', '0') ?>" min="0" oninput="runSim()">
                <small style="color:#888;font-size:11px">Dipakai jika Flat/Hybrid</small>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-0">
                <label class="c-label">Bonus Deposit Persen (%)</label>
                <input type="number" name="promotor_deposit_percent" id="cfg_dep_pct" class="c-form-control" value="<?= setting($pdo, 'promotor_deposit_percent', '0') ?>" min="0" max="100" step="0.1" oninput="runSim()">
                <small style="color:#888;font-size:11px">Dipakai jika Persen/Hybrid</small>
              </div>
            </div>
          </div>
          
          <button type="submit" class="btn btn-sm text-white mt-3" style="background:var(--brand)">Simpan Konfigurasi</button>
        </form>
      </div>
    </div>
  </div>
  
  <div class="col-md-6">
    <div class="c-card" style="border:2px dashed var(--brand); background:rgba(99,102,241,0.05)">
      <div class="c-card-header" style="border-bottom:1px dashed var(--brand)"><span class="c-card-title text-brand">🕹️ Kalkulator Simulasi</span></div>
      <div class="c-card-body p-3">
        <div style="font-size:12px;color:#aaa;margin-bottom:12px">Uji coba Skenario sebelum disimpan! Ubah input di bawah ini untuk melihat estimasi perhitungan gaji harian promotor.</div>
        
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="c-label">Jumlah Member Baru Daftar</label>
            <input type="number" id="sim_reg_count" class="c-form-control" value="10" oninput="runSim()">
          </div>
          <div class="col-6">
            <label class="c-label">Berapa Kali Deposit?</label>
            <input type="number" id="sim_dep_count" class="c-form-control" value="2" oninput="runSim()">
          </div>
          <div class="col-12 mt-2">
            <label class="c-label">Total Nominal Seluruh Deposit (Rp)</label>
            <input type="number" id="sim_dep_amount" class="c-form-control" value="100000" oninput="runSim()">
          </div>
        </div>
        
        <div style="background:#11131a;border-radius:8px;padding:12px;border:1px solid #2d3149">
          <div style="font-size:11px;font-weight:700;color:#666;text-transform:uppercase;margin-bottom:8px">Hasil Simulasi (Berdasarkan Konfigurasi Kiri):</div>
          
          <div class="d-flex justify-content-between mb-1" style="font-size:13px">
            <span style="color:#aaa">Dari Pendaftaran:</span>
            <strong id="res_reg" style="color:#fff">Rp 0</strong>
          </div>
          <div class="d-flex justify-content-between mb-2" style="font-size:13px">
            <span style="color:#aaa">Dari Transaksi Deposit:</span>
            <strong id="res_dep" style="color:#fff">Rp 0</strong>
          </div>
          <div style="border-top:1px dashed #444;margin:8px 0"></div>
          <div class="d-flex justify-content-between align-items-center">
            <span style="font-size:14px;font-weight:800;color:var(--lime)">Total Gaji Promotor:</span>
            <strong id="res_total" style="font-size:18px;color:var(--lime)">Rp 0</strong>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function formatRp(num) {
    return 'Rp ' + parseFloat(num).toLocaleString('id-ID');
}
function runSim() {
    let scheme = document.getElementById('cfg_scheme').value;
    let b_reg = parseFloat(document.getElementById('cfg_reg').value) || 0;
    let b_flat = parseFloat(document.getElementById('cfg_dep_flat').value) || 0;
    let b_pct = parseFloat(document.getElementById('cfg_dep_pct').value) || 0;
    
    let sim_regs = parseInt(document.getElementById('sim_reg_count').value) || 0;
    let sim_deps = parseInt(document.getElementById('sim_dep_count').value) || 0;
    let sim_amt = parseFloat(document.getElementById('sim_dep_amount').value) || 0;
    
    let total_reg = sim_regs * b_reg;
    let total_dep = 0;
    
    if (scheme === 'flat') {
        total_dep = sim_deps * b_flat;
    } else if (scheme === 'percent') {
        total_dep = sim_amt * (b_pct / 100);
    } else if (scheme === 'hybrid') {
        total_dep = (sim_deps * b_flat) + (sim_amt * (b_pct / 100));
    }
    
    document.getElementById('res_reg').textContent = formatRp(total_reg);
    document.getElementById('res_dep').textContent = formatRp(total_dep);
    document.getElementById('res_total').textContent = formatRp(total_reg + total_dep);
}
// Run initially
setTimeout(runSim, 100);
</script>

<?php elseif ($tab === 'logs'): ?>
<!-- LOGS TAB -->
<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">Riwayat Performansi Target Harian</span></div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "desc"]]' style="font-size: 13px;">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Promotor</th>
            <th>Persentase</th>
            <th>Pencapaian (Depo / Reg)</th>
            <th>Target (Depo / Reg)</th>
            <th>Gaji Harian</th>
            <th>Status Payout</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): 
            $is_today_log = ($log['date'] === date('Y-m-d'));
            $earned = (float)$log['earned_amount'];
            $is_pending = (!$log['is_paid'] && !$is_today_log && $earned > 0);
          ?>
            <tr style="vertical-align: middle;">
              <td>
                <span class="fw-bold" style="color: #fff;"><?= htmlspecialchars($log['date']) ?></span>
                <?php if ($is_today_log): ?>
                  <span class="badge" style="background:#0284c7;color:#fff;font-size:9.5px;margin-left:4px;">Live Hari Ini</span>
                <?php endif; ?>
              </td>
              <td><strong>@<?= htmlspecialchars($log['username']) ?></strong></td>
              <td>
                <span class="badge <?= (float)$log['percentage'] >= 100 ? 'b-success' : 'b-warn' ?>" style="font-size: 11px; padding: 4px 8px; border-radius: 6px;">
                  <?= number_format((float)$log['percentage'], 1) ?>%
                </span>
              </td>
              <td style="color:#aaa;">
                Depo: <?= format_rp((float)$log['actual_deposits']) ?> (<?= (int)($log['deposit_count'] ?? 0) ?>x)<br>
                Reg: <?= isset($log['valid_regs']) ? (int)$log['valid_regs'] . ' valid / ' : '' ?><?= $log['actual_regs'] ?> daftar
              </td>
              <td style="color:#666; font-size:11px;">
                Depo: <?= format_rp((float)$log['target_deposits']) ?> <br>
                Reg: <?= $log['target_regs'] ?> member
              </td>
              <td>
                <div style="font-size:11px;color:#aaa">Rate: <?= format_rp((float)$log['salary_rate']) ?> &bull; Jaminan: <?= format_rp((float)($log['guarantee_amount'] ?? 0)) ?></div>
                <?php if ($log['is_paid']): ?>
                  <div style="font-weight:700;color:#4CAF82;font-size:12.5px">Paid: <?= format_rp((float)$log['paid_amount']) ?></div>
                <?php else: ?>
                  <div style="font-weight:700;color:<?= $is_today_log ? '#38bdf8' : '#FF6B35' ?>;font-size:12.5px">
                    <?= $is_today_log ? 'Live' : 'Earned' ?>: <?= format_rp($earned) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($log['is_paid']): ?>
                  <span class="badge b-success" style="padding: 4px 8px; border-radius: 6px;">Paid ✅</span>
                <?php elseif ($is_today_log): ?>
                  <span class="badge" style="padding: 4px 8px; border-radius: 6px; background:rgba(2,132,199,0.25); color:#38bdf8; border:1px solid rgba(2,132,199,0.45);">
                    Berjalan 🔄
                  </span>
                <?php elseif ($is_pending): ?>
                  <span class="badge" style="padding: 4px 8px; border-radius: 6px; background:#f59e0b; color:#fff;">
                    Pending ⏳
                  </span>
                <?php else: ?>
                  <span class="badge b-neutral" style="padding: 4px 8px; border-radius: 6px; background:#444; color:#aaa">0% ❌</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if ($is_pending): ?>
                  <button class="btn btn-sm btn-success text-white" style="border:none;border-radius:6px;font-size:11px;background:#4CAF82"
                          onclick="openPayoutModal(<?= $log['id'] ?>, '<?= htmlspecialchars($log['username']) ?>', '<?= date('d M Y', strtotime($log['date'])) ?>', '<?= format_rp($earned) ?>', '<?= number_format((float)$log['percentage'], 1) ?>%')">
                    💸 Bayar Gaji
                  </button>
                <?php elseif ($is_today_log): ?>
                  <span style="font-size:10.5px;color:#0284c7;">Target Live</span>
                <?php else: ?>
                  <span style="font-size: 11px; color:#555">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($tab === 'members'): ?>
<!-- MEMBERS TAB -->
<div class="c-card">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="c-card-title">Member Referral Promotor (Direct Level 1)</span>
  </div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[4, "desc"]]' style="font-size: 13px;">
        <thead>
          <tr>
            <th>Member</th>
            <th>Promotor</th>
            <th>Level</th>
            <th>Total Deposit</th>
            <th>Waktu Daftar</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($referred_members)): ?>
            <?php foreach ($referred_members as $rm): ?>
              <tr style="vertical-align: middle;">
                <td>
                  <strong style="color: #fff;"><?= htmlspecialchars($rm['username']) ?></strong>
                  <div style="font-size: 11px; color: #666;"><?= htmlspecialchars($rm['email']) ?></div>
                </td>
                <td><strong style="color:var(--brand)">@<?= htmlspecialchars($rm['promotor_name']) ?></strong></td>
                <td>
                  <span class="badge b-neutral" style="font-size: 11px; padding: 4px 8px; border-radius: 6px;">
                    <?= htmlspecialchars($rm['membership_name']) ?>
                  </span>
                </td>
                <td style="color: #4CAF82; font-weight: 700;"><?= format_rp((float)$rm['total_deposit']) ?></td>
                <td style="color: #ccc; font-size: 12px;"><?= date('d M Y H:i', strtotime($rm['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODALS -->

<!-- Promotor Setup Modal -->
<div class="modal fade" id="promotorModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_promotor">
        <div class="modal-header border-0">
          <h6 class="modal-title fw-bold" id="modal-title">Konfigurasi Promotor</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="c-form-group" id="user-select-group">
            <label class="c-label">Pilih User</label>
            <select name="user_id" id="f_user_id" class="c-form-control">
              <option value="">— Pilih Pengguna —</option>
              <?php foreach ($eligible_users as $eu): ?>
                <option value="<?= $eu['id'] ?>"><?= htmlspecialchars($eu['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="c-form-group" id="user-display-group" style="display:none">
            <label class="c-label">User Seleksi</label>
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="text" id="edit_username" class="c-form-control" readonly style="background:#0f1117;color:#888">
          </div>
          
          <div class="c-form-group mt-3">
            <label class="c-label">Target Volume Deposit Harian (Rp)</label>
            <input type="number" name="target_deposits" id="f_target_deposits" class="c-form-control" min="0" step="any" required placeholder="Contoh: 500000">
            <span style="font-size:10px;color:#666">Reset harian. Total deposit downlines promotor hari itu.</span>
          </div>
          
          <div class="c-form-group mt-3">
            <label class="c-label">Target Registrasi Harian (Jumlah Member)</label>
            <input type="number" name="target_regs" id="f_target_regs" class="c-form-control" min="0" required placeholder="Contoh: 5">
            <span style="font-size:10px;color:#666">Reset harian. Hanya member VALID yang dihitung: daftar hari itu, bukan abuse, sudah nonton/mancing.</span>
          </div>
          
          <div class="c-form-group mt-3">
            <label class="c-label">Gaji Penuh Harian saat Capaian 100% (Rp)</label>
            <input type="number" name="salary_rate" id="f_salary_rate" class="c-form-control" min="0" step="any" required placeholder="Contoh: 50000">
            <span style="font-size:10px;color:#666">Gaji = rate × capaian (rata-rata % member &amp; % deposit, maks 100%). Jika jaminan komisi lebih besar, jaminan yang dibayar.</span>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Pengaturan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Nonaktifkan Promotor Form Modal -->
<div class="modal fade" id="removeModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_promotor">
        <input type="hidden" name="user_id" id="remove_user_id">
        <div class="modal-header border-0">
          <h6 class="modal-title fw-bold">Cabut Peran Promotor</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:#ccc">Apakah Anda yakin ingin mencabut peran Promotor dari <strong id="remove_username" style="color:#fff"></strong>?</p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger btn-sm">Cabut Peran</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Pay Salary Confirmation Modal -->
<div class="modal fade" id="payoutModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay_salary">
        <input type="hidden" name="log_id" id="pay_log_id">
        <div class="modal-header border-0">
          <h6 class="modal-title fw-bold">💸 Pencairan Gaji Promotor</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13.5px;color:#ccc;line-height:1.5">
            Anda akan merilis gaji harian untuk promotor <strong id="pay_username" style="color:#fff"></strong>.<br>
            Tanggal Target: <strong id="pay_date" style="color:#fff"></strong><br>
            Pencapaian Target: <strong id="pay_pct" style="color:#FF6B35"></strong><br>
            Jumlah Pencairan Gaji: <strong id="pay_amount" style="color:#4CAF82;font-size:16px"></strong><br><br>
            <span style="font-size:11px;color:#aaa">⚠️ Setelah diklik, saldo Penarikan milik promotor akan langsung bertambah secara proporsional sesuai pencapaian target dan notifikasi Telegram akan terkirim.</span>
          </p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm text-white" style="background:#4CAF82">Rilis Payout Gaji</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Display Gaji Pending & List History & Form Bayar -->
<div class="modal fade" id="pendingSalaryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background:#151824;border:1px solid #d97706;box-shadow:0 12px 36px rgba(0,0,0,0.6);">
      <form method="POST" id="form-pay-pending">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay_pending_salaries">
        <input type="hidden" name="user_id" id="ps_user_id">
        
        <div class="modal-header" style="background:linear-gradient(135deg,rgba(217,119,6,0.2),rgba(180,83,9,0.3));border-bottom:1px solid rgba(217,119,6,0.3);">
          <div>
            <h6 class="modal-title fw-bold text-white d-flex align-items-center gap-2">
              <span style="font-size:20px;">💸</span> Pencairan Gaji Pending Promotor: <span id="ps_modal_username" style="color:#fbbf24;"></span>
            </h6>
            <div style="font-size:11.5px;color:#cbd5e1;margin-top:2px;">
              Akumulasi riwayat target harian sebelum hari ini yang belum dicairkan.
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        
        <div class="modal-body p-4">
          <!-- Banner Ringkasan Gaji & Rekening -->
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="p-3 rounded h-100" style="background:#0f111a;border:1.5px solid #d97706;">
                <div style="font-size:11px;font-weight:700;color:#fde047;text-transform:uppercase;margin-bottom:4px;">
                  ⏳ Total Gaji Pending (Sebelum Hari Ini)
                </div>
                <div id="ps_total_pending_display" style="font-size:26px;font-weight:900;color:#fbbf24;line-height:1.1;">
                  Rp 0
                </div>
                <div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">
                  Gabungan dari <strong id="ps_pending_days_display" style="color:#fff;">0</strong> hari target selesai
                </div>
                <div style="font-size:10px;color:#f59e0b;margin-top:6px;border-top:1px dashed #2d3149;padding-top:4px;">
                  ℹ️ <em>Target hari ini (<?= date('d M Y') ?>) tidak dihitung karena masih berlangsung.</em>
                </div>
              </div>
            </div>
            
            <div class="col-md-6">
              <div class="p-3 rounded h-100" style="background:#0f111a;border:1px solid #282c4b;">
                <div style="font-size:11px;font-weight:700;color:#93c5fd;text-transform:uppercase;margin-bottom:4px;">
                  🏦 Rekening Penerima Pencairan
                </div>
                <div id="ps_bank_info" style="font-size:12.5px;color:#fff;line-height:1.4;">
                  <!-- dynamically filled -->
                </div>
                <div style="font-size:11px;color:#94a3b8;margin-top:6px;">
                  Saldo Penarikan Saat Ini: <strong id="ps_current_balance_wd" style="color:#38bdf8;">Rp 0</strong>
                </div>
              </div>
            </div>
          </div>

          <!-- Section: List History Hari Target Pending -->
          <div class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <h6 class="fw-bold mb-0 text-white" style="font-size:13px;">
                📜 Rincian Riwayat Tanggal Target Pending:
              </h6>
              <span class="badge" style="background:rgba(245,158,11,0.2);color:#fbbf24;border:1px solid rgba(245,158,11,0.4);font-size:10.5px;" id="ps_history_badge">
                0 Hari
              </span>
            </div>
            <div class="table-responsive" style="max-height:240px;overflow-y:auto;border:1px solid #23283c;border-radius:8px;">
              <table class="table table-dark table-sm table-hover mb-0" style="font-size:12px;">
                <thead style="position:sticky;top:0;background:#1a1d2d;z-index:1;">
                  <tr>
                    <th>Tanggal Target</th>
                    <th>Target vs Realisasi Depo</th>
                    <th>Target vs Realisasi Reg</th>
                    <th>Persentase</th>
                    <th class="text-end">Gaji Diperoleh</th>
                  </tr>
                </thead>
                <tbody id="ps_history_tbody">
                  <!-- dynamically filled -->
                </tbody>
                <tfoot style="background:#131522;font-weight:700;">
                  <tr>
                    <td colspan="4" class="text-end" style="color:#aaa;">TOTAL GAJI PENDING DICAIRKAN:</td>
                    <td class="text-end" style="color:#fbbf24;font-size:13px;" id="ps_history_tfoot_total">Rp 0</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <!-- Catatan Admin & Verifikasi -->
          <div class="p-3 rounded mb-2" style="background:#0f111a;border:1px solid #23283c;">
            <label class="c-label mb-1" style="font-size:11.5px;color:#cbd5e1;">Catatan Admin (Opsional):</label>
            <input type="text" name="admin_note" class="c-form-control" placeholder="Contoh: Pencairan via Transfer Bank / E-Wallet pada <?= date('d M Y') ?>" style="font-size:12.5px;">
            <div style="font-size:10.5px;color:#64748b;margin-top:4px;">
              Catatan ini akan disertakan dalam notifikasi Telegram log keuangan.
            </div>
          </div>
          
          <div class="alert mb-0 mt-3" style="background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:#86efac;font-size:11.5px;border-radius:8px;">
            <i class="ph-bold ph-shield-check me-1"></i> Setelah tombol di bawah diklik, status target pada tanggal-tanggal di atas akan otomatis ditandai <strong>LUNAS (Paid)</strong> dan nominal total akan langsung dikreditkan ke <strong>Saldo Penarikan (WD)</strong> promotor.
          </div>
        </div>

        <div class="modal-footer border-0" style="background:#10121c;">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
          <button type="submit" class="btn btn-sm text-dark fw-bold" id="ps_submit_btn" style="background:linear-gradient(135deg,#f59e0b,#fbbf24);border:none;box-shadow:0 0 10px rgba(245,158,11,0.4);padding:6px 14px;">
            ✅ Konfirmasi &amp; Bayar Gaji Pending
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openAddModal() {
  document.getElementById('modal-title').textContent = '➕ Tambah Promotor Baru';
  document.getElementById('user-select-group').style.display = 'block';
  document.getElementById('user-display-group').style.display = 'none';
  
  // Enable dropdown and disable edit input so only the selected user_id is sent
  document.getElementById('f_user_id').disabled = false;
  document.getElementById('f_user_id').value = '';
  document.getElementById('edit_user_id').disabled = true;
  document.getElementById('edit_user_id').value = '';
  
  document.getElementById('f_target_deposits').value = '';
  document.getElementById('f_target_regs').value = '';
  document.getElementById('f_salary_rate').value = '';
  
  new bootstrap.Modal(document.getElementById('promotorModal')).show();
}

function openEditModal(p) {
  document.getElementById('modal-title').textContent = '✏️ Edit Konfigurasi Promotor: ' + p.username;
  document.getElementById('user-select-group').style.display = 'none';
  document.getElementById('user-display-group').style.display = 'block';
  
  // Disable dropdown and enable edit input so only the edit user_id is sent
  document.getElementById('f_user_id').disabled = true;
  document.getElementById('edit_user_id').disabled = false;
  document.getElementById('edit_user_id').value = p.id;
  
  document.getElementById('edit_username').value = p.username;
  document.getElementById('f_target_deposits').value = p.promotor_target_deposits;
  document.getElementById('f_target_regs').value = p.promotor_target_regs;
  document.getElementById('f_salary_rate').value = p.promotor_salary_rate;
  
  new bootstrap.Modal(document.getElementById('promotorModal')).show();
}

function confirmRemove(uid, uname) {
  document.getElementById('remove_user_id').value = uid;
  document.getElementById('remove_username').textContent = '@' + uname;
  
  new bootstrap.Modal(document.getElementById('removeModal')).show();
}

function openPayoutModal(lid, uname, date, amount, pct) {
  document.getElementById('pay_log_id').value = lid;
  document.getElementById('pay_username').textContent = '@' + uname;
  document.getElementById('pay_date').textContent = date;
  document.getElementById('pay_amount').textContent = amount;
  document.getElementById('pay_pct').textContent = pct;
  
  new bootstrap.Modal(document.getElementById('payoutModal')).show();
}

function openPendingModal(data) {
  document.getElementById('ps_user_id').value = data.user_id;
  document.getElementById('ps_modal_username').textContent = '@' + data.username;
  document.getElementById('ps_total_pending_display').textContent = 'Rp ' + parseFloat(data.total_pending).toLocaleString('id-ID');
  document.getElementById('ps_pending_days_display').textContent = data.pending_days;
  document.getElementById('ps_current_balance_wd').textContent = 'Rp ' + parseFloat(data.balance_wd).toLocaleString('id-ID');
  document.getElementById('ps_history_badge').textContent = data.pending_days + ' Hari Target';
  document.getElementById('ps_history_tfoot_total').textContent = 'Rp ' + parseFloat(data.total_pending).toLocaleString('id-ID');

  // Bank Info
  let bankHtml = '';
  if (data.bank_name && data.account_number && data.account_number !== '-') {
    bankHtml = `<strong>${data.bank_name}</strong> - <code>${data.account_number}</code><br>a/n <strong>${data.account_name}</strong>`;
  } else {
    bankHtml = `<span style="color:#f87171;">⚠️ Rekening belum dilengkapi oleh promotor</span>`;
  }
  document.getElementById('ps_bank_info').innerHTML = bankHtml;

  // History Rows
  let tbody = document.getElementById('ps_history_tbody');
  tbody.innerHTML = '';
  if (data.history && data.history.length > 0) {
    data.history.forEach(item => {
      let tr = document.createElement('tr');
      let earned = parseFloat(item.earned || 0);
      let pct = parseFloat(item.percentage || 0);
      let actDepo = parseFloat(item.actual_deposits || 0);
      let tgtDepo = parseFloat(item.target_deposits || 0);
      
      tr.innerHTML = `
        <td class="fw-bold text-white">${item.date}</td>
        <td>Rp ${actDepo.toLocaleString('id-ID')} / ${tgtDepo.toLocaleString('id-ID')}</td>
        <td>${item.valid_regs ?? item.actual_regs} / ${item.target_regs} member</td>
        <td><span class="badge ${pct >= 100 ? 'bg-success' : 'bg-warning text-dark'}">${pct.toFixed(1)}%</span></td>
        <td class="text-end fw-bold" style="color:#4CAF82;">Rp ${earned.toLocaleString('id-ID')}</td>
      `;
      tbody.appendChild(tr);
    });
  } else {
    tbody.innerHTML = `<tr><td colspan="5" class="text-center text-muted py-3">Tidak ada riwayat pending.</td></tr>`;
  }

  document.getElementById('ps_submit_btn').textContent = `✅ Konfirmasi & Bayar Gaji Pending (Rp ${parseFloat(data.total_pending).toLocaleString('id-ID')})`;

  new bootstrap.Modal(document.getElementById('pendingSalaryModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>