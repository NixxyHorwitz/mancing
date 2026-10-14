<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

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

    // ── 4. HOURLY ACTIVITY HEATMAP (00:00 - 23:00) ───────────────────
    $hourlyActivity = array_fill(0, 24, ['watches' => 0, 'deposits' => 0, 'views' => 0]);

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
        $score = ($act['watches'] * 2) + ($act['deposits'] * 5) + $act['views'];
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

    if ($range === 'today') {
        // Mode today: Breakdown 24 jam
        for ($h = 0; $h <= 23; $h++) {
            $label = sprintf("%02d:00", $h);
            $chartLabels[] = $label;
            $chartDepoData[] = (float)($hourlyActivity[$h]['deposits'] ?? 0);
            $chartViewsData[] = (int)($hourlyActivity[$h]['views'] ?? 0);
            $chartWatchData[] = (int)($hourlyActivity[$h]['watches'] ?? 0);
        }
    } else {
        // Mode N days
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartLabels[] = date('d/m', strtotime($date));
            $chartDepoData[] = (float)($depoDailyMap[$date]['total_nominal'] ?? 0);
            $chartViewsData[] = (int)($pvDailyMap[$date]['views'] ?? 0);
            $chartWatchData[] = (int)($watchDailyMap[$date]['cnt'] ?? 0);
        }
    }

} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Pusat Analisis & Aktivitas';
$activePage = 'activity_analytics';
require __DIR__ . '/partials/header.php';
?>

<style>
@keyframes onlinePulse {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.online-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10b981;
  animation: onlinePulse 2s infinite;
  flex-shrink: 0;
}
.offline-dot {
  display: inline-block;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #64748b;
  flex-shrink: 0;
}
</style>

<!-- Header Toolbar -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:24px;">📊</span>
      <h4 class="mb-0 fw-bold text-white">Pusat Analisis &amp; Degup Aktivitas Web</h4>
      <span class="badge" style="background:rgba(245,158,11,0.2);color:#f59e0b;border:1px solid rgba(245,158,11,0.35);font-size:11px;padding:4px 8px;">
        ⭐ Master Analytics Hub
      </span>
    </div>
    <div style="font-size:12.5px;color:#94a3b8;margin-top:4px;">
      Visualisasi komprehensif performa deposit omset, trafik pengunjung, aktivitas nonton video, dan jam sibuk platform.
    </div>
  </div>

  <div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="#section-online-users" class="btn btn-sm d-flex align-items-center gap-2" style="background:rgba(16,185,129,0.12);color:#34d399;border:1px solid rgba(16,185,129,0.3);border-radius:10px;font-size:12px;font-weight:700;padding:5px 12px;text-decoration:none;" title="Lihat daftar user yang sedang online">
      <span class="online-dot"></span> <?= $onlineCount ?> User Sedang Online
    </a>

    <!-- Range Filter Buttons -->
    <div class="d-flex align-items-center gap-1 p-1" style="background:#121524;border-radius:10px;border:1px solid #1f2438;">
      <span class="text-secondary small px-2 fw-semibold" style="font-size:11px;">Periode:</span>
      <a href="?range=today" class="btn btn-sm <?= $range==='today'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">Hari Ini</a>
      <a href="?range=7" class="btn btn-sm <?= $range==='7'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">7 Hari</a>
      <a href="?range=14" class="btn btn-sm <?= $range==='14'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">14 Hari</a>
      <a href="?range=30" class="btn btn-sm <?= $range==='30'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">30 Hari</a>
    </div>
  </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="border-radius:12px;font-size:13px;">
  <strong>Error:</strong> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- 4 PILAR METRIK UTAMA -->
<div class="row g-3 mb-4">
  <!-- 1. DEPOSIT ANALYTICS CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #10b981;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#10b981;">💰 Deposit Sukses</div>
        <div class="c-stat__icon" style="background:rgba(16,185,129,0.15);color:#10b981;font-size:16px;">💳</div>
      </div>
      <div class="c-stat__val" style="color:#10b981;"><?= format_rp($totalDepoSuccessAmount) ?></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary"><?= number_format($totalDepoSuccessCount) ?> tx berhasil</span>
        <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;">Success <?= $depoSuccessRate ?>%</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Avg: <?= format_rp($avgDepoAmount) ?> · <?= $totalDepoPendingCount ?> pending
      </div>
    </div>
  </div>

  <!-- 2. TRAFFIC / VISITOR CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #38bdf8;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#38bdf8;">🌐 Kunjungan Visitor</div>
        <div class="c-stat__icon" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:16px;">👥</div>
      </div>
      <div class="c-stat__val" style="color:#38bdf8;"><?= number_format($totalViews) ?> <span style="font-size:13px;color:#94a3b8;font-weight:600;">Views</span></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary"><?= number_format($totalUniqueVisitors) ?> IP Unik</span>
        <span class="badge" style="background:rgba(56,189,248,0.15);color:#7dd3fc;"><?= number_format($totalActiveUsers) ?> User Login</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Top URL: <?= !empty($topPages[0]['path']) ? htmlspecialchars($topPages[0]['path']) : '/' ?>
      </div>
    </div>
  </div>

  <!-- 3. NONTON & REWARD CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #f59e0b;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#f59e0b;">🎬 Nonton Video</div>
        <div class="c-stat__icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;font-size:16px;">📺</div>
      </div>
      <div class="c-stat__val" style="color:#f59e0b;"><?= number_format($totalWatches) ?> <span style="font-size:13px;color:#94a3b8;font-weight:600;">Ditonton</span></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary"><?= number_format($totalUniqueViewers) ?> penonton</span>
        <span class="badge" style="background:rgba(245,158,11,0.15);color:#fde047;"><?= $avgWatchPerViewer ?> vid/user</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Total Reward: <strong><?= format_rp($totalRewardsGiven) ?></strong>
      </div>
    </div>
  </div>

  <!-- 4. JAM SIBUK & REKOR ONLINE TERBANYAK -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #a855f7;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#a855f7;">🔥 Puncak Online Terbanyak</div>
        <div class="c-stat__icon" style="background:rgba(168,85,247,0.15);color:#a855f7;font-size:16px;">⏰</div>
      </div>
      <div class="c-stat__val" style="color:#a855f7;font-size:22px;"><?= sprintf("%02d:00 - %02d:00", $peakOnlineHour, ($peakOnlineHour+1)%24) ?></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-white fw-bold"><i class="bi bi-people-fill text-info"></i> <?= number_format($peakOnlineCount) ?> user online</span>
        <span class="badge" style="background:rgba(168,85,247,0.15);color:#d8b4fe;">🔥 <?= number_format($peakOnlineHits) ?> hits</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Avg aktif: <strong><?= $avgOnlinePerHour ?></strong> user/jam · Waktu WIB
      </div>
    </div>
  </div>
</div>

<!-- GRAFIK UTAMA: MULTI-AXIS TREND VISUALIZATION -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="c-card h-100">
      <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:18px;">📈</span>
          <span class="c-card-title">Tren Aktivitas Web (Deposit vs Visitor vs Nonton) — <?= htmlspecialchars($rangeTitle) ?></span>
        </div>
        <div style="font-size:11px;color:#94a3b8;">
          <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#10b981;margin-right:3px;"></span> Deposit
          <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#38bdf8;margin-left:8px;margin-right:3px;"></span> Visitor
          <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#f59e0b;margin-left:8px;margin-right:3px;"></span> Nonton
        </div>
      </div>
      <div class="c-card-body">
        <div style="position:relative;height:280px;width:100%;">
          <canvas id="multiTrendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- JAM SIBUK / HOURLY BAR CHART -->
  <div class="col-lg-4">
    <div class="c-card h-100">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:18px;">🕒</span>
          <span class="c-card-title">Pola Jam Sibuk (24 Jam)</span>
        </div>
        <span class="badge" style="background:#1e2438;color:#a855f7;font-size:10px;">Peak: <?= sprintf("%02d:00", $peakHour) ?></span>
      </div>
      <div class="c-card-body">
        <div style="position:relative;height:280px;width:100%;">
          <canvas id="hourlyBarChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- BREAKDOWN SECTION & LIVE ACTIVITY FEED -->
<div class="row g-3 mb-4">
  <!-- TOP KONTEN & HALAMAN -->
  <div class="col-lg-5">
    <div class="c-card mb-3">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title">🎬 Top Video Ditonton (<?= htmlspecialchars($rangeTitle) ?>)</span>
        <a href="/console/video_analytics.php" class="btn btn-sm btn-link text-warning p-0" style="font-size:11.5px;text-decoration:none;">Lihat Semua &rarr;</a>
      </div>
      <div class="c-card-body p-0">
        <div class="table-responsive">
          <table class="c-table">
            <thead><tr><th>Video</th><th class="text-end">Ditonton</th><th class="text-end">Cuan Keluar</th></tr></thead>
            <tbody>
              <?php if (empty($topWatchedVideos)): ?>
              <tr><td colspan="3" class="text-center text-secondary py-3" style="font-size:12px;">Belum ada video ditonton pada periode ini.</td></tr>
              <?php else: foreach ($topWatchedVideos as $v): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-white text-truncate" style="max-width:180px;font-size:12px;" title="<?= htmlspecialchars($v['title']) ?>">
                    <?= htmlspecialchars($v['title']) ?>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">ID #<?= $v['id'] ?></div>
                </td>
                <td class="text-end font-monospace fw-bold text-warning" style="font-size:12px;"><?= number_format((int)$v['watch_count']) ?>x</td>
                <td class="text-end font-monospace text-emerald" style="color:#10b981;font-size:12px;"><?= format_rp((float)$v['rewards_sum']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TOP HALAMAN VISITOR -->
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title">🌐 Top Halaman Dikunjungi</span>
        <a href="/console/analytics.php" class="btn btn-sm btn-link text-info p-0" style="font-size:11.5px;text-decoration:none;">Traffic Detail &rarr;</a>
      </div>
      <div class="c-card-body p-0">
        <div class="table-responsive">
          <table class="c-table">
            <thead><tr><th>Path / Halaman</th><th class="text-end">Views</th><th class="text-end">IP Unik</th></tr></thead>
            <tbody>
              <?php if (empty($topPages)): ?>
              <tr><td colspan="3" class="text-center text-secondary py-3" style="font-size:12px;">Belum ada kunjungan tercatat.</td></tr>
              <?php else: foreach ($topPages as $p): ?>
              <tr>
                <td>
                  <span class="font-monospace text-white fw-semibold" style="font-size:12px;"><?= htmlspecialchars($p['path']) ?></span>
                </td>
                <td class="text-end font-monospace text-info fw-bold" style="font-size:12px;"><?= number_format((int)$p['cnt']) ?></td>
                <td class="text-end font-monospace text-secondary" style="font-size:12px;"><?= number_format((int)$p['unique_ips']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- LIVE RECENT ACTIVITY FEED -->
  <div class="col-lg-7">
    <div class="c-card h-100">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
          <span class="c-card-title">Degup Aktivitas Web Terkini (Live Activity Stream)</span>
        </div>
        <span class="badge" style="background:#1e2438;color:#94a3b8;font-size:10px;">30 Aksi Terakhir</span>
      </div>
      <div class="c-card-body p-0">
        <div style="max-height:540px;overflow-y:auto;padding:8px 14px;">
          <?php if (empty($activityStream)): ?>
          <div class="text-center text-secondary py-4" style="font-size:12.5px;">Belum ada aktivitas tercatat hari ini.</div>
          <?php else: foreach ($activityStream as $act): 
            $timeAgo = date('H:i:s', strtotime($act['created_at'])) . ' (' . date('d M', strtotime($act['created_at'])) . ')';
          ?>
          <div class="d-flex align-items-center justify-content-between py-2.5 px-2 my-1" style="border-radius:8px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);transition:all .15s ease;">
            <div class="d-flex align-items-center gap-2.5 min-w-0">
              <?php if ($act['act_type'] === 'deposit'): ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(16,185,129,0.15);color:#10b981;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">💳</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    <strong style="color:#fff;"><?= htmlspecialchars($act['username']) ?></strong> deposit 
                    <span style="color:#10b981;font-weight:700;"><?= format_rp((float)$act['amount']) ?></span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">
                    Status: <span class="badge" style="font-size:9px;background:<?= $act['status']==='confirmed'||$act['status']==='approved'?'rgba(16,185,129,0.2);color:#34d399;':'rgba(245,158,11,0.2);color:#fbbf24;' ?>"><?= htmlspecialchars($act['status']) ?></span>
                  </div>
                </div>

              <?php elseif ($act['act_type'] === 'watch'): ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(245,158,11,0.15);color:#f59e0b;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">🎬</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    <strong style="color:#fff;"><?= htmlspecialchars($act['username']) ?></strong> nonton video 
                    <span style="color:#f59e0b;font-weight:700;">+<?= format_rp((float)$act['amount']) ?></span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($act['extra_info'] ?? 'Video') ?>
                  </div>
                </div>

              <?php elseif ($act['act_type'] === 'upgrade'): ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(168,85,247,0.15);color:#a855f7;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">👑</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    <strong style="color:#fff;"><?= htmlspecialchars($act['username']) ?></strong> klaim kasta 
                    <span style="color:#a855f7;font-weight:700;"><?= htmlspecialchars($act['extra_info'] ?? '') ?></span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">
                    Biaya: <?= format_rp((float)$act['amount']) ?>
                  </div>
                </div>

              <?php else: ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(56,189,248,0.15);color:#38bdf8;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">👤</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    Pengguna baru mendaftar: <strong style="color:#38bdf8;"><?= htmlspecialchars($act['username']) ?></strong>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">
                    Kode Ref: <?= htmlspecialchars($act['extra_info'] ?? '-') ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="text-end" style="font-size:11px;color:#64748b;flex-shrink:0;">
              <?= $timeAgo ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── AKUMULASI USER ONLINE PER JAM (24 JAM WIB) ── -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:18px;">🕒</span>
          <span class="c-card-title">Akumulasi User Online Per Jam (00:00 - 23:00 WIB) — <?= htmlspecialchars($rangeTitle) ?></span>
          <span class="badge rounded-pill" style="background:rgba(16,185,129,0.15);color:#34d399;font-size:11px;font-weight:700;">
            🔥 Puncak: <?= sprintf("%02d:00 - %02d:00 WIB", $peakOnlineHour, ($peakOnlineHour+1)%24) ?> (<?= $peakOnlineCount ?> User Online)
          </span>
        </div>
        <div class="d-flex align-items-center gap-3" style="font-size:11px;color:#94a3b8;">
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#10b981;margin-right:4px;"></span> User Unik</span>
          <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:rgba(56,189,248,0.4);margin-right:4px;"></span> Total Hits</span>
        </div>
      </div>
      <div class="c-card-body p-3">
        <!-- 24 Hours Timeline Grid -->
        <div class="d-flex gap-2 overflow-auto pb-2" style="scrollbar-width:thin;">
          <?php 
          $maxOnlineSlot = max(1, $peakOnlineCount);
          for ($h = 0; $h <= 23; $h++): 
            $uCnt = $hourlyOnline[$h]['unique_users'];
            $pHits = $hourlyOnline[$h]['page_hits'];
            $isPeak = ($h === $peakOnlineHour && $uCnt > 0);
            $barHeight = min(100, max(6, round(($uCnt / $maxOnlineSlot) * 100)));
            $hourLabel = sprintf("%02d:00", $h);
          ?>
          <div class="text-center p-2 rounded-3 flex-shrink-0" style="min-width:68px;background:<?= $isPeak ? 'rgba(168,85,247,0.12)' : 'rgba(255,255,255,0.02)' ?>;border:1px solid <?= $isPeak ? '#a855f7' : 'rgba(255,255,255,0.06)' ?>;position:relative;">
            <?php if ($isPeak): ?>
            <div class="position-absolute top-0 start-50 translate-middle">
              <span class="badge bg-warning text-dark px-1 py-0" style="font-size:8px;font-weight:800;">PEAK</span>
            </div>
            <?php endif; ?>
            <div class="small fw-bold text-white mb-1" style="font-size:11px;"><?= $hourLabel ?></div>
            
            <!-- Mini Bar Indicator -->
            <div class="d-flex align-items-end justify-content-center my-2" style="height:48px;background:rgba(0,0,0,0.25);border-radius:4px;padding:2px;">
              <div style="width:18px;height:<?= $barHeight ?>%;background:<?= $isPeak ? 'linear-gradient(180deg, #c084fc, #9333ea)' : ($uCnt > 0 ? 'linear-gradient(180deg, #34d399, #059669)' : 'rgba(255,255,255,0.1)') ?>;border-radius:3px;transition:height 0.3s ease;"></div>
            </div>

            <div class="fw-bold <?= $uCnt > 0 ? ($isPeak ? 'text-warning' : 'text-success') : 'text-secondary' ?>" style="font-size:12px;">
              <?= $uCnt ?> <span style="font-size:9.5px;font-weight:normal;">user</span>
            </div>
            <div class="text-secondary" style="font-size:9.5px;">
              <?= $pHits ?> hits
            </div>
          </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── SEGMENTASI & ANALISIS TIER KEAKTIFAN PENGGUNA ── -->
<div class="row g-3 mb-4">
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:20px;">👑</span>
          <span class="c-card-title">Segmentasi &amp; Analisis Tier Keaktifan Pengguna</span>
          <span class="badge rounded-pill" style="background:rgba(245,158,11,0.2);color:#fbbf24;font-size:11px;">
            Total <?= number_format($totalTierUsersCount) ?> Pengguna Tersegmentasi
          </span>
        </div>
        <div class="text-secondary small" style="font-size:11px;">
          Sistem penilaian otomatis: Pageviews (1 pt) + Misi Nonton (8 pt) + Deposit (25 pt) + Bonus Login Aktif
        </div>
      </div>

      <div class="c-card-body p-3">
        <!-- 5 Tier Stat Cards -->
        <div class="row g-2 mb-3">
          <?php foreach ($tierDist as $tKey => $tier): 
            $pct = $totalTierUsersCount > 0 ? round(($tier['count'] / $totalTierUsersCount) * 100, 1) : 0;
          ?>
          <div class="col-6 col-md">
            <div class="p-3 rounded-3 text-center h-100" style="background:#0b0e18;border:1px solid rgba(255,255,255,0.06);">
              <div class="d-flex align-items-center justify-content-center gap-1 mb-1">
                <span style="font-size:16px;"><?= $tier['icon'] ?></span>
                <span class="fw-bold" style="color:<?= $tier['color'] ?>;font-size:12px;"><?= $tier['name'] ?></span>
              </div>
              <h4 class="mb-0 fw-bold text-white"><?= number_format($tier['count']) ?></h4>
              <div class="text-secondary small mb-1" style="font-size:11px;"><?= $pct ?>% populasi</div>
              <span class="badge" style="background:rgba(255,255,255,0.05);color:#94a3b8;font-size:9.5px;"><?= $tier['desc'] ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Stacked Tier Population Progress Bar -->
        <div class="progress mb-4" style="height:8px;background:#131826;border-radius:4px;overflow:hidden;">
          <?php foreach ($tierDist as $tKey => $tier): 
            $pct = $totalTierUsersCount > 0 ? round(($tier['count'] / $totalTierUsersCount) * 100, 1) : 0;
            if ($pct <= 0) continue;
          ?>
          <div class="progress-bar" role="progressbar" style="width:<?= $pct ?>%;background:<?= $tier['color'] ?>;" title="<?= $tier['name'] ?>: <?= $tier['count'] ?> (<?= $pct ?>%)"></div>
          <?php endforeach; ?>
        </div>

        <!-- Leaderboard Table: Top 10 Bintang Platform -->
        <div class="rounded-3 overflow-hidden" style="border:1px solid #1f2538;background:#0d101d;">
          <div class="p-2.5 px-3 border-bottom d-flex align-items-center justify-content-between" style="border-color:#1c2236 !important;background:#141724;">
            <div class="d-flex align-items-center gap-2">
              <span class="text-warning fw-bold">🏆 Top 10 Bintang Platform Paling Aktif</span>
              <span class="badge" style="background:rgba(255,255,255,0.08);color:#94a3b8;font-size:10px;">Leaderboard Keaktifan</span>
            </div>
            <a href="/console/users.php" class="btn btn-sm btn-link text-warning p-0" style="font-size:11.5px;text-decoration:none;">Buka Manajemen Pengguna &rarr;</a>
          </div>
          <div class="table-responsive">
            <table class="table table-dark table-hover mb-0 align-middle" style="font-size:12px;background:transparent;">
              <thead style="background:#0a0c14;color:#94a3b8;font-size:11px;text-transform:uppercase;">
                <tr>
                  <th style="width:60px;text-align:center;">Rank</th>
                  <th style="min-width:200px;">Pengguna / Akun</th>
                  <th style="min-width:130px;">Tier Keaktifan</th>
                  <th style="min-width:120px;">Skor Platform</th>
                  <th style="min-width:210px;">Rincian Interaksi</th>
                  <th style="min-width:140px;">Status Terakhir</th>
                  <th style="width:110px;text-align:center;">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $maxPlatformScore = !empty($topPlatformUsers) ? max(1, (int)$topPlatformUsers[0]['activity_score']) : 1;
                foreach ($topPlatformUsers as $idx => $pu): 
                  $rNum = $idx + 1;
                  $tMeta = $pu['tier_meta'];
                  $pScore = (int)$pu['activity_score'];
                  $scorePct = min(100, round(($pScore / $maxPlatformScore) * 100));
                  $secAgo = (int)($pu['seconds_ago'] ?? 999999);
                  $isOnlineNow = ($secAgo < 300);
                ?>
                <tr>
                  <td class="text-center">
                    <?php if ($rNum === 1): ?>
                      <span class="badge" style="background:#f59e0b;color:#000;font-weight:800;font-size:12px;">🥇 #1</span>
                    <?php elseif ($rNum === 2): ?>
                      <span class="badge" style="background:#94a3b8;color:#000;font-weight:800;font-size:12px;">🥈 #2</span>
                    <?php elseif ($rNum === 3): ?>
                      <span class="badge" style="background:#d97706;color:#fff;font-weight:800;font-size:12px;">🥉 #3</span>
                    <?php else: ?>
                      <span class="fw-bold text-secondary">#<?= $rNum ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width:28px;height:28px;font-size:11px;background:#1e293b;border:1px solid <?= $tMeta['color'] ?>;">
                        <?= strtoupper(substr($pu['username'], 0, 1)) ?>
                      </div>
                      <div>
                        <div class="fw-bold text-white"><?= htmlspecialchars($pu['username']) ?> <span class="text-secondary fw-normal" style="font-size:10px;">(#<?= (int)$pu['id'] ?>)</span></div>
                        <div class="text-secondary" style="font-size:11px;"><?= htmlspecialchars($pu['email']) ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="badge" style="background:<?= $tMeta['bg'] ?>;color:#fff;font-size:10px;font-weight:600;">
                      <?= $tMeta['icon'] ?> <?= $tMeta['name'] ?>
                    </span>
                  </td>
                  <td>
                    <div class="fw-bold" style="color:<?= $tMeta['color'] ?>;font-size:13px;"><?= number_format($pScore) ?> pts</div>
                    <div class="progress mt-1" style="height:4px;background:#1a1d27;border-radius:2px;">
                      <div class="progress-bar" role="progressbar" style="width:<?= $scorePct ?>%;background:<?= $tMeta['color'] ?>;"></div>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex flex-wrap gap-2 text-secondary" style="font-size:11px;">
                      <span title="Kunjungan Halaman"><i class="bi bi-eye text-info"></i> <?= number_format((int)$pu['pv_count']) ?> views</span>
                      <span title="Misi Video Ditonton"><i class="bi bi-play-circle text-warning"></i> <?= number_format((int)$pu['watch_count']) ?> watch</span>
                      <span title="Total Deposit"><i class="bi bi-wallet2 text-success"></i> Rp <?= number_format((float)$pu['dep_sum'], 0, ',', '.') ?></span>
                    </div>
                  </td>
                  <td>
                    <?php if ($isOnlineNow): ?>
                      <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;font-size:10px;"><span class="online-dot me-1"></span> Online Live</span>
                    <?php else: ?>
                      <span class="badge" style="background:rgba(148,163,184,0.1);color:#94a3b8;font-size:10px;">
                        <?= !empty($pu['last_seen']) ? date('d/m H:i', strtotime($pu['last_seen'])) : '-' ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <div class="d-flex align-items-center justify-content-center gap-1">
                      <a href="/console/user_detail.php?id=<?= $pu['id'] ?>" class="btn btn-sm" style="background:#132e27;color:#6ee7b7;border:1px solid #1e4d41;font-size:10.5px;padding:2px 6px;border-radius:4px;text-decoration:none;" title="Profil">
                        👁️
                      </a>
                      <a href="/console/users.php?q=<?= urlencode($pu['username']) ?>" class="btn btn-sm" style="background:#1e293b;color:#cbd5e1;border:1px solid #334155;font-size:10.5px;padding:2px 6px;border-radius:4px;text-decoration:none;" title="Edit / Kelola">
                        ✏️
                      </a>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<!-- TABEL PENGGUNA SEDANG ONLINE (LIVE REAL-TIME) -->
<div class="row g-3 mb-4" id="section-online-users">
  <div class="col-12">
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span class="online-dot"></span>
          <span class="c-card-title">Pengguna Sedang Online &amp; Berselancar (Live Session)</span>
          <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;border:1px solid rgba(16,185,129,0.3);font-size:11px;font-weight:700;border-radius:12px;padding:3px 9px;">
            <?= $onlineCount ?> User Aktif (&lt; 15 Menit)
          </span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <small class="text-secondary" style="font-size:11px;">Mendeteksi realtime session &amp; halaman terakhir dibuka</small>
          <a href="activity_analytics.php<?= $range !== '7' ? '?range=' . urlencode($range) : '' ?>#section-online-users" class="btn btn-sm" style="background:#1e2438;color:#94a3b8;border:1px solid #2d3652;font-size:11px;border-radius:6px;padding:3px 9px;">
            🔄 Refresh Live
          </a>
        </div>
      </div>
      <div class="c-card-body p-0">
        <div class="table-responsive">
          <table class="c-table no-datatable mb-0">
            <thead>
              <tr>
                <th style="min-width:210px">Pengguna / Akun</th>
                <th style="min-width:170px">Status Keaktifan</th>
                <th style="min-width:220px">Halaman Terakhir Diakses</th>
                <th style="min-width:180px">Saldo (WD / Dep)</th>
                <th style="min-width:190px">Perangkat &amp; IP</th>
                <th style="min-width:140px;text-align:center;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($onlineUsers)): ?>
              <tr>
                <td colspan="6" class="text-center text-secondary py-4" style="font-size:13px;">
                  Tidak ada pengguna yang terdeteksi aktif dalam 15 menit terakhir.
                </td>
              </tr>
              <?php else: foreach ($onlineUsers as $ou): 
                $secAgo = (int)($ou['seconds_ago'] ?? 0);
                $isRealtime = $secAgo < 180; // < 3 menit
                $pageInfo = format_activity_path($ou['current_path'] ?? null);
                $deviceInfo = parse_ua_short($ou['user_agent'] ?? null);
                $exactTime = !empty($ou['last_seen']) ? date('H:i:s', strtotime($ou['last_seen'])) : '-';
                
                if ($secAgo < 60) {
                    $agoText = "{$secAgo} detik lalu";
                } elseif ($secAgo < 3600) {
                    $m = max(1, (int)floor($secAgo / 60));
                    $agoText = "{$m} menit lalu";
                } else {
                    $h = (int)floor($secAgo / 3600);
                    $agoText = "{$h} jam lalu";
                }
              ?>
              <tr>
                <!-- 1. Pengguna -->
                <td>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge" style="background:rgba(255,255,255,0.06);color:#cbd5e1;font-family:monospace;font-size:11px;padding:2px 6px;border-radius:4px;border:1px solid rgba(255,255,255,0.1)">#<?= $ou['id'] ?></span>
                    <strong style="font-size:13px;color:#f8fafc;"><?= htmlspecialchars($ou['username']) ?></strong>
                    <?php if (!empty($ou['membership_name'])): ?>
                      <span class="badge" style="background:rgba(245,158,11,0.15);color:#fbbf24;font-size:9.5px;padding:2px 5px;border-radius:4px;">👑 <?= htmlspecialchars($ou['membership_name']) ?></span>
                    <?php else: ?>
                      <span class="badge b-neutral" style="font-size:9px;padding:2px 5px;border-radius:4px;">🌱 Free</span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:11px;color:#64748b;" title="<?= htmlspecialchars($ou['email']) ?>">
                    ✉️ <?= htmlspecialchars($ou['email']) ?>
                  </div>
                </td>

                <!-- 2. Status Keaktifan -->
                <td>
                  <div class="d-flex align-items-center gap-1.5 mb-1">
                    <?php if ($isRealtime): ?>
                      <span class="online-dot"></span>
                      <span style="color:#10b981;font-weight:700;font-size:11.5px;">Online Sekarang</span>
                    <?php else: ?>
                      <span class="offline-dot"></span>
                      <span style="color:#94a3b8;font-size:11.5px;"><?= $agoText ?></span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:10px;color:#64748b;">
                    🕒 <?= $exactTime ?> WIB
                  </div>
                </td>

                <!-- 3. Halaman Terakhir Diakses -->
                <td>
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge" style="background:<?= $pageInfo['badge'] ?>;color:<?= $pageInfo['color'] ?>;font-size:11px;font-weight:600;padding:3.5px 8px;border-radius:6px;">
                      <?= $pageInfo['icon'] ?> <?= htmlspecialchars($pageInfo['label']) ?>
                    </span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;font-family:monospace;">
                    Path: <?= htmlspecialchars($ou['current_path'] ?? '-') ?>
                  </div>
                </td>

                <!-- 4. Saldo (WD / Dep) -->
                <td>
                  <div style="font-size:12px;font-weight:700;color:#10b981;margin-bottom:2px;">
                    WD: <?= format_rp((float)$ou['balance_wd']) ?>
                  </div>
                  <div style="font-size:11px;color:#38bdf8;">
                    Dep: <?= format_rp((float)$ou['balance_dep']) ?>
                  </div>
                </td>

                <!-- 5. Perangkat & IP -->
                <td>
                  <div style="font-size:11.5px;color:#cbd5e1;margin-bottom:2px;" title="<?= htmlspecialchars($ou['user_agent'] ?? '') ?>">
                    💻 <?= htmlspecialchars($deviceInfo) ?>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;font-family:monospace;">
                    IP: <?= htmlspecialchars($ou['ip_hash'] ?? '-') ?>
                  </div>
                </td>

                <!-- 6. Aksi Cepat -->
                <td style="text-align:center;">
                  <div class="d-flex align-items-center justify-content-center gap-1">
                    <a href="/console/user_detail.php?id=<?= $ou['id'] ?>" class="btn btn-sm" style="background:#132e27;color:#6ee7b7;border:1px solid #1e4d41;font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px;text-decoration:none;" title="Lihat Profil Lengkap">
                      👁️ Detail
                    </a>
                    <a href="/console/users.php?q=<?= $ou['id'] ?>" class="btn btn-sm" style="background:#1e293b;color:#cbd5e1;border:1px solid #334155;font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px;text-decoration:none;" title="Kelola / Edit di Users">
                      ✏️ Edit
                    </a>
                  </div>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

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
