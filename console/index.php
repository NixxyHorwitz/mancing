<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Dashboard = Pusat Analisis (deposit, trafik, nonton, mancing, jam sibuk, keaktifan, user online)
if (!staff_can('dashboard')) {
    redirect(staff_home_url());
}


// Helper: Parse User Agent into readable text
if (!function_exists('parse_ua_short')) {
    function parse_ua_short(?string $ua): string {
        if (empty($ua)) return 'Web Browser';
        $os = 'Device';
        if (stripos($ua, 'windows nt 10.0') !== false) $os = 'Windows 10/11';
        elseif (stripos($ua, 'windows nt 6.1') !== false) $os = 'Windows 7';
        elseif (stripos($ua, 'iphone') !== false) $os = 'iPhone';
        elseif (stripos($ua, 'ipad') !== false) $os = 'iPad';
        elseif (stripos($ua, 'macintosh') !== false || stripos($ua, 'mac os x') !== false) $os = 'macOS';
        elseif (stripos($ua, 'android') !== false) $os = 'Android';
        elseif (stripos($ua, 'linux') !== false) $os = 'Linux';
        
        $browser = 'Browser';
        if (stripos($ua, 'edg/') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'chrome') !== false || stripos($ua, 'crios') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'safari') !== false) $browser = 'Safari';
        elseif (stripos($ua, 'firefox') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'opera') !== false || stripos($ua, 'opr/') !== false) $browser = 'Opera';
        
        return "{$os} ({$browser})";
    }
}

if (!function_exists('format_activity_path')) {
    function format_activity_path(?string $path): array {
        if (!$path) return ['label' => 'Web App', 'badge' => 'rgba(255,255,255,0.08)', 'color' => '#94a3b8', 'icon' => '🌐'];
        $clean = strtolower(rtrim($path, '/'));
        if ($clean === '/videos' || strpos($clean, '/watch') !== false) {
            return ['label' => 'Nonton Video', 'badge' => 'rgba(245,158,11,0.15)', 'color' => '#fbbf24', 'icon' => '🎬'];
        }
        if ($clean === '/home' || $clean === '') {
            return ['label' => 'Dashboard User', 'badge' => 'rgba(56,189,248,0.15)', 'color' => '#38bdf8', 'icon' => '🏠'];
        }
        if (strpos($clean, '/minigame') !== false) {
            return ['label' => 'Minigame Cuan', 'badge' => 'rgba(16,185,129,0.15)', 'color' => '#10b981', 'icon' => '🎮'];
        }
        if (strpos($clean, '/withdraw') !== false) {
            return ['label' => 'Halaman WD', 'badge' => 'rgba(239,68,68,0.15)', 'color' => '#f87171', 'icon' => '💸'];
        }
        if (strpos($clean, '/deposit') !== false) {
            return ['label' => 'Halaman Deposit', 'badge' => 'rgba(168,85,247,0.15)', 'color' => '#c084fc', 'icon' => '💳'];
        }
        if (strpos($clean, '/history') !== false) {
            return ['label' => 'Riwayat Mutasi', 'badge' => 'rgba(148,163,184,0.15)', 'color' => '#cbd5e1', 'icon' => '📜'];
        }
        if (strpos($clean, '/upgrade') !== false) {
            return ['label' => 'Upgrade Level', 'badge' => 'rgba(245,158,11,0.2)', 'color' => '#f59e0b', 'icon' => '👑'];
        }
        if (strpos($clean, '/survey') !== false) {
            return ['label' => 'Survei Akun', 'badge' => 'rgba(99,102,241,0.15)', 'color' => '#818cf8', 'icon' => '📝'];
        }
        if (strpos($clean, '/edit-rekening') !== false) {
            return ['label' => 'Edit Rekening', 'badge' => 'rgba(234,179,8,0.15)', 'color' => '#eab308', 'icon' => '💳'];
        }
        return ['label' => $path, 'badge' => 'rgba(255,255,255,0.06)', 'color' => '#94a3b8', 'icon' => '📄'];
    }
}

// Range Filter: today, 7, 14, 30 days
$range = trim($_GET['range'] ?? '7');
if (!in_array($range, ['today', '7', '14', '30'], true)) {
    $range = '7';
}

$days = $range === 'today' ? 1 : (int)$range;

if ($range === 'today') {
    $dateCondition = "created_at >= CURDATE()";
    $dateConditionWatched = "watched_at >= CURDATE()";
    $rangeTitle = "Hari Ini (Live)";
} else {
    $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)";
    $dateConditionWatched = "watched_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)";
    $rangeTitle = "{$days} Hari Terakhir";
}

// Nilai bawaan: bila satu query gagal, dashboard tetap tampil (dengan pesan error) alih-alih fatal.
$totalDepoSuccessAmount = 0.0; $totalDepoSuccessCount = $totalDepoPendingCount = $totalDepoFailedCount = $totalDepoAllCount = 0;
$depoSuccessRate = 0.0; $avgDepoAmount = 0;
$totalViews = $totalUniqueVisitors = $totalActiveUsers = 0;
$totalWatches = $totalUniqueViewers = 0; $totalRewardsGiven = 0.0; $avgWatchPerViewer = 0.0;
$topPages = $topWatchedVideos = $activityStream = $onlineUsers = $topPlatformUsers = [];
$fishCasts = $fishAnglers = $fishJunk = $fishJackpot = $fishSoldCount = $fishBaitPacks = $fishBaitQty = $fishLocks = $fishLocksPaid = $fishReleased = 0;
$fishCatchValue = $fishSoldValue = $fishBaitRevenue = $fishLockRevenue = 0.0; $fishCastsPerAngler = $fishJunkRate = 0.0;
$fishRarity = $topAnglers = []; $fishHourly = array_fill(0, 24, 0);
$hourlyActivity = array_fill(0, 24, ['watches' => 0, 'deposits' => 0, 'views' => 0, 'fishing' => 0]);
$hourlyOnline = array_fill(0, 24, ['unique_users' => 0, 'page_hits' => 0]);
$peakHour = $peakOnlineHour = $peakOnlineCount = $peakOnlineHits = 0; $avgOnlinePerHour = 0;
$tierDist = []; $totalTierUsersCount = 0; $onlineCount = 0;
$chartLabels = $chartDepoData = $chartViewsData = $chartWatchData = $chartFishData = [];

try {
    // ── 1. DEPOSIT METRICS ─────────────────────────────────────────────
    $depoQuery = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN status IN ('confirmed','approved') THEN amount ELSE 0 END), 0) as total_success_amount,
            COUNT(CASE WHEN status IN ('confirmed','approved') THEN 1 END) as success_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status IN ('rejected','expired','cancelled') THEN 1 END) as failed_count,
            COUNT(*) as total_count
        FROM deposits 
        WHERE {$dateCondition}
    ")->fetch();

    $totalDepoSuccessAmount = (float)($depoQuery['total_success_amount'] ?? 0);
    $totalDepoSuccessCount  = (int)($depoQuery['success_count'] ?? 0);
    $totalDepoPendingCount  = (int)($depoQuery['pending_count'] ?? 0);
    $totalDepoFailedCount   = (int)($depoQuery['failed_count'] ?? 0);
    $totalDepoAllCount      = (int)($depoQuery['total_count'] ?? 0);
    $depoSuccessRate        = $totalDepoAllCount > 0 ? round(($totalDepoSuccessCount / $totalDepoAllCount) * 100, 1) : 0.0;
    $avgDepoAmount          = $totalDepoSuccessCount > 0 ? round($totalDepoSuccessAmount / $totalDepoSuccessCount) : 0;

    // Daily Deposit breakdown for chart
    $depoDailyStmt = $pdo->query("
        SELECT DATE(created_at) as d, 
               COALESCE(SUM(CASE WHEN status IN ('confirmed','approved') THEN amount ELSE 0 END), 0) as total_nominal,
               COUNT(CASE WHEN status IN ('confirmed','approved') THEN 1 END) as total_trans
        FROM deposits 
        WHERE {$dateCondition}
        GROUP BY d ORDER BY d ASC
    ");
    $depoDailyMap = [];
    while ($r = $depoDailyStmt->fetch()) {
        $depoDailyMap[$r['d']] = $r;
    }

    // ── 2. VISITOR / TRAFFIC METRICS ──────────────────────────────────
    $pvQuery = $pdo->query("
        SELECT 
            COUNT(*) as total_views,
            COUNT(DISTINCT ip_hash) as unique_visitors,
            COUNT(DISTINCT user_id) as active_logged_users
        FROM page_views 
        WHERE {$dateCondition}
    ")->fetch();

    $totalViews           = (int)($pvQuery['total_views'] ?? 0);
    $totalUniqueVisitors  = (int)($pvQuery['unique_visitors'] ?? 0);
    $totalActiveUsers     = (int)($pvQuery['active_logged_users'] ?? 0);

    // Daily Pageviews breakdown
    $pvDailyStmt = $pdo->query("
        SELECT DATE(created_at) as d, COUNT(*) as views, COUNT(DISTINCT ip_hash) as visitors
        FROM page_views 
        WHERE {$dateCondition}
        GROUP BY d ORDER BY d ASC
    ");
    $pvDailyMap = [];
    while ($r = $pvDailyStmt->fetch()) {
        $pvDailyMap[$r['d']] = $r;
    }

    // Top Pages
    $topPages = $pdo->query("
        SELECT path, COUNT(*) as cnt, COUNT(DISTINCT ip_hash) as unique_ips
        FROM page_views 
        WHERE {$dateCondition}
        GROUP BY path ORDER BY cnt DESC LIMIT 7
    ")->fetchAll();

    // ── 3. WATCH / VIDEO METRICS ──────────────────────────────────────
    $watchQuery = $pdo->query("
        SELECT 
            COUNT(*) as total_watches,
            COALESCE(SUM(reward_given), 0) as total_rewards,
            COUNT(DISTINCT user_id) as unique_viewers,
            COUNT(DISTINCT video_id) as unique_videos_watched
        FROM watch_history 
        WHERE {$dateConditionWatched}
    ")->fetch();

    $totalWatches       = (int)($watchQuery['total_watches'] ?? 0);
    $totalRewardsGiven  = (float)($watchQuery['total_rewards'] ?? 0);
    $totalUniqueViewers = (int)($watchQuery['unique_viewers'] ?? 0);
    $avgWatchPerViewer  = $totalUniqueViewers > 0 ? round($totalWatches / $totalUniqueViewers, 1) : 0.0;

    // Daily Watches breakdown
    $watchDailyStmt = $pdo->query("
        SELECT DATE(watched_at) as d, COUNT(*) as cnt, COALESCE(SUM(reward_given),0) as rewards
        FROM watch_history 
        WHERE {$dateConditionWatched}
        GROUP BY d ORDER BY d ASC
    ");
    $watchDailyMap = [];
    while ($r = $watchDailyStmt->fetch()) {
        $watchDailyMap[$r['d']] = $r;
    }

    // Top Videos Watched
    $topWatchedVideos = $pdo->query("
        SELECT v.id, v.title, v.youtube_id, COUNT(wh.id) as watch_count, SUM(wh.reward_given) as rewards_sum
        FROM watch_history wh
        JOIN videos v ON v.id = wh.video_id
        WHERE wh.{$dateConditionWatched}
        GROUP BY wh.video_id
        ORDER BY watch_count DESC LIMIT 5
    ")->fetchAll();

    // ── 3B. FITUR MANCING ─────────────────────────────────────────────
    // Dipisah dalam try sendiri: bila tabel mancing belum ada, analitik lain tetap tampil.
    $fishCasts = $fishAnglers = $fishJunk = $fishJackpot = $fishSoldCount = $fishBaitPacks = $fishBaitQty = $fishLocks = $fishLocksPaid = $fishReleased = 0;
    $fishCatchValue = $fishSoldValue = $fishBaitRevenue = $fishLockRevenue = 0.0;
    $fishDailyMap = []; $fishHourly = array_fill(0, 24, 0);
    $fishRarity = []; $topAnglers = []; $recFish = [];
    try {
        $f = $pdo->query("
            SELECT COUNT(*) casts, COUNT(DISTINCT user_id) anglers, COALESCE(SUM(reward), 0) catch_value,
                   SUM(UPPER(rarity) = 'JUNK') junk, SUM(UPPER(rarity) = 'JACKPOT') jackpot
            FROM fishing_logs WHERE {$dateCondition}
        ")->fetch();
        $fishCasts      = (int)($f['casts'] ?? 0);
        $fishAnglers    = (int)($f['anglers'] ?? 0);
        $fishCatchValue = (float)($f['catch_value'] ?? 0);
        $fishJunk       = (int)($f['junk'] ?? 0);
        $fishJackpot    = (int)($f['jackpot'] ?? 0);

        $s = $pdo->query("SELECT COALESCE(SUM(total), 0) v, COALESCE(SUM(fish_count), 0) n FROM fishing_sales WHERE {$dateCondition}")->fetch();
        $fishSoldValue = (float)$s['v']; $fishSoldCount = (int)$s['n'];

        $b = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(qty), 0) q, COALESCE(SUM(price), 0) v FROM fishing_bait_purchases WHERE {$dateCondition}")->fetch();
        $fishBaitPacks = (int)$b['n']; $fishBaitQty = (int)$b['q']; $fishBaitRevenue = (float)$b['v'];

        $l = $pdo->query("SELECT COUNT(*) n, SUM(is_free = 0) paid, COALESCE(SUM(price), 0) v FROM fishing_lock_uses WHERE {$dateCondition}")->fetch();
        $fishLocks = (int)$l['n']; $fishLocksPaid = (int)$l['paid']; $fishLockRevenue = (float)$l['v'];

        $fishReleased = (int)$pdo->query("SELECT COUNT(*) FROM fishing_released WHERE {$dateCondition}")->fetchColumn();

        foreach ($pdo->query("SELECT DATE(created_at) d, COUNT(*) cnt, COALESCE(SUM(reward), 0) val FROM fishing_logs WHERE {$dateCondition} GROUP BY d") as $r) $fishDailyMap[$r['d']] = $r;
        foreach ($pdo->query("SELECT HOUR(created_at) h, COUNT(*) cnt FROM fishing_logs WHERE {$dateCondition} GROUP BY h") as $r) $fishHourly[(int)$r['h']] = (int)$r['cnt'];

        $fishRarity = $pdo->query("
            SELECT rarity, COUNT(*) cnt, COALESCE(SUM(reward), 0) val FROM fishing_logs WHERE {$dateCondition}
            GROUP BY rarity ORDER BY FIELD(UPPER(rarity), 'COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'JACKPOT', 'JUNK')
        ")->fetchAll();

        $topAnglers = $pdo->query("
            SELECT u.id, u.username, COUNT(fl.id) casts, COALESCE(SUM(fl.reward), 0) catch_value,
                   SUM(UPPER(fl.rarity) IN ('RARE', 'EPIC', 'JACKPOT')) rare_plus,
                   (SELECT COALESCE(SUM(fs.total), 0) FROM fishing_sales fs WHERE fs.user_id = u.id AND fs.{$dateCondition}) sold_value
            FROM fishing_logs fl JOIN users u ON u.id = fl.user_id
            WHERE fl.{$dateCondition}
            GROUP BY u.id ORDER BY catch_value DESC LIMIT 8
        ")->fetchAll();

        // Untuk live stream gabungan
        foreach ($pdo->query("
            SELECT fl.id, fl.reward amount, fl.rarity status, fl.created_at, u.username, fl.fish_name extra_info, 'fish' act_type
            FROM fishing_logs fl JOIN users u ON u.id = fl.user_id ORDER BY fl.id DESC LIMIT 15
        ") as $r) $recFish[] = $r;
        foreach ($pdo->query("
            SELECT fs.id, fs.total amount, 'sold' status, fs.created_at, u.username, fs.fish_count extra_info, 'fish_sale' act_type
            FROM fishing_sales fs JOIN users u ON u.id = fs.user_id ORDER BY fs.id DESC LIMIT 10
        ") as $r) $recFish[] = $r;
        foreach ($pdo->query("
            SELECT bp.id, bp.price amount, 'bait' status, bp.created_at, u.username, CONCAT(bp.pack_name, ' (+', bp.qty, ' umpan)') extra_info, 'fish_bait' act_type
            FROM fishing_bait_purchases bp JOIN users u ON u.id = bp.user_id ORDER BY bp.id DESC LIMIT 10
        ") as $r) $recFish[] = $r;
    } catch (\Throwable $fe) {
        $fishError = $fe->getMessage();
    }
    $fishCastsPerAngler = $fishAnglers > 0 ? round($fishCasts / $fishAnglers, 1) : 0.0;
    $fishJunkRate       = $fishCasts > 0 ? round($fishJunk / $fishCasts * 100, 1) : 0.0;

    // ── 4. HOURLY ACTIVITY HEATMAP (00:00 - 23:00) ───────────────────
    $hourlyActivity = array_fill(0, 24, ['watches' => 0, 'deposits' => 0, 'views' => 0, 'fishing' => 0]);
    foreach ($fishHourly as $h => $c) $hourlyActivity[$h]['fishing'] = $c;

    // Hourly watches
    $hw = $pdo->query("
        SELECT HOUR(watched_at) as h, COUNT(*) as cnt 
        FROM watch_history 
        WHERE {$dateConditionWatched}
        GROUP BY h
    ")->fetchAll();
    foreach ($hw as $row) { $hourlyActivity[(int)$row['h']]['watches'] = (int)$row['cnt']; }

    // Hourly deposits
    $hd = $pdo->query("
        SELECT HOUR(created_at) as h, COUNT(*) as cnt 
        FROM deposits 
        WHERE status IN ('confirmed','approved') AND {$dateCondition}
        GROUP BY h
    ")->fetchAll();
    foreach ($hd as $row) { $hourlyActivity[(int)$row['h']]['deposits'] = (int)$row['cnt']; }

    // Hourly pageviews
    $hp = $pdo->query("
        SELECT HOUR(created_at) as h, COUNT(*) as cnt 
        FROM page_views 
        WHERE {$dateCondition}
        GROUP BY h
    ")->fetchAll();
    foreach ($hp as $row) { $hourlyActivity[(int)$row['h']]['views'] = (int)$row['cnt']; }

    // Cari jam tersibuk (Peak Hour)
    $peakHour = 0; $peakScore = 0;
    foreach ($hourlyActivity as $h => $act) {
        $score = ($act['watches'] * 2) + ($act['fishing'] * 2) + ($act['deposits'] * 5) + $act['views'];
        if ($score > $peakScore) { $peakScore = $score; $peakHour = $h; }
    }

    // ── 4B. AKUMULASI USER ONLINE PER JAM (00:00 - 23:00) ─────────────
    $hourlyOnline = array_fill(0, 24, ['unique_users' => 0, 'page_hits' => 0]);
    $stmtHOnline = $pdo->query("
        SELECT HOUR(created_at) as h, COUNT(DISTINCT user_id) as u_cnt, COUNT(*) as pv_cnt 
        FROM page_views 
        WHERE user_id IS NOT NULL AND {$dateCondition} 
        GROUP BY h
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stmtHOnline as $row) {
        $h = (int)$row['h'];
        $hourlyOnline[$h]['unique_users'] = (int)$row['u_cnt'];
        $hourlyOnline[$h]['page_hits']    = (int)$row['pv_cnt'];
    }

    // Cari Akumulasi Online Terbanyak (Peak Online Record)
    $peakOnlineHour  = 0;
    $peakOnlineCount = 0;
    $peakOnlineHits  = 0;
    $totalHourlyOnlineUniqueSum = 0;
    $activeHourCount = 0;

    foreach ($hourlyOnline as $h => $d) {
        if ($d['unique_users'] > $peakOnlineCount) {
            $peakOnlineCount = $d['unique_users'];
            $peakOnlineHour  = $h;
            $peakOnlineHits  = $d['page_hits'];
        }
        if ($d['unique_users'] > 0) {
            $totalHourlyOnlineUniqueSum += $d['unique_users'];
            $activeHourCount++;
        }
    }
    $avgOnlinePerHour = $activeHourCount > 0 ? round($totalHourlyOnlineUniqueSum / $activeHourCount, 1) : 0;

    // ── 4C. SEGMENTASI & DISTRIBUSI TIER KEAKTIFAN PENGGUNA ───────────
    $tierQuery = $pdo->query("
        SELECT 
            u.id, u.username, u.email, u.last_seen, u.balance_wd, u.balance_dep,
            COALESCE(m.name, 'Free') as membership_name,
            COALESCE(wh.cnt, 0) as watch_count,
            COALESCE(dep.dep_sum, 0) as dep_sum,
            COALESCE(pv.pv_count, 0) as pv_count,
            TIMESTAMPDIFF(SECOND, u.last_seen, NOW()) as seconds_ago,
            (
                (COALESCE(pv.pv_count, 0) * 1) + 
                (COALESCE(wh.cnt, 0) * 8) + 
                (COALESCE(fl.cnt, 0) * 4) + 
                (COALESCE(dep.dep_count, 0) * 25) +
                (CASE 
                    WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 60 
                    WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 40
                    WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 20
                    WHEN u.last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 10 
                    ELSE 0 END)
            ) as activity_score
        FROM users u
        LEFT JOIN memberships m ON m.id = u.membership_id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as cnt 
            FROM watch_history 
            WHERE watched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
            GROUP BY user_id
        ) wh ON wh.user_id = u.id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as cnt 
            FROM fishing_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
            GROUP BY user_id
        ) fl ON fl.user_id = u.id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as dep_count, SUM(amount) as dep_sum 
            FROM deposits 
            WHERE status IN ('confirmed','approved') 
            GROUP BY user_id
        ) dep ON dep.user_id = u.id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as pv_count 
            FROM page_views 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
            GROUP BY user_id
        ) pv ON pv.user_id = u.id
        ORDER BY activity_score DESC, u.last_seen DESC
    ");

    $tierDist = [
        'mythic'   => ['count' => 0, 'min_score' => 150, 'name' => 'Mythic Sultan', 'color' => '#fbbf24', 'bg' => 'linear-gradient(135deg, #d97706, #b45309)', 'badge_class' => 'bg-warning text-dark', 'icon' => '👑', 'desc' => 'Skor ≥ 150 (Kontributor Misi & Depo Utama)'],
        'platinum' => ['count' => 0, 'min_score' => 60,  'name' => 'Platinum Star', 'color' => '#38bdf8', 'bg' => 'linear-gradient(135deg, #0284c7, #0369a1)', 'badge_class' => 'bg-info text-white', 'icon' => '💎', 'desc' => 'Skor 60 - 149 (Pengguna Sangat Aktif)'],
        'gold'     => ['count' => 0, 'min_score' => 20,  'name' => 'Gold Active',   'color' => '#facc15', 'bg' => 'linear-gradient(135deg, #ca8a04, #a16207)', 'badge_class' => 'bg-warning text-dark', 'icon' => '🌟', 'desc' => 'Skor 20 - 59 (Aktivitas Reguler)'],
        'silver'   => ['count' => 0, 'min_score' => 1,   'name' => 'Silver Regular', 'color' => '#94a3b8', 'bg' => 'linear-gradient(135deg, #475569, #334155)', 'badge_class' => 'bg-secondary text-white', 'icon' => '⚡', 'desc' => 'Skor 1 - 19 (Pengguna Casual)'],
        'dormant'  => ['count' => 0, 'min_score' => 0,   'name' => 'Dormant Inactive', 'color' => '#64748b', 'bg' => 'linear-gradient(135deg, #1e293b, #0f172a)', 'badge_class' => 'bg-dark text-secondary', 'icon' => '💤', 'desc' => 'Skor 0 (Belum Ada Interaksi Baru)'],
    ];

    $topPlatformUsers = [];
    $totalTierUsersCount = 0;

    while ($row = $tierQuery->fetch(PDO::FETCH_ASSOC)) {
        $totalTierUsersCount++;
        $score = (int)$row['activity_score'];
        
        if ($score >= 150) {
            $tierDist['mythic']['count']++;
            $tierKey = 'mythic';
        } elseif ($score >= 60) {
            $tierDist['platinum']['count']++;
            $tierKey = 'platinum';
        } elseif ($score >= 20) {
            $tierDist['gold']['count']++;
            $tierKey = 'gold';
        } elseif ($score >= 1) {
            $tierDist['silver']['count']++;
            $tierKey = 'silver';
        } else {
            $tierDist['dormant']['count']++;
            $tierKey = 'dormant';
        }

        if (count($topPlatformUsers) < 10) {
            $row['tier_key'] = $tierKey;
            $row['tier_meta'] = $tierDist[$tierKey];
            $topPlatformUsers[] = $row;
        }
    }

    // ── 5. LIVE RECENT ACTIVITY STREAM (50 Terkini) ───────────────────
    $activityStream = [];

    // Recent deposits
    $recDepo = $pdo->query("
        SELECT d.id, d.amount, d.status, d.created_at, u.username, 'deposit' as act_type
        FROM deposits d
        JOIN users u ON u.id = d.user_id
        ORDER BY d.id DESC LIMIT 15
    ")->fetchAll();
    foreach ($recDepo as $item) { $activityStream[] = $item; }

    // Recent watches
    $recWatch = $pdo->query("
        SELECT wh.id, wh.reward_given as amount, 'watch' as status, wh.watched_at as created_at, u.username, v.title as extra_info, 'watch' as act_type
        FROM watch_history wh
        JOIN users u ON u.id = wh.user_id
        JOIN videos v ON v.id = wh.video_id
        ORDER BY wh.id DESC LIMIT 15
    ")->fetchAll();
    foreach ($recWatch as $item) { $activityStream[] = $item; }

    // Recent upgrades
    $recUpg = $pdo->query("
        SELECT uo.id, uo.amount as amount, uo.status, uo.created_at, u.username, m.name as extra_info, 'upgrade' as act_type
        FROM upgrade_orders uo
        JOIN users u ON u.id = uo.user_id
        JOIN memberships m ON m.id = uo.membership_id
        ORDER BY uo.id DESC LIMIT 10
    ")->fetchAll();
    foreach ($recUpg as $item) { $activityStream[] = $item; }

    // Recent registrations
    $recReg = $pdo->query("
        SELECT u.id, 0 as amount, 'registered' as status, u.created_at, u.username, u.referral_code as extra_info, 'register' as act_type
        FROM users u
        ORDER BY u.id DESC LIMIT 10
    ")->fetchAll();
    foreach ($recReg as $item) { $activityStream[] = $item; }

    // Recent fishing (tangkapan, jual ikan, beli umpan)
    foreach ($recFish as $item) { $activityStream[] = $item; }

    // Urutkan seluruh aktivitas stream berdasarkan timestamp DESC
    usort($activityStream, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $activityStream = array_slice($activityStream, 0, 30);

    // ── 7. ONLINE USERS (Aktif dalam 15 Menit Terakhir) ───────────────
    $onlineUsers = [];
    try {
        $onlineUsersStmt = $pdo->query("
            SELECT 
                u.id, 
                u.username, 
                u.email, 
                u.whatsapp,
                u.balance_wd, 
                u.balance_dep, 
                u.last_seen,
                u.membership_id,
                m.name as membership_name,
                pv.path as current_path,
                pv.ip_hash,
                pv.user_agent,
                pv.created_at as page_hit_at,
                TIMESTAMPDIFF(SECOND, u.last_seen, NOW()) as seconds_ago
            FROM users u
            LEFT JOIN memberships m ON m.id = u.membership_id
            LEFT JOIN (
                SELECT pv1.user_id, pv1.path, pv1.ip_hash, pv1.user_agent, pv1.created_at
                FROM page_views pv1
                INNER JOIN (
                    SELECT user_id, MAX(id) as max_id
                    FROM page_views
                    WHERE user_id IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                    GROUP BY user_id
                ) pv2 ON pv1.id = pv2.max_id
            ) pv ON pv.user_id = u.id
            WHERE u.last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ORDER BY u.last_seen DESC
            LIMIT 50
        ");
        $onlineUsers = $onlineUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
    $onlineCount = count($onlineUsers);

    // ── 6. BUILD MULTI-METRIC CHART DATA ──────────────────────────────
    $chartLabels = [];
    $chartDepoData = [];
    $chartViewsData = [];
    $chartWatchData = [];
    $chartFishData = [];

    if ($range === 'today') {
        // Mode today: Breakdown 24 jam
        for ($h = 0; $h <= 23; $h++) {
            $label = sprintf("%02d:00", $h);
            $chartLabels[] = $label;
            $chartDepoData[] = (float)($hourlyActivity[$h]['deposits'] ?? 0);
            $chartViewsData[] = (int)($hourlyActivity[$h]['views'] ?? 0);
            $chartWatchData[] = (int)($hourlyActivity[$h]['watches'] ?? 0);
            $chartFishData[] = (int)($hourlyActivity[$h]['fishing'] ?? 0);
        }
    } else {
        // Mode N days
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartLabels[] = date('d/m', strtotime($date));
            $chartDepoData[] = (float)($depoDailyMap[$date]['total_nominal'] ?? 0);
            $chartViewsData[] = (int)($pvDailyMap[$date]['views'] ?? 0);
            $chartWatchData[] = (int)($watchDailyMap[$date]['cnt'] ?? 0);
            $chartFishData[] = (int)($fishDailyMap[$date]['cnt'] ?? 0);
        }
    }

} catch (\Throwable $e) {
    $error = $e->getMessage();
}


// ── Tambahan dari dashboard lama: antrean pending & total keseluruhan ──
try {
    $pendingWd     = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status IN ('pending','hold')")->fetchColumn();
    $pendingDep    = (int)$pdo->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
    $pendingUpg    = (int)$pdo->query("SELECT COUNT(*) FROM upgrade_orders WHERE status='pending'")->fetchColumn();
    $totalUsersAll = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalUserWd   = (float)$pdo->query("SELECT COALESCE(SUM(balance_wd),0) FROM users")->fetchColumn();
    $totalRevenue  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE status='confirmed'")->fetchColumn();
} catch (\Throwable) {
    $pendingWd = $pendingDep = $pendingUpg = $totalUsersAll = 0; $totalUserWd = $totalRevenue = 0.0;
}

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/partials/header.php';

$rarityMeta = [
    'COMMON' => ['Biasa', '#38bdf8'], 'UNCOMMON' => ['Bagus', '#f59e0b'], 'RARE' => ['Langka', '#f43f5e'],
    'EPIC'   => ['Super', '#a855f7'], 'JACKPOT'  => ['Peti Harta', '#10b981'], 'JUNK' => ['Sampah', '#78716c'],
];
$fishRarityMax = max(1, ...array_map(fn($r) => (int)$r['cnt'], $fishRarity ?: [['cnt' => 1]]));
$rangeQs = $range !== '7' ? '?range=' . urlencode($range) : '';
?>

<style>
/* ══ DASHBOARD — ringkas & padat ══ */
.db .c-card { margin: 0; }
.db .c-card-header { padding: 8px 12px; min-height: 0; }
.db .c-card-title { font-size: 12.5px; }
.db .c-card-body { padding: 10px 12px; }
.db-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.db-h h4 { margin: 0; font-size: 16px; font-weight: 800; color: #fff; }
.db-h small { font-size: 11.5px; color: #64748b; }
.db-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; text-decoration: none; border: 1px solid; white-space: nowrap; }
.db-seg { display: inline-flex; gap: 2px; padding: 2px; border-radius: 9px; background: #121524; border: 1px solid #1f2438; }
.db-seg a { padding: 3px 9px; border-radius: 7px; font-size: 11px; font-weight: 700; color: #94a3b8; text-decoration: none; }
.db-seg a.on { background: #f59e0b; color: #111; }

.db-kpi { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
@media (max-width: 1199px) { .db-kpi { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 575px)  { .db-kpi { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.db-k { background: #101321; border: 1px solid #1c2133; border-top: 2px solid var(--c); border-radius: 10px; padding: 8px 10px; min-width: 0; }
.db-k .l { font-size: 10.5px; font-weight: 700; color: var(--c); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-k .v { font-size: 17px; font-weight: 800; color: #f8fafc; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-k .s { font-size: 10.5px; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-k .s b { color: #cbd5e1; font-weight: 700; }

.db-row { display: grid; gap: 10px; margin-bottom: 10px; }
.db-2-1 { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
.db-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.db-1-1 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
@media (max-width: 991px) { .db-2-1, .db-3, .db-1-1 { grid-template-columns: minmax(0, 1fr); } }

.db-list { font-size: 11.5px; }
.db-list .r { display: flex; align-items: center; gap: 8px; padding: 5px 0; border-bottom: 1px dashed rgba(255,255,255,0.06); }
.db-list .r:last-child { border-bottom: 0; }
.db-list .n { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #e2e8f0; font-weight: 600; }
.db-list .m { flex-shrink: 0; font-family: ui-monospace, monospace; font-size: 11px; }
.db-bar { height: 5px; border-radius: 3px; background: rgba(255,255,255,0.06); overflow: hidden; margin-top: 2px; }
.db-bar i { display: block; height: 100%; border-radius: 3px; }
.db-tabs { display: flex; gap: 4px; }
.db-tabs button { border: 0; background: transparent; color: #64748b; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
.db-tabs button.on { background: #1e2438; color: #fbbf24; }

.db-feed { max-height: 330px; overflow-y: auto; scrollbar-width: thin; }
.db-feed .f { display: flex; align-items: center; gap: 8px; padding: 5px 2px; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 11.5px; }
.db-feed .ic { width: 24px; height: 24px; border-radius: 7px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; }
.db-feed .tx { flex: 1; min-width: 0; color: #cbd5e1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-feed .tx b { color: #fff; }
.db-feed .t { flex-shrink: 0; font-size: 10px; color: #64748b; font-family: ui-monospace, monospace; }

.db-hours { display: grid; grid-template-columns: repeat(24, minmax(0, 1fr)); gap: 3px; align-items: end; }
.db-hr { text-align: center; }
.db-hr .b { height: 54px; display: flex; align-items: flex-end; justify-content: center; background: rgba(0,0,0,0.2); border-radius: 4px; padding: 2px; }
.db-hr .b i { width: 70%; border-radius: 3px; background: linear-gradient(180deg, #34d399, #059669); }
.db-hr.peak .b i { background: linear-gradient(180deg, #c084fc, #9333ea); }
.db-hr .u { font-size: 10px; font-weight: 800; color: #e2e8f0; margin-top: 2px; }
.db-hr .h { font-size: 9px; color: #64748b; }
@media (max-width: 767px) { .db-hours { grid-template-columns: repeat(12, minmax(0, 1fr)); row-gap: 8px; } }

.db-tiers { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; margin-bottom: 8px; }
.db-tier { background: #0b0e18; border: 1px solid rgba(255,255,255,0.06); border-radius: 9px; padding: 6px 8px; min-width: 0; }
.db-tier .nm { font-size: 10.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.db-tier .ct { font-size: 15px; font-weight: 800; color: #fff; line-height: 1.2; }
.db-tier .pc { font-size: 10px; color: #64748b; }

.db .c-table th, .db .c-table td { padding: 6px 10px; font-size: 11.5px; }
.online-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #10b981; animation: onlinePulse 2s infinite; flex-shrink: 0; }
.offline-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #64748b; flex-shrink: 0; }
@keyframes onlinePulse { 0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.7); } 70% { box-shadow: 0 0 0 5px rgba(16,185,129,0); } 100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); } }
</style>

<div class="db">

<!-- ── Bar atas: judul, antrean pending, periode ── -->
<div class="db-h">
  <div>
    <h4>📊 Dashboard</h4>
    <small>Deposit, trafik, nonton, mancing & jam sibuk — <?= htmlspecialchars($rangeTitle) ?></small>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="#section-online-users" class="db-pill" style="background:rgba(16,185,129,0.1);color:#34d399;border-color:rgba(16,185,129,0.3);"><span class="online-dot"></span><?= $onlineCount ?> online</a>
    <a href="/console/withdrawals.php" class="db-pill" style="background:rgba(244,63,94,0.1);color:#fb7185;border-color:rgba(244,63,94,0.3);">WD pending <b><?= $pendingWd ?></b></a>
    <a href="/console/deposits.php" class="db-pill" style="background:rgba(56,189,248,0.1);color:#7dd3fc;border-color:rgba(56,189,248,0.3);">Depo pending <b><?= $pendingDep ?></b></a>
    <a href="/console/upgrades.php" class="db-pill" style="background:rgba(168,85,247,0.1);color:#d8b4fe;border-color:rgba(168,85,247,0.3);">Upgrade pending <b><?= $pendingUpg ?></b></a>
    <div class="db-seg">
      <?php foreach (['today' => 'Hari Ini', '7' => '7H', '14' => '14H', '30' => '30H'] as $k => $l): ?>
        <a href="?range=<?= $k ?>" class="<?= $range === $k ? 'on' : '' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger py-2" style="border-radius:10px;font-size:12px;"><strong>Error:</strong> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ── KPI ── -->
<div class="db-kpi">
  <div class="db-k" style="--c:#10b981">
    <div class="l">💰 Deposit sukses</div>
    <div class="v"><?= format_rp($totalDepoSuccessAmount) ?></div>
    <div class="s"><b><?= number_format($totalDepoSuccessCount) ?></b> tx · <?= $depoSuccessRate ?>% sukses · <?= $totalDepoPendingCount ?> pending</div>
  </div>
  <div class="db-k" style="--c:#38bdf8">
    <div class="l">🌐 Kunjungan</div>
    <div class="v"><?= number_format($totalViews) ?> <span style="font-size:11px;color:#64748b;">views</span></div>
    <div class="s"><b><?= number_format($totalUniqueVisitors) ?></b> IP · <b><?= number_format($totalActiveUsers) ?></b> user login</div>
  </div>
  <div class="db-k" style="--c:#f59e0b">
    <div class="l">🎬 Nonton video</div>
    <div class="v"><?= number_format($totalWatches) ?></div>
    <div class="s"><b><?= number_format($totalUniqueViewers) ?></b> penonton · reward <b><?= format_rp($totalRewardsGiven) ?></b></div>
  </div>
  <div class="db-k" style="--c:#22d3ee">
    <div class="l">🎣 Mancing</div>
    <div class="v"><?= number_format($fishCasts) ?> <span style="font-size:11px;color:#64748b;">lempar</span></div>
    <div class="s"><b><?= number_format($fishAnglers) ?></b> pemancing · jual <b><?= format_rp($fishSoldValue) ?></b></div>
  </div>
  <div class="db-k" style="--c:#a855f7">
    <div class="l">🔥 Puncak online</div>
    <div class="v"><?= sprintf('%02d:00', $peakOnlineHour) ?> <span style="font-size:11px;color:#64748b;"><?= $peakOnlineCount ?> user</span></div>
    <div class="s">rata-rata <b><?= $avgOnlinePerHour ?></b> user/jam · WIB</div>
  </div>
  <div class="db-k" style="--c:#fbbf24">
    <div class="l">🏦 Total keseluruhan</div>
    <div class="v"><?= format_rp($totalRevenue) ?></div>
    <div class="s"><b><?= number_format($totalUsersAll) ?></b> user · saldo WD <b><?= format_rp($totalUserWd) ?></b></div>
  </div>
</div>

<!-- ── Grafik ── -->
<div class="db-row db-2-1">
  <div class="c-card">
    <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <span class="c-card-title">📈 Tren aktivitas</span>
      <div style="font-size:10.5px;color:#94a3b8;">
        <?php foreach ([['#10b981', 'Deposit'], ['#38bdf8', 'Visitor'], ['#f59e0b', 'Nonton'], ['#22d3ee', 'Mancing']] as [$c, $l]): ?>
          <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:<?= $c ?>;margin:0 3px 0 8px;"></span><?= $l ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="c-card-body"><div style="position:relative;height:210px;"><canvas id="multiTrendChart"></canvas></div></div>
  </div>
  <div class="c-card">
    <div class="c-card-header d-flex align-items-center justify-content-between">
      <span class="c-card-title">🕒 Jam sibuk</span>
      <span class="badge" style="background:#1e2438;color:#a855f7;font-size:10px;">Peak <?= sprintf('%02d:00', $peakHour) ?></span>
    </div>
    <div class="c-card-body"><div style="position:relative;height:210px;"><canvas id="hourlyBarChart"></canvas></div></div>
  </div>
</div>

<!-- ── Mancing · Konten · Live ── -->
<div class="db-row db-3">

  <div class="c-card" id="section-fishing">
    <div class="c-card-header d-flex align-items-center justify-content-between">
      <span class="c-card-title">🎣 Mancing</span>
      <a href="/console/fishing_dashboard.php" style="font-size:11px;color:#22d3ee;text-decoration:none;">Detail &rarr;</a>
    </div>
    <div class="c-card-body">
      <?php if (!empty($fishError)): ?>
        <div class="text-warning" style="font-size:11.5px;">Data mancing belum bisa dimuat.</div>
      <?php else: ?>
        <div class="db-list mb-2">
          <?php if (empty($fishRarity)): ?><div class="text-secondary">Belum ada tangkapan.</div><?php endif; ?>
          <?php foreach ($fishRarity as $r): [$lbl, $col] = $rarityMeta[strtoupper((string)$r['rarity'])] ?? [(string)$r['rarity'], '#94a3b8']; ?>
            <div style="padding:3px 0;">
              <div class="d-flex justify-content-between" style="font-size:11px;"><span style="color:<?= $col ?>;font-weight:700;"><?= htmlspecialchars($lbl) ?></span>
                <span class="text-secondary"><?= number_format((int)$r['cnt']) ?>x · <?= format_rp((float)$r['val']) ?></span></div>
              <div class="db-bar"><i style="width:<?= round($r['cnt'] / $fishRarityMax * 100) ?>%;background:<?= $col ?>;"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap gap-1 mb-2" style="font-size:10.5px;">
          <span class="badge" style="background:rgba(34,211,238,0.1);color:#67e8f9;">Tangkapan <?= format_rp($fishCatchValue) ?></span>
          <span class="badge" style="background:rgba(251,146,60,0.1);color:#fdba74;">Umpan <?= number_format($fishBaitPacks) ?> paket · <?= format_rp($fishBaitRevenue) ?></span>
          <span class="badge" style="background:rgba(168,85,247,0.1);color:#d8b4fe;">Kunci <?= number_format($fishLocks) ?>x (<?= number_format($fishLocksPaid) ?> bayar)</span>
          <span class="badge" style="background:rgba(120,113,108,0.15);color:#a8a29e;">Sampah <?= $fishJunkRate ?>%</span>
        </div>
        <div class="db-list">
          <?php foreach (array_slice($topAnglers, 0, 5) as $i => $a): ?>
            <div class="r"><span class="text-secondary" style="width:14px;"><?= $i + 1 ?></span>
              <a class="n" href="/console/user_detail.php?id=<?= (int)$a['id'] ?>" style="text-decoration:none;"><?= htmlspecialchars($a['username']) ?></a>
              <span class="m" style="color:#22d3ee;"><?= (int)$a['casts'] ?>x</span>
              <span class="m" style="color:#10b981;"><?= format_rp((float)$a['catch_value']) ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="c-card">
    <div class="c-card-header d-flex align-items-center justify-content-between">
      <span class="c-card-title">🏆 Konten teratas</span>
      <div class="db-tabs"><button type="button" class="on" data-tab="tv">Video</button><button type="button" data-tab="tp">Halaman</button></div>
    </div>
    <div class="c-card-body">
      <div class="db-list" id="tv">
        <?php if (empty($topWatchedVideos)): ?><div class="text-secondary">Belum ada video ditonton.</div><?php endif; ?>
        <?php foreach ($topWatchedVideos as $v): ?>
          <div class="r"><span class="n" title="<?= htmlspecialchars($v['title']) ?>"><?= htmlspecialchars($v['title']) ?></span>
            <span class="m" style="color:#f59e0b;"><?= number_format((int)$v['watch_count']) ?>x</span>
            <span class="m" style="color:#10b981;"><?= format_rp((float)$v['rewards_sum']) ?></span></div>
        <?php endforeach; ?>
        <a href="/console/video_analytics.php" style="display:block;margin-top:6px;font-size:11px;color:#fbbf24;text-decoration:none;">Analisis video &rarr;</a>
      </div>
      <div class="db-list" id="tp" hidden>
        <?php if (empty($topPages)): ?><div class="text-secondary">Belum ada kunjungan.</div><?php endif; ?>
        <?php foreach ($topPages as $p): ?>
          <div class="r"><span class="n font-monospace"><?= htmlspecialchars($p['path']) ?></span>
            <span class="m" style="color:#38bdf8;"><?= number_format((int)$p['cnt']) ?></span>
            <span class="m text-secondary"><?= number_format((int)$p['unique_ips']) ?> IP</span></div>
        <?php endforeach; ?>
        <a href="/console/analytics.php" style="display:block;margin-top:6px;font-size:11px;color:#38bdf8;text-decoration:none;">Detail trafik &rarr;</a>
      </div>
    </div>
  </div>

  <div class="c-card">
    <div class="c-card-header d-flex align-items-center justify-content-between">
      <span class="c-card-title"><span class="online-dot me-1"></span> Aktivitas terkini</span>
      <span class="badge" style="background:#1e2438;color:#94a3b8;font-size:10px;"><?= count($activityStream) ?> aksi</span>
    </div>
    <div class="c-card-body py-1">
      <div class="db-feed">
        <?php if (empty($activityStream)): ?><div class="text-secondary py-3 text-center" style="font-size:12px;">Belum ada aktivitas.</div><?php endif; ?>
        <?php foreach ($activityStream as $act):
          $at = $act['act_type']; $u = '<b>' . htmlspecialchars((string)$act['username']) . '</b>'; $amt = format_rp((float)$act['amount']);
          $st = strtoupper((string)($act['status'] ?? ''));
          [$ic, $bg, $txt] = match ($at) {
              'deposit'   => ['💳', 'rgba(16,185,129,0.15)', "$u deposit <span style=\"color:#10b981\">$amt</span> · " . htmlspecialchars((string)$act['status'])],
              'watch'     => ['🎬', 'rgba(245,158,11,0.15)', "$u nonton <span style=\"color:#f59e0b\">+$amt</span> · " . htmlspecialchars((string)($act['extra_info'] ?? ''))],
              'upgrade'   => ['👑', 'rgba(168,85,247,0.15)', "$u upgrade <span style=\"color:#a855f7\">" . htmlspecialchars((string)($act['extra_info'] ?? '')) . "</span> · $amt"],
              'fish'      => $st === 'JUNK'
                  ? ['🥾', 'rgba(120,113,108,0.15)', "$u dapat sampah · " . htmlspecialchars((string)($act['extra_info'] ?? ''))]
                  : ['🎣', 'rgba(34,211,238,0.15)', "$u tangkap <span style=\"color:#22d3ee\">$amt</span> · " . htmlspecialchars((string)($act['extra_info'] ?? '')) . " ($st)"],
              'fish_sale' => ['🏪', 'rgba(16,185,129,0.15)', "$u jual " . (int)$act['extra_info'] . " ikan <span style=\"color:#10b981\">+$amt</span>"],
              'fish_bait' => ['🪱', 'rgba(251,146,60,0.15)', "$u beli umpan <span style=\"color:#fb923c\">$amt</span> · " . htmlspecialchars((string)($act['extra_info'] ?? ''))],
              default     => ['👤', 'rgba(56,189,248,0.15)', "User baru $u · ref " . htmlspecialchars((string)($act['extra_info'] ?? '-'))],
          }; ?>
          <div class="f"><span class="ic" style="background:<?= $bg ?>"><?= $ic ?></span><span class="tx"><?= $txt ?></span>
            <span class="t"><?= date('d/m H:i', strtotime((string)$act['created_at'])) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── User online per jam ── -->
<div class="c-card mb-2">
  <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span class="c-card-title">🕒 User online per jam (WIB)</span>
    <span class="badge" style="background:rgba(168,85,247,0.15);color:#d8b4fe;font-size:10px;">Puncak <?= sprintf('%02d:00', $peakOnlineHour) ?> · <?= $peakOnlineCount ?> user · <?= $peakOnlineHits ?> hits</span>
  </div>
  <div class="c-card-body">
    <div class="db-hours">
      <?php $maxOnlineSlot = max(1, $peakOnlineCount);
      for ($h = 0; $h <= 23; $h++): $uCnt = $hourlyOnline[$h]['unique_users']; $pk = $h === $peakOnlineHour && $uCnt > 0; ?>
        <div class="db-hr <?= $pk ? 'peak' : '' ?>" title="<?= sprintf('%02d:00', $h) ?> · <?= $uCnt ?> user · <?= $hourlyOnline[$h]['page_hits'] ?> hits">
          <div class="b"><i style="height:<?= $uCnt > 0 ? max(6, round($uCnt / $maxOnlineSlot * 100)) : 0 ?>%"></i></div>
          <div class="u"><?= $uCnt ?></div><div class="h"><?= sprintf('%02d', $h) ?></div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- ── Tier keaktifan · User online ── -->
<div class="db-row db-1-1 mt-2">
  <div class="c-card">
    <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <span class="c-card-title">👑 Tier keaktifan</span>
      <span style="font-size:10px;color:#64748b;" title="Pageview 1 · nonton 8 · mancing 4 · deposit 25 · bonus login aktif">skor: view 1 · nonton 8 · mancing 4 · depo 25</span>
    </div>
    <div class="c-card-body">
      <div class="db-tiers">
        <?php foreach ($tierDist as $tier): $pct = $totalTierUsersCount > 0 ? round($tier['count'] / $totalTierUsersCount * 100, 1) : 0; ?>
          <div class="db-tier" title="<?= htmlspecialchars($tier['desc']) ?>"><div class="nm" style="color:<?= $tier['color'] ?>"><?= $tier['icon'] ?> <?= htmlspecialchars($tier['name']) ?></div>
            <div class="ct"><?= number_format($tier['count']) ?></div><div class="pc"><?= $pct ?>%</div></div>
        <?php endforeach; ?>
      </div>
      <div class="db-list">
        <?php $maxPlatformScore = !empty($topPlatformUsers) ? max(1, (int)$topPlatformUsers[0]['activity_score']) : 1;
        foreach ($topPlatformUsers as $i => $pu): $tm = $pu['tier_meta']; $online = (int)($pu['seconds_ago'] ?? 999999) < 300; ?>
          <div class="r">
            <span class="text-secondary" style="width:18px;font-size:10.5px;">#<?= $i + 1 ?></span>
            <a class="n" href="/console/user_detail.php?id=<?= (int)$pu['id'] ?>" style="text-decoration:none;"><?= htmlspecialchars($pu['username']) ?></a>
            <?php if ($online): ?><span class="online-dot"></span><?php endif; ?>
            <span class="m text-secondary" title="views · nonton"><?= (int)$pu['pv_count'] ?>v · <?= (int)$pu['watch_count'] ?>w</span>
            <span class="m" style="color:<?= $tm['color'] ?>;width:62px;text-align:right;"><?= number_format((int)$pu['activity_score']) ?> pts</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="c-card" id="section-online-users">
    <div class="c-card-header d-flex align-items-center justify-content-between">
      <span class="c-card-title"><span class="online-dot me-1"></span> Sedang online (&lt; 15 menit)</span>
      <a href="/console/<?= $rangeQs ?>#section-online-users" style="font-size:11px;color:#94a3b8;text-decoration:none;">🔄 Refresh</a>
    </div>
    <div class="c-card-body p-0">
      <div class="table-responsive" style="max-height:330px;overflow-y:auto;">
        <table class="c-table no-datatable mb-0">
          <thead><tr><th>User</th><th>Status</th><th>Halaman</th><th class="text-end">Saldo WD / Dep</th></tr></thead>
          <tbody>
            <?php if (empty($onlineUsers)): ?>
              <tr><td colspan="4" class="text-center text-secondary py-3">Tidak ada user aktif dalam 15 menit terakhir.</td></tr>
            <?php else: foreach ($onlineUsers as $ou):
              $secAgo = (int)($ou['seconds_ago'] ?? 0);
              $pageInfo = format_activity_path($ou['current_path'] ?? null);
              $ago = $secAgo < 60 ? "{$secAgo} dtk" : ($secAgo < 3600 ? max(1, (int)floor($secAgo / 60)) . ' mnt' : (int)floor($secAgo / 3600) . ' jam'); ?>
              <tr>
                <td><a href="/console/user_detail.php?id=<?= (int)$ou['id'] ?>" class="text-white fw-semibold" style="text-decoration:none;"><?= htmlspecialchars($ou['username']) ?></a>
                  <div style="font-size:10px;color:#64748b;"><?= htmlspecialchars($ou['membership_name'] ?: 'Free') ?> · <?= htmlspecialchars(parse_ua_short($ou['user_agent'] ?? null)) ?></div></td>
                <td style="white-space:nowrap;"><?php if ($secAgo < 180): ?><span class="online-dot"></span> <span style="color:#10b981;">live</span><?php else: ?><span class="offline-dot"></span> <span class="text-secondary"><?= $ago ?></span><?php endif; ?></td>
                <td><span class="badge" style="background:<?= $pageInfo['badge'] ?>;color:<?= $pageInfo['color'] ?>;font-size:10px;"><?= $pageInfo['icon'] ?> <?= htmlspecialchars($pageInfo['label']) ?></span></td>
                <td class="text-end font-monospace" style="white-space:nowrap;"><span style="color:#10b981;"><?= format_rp((float)$ou['balance_wd']) ?></span><div style="font-size:10px;color:#38bdf8;"><?= format_rp((float)$ou['balance_dep']) ?></div></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

</div>

<script>
document.querySelectorAll('.db-tabs button').forEach(b => b.addEventListener('click', () => {
  b.parentElement.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
  ['tv', 'tp'].forEach(id => { document.getElementById(id).hidden = id !== b.dataset.tab; });
}));
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // 1. Multi-Trend Chart
  const ctxMulti = document.getElementById('multiTrendChart');
  if (ctxMulti) {
    new Chart(ctxMulti, {
      type: 'line',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
          {
            label: 'Deposit Sukses (Rp)',
            data: <?= json_encode($chartDepoData) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            borderWidth: 2.5,
            fill: true,
            tension: 0.35,
            yAxisID: 'yDeposit',
            pointRadius: 3,
            pointHoverRadius: 6
          },
          {
            label: 'Visitor (Pageviews)',
            data: <?= json_encode($chartViewsData) ?>,
            borderColor: '#38bdf8',
            backgroundColor: 'transparent',
            borderWidth: 2,
            borderDash: [4, 4],
            tension: 0.35,
            yAxisID: 'yCount',
            pointRadius: 2.5,
            pointHoverRadius: 5
          },
          {
            label: 'Video Ditonton',
            data: <?= json_encode($chartWatchData) ?>,
            borderColor: '#f59e0b',
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            yAxisID: 'yCount',
            pointRadius: 2.5,
            pointHoverRadius: 5
          },
          {
            label: 'Lempar Kail',
            data: <?= json_encode($chartFishData) ?>,
            borderColor: '#22d3ee',
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            yAxisID: 'yCount',
            pointRadius: 2.5,
            pointHoverRadius: 5
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.95)',
            titleColor: '#fff',
            bodyColor: '#cbd5e1',
            borderColor: '#334155',
            borderWidth: 1,
            padding: 10,
            callbacks: {
              label: function(context) {
                let label = context.dataset.label || '';
                if (label) label += ': ';
                if (context.datasetIndex === 0) {
                  label += 'Rp ' + Number(context.parsed.y).toLocaleString('id-ID');
                } else {
                  label += Number(context.parsed.y).toLocaleString('id-ID');
                }
                return label;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { color: 'rgba(255,255,255,0.05)' },
            ticks: { color: '#94a3b8', font: { size: 10.5 } }
          },
          yDeposit: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            grid: { color: 'rgba(255,255,255,0.05)' },
            ticks: {
              color: '#10b981',
              font: { size: 10.5 },
              callback: function(val) {
                if (val >= 1000000) return (val/1000000).toFixed(1) + 'jt';
                if (val >= 1000) return (val/1000).toFixed(0) + 'rb';
                return val > 0 ? 'Rp ' + Number(val).toLocaleString('id-ID') : '0';
              }
            }
          },
          yCount: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            ticks: {
              precision: 0,
              color: '#94a3b8',
              font: { size: 10.5 }
            }
          }
        }
      }
    });
  }

  // 2. Hourly Bar Chart (Peak Activity)
  const ctxHourly = document.getElementById('hourlyBarChart');
  if (ctxHourly) {
    const hours = Array.from({length: 24}, (_, i) => i + ':00');
    const hourlyViews = <?= json_encode(array_column($hourlyActivity, 'views')) ?>;
    const hourlyWatches = <?= json_encode(array_column($hourlyActivity, 'watches')) ?>;

    new Chart(ctxHourly, {
      type: 'bar',
      data: {
        labels: hours,
        datasets: [
          {
            label: 'User Online Unik',
            data: <?= json_encode(array_column($hourlyOnline, 'unique_users')) ?>,
            backgroundColor: '#10b981',
            borderRadius: 4,
            stack: 'combined'
          },
          {
            label: 'Nonton Video',
            data: hourlyWatches,
            backgroundColor: '#f59e0b',
            borderRadius: 4,
            stack: 'combined'
          },
          {
            label: 'Mancing',
            data: <?= json_encode(array_column($hourlyActivity, 'fishing')) ?>,
            backgroundColor: '#22d3ee',
            borderRadius: 4,
            stack: 'combined'
          },
          {
            label: 'Page Views',
            data: hourlyViews,
            backgroundColor: 'rgba(56, 189, 248, 0.4)',
            borderRadius: 4,
            stack: 'combined'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: { color: '#94a3b8', boxWidth: 10, font: { size: 10 } }
          },
          tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.95)',
            titleColor: '#fff',
            bodyColor: '#cbd5e1',
            borderColor: '#334155',
            borderWidth: 1
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: '#94a3b8', font: { size: 9 }, maxRotation: 0 }
          },
          y: {
            grid: { color: 'rgba(255,255,255,0.05)' },
            ticks: { color: '#94a3b8', font: { size: 10 } }
          }
        }
      }
    });
  }
});
</script>


<?php require __DIR__ . '/partials/footer.php'; ?>
