<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0, 'username' => 'Sobat Cuan', 'balance_wd' => 0, 'balance_dep' => 0,
        'membership_id' => null, 'membership_expires_at' => null, 'referral_code' => '-', 'is_promotor' => 0,
        'total_earned' => 0, 'watch_count_today' => 0, 'last_checkin' => null,
    ];
}

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Ambil data terbaru user
if (!$is_guest) {
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$user['id']]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
}

// ── KONFIGURASI HOME CMS (console/home_cms.php) ──
$cms     = home_cms_config($pdo);
$H       = $cms['hero'];
$fish_on = !empty($cms['fishing_engine']['enabled']);
$cms_txt = static fn(string $t): string => str_replace('{username}', (string)($GLOBALS['user']['username'] ?? 'Kapten'), $t);

// ── TUGAS UTAMA: DATA NONTON VIDEO ──
$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);
$watch_pct   = $watch_limit > 0 ? min(100, (int)round(($watch_today / $watch_limit) * 100)) : 0;
$watch_remaining = max(0, $watch_limit - $watch_today);

// Cuan dari Nonton Video Hari Ini
$today_watch_earned = 0.0;
if (!$is_guest) {
    try {
        $twStmt = $pdo->prepare("SELECT COALESCE(SUM(reward_given), 0) FROM watch_history WHERE user_id = ? AND DATE(watched_at) = CURDATE()");
        $twStmt->execute([$user['id']]);
        $today_watch_earned = (float)$twStmt->fetchColumn();
    } catch (\Throwable) {}
}

// Data Referral Pengguna untuk Flex / Promosi
$ref_code = $user['referral_code'] ?? '-';
$ref_url = function_exists('base_url') ? base_url('register/' . ($ref_code !== '-' ? $ref_code : '')) : '/register?ref=' . urlencode($ref_code);

// Video Populer & Cuan Hari Ini
$featured_videos = [];
$feat_limit = max(1, min(20, (int)($cms['featured_videos']['limit'] ?? 4)));
if (!empty($cms['featured_videos']['enabled'])) {
    try {
        $vStmt = $pdo->prepare("
            SELECT v.*,
                   (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id = ? AND wh.video_id = v.id AND DATE(wh.watched_at) = CURDATE()) AS watched_today
            FROM videos v
            WHERE v.is_active = 1
            ORDER BY v.sort_order ASC, v.id DESC
            LIMIT {$feat_limit}
        ");
        $vStmt->execute([$user['id']]);
        $featured_videos = $vStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// Riwayat Tontonan Belum Selesai
$continue_watching_home = [];
$cw_limit = max(1, min(10, (int)($cms['continue_watching']['limit'] ?? 3)));
if (!$is_guest && !empty($cms['continue_watching']['enabled'])) {
    try {
        $pdo->prepare(
            "DELETE uwp FROM user_watch_progress uwp
             JOIN watch_history wh ON wh.user_id = uwp.user_id AND wh.video_id = uwp.video_id AND DATE(wh.watched_at) = CURDATE()
             WHERE uwp.user_id = ?"
        )->execute([$user['id']]);

        $cwHStmt = $pdo->prepare("
            SELECT uwp.seconds_watched, uwp.duration, uwp.last_position,
                   v.id, v.title, v.youtube_id, v.watch_duration, v.reward_amount
            FROM user_watch_progress uwp
            JOIN videos v ON v.id = uwp.video_id
            WHERE uwp.user_id = ? AND v.is_active = 1
            ORDER BY uwp.updated_at DESC
            LIMIT {$cw_limit}
        ");
        $cwHStmt->execute([$user['id']]);
        $continue_watching_home = $cwHStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// Notifikasi Preview
$notif_preview = [];
if (!$is_guest && !empty($cms['notif_inbox']['enabled'])) {
    try {
        $uid = $user['id'];
        $np = $pdo->prepare("
            SELECT n.* FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
            WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
              AND (n.expires_at IS NULL OR n.expires_at > NOW()) ORDER BY n.created_at DESC LIMIT 2
        ");
        $np->execute([$uid, (string)$uid]);
        $notif_preview = $np->fetchAll();
    } catch (\Throwable) {}
}

// Level Membership
$membership_name = '';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn();
}
if (!$membership_name) {
    $membership_name = $pdo->query("SELECT name FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
    if (!$membership_name) $membership_name = 'Member Gratis';
}

// ── STATUS CHECK-IN HARIAN ──
$has_checked_in_today = false;
if (!$is_guest) {
    $has_checked_in_today = (($user['last_checkin'] ?? '') === date('Y-m-d'));
}

// ── KELAYAKAN FITUR UPGRADE ──
// User berpenghasilan lumayan: total_earned >= 1500, balance_wd >= 1500, cuan hari ini >= 1000,
// tonton >= 2 video hari ini, atau sudah berstatus VIP
$user_has_good_income = !$is_guest && (
    (float)($user['total_earned'] ?? 0) >= 1500 || 
    (float)($user['balance_wd'] ?? 0) >= 1500 ||
    $today_watch_earned >= 1000 || 
    $watch_today >= 2 || 
    (int)($user['watch_count_today'] ?? 0) >= 2 ||
    (!empty($user['membership_id']) && (int)$user['membership_id'] > 1)
);

// Menu upgrade ditampilkan jika sudah berpenghasilan lumayan atau sudah mulai aktif
$show_upgrade_feature = $user_has_good_income || (!$is_guest && (float)($user['total_earned'] ?? 0) > 0);

// ── PENGATURAN POPUP ──
$popup_enabled     = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title       = setting($pdo, 'popup_title', 'Informasi Spesial MancingCuan');
$popup_body        = setting($pdo, 'popup_body', 'Biar makin lancar dapat reward, yuk tonton video pilihan dan dapatkan bonus harian!');
$popup_cta_text    = setting($pdo, 'popup_cta_text', 'Buka Sekarang');
$popup_cta_url     = setting($pdo, 'popup_cta_url', '/panduan');
$popup_delay       = max(200, (int) setting($pdo, 'popup_delay', '500'));
$popup_reset_hours = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));

// Sanitize tanda tanya (??) dari database yang timbul akibat karakter emoji rusak
$popup_title    = trim(preg_replace('/^\?+\s*/', '', (string)$popup_title));
$popup_cta_text = trim(preg_replace('/^\?+\s*/', '', (string)$popup_cta_text));
$popup_body     = trim(str_replace('??', '', (string)$popup_body));

// Jika popup promosi mengarah ke /upgrade tetapi user belum menghasilkan cuan lumayan,
// sembunyikan popup upgrade ini agar user baru tidak kabur/kaget, biarkan mereka fokus tonton/absen
if ($popup_enabled && str_contains($popup_cta_url, 'upgrade') && !$show_upgrade_feature) {
    $popup_enabled = false;
}

// ── KAMPANYE PROMOSI THREADS ──
$threads_campaign_enabled = setting($pdo, 'threads_campaign_enabled', '1') === '1';
$threads_step1_reward     = (float)setting($pdo, 'threads_campaign_reward', '25000');
$threads_step2_reward     = (float)setting($pdo, 'threads_campaign_reward_step2', '50000');

// ── CEK STATUS SURVEI BERHADIAH ──
$user_has_surveyed = false;
if (!$is_guest && !empty($user['id'])) {
    try {
        $survCheck = $pdo->prepare("SELECT 1 FROM user_surveys WHERE user_id = ? LIMIT 1");
        $survCheck->execute([$user['id']]);
        $user_has_surveyed = (bool)$survCheck->fetchColumn();
    } catch (\Throwable) {}
}

$pageTitle = 'Nonton Video & Cuan Harian';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   MANCINGCUAN — VIBRANT LIVING CORAL REEF & FANTASY MARINE BIOTA
   3D Claymorphism compact: border putih polos, glossy top sheen,
   warm inner shadow. Token warna ada di partials/header.php (:root).
   ══════════════════════════════════════════════════════════ */

.fantasy-ocean-stage {
  position: relative;
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 22px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.fantasy-ocean-stage > :not(.ambient-bubble-layer) {
  position: relative;
  z-index: 1;
}

/* ── AMBIENT: gelembung ── */
.ambient-bubble-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 0;
  overflow: hidden;
}
.sea-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.9) 0%, rgba(103, 232, 249, 0.35) 60%, rgba(192, 132, 252, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.7);
  box-shadow: inset 1px 1px 2px #fff, 0 2px 6px rgba(8, 145, 178, 0.18);
  animation: bubbleAscend 9s infinite ease-in;
}
.b-1 { width: 14px; height: 14px; left: 8%;  bottom: -20px; animation-duration: 8s; }
.b-2 { width: 22px; height: 22px; left: 85%; bottom: -30px; animation-duration: 11s; animation-delay: 2.5s; }
.b-3 { width: 10px; height: 10px; left: 45%; bottom: -15px; animation-duration: 7s;  animation-delay: 4s; }
.b-4 { width: 18px; height: 18px; left: 22%; bottom: -25px; animation-duration: 10s; animation-delay: 1.2s; }
.b-5 { width: 12px; height: 12px; left: 68%; bottom: -20px; animation-duration: 9s;  animation-delay: 5s; }
@keyframes bubbleAscend {
  0% { transform: translateY(0) translateX(0) scale(0.85); opacity: 0; }
  15% { opacity: 0.85; }
  50% { transform: translateY(-350px) translateX(12px) scale(1.05); }
  85% { opacity: 0.75; }
  100% { transform: translateY(-750px) translateX(-10px) scale(1.2); opacity: 0; }
}
.kelp-blade {
  transform-box: fill-box;
  transform-origin: 50% 100%;
  animation: kelpSway 5.5s ease-in-out infinite;
}
.kelp-blade:nth-child(2) { animation-duration: 6.8s; animation-delay: -1.5s; }
.kelp-blade:nth-child(3) { animation-duration: 4.9s; animation-delay: -3s; }
@keyframes kelpSway {
  0%, 100% { transform: rotate(-3.5deg); }
  50% { transform: rotate(4deg); }
}

/* ── 1. VAULT SALDO — PANEL SUNSET REEF + TILE AKSI CLAY ── */
.clay-vault-card {
  border: 2px solid #ffffff;
  border-radius: 24px;
  padding: 6px;
  background: var(--reef-card);
  box-shadow:
    0 16px 34px rgba(244, 63, 94, 0.14),
    0 6px 14px rgba(124, 58, 237, 0.10),
    inset 3px 3px 7px rgba(255, 255, 255, 0.95),
    inset -4px -4px 10px rgba(251, 146, 60, 0.16);
}

/* Panel atas: gradasi senja karang (violet → magenta → coral → jingga) */
.vault-hero {
  position: relative;
  overflow: hidden;
  border-radius: 19px;
  padding: 12px 12px 12px 14px;
  min-height: 148px;
  color: #ffffff;
  background:
    radial-gradient(circle at 88% 110%, rgba(254, 240, 138, 0.50) 0, transparent 46%),
    radial-gradient(circle at 0% 0%, rgba(192, 132, 252, 0.55) 0, transparent 50%),
    linear-gradient(135deg, #7c3aed 0%, #c026d3 36%, #f43f5e 70%, #fb923c 100%);
  box-shadow:
    inset 2px 2px 6px rgba(255, 255, 255, 0.35),
    inset -3px -4px 10px rgba(76, 5, 25, 0.25);
}
.vault-hero::before {
  content: '';
  position: absolute;
  top: 5px; left: 14px;
  width: 40%; height: 10px;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0));
  pointer-events: none;
}
.vault-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.vault-greet {
  display: flex;
  align-items: center;
  gap: 7px;
  min-width: 0;
}
.vault-clay-avatar {
  width: 30px;
  height: 30px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.22);
  color: #ffffff;
  font-size: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.45), inset -1.5px -1.5px 3px rgba(76, 5, 25, 0.2);
}
.vault-user-info { display: flex; flex-direction: column; min-width: 0; }
.vault-username-txt {
  font-size: 12px;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: -0.2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.vault-rank-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 999px;
  padding: 1px 7px;
  font-size: 9px;
  font-weight: 900;
  color: #fef08a;
  margin-top: 2px;
}
.vault-today-chip {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: #ffffff;
  color: #e11d48;
  font-size: 10px;
  font-weight: 900;
  border-radius: 999px;
  padding: 3px 9px;
  box-shadow: 0 3px 8px rgba(76, 5, 25, 0.22), inset -1px -1px 2px rgba(244, 63, 94, 0.15);
}

.vault-balance-showcase {
  position: relative;
  z-index: 2;
  margin-top: 12px;
  max-width: 64%;
}
.vault-balance-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 9px;
  font-weight: 900;
  color: rgba(255, 255, 255, 0.88);
  text-transform: uppercase;
  letter-spacing: 0.6px;
}
.vault-live-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #34d399;
  box-shadow: 0 0 0 2px rgba(52, 211, 153, 0.35);
  animation: vaultLive 2s ease-in-out infinite;
}
@keyframes vaultLive {
  0%, 100% { box-shadow: 0 0 0 2px rgba(52, 211, 153, 0.35); }
  50% { box-shadow: 0 0 0 5px rgba(52, 211, 153, 0.1); }
}
.vault-balance-main-num {
  font-size: 28px;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: -0.8px;
  line-height: 1.15;
  font-family: 'Nunito', sans-serif;
  text-shadow: 0 2px 8px rgba(76, 5, 25, 0.30);
  white-space: nowrap;
}
.vault-balance-main-num .rp-prefix {
  font-size: 14px;
  opacity: 0.85;
  margin-right: 3px;
  vertical-align: 4px;
}
.vault-stats-ribbon {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 8px;
}
.vault-stat-capsule {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border-radius: 8px;
  padding: 2px 7px;
  font-size: 9.5px;
  font-weight: 800;
  color: #ffffff;
  background: rgba(255, 255, 255, 0.18);
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.3);
}
.vault-stat-capsule strong { font-weight: 900; color: #fef08a; }

/* Ilustrasi biota di kanan panel */
.vault-hero-art {
  position: absolute;
  right: -2px;
  bottom: -2px;
  width: 140px;
  height: 108px;
  pointer-events: none;
  z-index: 1;
}
.fish-swim {
  transform-box: fill-box;
  transform-origin: center;
  animation: fishSwim 4.5s ease-in-out infinite;
}
@keyframes fishSwim {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  50% { transform: translate(-6px, -4px) rotate(-4deg); }
}
.pearl-glow { animation: pearlPulse 2.8s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
@keyframes pearlPulse {
  0%, 100% { opacity: 0.45; transform: scale(0.9); }
  50% { opacity: 0.95; transform: scale(1.15); }
}
.starfish-wiggle { animation: starWiggle 6s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
@keyframes starWiggle {
  0%, 100% { transform: rotate(-8deg); }
  50% { transform: rotate(10deg); }
}

/* Baris aksi: tile putih clay + ikon berwarna */
.vault-actions-cluster {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  padding: 6px 0 0;
}
.clay-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  height: 42px;
  border-radius: 14px;
  background: #ffffff;
  font-size: 11.5px;
  font-weight: 900;
  text-decoration: none;
  cursor: pointer;
  user-select: none;
  box-shadow:
    0 3px 8px rgba(30, 27, 46, 0.05),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 5px rgba(251, 146, 60, 0.14);
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s;
}
.clay-btn:active {
  transform: translateY(1px) scale(0.97);
  box-shadow: inset 2px 2px 5px rgba(251, 146, 60, 0.22);
}
.clay-btn-ico {
  width: 26px;
  height: 26px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: #ffffff;
  box-shadow:
    0 3px 6px var(--ico-sh, rgba(0, 0, 0, 0.12)),
    inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.6),
    inset -1.5px -1.5px 3px rgba(0, 0, 0, 0.15);
}
.clay-btn--primary { color: #e11d48 !important; }
.clay-btn--primary .clay-btn-ico { background: linear-gradient(145deg, #fda4af, #f43f5e); --ico-sh: rgba(244, 63, 94, 0.35); }
.clay-btn--secondary { color: #059669 !important; }
.clay-btn--secondary .clay-btn-ico { background: linear-gradient(145deg, #6ee7b7, #059669); --ico-sh: rgba(5, 150, 105, 0.32); }
.clay-btn--ghost { color: #9a3412 !important; }
.clay-btn--ghost .clay-btn-ico { background: linear-gradient(145deg, #fef3c7, #fdba74); color: #9a3412; --ico-sh: rgba(251, 146, 60, 0.32); }

/* ── 2. DUA PORTAL BIOTA (MANCING & VIDEO) ── */
.fantasy-portals-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.fantasy-portal-card {
  position: relative;
  border: 2px solid transparent;
  border-radius: 20px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  text-decoration: none;
  color: inherit;
  overflow: hidden;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s;
}
.fantasy-portal-card:active { transform: translateY(2px) scale(0.97); }
.portal-card--fish {
  background:
    linear-gradient(160deg, #ffffff 0%, #fff7ed 60%, #ffe4e6 100%) padding-box,
    var(--reef-border) border-box;
  box-shadow:
    0 12px 26px rgba(244, 63, 94, 0.14),
    inset 2.5px 2.5px 5px #ffffff,
    inset -3px -3px 7px rgba(251, 146, 60, 0.16);
}
.portal-card--watch {
  background:
    linear-gradient(160deg, #ffffff 0%, #faf5ff 60%, #ecfeff 100%) padding-box,
    var(--reef-border) border-box;
  box-shadow:
    0 12px 26px rgba(139, 92, 246, 0.16),
    inset 2.5px 2.5px 5px #ffffff,
    inset -3px -3px 7px rgba(139, 92, 246, 0.12);
}

.portal-top-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 7px;
}
.portal-clay-icon {
  width: 28px;
  height: 28px;
  border-radius: 10px;
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  color: #fff;
  box-shadow:
    0 5px 12px rgba(0, 0, 0, 0.12),
    inset 2px 2px 3px rgba(255, 255, 255, 0.7),
    inset -2px -2px 4px rgba(0, 0, 0, 0.12);
}
.portal-clay-icon--fish  { background: linear-gradient(145deg, #fdba74, #f43f5e); }
.portal-clay-icon--watch { background: linear-gradient(145deg, #c084fc, #8b5cf6 55%, #0891b2); }

.portal-sparkle-tag {
  font-size: 8.5px;
  font-weight: 900;
  text-transform: uppercase;
  padding: 2.5px 8px;
  border-radius: 999px;
  letter-spacing: 0.3px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.portal-card--fish .portal-sparkle-tag  { background: #ffe4e6; color: #be123c; }
.portal-card--watch .portal-sparkle-tag { background: #f3e8ff; color: #7e22ce; }

/* Jendela ilustrasi biota di dalam portal */
.portal-scene {
  position: relative;
  height: 56px;
  border-radius: 13px;
  overflow: hidden;
  margin-bottom: 8px;
  border: 2px solid #ffffff;
  box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.10), 0 3px 8px rgba(0, 0, 0, 0.05);
}
.portal-scene svg { display: block; width: 100%; height: 100%; }
.portal-scene--reef  { background: linear-gradient(180deg, #a5f3fc 0%, #67e8f9 55%, #22d3ee 100%); }
.portal-scene--jelly { background: radial-gradient(circle at 50% 40%, #6d28d9 0%, #3b0764 55%, #164e63 100%); }

.fish-jump-a, .fish-jump-b {
  transform-box: fill-box;
  transform-origin: center;
}
.fish-jump-a { animation: fishJumpA 3.6s ease-in-out infinite; }
.fish-jump-b { animation: fishJumpB 4.2s ease-in-out infinite; animation-delay: -1.6s; }
@keyframes fishJumpA {
  0%, 100% { transform: translate(-4px, 10px) rotate(-28deg); }
  50%      { transform: translate(6px, -6px) rotate(14deg); }
}
@keyframes fishJumpB {
  0%, 100% { transform: translate(4px, 8px) rotate(24deg); }
  50%      { transform: translate(-6px, -5px) rotate(-12deg); }
}
.jelly-bob {
  transform-box: fill-box;
  transform-origin: center;
  animation: jellyBob 4s ease-in-out infinite;
}
.jelly-bob--small { animation-duration: 3.2s; animation-delay: -1.2s; }
@keyframes jellyBob {
  0%, 100% { transform: translateY(3px) scaleY(0.96); }
  50%      { transform: translateY(-4px) scaleY(1.04); }
}
.sparkle-bubble { animation: sparkleTwinkle 2.4s ease-in-out infinite; }
.sparkle-bubble:nth-of-type(2n) { animation-delay: -0.8s; }
.sparkle-bubble:nth-of-type(3n) { animation-delay: -1.6s; }
@keyframes sparkleTwinkle {
  0%, 100% { opacity: 0.25; }
  50% { opacity: 1; }
}

.portal-heading {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 0;
  letter-spacing: -0.2px;
}
.portal-subtext {
  font-size: 10px;
  font-weight: 700;
  color: var(--reef-muted);
  margin-bottom: 7px;
}
.portal-bait-line {
  font-size: 10px;
  font-weight: 900;
  color: #d97706;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.portal-wave-progress {
  width: 100%;
  height: 6px;
  background: #ede9fe;
  border-radius: 999px;
  overflow: hidden;
  margin-bottom: 9px;
  box-shadow: inset 1px 1px 2px rgba(76, 29, 149, 0.15);
}
.portal-wave-fill {
  height: 100%;
  background: linear-gradient(90deg, #34d399, #22d3ee 50%, #a855f7);
  border-radius: 999px;
  transition: width 0.3s;
  box-shadow: 0 0 8px rgba(168, 85, 247, 0.55);
}

.portal-cta-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 9px;
  border-radius: 11px;
  font-size: 10.5px;
  font-weight: 900;
  color: #ffffff;
  border: none;
  transition: filter 0.15s;
}
.portal-card--fish .portal-cta-badge {
  background: linear-gradient(145deg, #fb7185, #f43f5e);
  box-shadow: 0 5px 12px rgba(244, 63, 94, 0.30), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.55), inset -2px -2px 4px rgba(190, 18, 60, 0.3);
}
.portal-card--watch .portal-cta-badge {
  background: linear-gradient(145deg, #c084fc, #8b5cf6);
  box-shadow: 0 5px 12px rgba(139, 92, 246, 0.32), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.55), inset -2px -2px 4px rgba(91, 33, 182, 0.3);
}
.fantasy-portal-card:hover .portal-cta-badge { filter: brightness(1.06); }

/* ── 3. TOKEN LAYANAN KARANG (5 CLAY TOKENS) ── */
.reef-services-deck {
  border: 2px solid transparent;
  border-radius: 20px;
  padding: 10px 6px 9px;
  background: var(--reef-card) padding-box, var(--reef-border) border-box;
  box-shadow: var(--reef-clay-shadow);
  overflow: hidden;
}
.reef-tokens-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 4px;
  position: relative;
  z-index: 1;
}
.reef-token-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;
  text-decoration: none;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 4px 2px;
  border-radius: 16px;
  font-family: inherit;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
  width: 100%;
}
.reef-token-btn:active { transform: translateY(2px) scale(0.92); }
.clay-token-icon {
  width: 38px;
  height: 38px;
  border-radius: 13px;
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 17px;
  position: relative;
  box-shadow:
    0 7px 14px var(--tk-shadow, rgba(0, 0, 0, 0.1)),
    inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.75),
    inset -2.5px -2.5px 5px rgba(0, 0, 0, 0.14);
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.reef-token-btn:hover .clay-token-icon { transform: translateY(-3px) scale(1.05); }
.clay-token-icon.t-checkin { background: linear-gradient(145deg, #fef08a, #fbbf24 45%, #f59e0b); color: #78350f; --tk-shadow: rgba(245, 158, 11, 0.35); }
.clay-token-icon.t-threads { background: linear-gradient(145deg, #3f3f46, #18181b); color: #fde68a; --tk-shadow: rgba(24, 24, 27, 0.30); }
.clay-token-icon.t-survey  { background: linear-gradient(145deg, #6ee7b7, #10b981 55%, #059669); color: #ffffff; --tk-shadow: rgba(16, 185, 129, 0.35); }
.clay-token-icon.t-invite  { background: linear-gradient(145deg, #fda4af, #fb7185 45%, #f43f5e); color: #ffffff; --tk-shadow: rgba(244, 63, 94, 0.35); }
.clay-token-icon.t-vip     { background: linear-gradient(145deg, #e9d5ff, #c084fc 40%, #8b5cf6); color: #ffffff; --tk-shadow: rgba(139, 92, 246, 0.45); }
.clay-token-icon.t-redeem  { background: linear-gradient(145deg, #f472b6, #ec4899 45%, #be185d); color: #ffffff; --tk-shadow: rgba(236, 72, 153, 0.35); }
.clay-token-icon.t-vip::after {
  content: '';
  position: absolute;
  inset: -3px;
  border-radius: 15px;
  box-shadow: 0 0 12px 2px rgba(192, 132, 252, 0.55);
  animation: vipGlow 2.6s ease-in-out infinite;
  pointer-events: none;
}
@keyframes vipGlow {
  0%, 100% { opacity: 0.35; }
  50% { opacity: 1; }
}
.token-ping {
  position: absolute;
  top: -4px; right: -4px;
  width: 11px; height: 11px;
  border-radius: 50%;
  background: #f43f5e;
  border: 2px solid #fff;
}
.reef-token-label {
  font-size: 9.5px;
  font-weight: 900;
  color: #44403c;
  white-space: nowrap;
}

/* ── 4. VIDEO REKOMENDASI ── */
.fantasy-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4px;
}
.fsh-title {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 6px;
  letter-spacing: -0.3px;
}
.fsh-title-icon {
  width: 22px; height: 22px;
  border-radius: 8px;
  display: inline-flex; align-items: center; justify-content: center;
  background: linear-gradient(145deg, #fef08a, #f59e0b);
  color: #ffffff;
  font-size: 13px;
  border: none;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.35), inset 1px 1px 2px rgba(255, 255, 255, 0.7);
}
.fsh-link {
  font-size: 11px;
  font-weight: 900;
  color: #e11d48;
  text-decoration: none;
  background: #fff1f2;
  padding: 3px 10px;
  border-radius: 999px;
}
.fsh-link:hover { background: #ffe4e6; }

.fantasy-video-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.clay-video-card {
  border: 2px solid transparent;
  border-radius: 18px;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  background: linear-gradient(170deg, #ffffff 0%, #fff7ed 100%) padding-box, var(--reef-border) border-box;
  box-shadow:
    0 10px 22px rgba(244, 63, 94, 0.10),
    0 4px 10px rgba(8, 145, 178, 0.08),
    inset 2px 2px 4px #ffffff;
  display: flex;
  flex-direction: column;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s;
}
.clay-video-card:active { transform: translateY(2px) scale(0.97); }
.clay-video-thumb-wrap {
  width: 100%;
  aspect-ratio: 16 / 9;
  background: linear-gradient(135deg, #3b0764, #164e63);
  position: relative;
  overflow: hidden;
}
.clay-video-thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.clay-reward-pill {
  position: absolute;
  top: 7px;
  left: 7px;
  background: linear-gradient(135deg, #fef08a, #fbbf24);
  color: #78350f;
  font-size: 9.5px;
  font-weight: 900;
  border-radius: 8px;
  padding: 2px 7px;
  border: none;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.45), inset 1px 1px 2px rgba(255, 255, 255, 0.7);
}
.clay-duration-pill {
  position: absolute;
  bottom: 7px;
  right: 7px;
  background: rgba(59, 7, 100, 0.78);
  color: #ffffff;
  font-size: 8.5px;
  font-weight: 800;
  border-radius: 6px;
  padding: 1.5px 6px;
}
.clay-video-body {
  padding: 8px 9px 10px;
  display: flex;
  flex-direction: column;
  flex: 1;
  justify-content: space-between;
}
.clay-video-title-txt {
  font-size: 11.5px;
  font-weight: 800;
  color: var(--reef-ink);
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 8px;
}
.clay-video-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10px;
  font-weight: 900;
  color: #e11d48;
}
.clay-video-footer i {
  width: 20px; height: 20px;
  border-radius: 7px;
  background: linear-gradient(145deg, #fb7185, #f43f5e);
  color: #fff;
  font-size: 10px;
  box-shadow: 0 2px 5px rgba(244, 63, 94, 0.3);
}

/* Toast Notification */
.cuan-toast {
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%) translateY(30px);
  background: linear-gradient(135deg, #3b0764, #1e1b2e);
  color: #fff;
  padding: 10px 18px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 800;
  border: none;
  box-shadow: 0 10px 24px rgba(59, 7, 100, 0.35);
  opacity: 0;
  pointer-events: none;
  transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
  z-index: 10001;
  display: flex;
  align-items: center;
  gap: 7px;
  white-space: nowrap;
}
.cuan-toast.show {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

/* ══════════════════════════════════════════════════════════
   MODAL DIALOGS — CORAL REEF CLAY
   ══════════════════════════════════════════════════════════ */
.amber-modal-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(30, 27, 46, 0.45);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.amber-modal-backdrop.open { display: flex !important; }
.amber-modal-box {
  border: 2px solid transparent;
  background: var(--reef-card) padding-box, var(--reef-border) border-box;
  border-radius: 24px;
  padding: 20px 18px 16px;
  width: 100%;
  max-width: 380px;
  position: relative;
  box-shadow:
    0 22px 50px rgba(244, 63, 94, 0.20),
    0 8px 20px rgba(8, 145, 178, 0.12),
    inset 3px 3px 6px #ffffff,
    inset -4px -4px 9px rgba(251, 146, 60, 0.16);
  text-align: center;
}
.amber-modal-close {
  position: absolute;
  top: 14px; right: 14px;
  background: #ffffff;
  border: none;
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; color: #9a3412;
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(251, 146, 60, 0.15), inset 1px 1px 2px #fff;
}
.amber-modal-badge {
  width: 54px; height: 54px;
  border-radius: 19px;
  background: linear-gradient(145deg, #fda4af, #fb7185 40%, #f43f5e);
  color: #fff;
  border: 2.5px solid #ffffff;
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; margin: 0 auto 14px;
  box-shadow: 0 8px 18px rgba(244, 63, 94, 0.32), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.65), inset -2.5px -2.5px 5px rgba(190, 18, 60, 0.3);
}
.amber-modal-title {
  font-size: 16px; font-weight: 900; color: var(--reef-ink); margin-bottom: 6px; letter-spacing: -0.3px; line-height: 1.3;
}
.amber-modal-body {
  font-size: 12px; font-weight: 700; color: var(--reef-muted); line-height: 1.55; margin-bottom: 18px;
}
.btn-modal-primary {
  background: linear-gradient(145deg, #fda4af 0%, #fb7185 38%, #f43f5e 100%);
  color: #ffffff !important;
  padding: 12px 18px;
  border-radius: 17px;
  text-align: center;
  font-size: 12.5px;
  font-weight: 900;
  text-decoration: none;
  text-shadow: 0 1px 2px rgba(159, 18, 57, 0.4);
  display: flex; align-items: center; justify-content: center; gap: 6px;
  border: 2px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 8px 18px rgba(244, 63, 94, 0.35), inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.6), inset -3px -3px 6px rgba(190, 18, 60, 0.35);
  cursor: pointer; width: 100%;
}
.btn-modal-dismiss {
  background: transparent; border: none; color: #a8a29e; font-size: 11.5px; font-weight: 800; cursor: pointer; padding: 8px; width: 100%; text-align: center;
}

/* Tour Select Modal */
.tour-select-option {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px; border-radius: 18px;
  border: 2px solid transparent;
  background: linear-gradient(160deg, #ffffff, #fff7ed) padding-box, var(--reef-border) border-box;
  cursor: pointer; text-align: left; width: 100%; margin-bottom: 10px;
  font-family: inherit;
  box-shadow: 0 5px 12px rgba(244, 63, 94, 0.08), inset 2px 2px 4px #ffffff;
}
.tour-select-icon {
  width: 40px; height: 40px; border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: #ffffff; flex-shrink: 0;
  border: 2px solid #fff;
}
.tour-select-icon--watch { background: linear-gradient(145deg, #c084fc, #8b5cf6); box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); }
.tour-select-title { font-size: 12.5px; font-weight: 900; color: var(--reef-ink); margin-bottom: 2px; }
.tour-select-desc { font-size: 10px; font-weight: 700; color: var(--reef-muted); line-height: 1.35; }

/* Promo Hub Modal */
.promo-hub-card-item {
  background: #ffffff; border: 2px solid #ffffff; border-radius: 16px;
  padding: 12px 14px; margin-bottom: 10px; text-align: left;
  box-shadow: 0 4px 12px rgba(251, 146, 60, 0.10), inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(251, 146, 60, 0.08);
}
.phc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.phc-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 8.5px; font-weight: 900; padding: 2px 7px; border-radius: 6px; text-transform: uppercase; }
.phc-tag--threads { background: #18181b; color: #fde68a; }
.phc-tag--referral { background: #ffe4e6; color: #be123c; }
.phc-reward { font-size: 10.5px; font-weight: 900; color: #059669; }
.phc-title { font-size: 12.5px; font-weight: 900; color: var(--reef-ink); margin-bottom: 2px; }
.phc-desc { font-size: 10px; font-weight: 700; color: var(--reef-muted); line-height: 1.35; margin-bottom: 8px; }
.phc-caption-box { background: #fff7ed; border: none; border-radius: 12px; padding: 8px 10px; font-size: 9.5px; font-weight: 700; color: #9a3412; line-height: 1.4; margin-bottom: 8px; }
.phc-actions { display: flex; align-items: center; gap: 6px; }
.btn-phc-action { flex: 1; padding: 8px 10px; border-radius: 12px; font-size: 10.5px; font-weight: 900; text-align: center; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: none; cursor: pointer; font-family: inherit; }
.btn-phc-action--primary { background: linear-gradient(145deg, #fb7185, #f43f5e); color: #ffffff !important; border: none; box-shadow: 0 3px 8px rgba(244, 63, 94, 0.28), inset 1px 1px 2px rgba(255, 255, 255, 0.5); }
.btn-phc-action--share { background: linear-gradient(145deg, #34d399, #059669); color: #ffffff !important; border: none; box-shadow: 0 3px 8px rgba(5, 150, 105, 0.28), inset 1px 1px 2px rgba(255, 255, 255, 0.5); }
.btn-phc-action--copy { background: #fff7ed; color: #9a3412 !important; }

/* Mobile kecil */
@media (max-width: 360px) {
  .vault-balance-main-num { font-size: 25px; }
  .vault-hero-art { width: 112px; height: 88px; }
  .reef-token-label { font-size: 9px; }
  .clay-token-icon { width: 34px; height: 34px; }
}
/* ══ Komponen yang dikendalikan CMS (console/home_cms.php) ══ */
.fantasy-portals-grid.is-single { grid-template-columns: 1fr; }
.portal-subtext { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.portal-heading { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.portal-cta-badge span { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.portal-sparkle-tag { max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-deck-title { margin: 0 4px 6px; font-size: 11px; font-weight: 900; color: var(--reef-muted); text-transform: uppercase; letter-spacing: 0.5px; position: relative; z-index: 1; }
.reef-tokens-grid { row-gap: 8px; }
.cms-mascot { display: flex; align-items: flex-start; gap: 8px; padding: 0 2px; }
.cms-mascot-ava { width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: radial-gradient(circle at 40% 35%, #fff, #cffafe); border: 2px solid #fff; box-shadow: 0 5px 12px rgba(8, 145, 178, 0.2); }
.cms-mascot-ava img { width: 34px; height: 34px; object-fit: contain; }
.cms-mascot-bubble { position: relative; flex: 1; padding: 8px 11px; border: 2px solid #fff; border-radius: 4px 16px 16px 16px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); font-size: 11.5px; font-weight: 800; line-height: 1.45; color: var(--reef-ink); }
.cms-strip { display: flex; align-items: center; gap: 9px; width: 100%; padding: 8px 10px; border: 2px solid #fff; border-radius: 18px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); text-align: left; cursor: pointer; font-family: inherit; color: var(--reef-ink); }
.cms-strip > i { flex-shrink: 0; color: var(--reef-muted); font-size: 14px; }
.cms-strip-ic { width: 34px; height: 34px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 17px; color: #fff; box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -2px -2px 4px rgba(0, 0, 0, 0.14); }
.cms-strip--tour .cms-strip-ic { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.cms-strip--ref .cms-strip-ic { background: linear-gradient(145deg, #fda4af, #e11d48); }
.cms-strip-tx { flex: 1; min-width: 0; }
.cms-strip-tx b { display: block; font-size: 12px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-strip-tx small { display: block; font-size: 10px; font-weight: 700; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-ref-code { font-family: ui-monospace, Menlo, Consolas, monospace; letter-spacing: 1.5px; color: #be123c; }
.cms-ref-copy { flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; padding: 6px 11px; border: 2px solid #fff; border-radius: 999px; cursor: pointer; font-family: inherit; font-size: 10.5px; font-weight: 900; color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 4px 10px rgba(225, 29, 72, 0.28); }
.cms-inbox { display: flex; flex-direction: column; border: 2px solid #fff; border-radius: 18px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }
.cms-inbox-row { display: flex; align-items: center; gap: 9px; padding: 7px 10px; text-decoration: none; color: var(--reef-ink); }
.cms-inbox-row + .cms-inbox-row { border-top: 1.5px dashed rgba(251, 146, 60, 0.22); }
.cms-inbox-ic { width: 30px; height: 30px; flex-shrink: 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: #fff; background: linear-gradient(145deg, #fcd34d, #f59e0b); }
.cms-inbox-tx { flex: 1; min-width: 0; }
.cms-inbox-tx b { display: block; font-size: 11.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-inbox-tx small { display: block; font-size: 10px; font-weight: 700; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-inbox-go { color: var(--reef-muted); }
.cms-banners { display: flex; flex-direction: column; gap: 7px; }
.cms-bnr { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 10px; border: 2px solid #fff; border-radius: 18px; text-align: left; text-decoration: none; cursor: pointer; font-family: inherit; color: #fff; box-shadow: 0 8px 18px rgba(30, 27, 46, 0.14), inset 2px 2px 4px rgba(255, 255, 255, 0.3); }
.cms-bnr--survey { background: linear-gradient(135deg, #059669, #34d399); }
.cms-bnr--threads { background: linear-gradient(135deg, #18181b, #3f3f46); }
.cms-bnr--promo { background: linear-gradient(135deg, #7c3aed, #c084fc); }
.cms-bnr-ic { width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 19px; background: rgba(255, 255, 255, 0.2); border: 1.5px solid rgba(255, 255, 255, 0.5); }
.cms-bnr-tx { flex: 1; min-width: 0; }
.cms-bnr-kicker { display: block; font-style: normal; font-size: 8.5px; font-weight: 900; letter-spacing: 0.6px; color: #fde68a; }
.cms-bnr-tx b { display: block; font-size: 12px; font-weight: 900; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-bnr-tx small { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-top: 1px; font-size: 9.5px; font-weight: 700; opacity: 0.92; line-height: 1.3; }
.cms-bnr-side { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.cms-bnr-side em { font-style: normal; padding: 1px 7px; border-radius: 999px; font-size: 9px; font-weight: 900; background: #fde68a; color: #78350f; white-space: nowrap; }
.cms-bnr-cta { padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 900; background: #fff; color: var(--reef-ink); white-space: nowrap; }
.cms-cw-list { display: flex; flex-direction: column; gap: 6px; margin-top: 8px; }
.cms-cw { display: flex; align-items: center; gap: 9px; padding: 6px; border: 2px solid #fff; border-radius: 16px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); text-decoration: none; color: var(--reef-ink); }
.cms-cw-thumb { position: relative; width: 76px; height: 44px; flex-shrink: 0; border-radius: 11px; overflow: hidden; }
.cms-cw-thumb img { width: 100%; height: 100%; object-fit: cover; }
.cms-cw-thumb i { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; background: rgba(8, 51, 68, 0.25); }
.cms-cw-tx { flex: 1; min-width: 0; }
.cms-cw-tx b { display: block; font-size: 11.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-cw-bar { display: block; height: 5px; margin: 4px 0 2px; border-radius: 999px; background: rgba(8, 145, 178, 0.15); overflow: hidden; }
.cms-cw-bar i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #22d3ee, #34d399); }
.cms-cw-tx small { font-size: 9.5px; font-weight: 800; color: var(--reef-muted); }
.cms-sec-desc { margin: 2px 4px 0; font-size: 10.5px; font-weight: 700; color: var(--reef-muted); }
.cms-tips { display: flex; align-items: flex-start; gap: 9px; padding: 9px 11px; border: 2px solid #fff; border-radius: 18px; background: linear-gradient(145deg, #fffbeb, #fef3c7); box-shadow: var(--reef-clay-shadow); font-size: 11px; font-weight: 700; color: #78350f; line-height: 1.45; }
.cms-tips b { display: block; font-size: 11.5px; font-weight: 900; }
.cms-tips-ic { width: 30px; height: 30px; flex-shrink: 0; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #fff; background: linear-gradient(145deg, #fcd34d, #f59e0b); }
.cms-float { position: fixed; right: 14px; bottom: calc(164px + env(safe-area-inset-bottom)); z-index: 900; display: flex; align-items: center; gap: 6px; padding: 5px 10px 5px 5px; max-width: 150px; border: 2px solid #fff; border-radius: 999px; cursor: pointer; font-family: inherit; color: #fff; text-align: left; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 10px 22px rgba(225, 29, 72, 0.35), inset 2px 2px 4px rgba(255, 255, 255, 0.35); animation: cmsFloat 3s ease-in-out infinite; }
.cms-float > i { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; background: rgba(255, 255, 255, 0.22); }
.cms-float b { display: block; font-size: 11px; font-weight: 900; line-height: 1.1; }
.cms-float small { display: block; font-size: 9.5px; font-weight: 800; opacity: 0.92; }
.cms-float em { position: absolute; top: -7px; right: -4px; padding: 0 6px; border-radius: 999px; font-style: normal; font-size: 8.5px; font-weight: 900; background: #fde68a; color: #78350f; border: 1.5px solid #fff; }
@keyframes cmsFloat { 50% { transform: translateY(-4px); } }
@media (prefers-reduced-motion: reduce) {
  .cms-float { animation: none !important; }
}
@media (prefers-reduced-motion: reduce) {
  .sea-bubble, .kelp-blade, .fish-swim, .pearl-glow, .starfish-wiggle,
  .fish-jump-a, .fish-jump-b, .jelly-bob, .sparkle-bubble, .vault-live-dot, .clay-token-icon.t-vip::after {
    animation: none !important;
  }
}
</style>


<div class="fantasy-ocean-stage">

  <!-- Gelembung naik -->
  <div class="ambient-bubble-layer" aria-hidden="true">
    <div class="sea-bubble b-1"></div>
    <div class="sea-bubble b-2"></div>
    <div class="sea-bubble b-3"></div>
    <div class="sea-bubble b-4"></div>
    <div class="sea-bubble b-5"></div>
  </div>

  <!-- ── 1. VAULT SALDO (PANEL SUNSET REEF + TILE AKSI) ── -->
  <?php if ($H['show_user_bar'] || $H['show_balance_card'] || $H['show_quick_actions']): ?>
  <div class="clay-vault-card" id="tour-balance-box">
    <div id="cuan-ss-target">
      <div class="vault-hero">

        <!-- Ilustrasi: ikan badut, karang, kerang mutiara, bintang laut -->
        <svg class="vault-hero-art" viewBox="0 0 140 108" fill="none" aria-hidden="true">
          <defs>
            <linearGradient id="vaultClamGrad" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="#fef3c7"/>
              <stop offset="100%" stop-color="#fdba74"/>
            </linearGradient>
            <radialGradient id="vaultPearlGrad" cx="35%" cy="35%" r="70%">
              <stop offset="0%" stop-color="#ffffff"/>
              <stop offset="55%" stop-color="#fef08a"/>
              <stop offset="100%" stop-color="#f59e0b"/>
            </radialGradient>
            <radialGradient id="vaultPearlHalo" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="#fef08a" stop-opacity="0.95"/>
              <stop offset="100%" stop-color="#fbbf24" stop-opacity="0"/>
            </radialGradient>
          </defs>
          <!-- gelembung -->
          <g fill="#ffffff">
            <circle class="sparkle-bubble" cx="104" cy="14" r="2.4" opacity="0.8"/>
            <circle class="sparkle-bubble" cx="112" cy="6" r="1.5" opacity="0.7"/>
            <circle class="sparkle-bubble" cx="40" cy="56" r="1.6" opacity="0.6"/>
          </g>
          <!-- ikan badut -->
          <g class="fish-swim">
            <g transform="translate(84 32)">
              <path d="M-16 0 L-24 -7 L-23 7 Z" fill="#ea580c"/>
              <path d="M-4 -9 C0 -15, 8 -14, 10 -8 Z" fill="#f97316"/>
              <ellipse cx="0" cy="0" rx="16" ry="10" fill="#fb923c"/>
              <path d="M-6 -9.6 C-4 -3, -4 3, -6 9.6 L-1.5 9.9 C-0.5 3, -0.5 -3, -1.5 -9.9 Z" fill="#ffffff" stroke="#1e1b2e" stroke-opacity="0.35" stroke-width="0.7"/>
              <path d="M6 -8.4 C7.6 -3, 7.6 3, 6 8.4 L9.4 7.2 C10.6 3, 10.6 -3, 9.4 -7.2 Z" fill="#ffffff" stroke="#1e1b2e" stroke-opacity="0.35" stroke-width="0.7"/>
              <ellipse cx="-1" cy="-4.5" rx="7" ry="2.2" fill="#ffffff" opacity="0.45"/>
              <circle cx="12" cy="-2" r="2.3" fill="#ffffff"/><circle cx="12.6" cy="-2.2" r="1.2" fill="#1e1b2e"/>
            </g>
          </g>
          <!-- gundukan pasir -->
          <path d="M14 108 C34 94, 70 90, 140 92 V108 Z" fill="#fed7aa"/>
          <path d="M40 108 C60 100, 96 98, 140 100 V108 Z" fill="#fdba74" opacity="0.6"/>
          <!-- karang bercabang -->
          <g stroke="#fecdd3" stroke-linecap="round" stroke-linejoin="round">
            <path d="M114 98 V78 L104 64 L101 52 M104 64 L111 54 M114 82 L124 68 L122 56 M124 68 L132 62" stroke-width="4.5"/>
            <path d="M134 98 V86 L139 78" stroke-width="3.5"/>
          </g>
          <g fill="#fff1f2">
            <circle cx="101" cy="52" r="2.6"/><circle cx="111" cy="54" r="2.6"/>
            <circle cx="122" cy="56" r="2.6"/><circle cx="132" cy="62" r="2.4"/><circle cx="139" cy="78" r="2.2"/>
          </g>
          <!-- kerang mutiara emas -->
          <g transform="translate(66 98)">
            <circle class="pearl-glow" cx="0" cy="-6" r="13" fill="url(#vaultPearlHalo)"/>
            <path d="M-15 0 A15 10 0 0 0 15 0 Z" fill="url(#vaultClamGrad)" stroke="#f59e0b" stroke-width="1.2"/>
            <g transform="rotate(-24 -15 0)">
              <path d="M-15 0 A15 11 0 0 1 15 0 Z" fill="url(#vaultClamGrad)" stroke="#f59e0b" stroke-width="1.2"/>
              <path d="M0 -9 L-10 -1 M0 -9 L-3.5 0 M0 -9 L3.5 0 M0 -9 L10 -1" stroke="#f59e0b" stroke-width="1" opacity="0.6"/>
            </g>
            <circle cx="1" cy="-4" r="5" fill="url(#vaultPearlGrad)"/>
            <circle cx="-0.6" cy="-5.6" r="1.4" fill="#ffffff"/>
          </g>
          <!-- bintang laut -->
          <g transform="translate(90 100)">
            <g class="starfish-wiggle">
              <path d="M0,-9 L2.4,-3.2 L8.6,-2.8 L3.9,1.3 L5.3,7.3 L0,4 L-5.3,7.3 L-3.9,1.3 L-8.6,-2.8 L-2.4,-3.2 Z"
                    fill="#fb923c" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
              <circle cx="0" cy="0" r="1.1" fill="#fde68a"/>
            </g>
          </g>
        </svg>

        <?php if ($H['show_user_bar']): ?>
        <div class="vault-hero-top">
          <div class="vault-greet">
            <div class="vault-clay-avatar">
              <i class="ph-fill ph-fish"></i>
            </div>
            <div class="vault-user-info">
              <div class="vault-username-txt">
                Halo, <?= htmlspecialchars($user['username'] ?? 'Kapten Cuan') ?>
              </div>
              <div>
                <span class="vault-rank-pill">
                  <i class="ph-fill ph-crown-simple"></i> <?= htmlspecialchars($membership_name ?? 'Kru Nelayan') ?>
                </span>
              </div>
            </div>
          </div>
          <?php if ($H['show_today_stats']): ?>
          <span class="vault-today-chip">
            <i class="ph-bold ph-trend-up"></i> +Rp <?= number_format($today_watch_earned, 0, ',', '.') ?>
          </span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($H['show_balance_card']): ?>
        <div class="vault-balance-showcase">
          <div class="vault-balance-tag">
            <span class="vault-live-dot"></span> Saldo Siap Tarik
          </div>
          <div class="vault-balance-main-num">
            <span class="rp-prefix">Rp</span><?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?>
          </div>
          <?php if ($H['show_today_stats']): ?>
          <div class="vault-stats-ribbon">
            <span class="vault-stat-capsule">
              <i class="ph-bold ph-wallet"></i> Kas <strong>Rp <?= number_format((float)($user['balance_dep'] ?? 0), 0, ',', '.') ?></strong>
            </span>
            <?php if ($fish_on): ?>
            <span class="vault-stat-capsule">
              <i class="ph-fill ph-fish"></i> <strong><?= (int)($user['fishing_baits'] ?? 0) ?></strong> Umpan
            </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($H['show_quick_actions']): ?>
      <div class="vault-actions-cluster">
        <a href="/withdraw" class="clay-btn clay-btn--primary" id="tour-btn-withdraw">
          <span class="clay-btn-ico"><i class="ph-bold ph-arrow-up-right"></i></span> Tarik
        </a>
        <a href="/deposit" class="clay-btn clay-btn--secondary">
          <span class="clay-btn-ico"><i class="ph-bold ph-plus"></i></span> Isi Kas
        </a>
        <a href="/history" class="clay-btn clay-btn--ghost">
          <span class="clay-btn-ico"><i class="ph-bold ph-clock-counter-clockwise"></i></span> Riwayat
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 1b. MASKOT, INBOX, TOUR & REFERRAL (dari CMS) ── -->
  <?php if ($H['show_mascot'] && trim((string)$H['mascot_text']) !== ''): ?>
  <div class="cms-mascot">
    <span class="cms-mascot-ava"><img src="/assets/img/fish/bagus.png" alt="" width="34" height="34"></span>
    <div class="cms-mascot-bubble"><?= htmlspecialchars($cms_txt((string)$H['mascot_text'])) ?></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($cms['notif_inbox']['enabled']) && $notif_preview): ?>
  <div class="cms-inbox">
    <?php foreach ($notif_preview as $nf): ?>
    <a href="/notifications" class="cms-inbox-row">
      <span class="cms-inbox-ic"><i class="ph-fill ph-bell-ringing"></i></span>
      <span class="cms-inbox-tx"><b><?= htmlspecialchars((string)($nf['title'] ?? 'Pengumuman')) ?></b><small><?= htmlspecialchars(mb_strimwidth(strip_tags((string)($nf['message'] ?? $nf['body'] ?? '')), 0, 70, '…')) ?></small></span>
      <i class="ph-bold ph-caret-right cms-inbox-go"></i>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($cms['tour']['show_banner'])): ?>
  <button type="button" class="cms-strip cms-strip--tour" onclick="openTourSelectModal()">
    <span class="cms-strip-ic"><i class="ph-fill ph-compass"></i></span>
    <span class="cms-strip-tx"><b><?= htmlspecialchars((string)$cms['tour']['title']) ?></b><small><?= htmlspecialchars((string)$cms['tour']['desc']) ?></small></span>
    <i class="ph-bold ph-caret-right"></i>
  </button>
  <?php endif; ?>

  <?php if ($H['show_ref_strip'] && !$is_guest && $ref_code !== '-'): ?>
  <div class="cms-strip cms-strip--ref">
    <span class="cms-strip-ic"><i class="ph-fill ph-ticket"></i></span>
    <span class="cms-strip-tx"><small>Kode undanganmu</small><b class="cms-ref-code"><?= htmlspecialchars($ref_code) ?></b></span>
    <button type="button" class="cms-ref-copy" onclick="nToast.copy(<?= htmlspecialchars(json_encode($ref_url), ENT_QUOTES) ?>, 'Link undangan')"><i class="ph-bold ph-copy"></i> Salin</button>
  </div>
  <?php endif; ?>

  <!-- ── 2. DUA PORTAL BIOTA (KOLAM MANCING & BIOSKOP SAMUDRA) ── -->
  <?php
    $FS = $cms['fishing_spotlight'];
    $show_fish_portal  = $fish_on && !empty($FS['enabled']);
    $show_watch_portal = !empty($H['show_watch_cta']);
  ?>
  <?php if ($show_fish_portal || $show_watch_portal): ?>
  <div class="fantasy-portals-grid <?= $show_fish_portal && $show_watch_portal ? '' : 'is-single' ?>">
    <?php if ($show_fish_portal): ?>
    <!-- Portal 1: Mancing Cuan — ikan badut & yellow tang melompat di atas karang -->
    <a href="<?= htmlspecialchars((string)($FS['url'] ?: '/minigame')) ?>" class="fantasy-portal-card portal-card--fish" id="tour-sidejob-card">
      <div>
        <div class="portal-top-bar">
          <div class="portal-clay-icon portal-clay-icon--fish">
            <i class="ph-fill ph-fish-simple"></i>
          </div>
          <span class="portal-sparkle-tag"><i class="ph-fill ph-sparkle"></i> <?= htmlspecialchars((string)($FS['badge'] ?: 'Kolam Cuan')) ?></span>
        </div>
        <div class="portal-scene portal-scene--reef" aria-hidden="true">
          <svg viewBox="0 0 180 74" preserveAspectRatio="xMidYMax slice" fill="none">
            <!-- Sinar matahari bawah air -->
            <path d="M30 0 L48 0 L22 74 L4 74 Z" fill="#ffffff" opacity="0.18"/>
            <path d="M96 0 L108 0 L92 74 L78 74 Z" fill="#ffffff" opacity="0.14"/>
            <path d="M150 0 L160 0 L170 74 L156 74 Z" fill="#ffffff" opacity="0.12"/>
            <!-- Pasir -->
            <path d="M0 64 C30 58, 60 66, 90 61 C120 56, 150 64, 180 60 V74 H0 Z" fill="#fdba74"/>
            <!-- Karang otak emas -->
            <ellipse cx="34" cy="62" rx="14" ry="9" fill="#fbbf24"/>
            <path d="M24 60 C28 56, 32 64, 36 58 C40 54, 42 62, 44 60" stroke="#d97706" stroke-width="1.4" stroke-linecap="round"/>
            <!-- Anemon ungu -->
            <g stroke="#c084fc" stroke-width="3" stroke-linecap="round">
              <path d="M70 64 C68 56, 64 52, 62 46"/><path d="M73 64 C73 55, 72 50, 72 44"/>
              <path d="M76 64 C78 56, 82 52, 84 47"/><path d="M71.5 64 C70 58, 66 56, 66 50"/>
              <path d="M74.5 64 C76 58, 79 55, 80 50"/>
            </g>
            <g fill="#f0abfc"><circle cx="62" cy="46" r="2"/><circle cx="72" cy="44" r="2"/><circle cx="84" cy="47" r="2"/><circle cx="66" cy="50" r="1.7"/><circle cx="80" cy="50" r="1.7"/></g>
            <!-- Karang bercabang coral -->
            <g stroke="#f43f5e" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M140 66 V52 L132 42 M140 55 L148 44 L146 36 M148 44 L155 40"/>
              <path d="M162 66 V56 L168 50"/>
            </g>
            <g fill="#fda4af"><circle cx="132" cy="42" r="2.4"/><circle cx="146" cy="36" r="2.4"/><circle cx="155" cy="40" r="2.2"/><circle cx="168" cy="50" r="2.2"/></g>
            <!-- Rumput laut -->
            <path d="M110 66 C106 58, 114 52, 108 42" stroke="#10b981" stroke-width="3" stroke-linecap="round"/>
            <path d="M116 66 C120 58, 113 54, 118 46" stroke="#34d399" stroke-width="2.5" stroke-linecap="round"/>

            <!-- Ikan badut melompat -->
            <g class="fish-jump-a">
              <g transform="translate(48 26)">
                <path d="M-15 0 L-22 -6 L-21 6 Z" fill="#ea580c"/>
                <ellipse cx="0" cy="0" rx="15" ry="9" fill="#f97316"/>
                <path d="M-5 -8.6 C-3 -3, -3 3, -5 8.6 L-1 8.9 C0 3, 0 -3, -1 -8.9 Z" fill="#ffffff" stroke="#1e1b2e" stroke-opacity="0.4" stroke-width="0.6"/>
                <path d="M6 -7.4 C7.5 -3, 7.5 3, 6 7.4 L9 6.4 C10 3, 10 -3, 9 -6.4 Z" fill="#ffffff" stroke="#1e1b2e" stroke-opacity="0.4" stroke-width="0.6"/>
                <ellipse cx="-1" cy="-4" rx="7" ry="2" fill="#ffffff" opacity="0.4"/>
                <circle cx="11" cy="-2" r="2.1" fill="#ffffff"/><circle cx="11.6" cy="-2.2" r="1.1" fill="#1e1b2e"/>
              </g>
            </g>
            <!-- Yellow tang melompat -->
            <g class="fish-jump-b">
              <g transform="translate(112 22) scale(-1 1)">
                <path d="M-13 0 L-20 -6 L-20 6 Z" fill="#eab308"/>
                <path d="M-6 -9 C0 -16, 8 -12, 10 -6 Z" fill="#facc15"/>
                <path d="M-6 9 C0 16, 8 12, 10 6 Z" fill="#facc15"/>
                <ellipse cx="0" cy="0" rx="13" ry="10.5" fill="#fde047"/>
                <path d="M-11 0 L-8 -1.6 L-8 1.6 Z" fill="#ffffff"/>
                <ellipse cx="1" cy="-5" rx="6" ry="2" fill="#ffffff" opacity="0.5"/>
                <circle cx="8" cy="-2" r="2" fill="#ffffff"/><circle cx="8.5" cy="-2.2" r="1.05" fill="#1e1b2e"/>
                <path d="M12 1.5 L15 2.5" stroke="#ca8a04" stroke-width="1.6" stroke-linecap="round"/>
              </g>
            </g>
            <!-- Percikan gelembung -->
            <g fill="#ffffff">
              <circle class="sparkle-bubble" cx="62" cy="14" r="2"/>
              <circle class="sparkle-bubble" cx="68" cy="8" r="1.3"/>
              <circle class="sparkle-bubble" cx="96" cy="12" r="1.6"/>
              <circle class="sparkle-bubble" cx="126" cy="8" r="1.2"/>
            </g>
          </svg>
        </div>
        <div class="portal-heading"><?= htmlspecialchars((string)($FS['title'] ?: 'Mancing Cuan')) ?></div>
        <div class="portal-subtext"><?= htmlspecialchars((string)($FS['desc'] ?: 'Pancing Ikan Emas Samudra')) ?></div>
      </div>
      <div>
        <div class="portal-bait-line">
          <i class="ph-fill ph-bug"></i> <?= (int)($user['fishing_baits'] ?? 0) ?> Umpan Tersedia
        </div>
        <div class="portal-cta-badge">
          <span><?= htmlspecialchars((string)($FS['cta'] ?: 'Lempar Kail')) ?></span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </div>
    </a>
    <?php endif; ?>

    <?php if ($show_watch_portal): ?>
    <!-- Portal 2: Tonton Video — ubur-ubur magis bercahaya -->
    <a href="/videos" class="fantasy-portal-card portal-card--watch" id="tour-video-mission">
      <div>
        <div class="portal-top-bar">
          <div class="portal-clay-icon portal-clay-icon--watch">
            <i class="ph-fill ph-play-circle"></i>
          </div>
          <span class="portal-sparkle-tag"><i class="ph-fill ph-film-strip"></i> Misi Harian</span>
        </div>
        <div class="portal-scene portal-scene--jelly" aria-hidden="true">
          <svg viewBox="0 0 180 74" preserveAspectRatio="xMidYMid slice" fill="none">
            <defs>
              <radialGradient id="jellyBellGrad" cx="50%" cy="30%" r="70%">
                <stop offset="0%" stop-color="#f5d0fe"/>
                <stop offset="45%" stop-color="#c084fc"/>
                <stop offset="100%" stop-color="#22d3ee"/>
              </radialGradient>
              <radialGradient id="jellyHalo" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#c084fc" stop-opacity="0.65"/>
                <stop offset="60%" stop-color="#22d3ee" stop-opacity="0.18"/>
                <stop offset="100%" stop-color="#22d3ee" stop-opacity="0"/>
              </radialGradient>
              <radialGradient id="jellyPinkGrad" cx="50%" cy="30%" r="70%">
                <stop offset="0%" stop-color="#ffe4e6"/>
                <stop offset="100%" stop-color="#fb7185"/>
              </radialGradient>
            </defs>
            <!-- Plankton -->
            <g fill="#a5f3fc">
              <circle class="sparkle-bubble" cx="14" cy="16" r="1.2"/>
              <circle class="sparkle-bubble" cx="40" cy="58" r="1"/>
              <circle class="sparkle-bubble" cx="160" cy="14" r="1.3"/>
              <circle class="sparkle-bubble" cx="168" cy="54" r="1"/>
              <circle class="sparkle-bubble" cx="120" cy="64" r="1.1"/>
            </g>
            <!-- Ubur-ubur utama -->
            <g class="jelly-bob">
              <circle cx="88" cy="26" r="30" fill="url(#jellyHalo)"/>
              <path d="M66 30 C66 10, 110 10, 110 30 C104 33, 100 29, 95 32 C90 29, 86 33, 81 30 C76 33, 72 29, 66 30 Z" fill="url(#jellyBellGrad)"/>
              <ellipse cx="80" cy="18" rx="7" ry="3" fill="#ffffff" opacity="0.55"/>
              <circle cx="82" cy="24" r="1.6" fill="#3b0764"/><circle cx="94" cy="24" r="1.6" fill="#3b0764"/>
              <path d="M85 27 C87 29, 89 29, 91 27" stroke="#3b0764" stroke-width="1.2" stroke-linecap="round"/>
              <g stroke-width="2" stroke-linecap="round">
                <path d="M72 31 q4 8 0 16 q-4 8 0 16" stroke="#e9d5ff">
                  <animate attributeName="d" dur="2.4s" repeatCount="indefinite" values="M72 31 q4 8 0 16 q-4 8 0 16;M72 31 q-4 8 0 16 q4 8 0 16;M72 31 q4 8 0 16 q-4 8 0 16"/>
                </path>
                <path d="M81 32 q-4 9 0 18 q4 9 0 18" stroke="#67e8f9">
                  <animate attributeName="d" dur="2.8s" repeatCount="indefinite" values="M81 32 q-4 9 0 18 q4 9 0 18;M81 32 q4 9 0 18 q-4 9 0 18;M81 32 q-4 9 0 18 q4 9 0 18"/>
                </path>
                <path d="M90 32 q4 9 0 18 q-4 9 0 18" stroke="#f0abfc">
                  <animate attributeName="d" dur="2.2s" repeatCount="indefinite" values="M90 32 q4 9 0 18 q-4 9 0 18;M90 32 q-4 9 0 18 q4 9 0 18;M90 32 q4 9 0 18 q-4 9 0 18"/>
                </path>
                <path d="M99 32 q-4 8 0 16 q4 8 0 16" stroke="#67e8f9">
                  <animate attributeName="d" dur="2.6s" repeatCount="indefinite" values="M99 32 q-4 8 0 16 q4 8 0 16;M99 32 q4 8 0 16 q-4 8 0 16;M99 32 q-4 8 0 16 q4 8 0 16"/>
                </path>
                <path d="M106 31 q4 7 0 14 q-4 7 0 14" stroke="#e9d5ff">
                  <animate attributeName="d" dur="3s" repeatCount="indefinite" values="M106 31 q4 7 0 14 q-4 7 0 14;M106 31 q-4 7 0 14 q4 7 0 14;M106 31 q4 7 0 14 q-4 7 0 14"/>
                </path>
              </g>
            </g>
            <!-- Ubur-ubur kecil -->
            <g class="jelly-bob jelly-bob--small">
              <circle cx="146" cy="40" r="14" fill="url(#jellyHalo)"/>
              <path d="M136 42 C136 30, 156 30, 156 42 C152 44, 150 41, 146 43 C142 41, 140 44, 136 42 Z" fill="url(#jellyPinkGrad)"/>
              <ellipse cx="142" cy="35" rx="3.5" ry="1.5" fill="#ffffff" opacity="0.6"/>
              <g stroke="#fecdd3" stroke-width="1.4" stroke-linecap="round">
                <path d="M140 43 q2 5 0 10 q-2 5 0 10"/>
                <path d="M146 44 q-2 5 0 10 q2 5 0 10"/>
                <path d="M152 43 q2 5 0 10 q-2 5 0 10"/>
              </g>
            </g>
            <!-- Gelembung berkilau -->
            <g stroke="#a5f3fc" stroke-width="1" fill="#ffffff" fill-opacity="0.15">
              <circle class="sparkle-bubble" cx="30" cy="34" r="4"/>
              <circle class="sparkle-bubble" cx="40" cy="22" r="2.5"/>
              <circle class="sparkle-bubble" cx="34" cy="12" r="1.8"/>
              <circle class="sparkle-bubble" cx="124" cy="22" r="2.6"/>
            </g>
            <!-- Kilau bintang -->
            <path class="sparkle-bubble" d="M22 50 l1.4 3 l3 1.4 l-3 1.4 l-1.4 3 l-1.4 -3 l-3 -1.4 l3 -1.4 Z" fill="#fef08a"/>
            <path class="sparkle-bubble" d="M164 26 l1 2.2 l2.2 1 l-2.2 1 l-1 2.2 l-1 -2.2 l-2.2 -1 l2.2 -1 Z" fill="#f0abfc"/>
          </svg>
        </div>
        <div class="portal-heading">Tonton Video</div>
        <div class="portal-subtext"><?= $H['show_watch_mission'] ? $watch_today . ' / ' . $watch_limit . ' Video Tuntas' : 'Nonton & dapat saldo' ?></div>
      </div>
      <div>
        <?php if ($H['show_watch_mission']): ?>
        <div class="portal-wave-progress">
          <div class="portal-wave-fill" style="width: <?= $watch_pct ?>%;"></div>
        </div>
        <?php endif; ?>
        <div class="portal-cta-badge" id="tour-btn-watch">
          <span><?= htmlspecialchars((string)($H['watch_cta_text'] ?: 'Panen Cuan')) ?></span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </div>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── 3. MENU CEPAT (Bento tiles dari CMS) ── -->
  <?php
    $tiles = array_values(array_filter((array)($cms['bento']['tiles'] ?? []), function ($t) use ($fish_on) {
        return !empty($t['enabled']) && ($fish_on || ($t['id'] ?? '') !== 'fishing');
    }));
    $bento_cnt = count($tiles);
    $bento_cols = ($bento_cnt >= 9 || $bento_cnt === 5) ? 5 : ($bento_cnt === 6 ? 3 : min(4, max(3, $bento_cnt)));
  ?>
  <?php if (!empty($cms['bento']['show_section']) && $tiles): ?>
  <div class="reef-services-deck" id="tour-bento-grid">
    <?php if (trim((string)$cms['bento']['title']) !== ''): ?><div class="cms-deck-title"><?= htmlspecialchars((string)$cms['bento']['title']) ?></div><?php endif; ?>
    <div class="reef-tokens-grid" style="grid-template-columns: repeat(<?= $bento_cols ?>, 1fr)">
      <?php foreach ($tiles as $t):
        $tid = (string)($t['id'] ?? '');
        $ico = '<div class="clay-token-icon t-' . htmlspecialchars($tid, ENT_QUOTES) . '" style="background:' . htmlspecialchars((string)$t['bg'], ENT_QUOTES) . ';color:#fff"><i class="ph-fill ' . htmlspecialchars((string)$t['icon']) . '"></i>'
             . ($tid === 'checkin' && !$has_checked_in_today && !$is_guest ? '<span class="token-ping"></span>' : '') . '</div>';
        $lbl = '<span class="reef-token-label">' . htmlspecialchars($tid === 'checkin' && $has_checked_in_today ? 'Absen ✓' : (string)$t['label']) . '</span>';
      ?>
        <?php if ($tid === 'checkin'): ?>
          <button type="button" class="reef-token-btn" onclick="openCheckinModal()"><?= $ico . $lbl ?></button>
        <?php elseif ($tid === 'threads'): ?>
          <button type="button" class="reef-token-btn" id="tour-threads-promo-card" onclick="openThreadsPromoModal()"><?= $ico . $lbl ?></button>
        <?php else: ?>
          <a href="<?= htmlspecialchars((string)$t['url']) ?>" class="reef-token-btn"><?= $ico . $lbl ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 3b. BANNER (Survei, Promo Hub, Threads) dari CMS ── -->
  <?php $SB = $cms['survey_banner']; $PB = $cms['promo_banner']; $TC = $cms['threads_card'];
        if (setting($pdo, 'survey_enabled', '1') !== '1') $SB['enabled'] = false; // survei ditutup admin ?>
  <?php if (!empty($SB['enabled']) || !empty($PB['enabled']) || !empty($TC['enabled'])): ?>
  <div class="cms-banners">
    <?php if (!empty($SB['enabled'])): ?>
    <a href="/survey" class="cms-bnr cms-bnr--survey">
      <span class="cms-bnr-ic"><i class="ph-fill ph-clipboard-text"></i></span>
      <span class="cms-bnr-tx"><b><?= htmlspecialchars((string)$SB['title']) ?></b><small><?= htmlspecialchars((string)$SB['desc']) ?></small></span>
      <span class="cms-bnr-side"><?php if ($SB['badge']): ?><em><?= htmlspecialchars((string)$SB['badge']) ?></em><?php endif; ?><span class="cms-bnr-cta"><?= htmlspecialchars((string)($SB['cta'] ?: 'Buka')) ?></span></span>
    </a>
    <?php endif; ?>
    <?php if (!empty($TC['enabled'])): ?>
    <button type="button" class="cms-bnr cms-bnr--threads" onclick="openThreadsPromoModal()">
      <span class="cms-bnr-ic"><i class="ph-bold ph-threads-logo"></i></span>
      <span class="cms-bnr-tx"><?php if ($TC['badge_event']): ?><i class="cms-bnr-kicker"><?= htmlspecialchars((string)$TC['badge_event']) ?></i><?php endif; ?><b><?= htmlspecialchars((string)$TC['title']) ?></b><small><?= htmlspecialchars((string)$TC['desc']) ?></small></span>
      <span class="cms-bnr-side"><?php if ($TC['badge_cuan']): ?><em><?= htmlspecialchars((string)$TC['badge_cuan']) ?></em><?php endif; ?><span class="cms-bnr-cta">Ikut</span></span>
    </button>
    <?php endif; ?>
    <?php if (!empty($PB['enabled'])): ?>
    <button type="button" class="cms-bnr cms-bnr--promo" onclick="openPromoHubModal()">
      <span class="cms-bnr-ic"><i class="ph-fill ph-megaphone"></i></span>
      <span class="cms-bnr-tx"><b><?= htmlspecialchars((string)$PB['title']) ?></b><small><?= htmlspecialchars((string)$PB['desc']) ?></small></span>
      <span class="cms-bnr-side"><?php if ($PB['badge']): ?><em><?= htmlspecialchars((string)$PB['badge']) ?></em><?php endif; ?><span class="cms-bnr-cta"><?= htmlspecialchars((string)($PB['cta'] ?: 'Mulai')) ?></span></span>
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── 3c. LANJUTKAN MENONTON (CMS) ── -->
  <?php if (!empty($cms['continue_watching']['enabled']) && $continue_watching_home): ?>
  <div>
    <div class="fantasy-section-head">
      <div class="fsh-title"><span class="fsh-title-icon" style="background:linear-gradient(145deg,#67e8f9,#0891b2)"><i class="ph-fill ph-play"></i></span> <?= htmlspecialchars((string)$cms['continue_watching']['title']) ?></div>
    </div>
    <div class="cms-cw-list">
      <?php foreach ($continue_watching_home as $cw):
        $cwDur = max(1, (int)($cw['watch_duration'] ?: $cw['duration'] ?: 1));
        $cwPct = min(100, (int)round(((float)$cw['seconds_watched'] / $cwDur) * 100));
      ?>
      <a href="/watch/<?= (int)$cw['id'] ?>" class="cms-cw">
        <span class="cms-cw-thumb"><img src="<?= htmlspecialchars(yt_thumb($cw['youtube_id'])) ?>" alt="" loading="lazy"><i class="ph-fill ph-play-circle"></i></span>
        <span class="cms-cw-tx"><b><?= htmlspecialchars((string)$cw['title']) ?></b>
          <span class="cms-cw-bar"><i style="width:<?= $cwPct ?>%"></i></span>
          <small><?= $cwPct ?>% · +<?= format_rp((float)$cw['reward_amount']) ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 4. REKOMENDASI VIDEO CUAN ── -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
  <div>
    <div class="fantasy-section-head">
      <div class="fsh-title">
        <span class="fsh-title-icon"><i class="ph-fill ph-sparkle"></i></span> <?= htmlspecialchars((string)($cms['featured_videos']['title'] ?: 'Rekomendasi Video Cuan')) ?>
      </div>
      <a href="/videos" class="fsh-link">Lihat Semua &rarr;</a>
    </div>
    <?php if (trim((string)$cms['featured_videos']['desc']) !== ''): ?><div class="cms-sec-desc"><?= htmlspecialchars((string)$cms['featured_videos']['desc']) ?></div><?php endif; ?>

    <div class="fantasy-video-grid" style="margin-top:10px;">
      <?php foreach ($featured_videos as $fv): ?>
      <a href="/watch/<?= (int)$fv['id'] ?>" class="clay-video-card">
        <div class="clay-video-thumb-wrap">
          <img src="<?= htmlspecialchars(yt_thumb($fv['youtube_id'])) ?>"
               alt="<?= htmlspecialchars($fv['title']) ?>"
               class="clay-video-thumb-img"
               loading="lazy"
               onerror="this.onerror=null;this.src='https://img.youtube.com/vi/<?= htmlspecialchars($fv['youtube_id']) ?>/hqdefault.jpg'">
          <div class="clay-reward-pill">+<?= format_rp((float)$fv['reward_amount']) ?></div>
          <div class="clay-duration-pill"><?= (int)($fv['watch_duration'] ?? 0) ?>s</div>
        </div>
        <div class="clay-video-body">
          <div class="clay-video-title-txt"><?= htmlspecialchars($fv['title']) ?></div>
          <div class="clay-video-footer">
            <span>Tonton Video</span>
            <i class="ph-fill ph-play"></i>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($cms['tips']['enabled']) && trim((string)$cms['tips']['text']) !== ''): ?>
  <div class="cms-tips">
    <span class="cms-tips-ic"><i class="ph-fill ph-lightbulb"></i></span>
    <span><b><?= htmlspecialchars((string)$cms['tips']['title']) ?></b><?= htmlspecialchars($cms_txt((string)$cms['tips']['text'])) ?></span>
  </div>
  <?php endif; ?>

</div>

<?php if (!empty($cms['floating_promo']['enabled'])): $FP = $cms['floating_promo']; ?>
<button type="button" class="cms-float" onclick="openPromoHubModal()" aria-label="<?= htmlspecialchars((string)$FP['title']) ?>">
  <?php if ($FP['badge']): ?><em><?= htmlspecialchars((string)$FP['badge']) ?></em><?php endif; ?>
  <i class="ph-fill ph-gift"></i>
  <span><b><?= htmlspecialchars((string)$FP['title']) ?></b><small><?= htmlspecialchars((string)$FP['sub']) ?></small></span>
</button>
<?php endif; ?>

<!-- Toast element -->
<div id="cuan-toast" class="cuan-toast"></div>

<!-- ══════════════════════════════════════════════════════════
     MODAL POPUP 1: PENGUMUMAN / PROMO CONSOLE (JIKA AKTIF)
     ══════════════════════════════════════════════════════════ -->
<?php if ($popup_enabled): ?>
<div id="custom-announcement-popup" class="amber-modal-backdrop">
  <div class="amber-modal-box">
    <button type="button" class="amber-modal-close" onclick="closeCustomPopup()">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="amber-modal-badge">
      <i class="ph-fill ph-megaphone-simple"></i>
    </div>
    <h3 class="amber-modal-title"><?= htmlspecialchars($popup_title) ?></h3>
    <p class="amber-modal-body"><?= nl2br(htmlspecialchars($popup_body)) ?></p>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="btn-modal-primary">
        <i class="ph-bold ph-arrow-up-right"></i> <?= htmlspecialchars($popup_cta_text) ?>
      </a>
      <button type="button" onclick="closeCustomPopup()" class="btn-modal-dismiss">Nanti Saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     MODAL POPUP 2: AJAKAN CHECK-IN HARIAN (KOTAK REWARD)
     Muncul setelah pop-up pertama ditutup, atau otomatis jika belum absen
     ══════════════════════════════════════════════════════════ -->
<?php if (!$has_checked_in_today && !$is_guest): ?>
<div id="checkin-prompt-modal" class="amber-modal-backdrop">
  <div class="amber-modal-box">
    <button type="button" class="amber-modal-close" onclick="closeCheckinModal()">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="amber-modal-badge" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;">
      <i class="ph-fill ph-calendar-check"></i>
    </div>
    <h3 class="amber-modal-title">Hadiah Check-in Harian Menantimu!</h3>
    <p class="amber-modal-body">
      Bonus login hari ini sudah siap diklaim! Buka check-in sekarang untuk mendapatkan <strong>saldo tarik tunai</strong> langsung hari ini!
    </p>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="/checkin" class="btn-modal-primary" style="background:linear-gradient(135deg,#10b981,#059669);border:2px solid rgba(255,255,255,0.4);box-shadow:0 10px 24px rgba(5,150,105,0.35), inset 2px 2px 4px rgba(255,255,255,0.5), inset -2px -2px 4px rgba(6,78,59,0.3);text-shadow:0 1px 2px rgba(6,78,59,0.5);">
        <i class="ph-fill ph-sparkle"></i> Klaim Hadiah Check-in Sekarang
      </a>
      <button type="button" onclick="closeCheckinModal()" class="btn-modal-dismiss">Nanti Saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     MODAL POPUP 3: PILIH PANDUAN TOUR INTERAKTIF
     ══════════════════════════════════════════════════════════ -->
<div id="tour-select-modal" class="amber-modal-backdrop">
  <div class="amber-modal-box" style="max-width:360px;">
    <button type="button" class="amber-modal-close" onclick="closeTourSelectModal()">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="amber-modal-badge" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;">
      <i class="ph-fill ph-compass"></i>
    </div>
    <h3 class="amber-modal-title">Panduan Cuan MancingCuan 🧭</h3>
    <p class="amber-modal-body">
      Pelajari cara kerja menu dan fitur cuan dengan panduan interaktif 1 menit:
    </p>

    <!-- Pilihan: Tour Cuan Nonton -->
    <button type="button" class="tour-select-option tour-select-option--watch" onclick="startInteractiveTour('watch')">
      <div class="tour-select-icon tour-select-icon--watch">
        <i class="ph-fill ph-film-strip"></i>
      </div>
      <div>
        <div class="tour-select-title">🎬 Panduan Cuan Nonton Video</div>
        <div class="tour-select-desc">Pelajari kuota harian, pemutar video cuan, check-in gratis, & tarik saldo tunai.</div>
      </div>
    </button>

    <button type="button" onclick="closeTourSelectModal()" class="btn-modal-dismiss">Nanti Saja / Tutup</button>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL POPUP 4: AJAKAN PROMOSI THREADS (EVENT CUAN & PROMOTOR)
     ══════════════════════════════════════════════════════════ -->
<?php if ($threads_campaign_enabled): ?>
<div id="threads-promo-modal" class="amber-modal-backdrop">
  <div class="amber-modal-box" style="background:linear-gradient(145deg,#27272a,#18181b);border:2.5px solid rgba(245,158,11,0.4);box-shadow:0 20px 40px rgba(0,0,0,0.45), inset 2px 2px 6px rgba(255,255,255,0.15), inset -3px -3px 8px rgba(0,0,0,0.5);">
    <button type="button" class="amber-modal-close" onclick="closeThreadsPromoModal()">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="amber-modal-badge" style="background:linear-gradient(145deg,#27272a,#18181b);border:2.5px solid rgba(245,158,11,0.5);color:#fde68a;box-shadow:0 8px 18px rgba(0,0,0,0.35), inset 2px 2px 4px rgba(255,255,255,0.2), inset -2px -2px 4px rgba(0,0,0,0.4);">
      <i class="ph-bold ph-threads-logo" style="font-size:36px;"></i>
    </div>
    <div style="font-size:9.5px;font-weight:900;background:linear-gradient(135deg,#f59e0b,#d97706);color:#78350f;padding:2px 8px;border-radius:8px;display:inline-block;margin-bottom:6px;">
      🔥 EVENT RESMI MANCINGCUAN
    </div>
    <h3 class="amber-modal-title" style="color:#fde68a;">Raih Cuan s/d Rp 75.000 &amp; Gaji Pasti di Threads!</h3>
    <div class="amber-modal-body" style="color:#e2e8f0;font-size:11px;text-align:left;line-height:1.45;margin-bottom:16px;">
      Bagikan ceritamu di Threads dan dapatkan saldo tarik tunai langsung:
      <div style="background:rgba(255,255,255,0.06);border:1px solid rgba(245,158,11,0.25);border-radius:12px;padding:8px 10px;margin-top:8px;">
        <div style="margin-bottom:6px;color:#fde68a;font-weight:800;">
          • <strong>Langkah 1:</strong> Buat postingan cuan di Threads &rarr; Bonus <strong><?= format_rp($threads_step1_reward) ?></strong>.
        </div>
        <div style="color:#f8fafc;font-weight:800;">
          • <strong>Langkah 2:</strong> Postingan tembus <strong>minimal 5K views</strong> &rarr; Bonus <strong><?= format_rp($threads_step2_reward) ?></strong> + <strong>Gaji Pasti &amp; Chat WhatsApp Admin</strong>!
        </div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;">
      <a href="/threads" class="btn-modal-primary" style="background:linear-gradient(135deg,#f59e0b,#d97706);border:2px solid rgba(255,255,255,0.5);color:#78350f;box-shadow:0 10px 22px rgba(217,119,6,0.35), inset 2px 2px 4px rgba(255,255,255,0.6), inset -2px -2px 4px rgba(180,83,9,0.3);">
        <i class="ph-bold ph-threads-logo"></i> Mulai Promosi Threads Sekarang
      </a>
      <button type="button" onclick="closeThreadsPromoModal()" class="btn-modal-dismiss" style="color:#94a3b8;">Nanti Saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     MODAL POPUP: AJAKAN PROMOSI LENGKAP (PROMO HUB MODAL)
     ══════════════════════════════════════════════════════════ -->
<div id="promo-hub-modal" class="amber-modal-backdrop">
  <div class="amber-modal-box" style="max-width:350px;padding:22px 15px 16px;">
    <button type="button" class="amber-modal-close" onclick="closePromoHubModal()">
      <i class="ph-bold ph-x"></i>
    </button>
    
    <div class="amber-modal-badge" style="background:linear-gradient(135deg,#18181b,#27272a);border-color:#f59e0b;color:#fde68a;">
      <i class="ph-bold ph-megaphone" style="font-size:32px;"></i>
    </div>
    
    <div style="font-size:9.5px;font-weight:900;background:linear-gradient(135deg,#f59e0b,#d97706);color:#78350f;padding:2px 8px;border-radius:8px;display:inline-block;margin-bottom:6px;">
      🔥 PELUANG CUAN VIRAL
    </div>
    
    <h3 class="amber-modal-title" style="margin-bottom:3px;font-size:16px;">Ajak &amp; Promosikan MancingCuan! 🚀</h3>
    <p class="amber-modal-body" style="margin-bottom:12px;font-size:10.5px;">
      Pilih cara promosi dan panen saldo tarik tunai instan ke dompetmu:
    </p>

    <!-- Kartu 1: Event Threads -->
    <div class="promo-hub-card-item">
      <div class="phc-header">
        <span class="phc-tag phc-tag--threads"><i class="ph-bold ph-threads-logo"></i> EVENT THREADS</span>
        <span class="phc-reward">+Rp 75.000 &amp; Gaji Pasti</span>
      </div>
      <div class="phc-title">Posting Cerita Cuan di Threads</div>
      <div class="phc-desc">
        Raih <strong>Rp 25.000</strong> di Langkah 1 &amp; raih <strong>Rp 50.000 + Gaji Pasti &amp; Chat Admin WA</strong> jika postinganmu tembus 5K views!
      </div>
      <div class="phc-actions">
        <a href="/threads" class="btn-phc-action btn-phc-action--primary">
          <i class="ph-bold ph-threads-logo"></i>
          <span>Ikuti Event Threads</span>
          <i class="ph-bold ph-arrow-right"></i>
        </a>
      </div>
    </div>

    <!-- Kartu 2: Bagikan Link Referral -->
    <div class="promo-hub-card-item">
      <div class="phc-header">
        <span class="phc-tag phc-tag--referral"><i class="ph-fill ph-users-three"></i> UNDANG TEMAN</span>
        <span class="phc-reward">Komisi Pasif</span>
      </div>
      <div class="phc-title">Sebar Link &amp; Kode Referral</div>
      <div class="phc-desc">
        Ajak teman daftar untuk raih bonus komisi saat teman nonton video.
      </div>
      
      <!-- Template Caption Box -->
      <div class="phc-caption-box">
        🎯 Mau saldo gratis tiap hari? Cobain MancingCuan! Nonton video dibayar uang tunai &amp; selesaikan misi seru. Cuan siap ditarik tiap hari!
        <div style="margin-top:4px;font-weight:900;color:#92400e;">
          Kode Reff: <span style="font-family:monospace;letter-spacing:0.5px;"><?= htmlspecialchars($ref_code) ?></span>
        </div>
      </div>

      <div class="phc-actions">
        <button type="button" class="btn-phc-action btn-phc-action--copy" onclick="copyPromoFullCaption('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')">
          <i class="ph-bold ph-copy"></i>
          <span>Salin Teks</span>
        </button>
        <button type="button" class="btn-phc-action btn-phc-action--share" onclick="shareRefLink('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')">
          <i class="ph-bold ph-share-network"></i>
          <span>Share WA</span>
        </button>
        <a href="/referral" class="btn-phc-action btn-phc-action--primary" style="flex:0.75;">
          <i class="ph-bold ph-users-three"></i>
          <span>Detail</span>
        </a>
      </div>
    </div>

    <button type="button" onclick="closePromoHubModal()" class="btn-modal-dismiss" style="margin-top:4px;">Nanti Saja</button>
  </div>
</div>

<!-- Toast Container for Copy / Share feedback -->
<div id="cuan-toast"></div>

<script>
window.__HAS_CHECKED_IN__ = <?= $has_checked_in_today ? 'true' : 'false' ?>;
window.__IS_GUEST__ = <?= $is_guest ? 'true' : 'false' ?>;

function copyRefCode(code) {
  if (!code || code === '-') {
    showCuanToast('<i class="ph-bold ph-warning-circle"></i> Silakan masuk akun untuk melihat kode referral!');
    return;
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(code).then(() => {
      showCuanToast('<i class="ph-bold ph-check"></i> Kode Referral disalin: ' + code);
    }).catch(() => fallbackCopy(code));
  } else {
    fallbackCopy(code);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try {
    document.execCommand('copy');
    showCuanToast('<i class="ph-bold ph-check"></i> Kode Referral disalin: ' + text);
  } catch (err) {
    showCuanToast('<i class="ph-bold ph-warning-circle"></i> Gagal menyalin kode referral.');
  }
  document.body.removeChild(ta);
}

function shareRefLink(url, code) {
  if (!code || code === '-') {
    window.location.href = '/login';
    return;
  }
  if (navigator.share) {
    navigator.share({
      title: 'MancingCuan - Nonton Video Dapat Cuan!',
      text: 'Yuk raih jutaan rupiah dari nonton video dan selesaikan misi! Gunakan kode referralku: ' + code,
      url: url
    }).catch(() => {});
  } else {
    copyRefCode(code);
  }
}

let toastTimer = null;
function showCuanToast(msg) {
  const t = document.getElementById('cuan-toast');
  if (!t) return;
  t.innerHTML = msg;
  t.classList.add('show');
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    t.classList.remove('show');
  }, 2600);
}

/* ══════════════════════════════════════════════════════════
   PROMO HUB MODAL CONTROLLER & COPY FUNCTIONS
   ══════════════════════════════════════════════════════════ */
function openPromoHubModal() {
  const m = document.getElementById('promo-hub-modal');
  if (!m) return;
  m.style.display = 'flex';
  setTimeout(() => {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 30);
}

function closePromoHubModal() {
  const m = document.getElementById('promo-hub-modal');
  if (m) {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(0.85)'; b.style.opacity = '0'; }
    setTimeout(() => { m.style.display = 'none'; }, 250);
  }
  try {
    sessionStorage.setItem('promo_hub_dismissed', '1');
  } catch(e) {}

  // Lanjutkan rantai ke popup check-in jika belum absen
  setTimeout(triggerCheckinPopupIfEligible, 350);
}

function copyPromoFullCaption(url, code) {
  if (!code || code === '-') {
    showCuanToast('<i class="ph-bold ph-warning-circle"></i> Silakan masuk akun untuk menyalin teks promosi!');
    return;
  }
  const caption = "🎣 Mau saldo gratis tiap hari sambil santai? Cobain MancingCuan! Nonton video berhadiah uang tunai & selesaikan misi harian. Cuan siap ditarik ke DANA/Gopay/OVO/Bank setiap hari!\n\nDaftar sekarang lewat link ini:\n" + url + "\n(Gunakan Kode Referralku: " + code + ")";
  
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(caption).then(() => {
      showCuanToast('<i class="ph-bold ph-check"></i> Teks promosi disalin! Siap dibagikan.');
    }).catch(() => fallbackCopy(caption));
  } else {
    fallbackCopy(caption);
  }
}

/* ══════════════════════════════════════════════════════════
   INTERACTIVE TOUR INTEGRATION & MODAL CONTROLLERS
   ══════════════════════════════════════════════════════════ */
function openTourSelectModal() {
  const m = document.getElementById('tour-select-modal');
  if (!m) return;
  m.style.display = 'flex';
  setTimeout(() => {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 30);
}

function closeTourSelectModal() {
  const m = document.getElementById('tour-select-modal');
  if (m) {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(0.85)'; b.style.opacity = '0'; }
    setTimeout(() => { m.style.display = 'none'; }, 250);
  }
  try {
    sessionStorage.setItem('tour_modal_dismissed_session', '1');
  } catch(e) {}
}

function dismissTourBanner() {
  const b = document.getElementById('tour-invite-card');
  if (b) {
    b.style.opacity = '0';
    b.style.transform = 'translateY(-10px)';
    setTimeout(() => { b.style.display = 'none'; }, 280);
  }
  try {
    localStorage.setItem('mancing_tour_banner_dismissed', '1');
  } catch (e) {}
}

function startInteractiveTour(track) {
  closeTourSelectModal();
  if (typeof closeCustomPopup === 'function') closeCustomPopup();
  if (typeof closeCheckinModal === 'function') closeCheckinModal();

  if (typeof window.startGlobalTour === 'function') {
    window.startGlobalTour(track || 'watch', 0);
  }
}

/* ── LOGIKA POP-UP BERANTAI (CHAINED MODALS) ── */
function openCustomPopup() {
  try {
    const at = localStorage.getItem('mancing_tour_active');
    if (at && JSON.parse(at).isRunning) return;
  } catch(e) {}

  const p = document.getElementById('custom-announcement-popup');
  if (!p) {
    triggerCheckinPopupIfEligible();
    return;
  }
  p.style.display = 'flex';
  setTimeout(() => {
    const b = p.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 40);
}

function closeCustomPopup() {
  const p = document.getElementById('custom-announcement-popup');
  if (p) {
    const b = p.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(0.85)'; b.style.opacity = '0'; }
    setTimeout(() => { p.style.display = 'none'; }, 280);
  }
  try {
    localStorage.setItem('mancing_popup_seen', JSON.stringify({ ts: Date.now() }));
  } catch (e) {}

  setTimeout(() => {
    const promoDismissed = sessionStorage.getItem('promo_hub_dismissed') === '1';
    if (!promoDismissed) {
      openPromoHubModal();
      return;
    }
    if (typeof triggerThreadsPopupIfEligible === 'function' && triggerThreadsPopupIfEligible()) {
      return;
    }
    triggerCheckinPopupIfEligible();
  }, 350);
}

function openThreadsPromoModal() {
  const m = document.getElementById('threads-promo-modal');
  if (!m) return;
  m.style.display = 'flex';
  setTimeout(() => {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 40);
}

function closeThreadsPromoModal() {
  const m = document.getElementById('threads-promo-modal');
  if (m) {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(0.85)'; b.style.opacity = '0'; }
    setTimeout(() => { m.style.display = 'none'; }, 260);
  }
  try {
    sessionStorage.setItem('threads_promo_modal_closed', '1');
  } catch(e) {}

  setTimeout(triggerCheckinPopupIfEligible, 350);
}

function triggerThreadsPopupIfEligible() {
  if (window.__IS_GUEST__) return false;
  if (sessionStorage.getItem('threads_promo_modal_closed') === '1') return false;
  const m = document.getElementById('threads-promo-modal');
  if (!m) return false;
  m.style.display = 'flex';
  setTimeout(() => {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 40);
  return true;
}

function triggerCheckinPopupIfEligible() {
  if (window.__HAS_CHECKED_IN__ || window.__IS_GUEST__) return;
  if (sessionStorage.getItem('checkin_prompt_closed') === '1') return;

  try {
    const at = localStorage.getItem('mancing_tour_active');
    if (at && JSON.parse(at).isRunning) return;
  } catch(e) {}

  const m = document.getElementById('checkin-prompt-modal');
  if (!m) return;
  m.style.display = 'flex';
  setTimeout(() => {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 40);
}

function openCheckinModal() {
  const m = document.getElementById('checkin-prompt-modal');
  if (!m) { window.location.href = '/checkin'; return; }
  m.style.display = 'flex';
  setTimeout(() => {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(1)'; b.style.opacity = '1'; }
  }, 40);
}

function closeCheckinModal() {
  const m = document.getElementById('checkin-prompt-modal');
  if (m) {
    const b = m.querySelector('.amber-modal-box');
    if (b) { b.style.transform = 'scale(0.85)'; b.style.opacity = '0'; }
    setTimeout(() => { m.style.display = 'none'; }, 280);
  }
  try {
    sessionStorage.setItem('checkin_prompt_closed', '1');
  } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
  const allTourDone = localStorage.getItem('mancing_tour_done') === '1';

  try {
    const b = document.getElementById('tour-invite-card');
    if (b) {
      if (allTourDone || localStorage.getItem('mancing_tour_banner_dismissed') === '1') {
        b.style.display = 'none';
      } else {
        b.style.display = 'block';
      }
    }
  } catch (e) {}

  let isTourRunningNow = false;
  try {
    const rawTour = localStorage.getItem('mancing_tour_active');
    if (rawTour && JSON.parse(rawTour).isRunning) {
      isTourRunningNow = true;
    }
  } catch(e) {}

  const customPopupEl = document.getElementById('custom-announcement-popup');
  const resetMs = <?= $popup_reset_hours ?> * 3600000;
  let showCustom = <?= $popup_enabled ? 'true' : 'false' ?>;

  if (showCustom && customPopupEl) {
    try {
      const raw = localStorage.getItem('mancing_popup_seen');
      if (raw) {
        const data = JSON.parse(raw);
        if (resetMs > 0 && (Date.now() - data.ts) < resetMs) {
          showCustom = false;
        }
      }
    } catch(e){}
  }

  if (!isTourRunningNow) {
    if (showCustom && customPopupEl) {
      setTimeout(openCustomPopup, <?= $popup_delay ?>);
    } else {
      const promoDismissed = sessionStorage.getItem('promo_hub_dismissed') === '1';
      if (!promoDismissed) {
        setTimeout(openPromoHubModal, 900);
      } else {
        setTimeout(triggerCheckinPopupIfEligible, 600);
      }
    }
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
