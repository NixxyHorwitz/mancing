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
try {
    $vStmt = $pdo->prepare("
        SELECT v.*,
               (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id = ? AND wh.video_id = v.id AND DATE(wh.watched_at) = CURDATE()) AS watched_today
        FROM videos v
        WHERE v.is_active = 1
        ORDER BY v.sort_order ASC, v.id DESC
        LIMIT 4
    ");
    $vStmt->execute([$user['id']]);
    $featured_videos = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable) {}

// Riwayat Tontonan Belum Selesai (Maksimal 3 per user)
$continue_watching_home = [];
if (!$is_guest) {
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
            LIMIT 3
        ");
        $cwHStmt->execute([$user['id']]);
        $continue_watching_home = $cwHStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// Notifikasi Preview
$notif_preview = [];
if (!$is_guest) {
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
   MANCINGCUAN — CLAYMORPHISM 3D DESIGN SYSTEM
   Soft, Volumetric, Puffy 3D Elements with Tactile Depth
   ══════════════════════════════════════════════════════════ */

:root {
  --clay-bg: #f7f4ee;
  --clay-surface: #ffffff;
  --clay-ink: #2d3142;
  --clay-sub: #64748b;
  --clay-gold-top: #fde047;
  --clay-gold: #f59e0b;
  --clay-gold-dark: #d97706;
  --clay-brown: #78350f;
  --clay-emerald: #10b981;
  --clay-emerald-dark: #059669;
  --clay-sky: #38bdf8;
  --clay-sky-dark: #0284c7;
}

body {
  background: var(--clay-bg) !important;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
  color: var(--clay-ink);
  -webkit-font-smoothing: antialiased;
}

/* ── 1. HERO SECTION: COMPACT PUFFY HONEY CLAY DOCK ── */
.amber-hero-wrap {
  background: linear-gradient(180deg, #92400e 0%, #b45309 35%, #d97706 70%, #f59e0b 100%) !important;
  padding: 12px 12px 18px !important;
  position: relative;
  overflow: hidden;
  border-radius: 0 0 28px 28px !important;
  border-bottom: 2.5px solid #92400e !important;
  box-shadow: 
    0 14px 32px rgba(146, 64, 14, 0.25),
    0 4px 12px rgba(0, 0, 0, 0.08),
    inset 0 2px 6px rgba(255, 255, 255, 0.35) !important;
}

/* Subtle organic honey radial glows */
.amber-hero-wrap::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.2) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.2) 15%, transparent 16%);
  background-size: 22px 22px;
  background-position: 0 0, 11px 11px;
  opacity: 0.16; pointer-events: none;
}
.amber-hero-wrap::after {
  content: ''; position: absolute; top: -40px; right: -40px;
  width: 180px; height: 180px; border-radius: 50%;
  background: radial-gradient(circle, rgba(254,240,138,0.3) 0%, transparent 70%);
  pointer-events: none;
}

/* User Profile Row */
.hero-user-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px; position: relative; z-index: 2;
}
.hero-user-left {
  display: flex; align-items: center; gap: 8px;
}

/* Clay Avatar Cushion */
.hero-avatar-box {
  width: 40px; height: 40px;
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 14px !important;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 
    0 4px 10px rgba(120, 53, 15, 0.18),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(217, 119, 6, 0.1) !important;
  position: relative; flex-shrink: 0;
  animation: mascotBob 4s ease-in-out infinite;
}
.hero-avatar-box img { width: 26px; height: 26px; object-fit: contain; }
@keyframes mascotBob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-2px); }
}

.hero-avatar-badge {
  position: absolute; bottom: -2px; right: -2px;
  background: linear-gradient(135deg, #34d399, #10b981) !important;
  color: #fff; border-radius: 50%;
  width: 14px; height: 14px;
  border: 1.5px solid #ffffff !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 7.5px;
  box-shadow: 0 2px 5px rgba(16,185,129,0.4), inset 1px 1px 2px rgba(255,255,255,0.7) !important;
}

.hero-user-meta { line-height: 1.15; }
.hero-user-name {
  font-size: 14.5px !important; font-weight: 900 !important; color: #ffffff !important;
  text-shadow: 0 1.5px 2px rgba(120, 53, 15, 0.45) !important;
  display: flex; align-items: center; gap: 5px;
}
.hero-tier-pill {
  display: inline-flex; align-items: center; gap: 3px;
  background: rgba(120, 53, 15, 0.5) !important;
  border: 1px solid rgba(254, 240, 138, 0.9) !important;
  border-radius: 999px !important;
  padding: 2px 7px !important; font-size: 8.5px !important; font-weight: 900 !important;
  color: #fef08a !important; margin-top: 2px;
  box-shadow: inset 1px 1px 2px rgba(255,255,255,0.3) !important;
}

/* Clay Profile Button */
.hero-profile-btn {
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 14px !important;
  padding: 6px 11px !important; font-size: 11px !important; font-weight: 900 !important;
  color: #78350f !important; text-decoration: none;
  box-shadow: 
    0 4px 10px rgba(120, 53, 15, 0.15),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(217, 119, 6, 0.08) !important;
  display: flex; align-items: center; gap: 4px;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.hero-profile-btn:active {
  transform: translateY(2px) scale(0.94) !important;
  box-shadow: 0 2px 5px rgba(120, 53, 15, 0.12), inset 1px 1px 2px rgba(180, 83, 9, 0.12) !important;
}

/* ── CLAY MASCOT SPEECH CLOUD ── */
.mascot-dialogue {
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 16px !important;
  padding: 7px 11px !important;
  margin-bottom: 9px !important;
  position: relative;
  z-index: 2;
  box-shadow: 
    0 6px 16px rgba(120, 53, 15, 0.1),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(217, 119, 6, 0.06) !important;
  display: flex;
  align-items: center;
  gap: 8px;
}
.mascot-dialogue__icon { font-size: 18px; flex-shrink: 0; animation: beePulse 2.4s infinite; }
@keyframes beePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
.mascot-dialogue__text { font-size: 11px !important; font-weight: 800 !important; color: #78350f !important; line-height: 1.35 !important; }
.mascot-dialogue__text strong { color: #b45309; font-weight: 900; }

/* ══════════════════════════════════════════════════════════
   THE VIP CLAY EARNINGS CARD (COMPACT WITH CRISP BORDERS)
   ══════════════════════════════════════════════════════════ */
.cuan-flex-card {
  background: linear-gradient(145deg, #ffffff 0%, #fffdf8 45%, #fef8eb 100%) !important;
  border: 2px solid #fed7aa !important;
  border-radius: 24px !important;
  box-shadow: 
    0 16px 36px rgba(120, 53, 15, 0.14),
    0 4px 10px rgba(0, 0, 0, 0.03),
    inset 3px 3px 8px #ffffff,
    inset -3px -3px 8px rgba(217, 119, 6, 0.06) !important;
  padding: 13px 13px 12px !important;
  position: relative;
  z-index: 2;
  overflow: hidden;
}


/* Card Brand Header */
.cuan-card-header {
  display: flex; align-items: center; justify-content: space-between; gap: 6px;
  margin-bottom: 6px !important; padding-bottom: 5px !important;
  border-bottom: 1.5px dashed rgba(217, 119, 6, 0.22) !important;
  position: relative; z-index: 3;
}
.cuan-card-brand {
  display: flex; align-items: center; gap: 5px;
  font-size: 11px !important; font-weight: 900 !important; color: #78350f !important;
  white-space: nowrap;
}
.cuan-card-brand-badge {
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  color: #ffffff !important; font-size: 8.5px !important; font-weight: 900 !important;
  padding: 2px 7px !important; border-radius: 8px !important;
  border: 1px solid rgba(255, 255, 255, 0.8) !important;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3), inset 1px 1px 2px rgba(255, 255, 255, 0.7) !important;
}
.cuan-card-status-verified {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 9.5px !important; font-weight: 900 !important; color: #059669 !important;
  background: #ecfdf5 !important;
  border: 1.2px solid rgba(16, 185, 129, 0.7) !important;
  padding: 2px 8px !important; border-radius: 999px !important;
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.12), inset 1px 1px 2px #ffffff !important;
  white-space: nowrap; flex-shrink: 0;
}
.cuan-card-status-verified .pulse-dot {
  width: 6px; height: 6px; background: #10b981; border-radius: 50%;
  animation: pulseGreen 1.6s infinite;
}
@keyframes pulseGreen {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129,0.7); }
  70% { transform: scale(1.15); box-shadow: 0 0 0 5px rgba(16,185,129,0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}

.cuan-card-history-link {
  display: inline-flex; align-items: center; gap: 3px;
  background: #ffffff !important;
  border: 1.2px solid #fed7aa !important;
  border-radius: 999px !important;
  padding: 2px 8px !important; font-size: 9.5px !important; font-weight: 900 !important;
  color: #78350f !important; text-decoration: none;
  box-shadow: 0 2px 6px rgba(120, 53, 15, 0.08), inset 1px 1px 3px #ffffff !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.cuan-card-history-link:active {
  transform: scale(0.94) !important;
}

/* Balance Hero Display */
.cuan-card-balance-box {
  margin: 4px 0 6px !important;
}
.cuan-balance-sub {
  font-size: 9.5px !important; font-weight: 900 !important; color: #92400e !important; text-transform: uppercase;
  letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;
}
.cuan-balance-main {
  font-size: 26px !important; font-weight: 900 !important; color: #78350f !important;
  line-height: 1.1; margin: 2px 0 6px !important;
  text-shadow: 0 1px 2px rgba(255, 255, 255, 0.9);
  font-family: 'Nunito', sans-serif;
  letter-spacing: -0.5px;
  display: flex; align-items: baseline; gap: 3px;
}
.cuan-balance-main .curr {
  font-size: 17px !important; font-weight: 900 !important; color: #d97706 !important; margin-right: 2px;
}

/* Dual Cuan Stat Pills (Clay Mini-Beds dengan Border Tegas) */
.cuan-stats-pills {
  display: grid; grid-template-columns: 1fr 1fr; gap: 6px !important;
  margin-bottom: 8px !important;
}
.cuan-pill {
  background: #fffdf9 !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 14px !important;
  padding: 6px 9px !important;
  box-shadow: 
    0 4px 10px rgba(120, 53, 15, 0.07),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(217, 119, 6, 0.08) !important;
  display: flex; flex-direction: column;
}
.cuan-pill-lbl {
  font-size: 8.5px !important; font-weight: 900 !important; color: #92400e !important; text-transform: uppercase;
  display: flex; align-items: center; gap: 3px;
}
.cuan-pill-val {
  font-size: 13px !important; font-weight: 900 !important; line-height: 1.2; margin-top: 1px !important;
}
.cuan-pill-val--today { color: #059669 !important; }
.cuan-pill-val--total { color: #b45309 !important; }

/* Referral Promo Strip on Card (Border Tegas Amber) */
.cuan-ref-banner {
  background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 14px !important;
  padding: 6px 10px !important;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px !important;
  box-shadow: 
    0 6px 14px rgba(217, 119, 6, 0.2),
    inset 2px 2px 4px rgba(255, 255, 255, 0.9),
    inset -2px -2px 4px rgba(180, 83, 9, 0.12) !important;
  cursor: pointer;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.cuan-ref-banner:active { transform: scale(0.96) !important; }
.cuan-ref-left {
  display: flex; align-items: center; gap: 6px;
}
.cuan-ref-tag {
  background: #78350f !important; color: #fef08a !important; font-size: 8.5px !important; font-weight: 900 !important;
  padding: 2px 6px !important; border-radius: 6px !important; text-transform: uppercase;
  box-shadow: 0 1px 3px rgba(120,53,15,0.3) !important;
}
.cuan-ref-code {
  font-family: monospace; font-size: 13px !important; font-weight: 900 !important; color: #78350f !important;
  letter-spacing: 0.8px;
}
.cuan-ref-copy-btn {
  background: #ffffff !important;
  border: 1.2px solid #fed7aa !important;
  border-radius: 10px !important;
  padding: 3px 8px !important; font-size: 9.5px !important; font-weight: 900 !important; color: #78350f !important;
  display: flex; align-items: center; gap: 3px;
  box-shadow: 0 2px 6px rgba(120, 53, 15, 0.1), inset 1px 1px 2px #ffffff !important;
}

/* ── INTEGRATED CLAY VIDEO MISSION BAR (BORDER TEGAS) ── */
.cuan-video-mission {
  background: #fffdf9 !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 18px !important;
  padding: 9px 11px !important;
  box-shadow: 
    0 6px 16px rgba(120, 53, 15, 0.08),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(217, 119, 6, 0.06) !important;
  margin-bottom: 9px !important;
}
.cvm-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 5px !important;
}
.cvm-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: linear-gradient(135deg, #f97316, #ea580c) !important;
  color: #fff !important; font-size: 9px !important; font-weight: 900 !important;
  padding: 2px 8px !important; border-radius: 8px !important;
  border: 1px solid #ea580c !important;
  box-shadow: 0 2px 6px rgba(234, 88, 12, 0.35), inset 1px 1px 2px rgba(255, 255, 255, 0.6) !important;
  text-transform: uppercase;
}
.cvm-quota {
  font-size: 10.5px !important; font-weight: 900 !important; color: #78350f !important;
  background: #fffbeb !important; border: 1.2px solid #fcd34d !important;
  padding: 2px 7px !important; border-radius: 8px !important;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.8) !important;
}
.cvm-bar-wrap {
  height: 13px !important;
  background: #fed7aa !important;
  border: 1.2px solid #fcd34d !important;
  border-radius: 999px !important;
  overflow: hidden !important; position: relative !important; margin-bottom: 5px !important;
  box-shadow: inset 2px 2px 4px rgba(180, 83, 9, 0.2), inset -1px -1px 3px rgba(255, 255, 255, 0.8) !important;
}
.cvm-bar-fill {
  height: 100% !important;
  background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%) !important;
  border-radius: 999px !important;
  box-shadow: 0 2px 5px rgba(245, 158, 11, 0.4), inset 1px 1px 3px rgba(255, 255, 255, 0.7), inset -1px -1px 3px rgba(180, 83, 9, 0.3) !important;
  transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  position: relative; overflow: hidden;
}
.cvm-bar-fill::after {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.55), transparent);
  animation: barShimmer 2.2s infinite;
}
@keyframes barShimmer {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}
.cvm-foot {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 9.5px !important; font-weight: 800 !important; color: #78350f !important;
}

/* ── PRIMARY CTA: SQUISHY HONEY CLAY BUTTON (COMPACT & BORDER TEGAS) ── */
.btn-cuan-watch-now {
  width: 100%; padding: 11px 14px !important;
  border-radius: 16px !important;
  background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%) !important;
  border: 2px solid #b45309 !important;
  box-shadow: 
    0 10px 22px rgba(245, 158, 11, 0.38),
    0 3px 8px rgba(0, 0, 0, 0.05),
    inset 3px 3px 6px rgba(255, 255, 255, 0.8),
    inset -3px -3px 6px rgba(180, 83, 9, 0.35) !important;
  color: #fff !important; font-size: 13.5px !important; font-weight: 900 !important;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 7px;
  text-shadow: 0 1px 2px rgba(120, 53, 15, 0.5) !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s !important;
  font-family: 'Nunito', sans-serif;
  margin-bottom: 8px !important;
  animation: ctaGlowClay 3s ease-in-out infinite;
}
.btn-cuan-watch-now:active {
  transform: translateY(2px) scale(0.96) !important;
  box-shadow: 
    0 3px 8px rgba(245, 158, 11, 0.3),
    inset 2px 2px 4px rgba(180, 83, 9, 0.4),
    inset -1px -1px 3px rgba(255, 255, 255, 0.5) !important;
}
@keyframes ctaGlowClay {
  0%, 100% {
    box-shadow: 0 10px 22px rgba(245, 158, 11, 0.38), 0 3px 8px rgba(0,0,0,0.05), inset 3px 3px 6px rgba(255,255,255,0.8), inset -3px -3px 6px rgba(180,83,9,0.35);
  }
  50% {
    box-shadow: 0 14px 28px rgba(245, 158, 11, 0.5), 0 0 16px rgba(251, 191, 36, 0.45), inset 3px 3px 6px rgba(255,255,255,0.9), inset -3px -3px 6px rgba(180,83,9,0.4);
  }
}

/* ── QUICK ACTION CLAY BUTTONS (WD, TOP UP, RIWAYAT - BORDER TEGAS) ── */
.cuan-card-actions {
  display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 6px !important;
}
.cuan-btn-action {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: 8px 5px !important; border-radius: 13px !important; font-size: 11px !important; font-weight: 900 !important;
  text-decoration: none; font-family: 'Nunito', sans-serif;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s !important;
}
.cuan-btn-action:active {
  transform: translateY(2px) scale(0.94) !important;
}
.cuan-btn-action--wd {
  background: linear-gradient(145deg, #34d399 0%, #10b981 60%, #059669 100%) !important;
  color: #ffffff !important;
  border: 1.5px solid #059669 !important;
  box-shadow: 
    0 8px 16px rgba(16, 185, 129, 0.35),
    inset 2px 2px 4px rgba(255, 255, 255, 0.7),
    inset -2px -2px 4px rgba(6, 78, 59, 0.3) !important;
  text-shadow: 0 1px 2px rgba(6, 78, 59, 0.4);
}
.cuan-btn-action--dep {
  background: linear-gradient(145deg, #38bdf8 0%, #0ea5e9 60%, #0284c7 100%) !important;
  color: #ffffff !important;
  border: 1.5px solid #0284c7 !important;
  box-shadow: 
    0 8px 16px rgba(14, 165, 233, 0.35),
    inset 2px 2px 4px rgba(255, 255, 255, 0.75),
    inset -2px -2px 4px rgba(3, 105, 161, 0.3) !important;
  text-shadow: 0 1px 2px rgba(3, 105, 161, 0.4);
}
.cuan-btn-action--history {
  background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 60%, #d97706 100%) !important;
  color: #ffffff !important;
  border: 1.5px solid #d97706 !important;
  box-shadow: 
    0 8px 16px rgba(245, 158, 11, 0.35),
    inset 2px 2px 4px rgba(255, 255, 255, 0.75),
    inset -2px -2px 4px rgba(180, 83, 9, 0.3) !important;
  text-shadow: 0 1px 2px rgba(180, 83, 9, 0.4);
}

/* ── CUAN TOAST NOTIFICATION ── */
#cuan-toast {
  position: fixed;
  bottom: 82px;
  left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: #1e2230 !important;
  color: #fef08a !important;
  border: 1.5px solid #f59e0b !important;
  box-shadow: 0 14px 30px rgba(0, 0, 0, 0.35), inset 2px 2px 4px rgba(255,255,255,0.15) !important;
  padding: 9px 16px !important;
  border-radius: 999px !important;
  font-size: 11.5px;
  font-weight: 900;
  z-index: 999999;
  display: flex;
  align-items: center;
  gap: 7px;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  pointer-events: none;
  white-space: nowrap;
}
#cuan-toast.show {
  opacity: 1;
  visibility: visible;
  transform: translateX(-50%) translateY(0);
}

/* ── FEED CONTAINER (COMPACT) ── */
.home-feed { padding: 12px 10px 96px; max-width: 480px; margin: 0 auto; }

/* ── CLAY SECTION CARD (BORDER TEGAS) ── */
.amber-section-card {
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 22px !important;
  box-shadow: 
    0 12px 26px rgba(120, 53, 15, 0.08),
    0 3px 8px rgba(0, 0, 0, 0.02),
    inset 3px 3px 8px #ffffff,
    inset -3px -3px 8px rgba(180, 83, 9, 0.05) !important;
  padding: 13px !important;
  margin-bottom: 14px !important;
  position: relative;
  overflow: hidden;
}

/* ── BENTO QUICK ACCESS MENU (8 CLAY CUSHIONS DENGAN BORDER TEGAS) ── */
.bento-menu-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 7px !important;
  margin-top: 6px; margin-bottom: 14px;
}
.b-tile {
  background: #fffdfa !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 16px !important;
  box-shadow: 
    0 6px 14px rgba(120, 53, 15, 0.07),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(217, 119, 6, 0.06) !important;
  padding: 8px 3px 7px !important; text-decoration: none;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 5px; transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s !important; position: relative;
}
.b-tile:active {
  transform: translateY(2px) scale(0.93) !important;
  box-shadow: 0 2px 6px rgba(120, 53, 15, 0.06), inset 2px 2px 4px rgba(180, 83, 9, 0.1) !important;
}

.b-tile__icon {
  width: 38px !important; height: 38px !important;
  border-radius: 12px !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 19px !important; color: #fff !important;
  border: 1.5px solid rgba(255, 255, 255, 0.9) !important;
  box-shadow: 
    0 4px 10px rgba(0, 0, 0, 0.12),
    inset 2px 2px 4px rgba(255, 255, 255, 0.65),
    inset -2px -2px 4px rgba(0, 0, 0, 0.2) !important;
  position: relative;
}
.b-tile__lbl { font-size: 9px !important; font-weight: 900 !important; color: #78350f !important; text-align: center; line-height: 1.15; }

/* Pulse & VIP highlights */
.b-tile--vip-glow {
  background: linear-gradient(145deg, #fffbeb 0%, #fef3c7 100%) !important;
  border: 1.5px solid #f59e0b !important;
  box-shadow: 
    0 8px 18px rgba(245, 158, 11, 0.25),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(180, 83, 9, 0.1) !important;
  animation: vip-glow 2.5s infinite ease-in-out;
}
@keyframes vip-glow {
  0%, 100% { box-shadow: 0 8px 18px rgba(245, 158, 11, 0.25), inset 2px 2px 5px #fff, inset -2px -2px 5px rgba(180,83,9,0.1); }
  50% { box-shadow: 0 12px 24px rgba(245, 158, 11, 0.45), 0 0 14px rgba(251, 191, 36, 0.5), inset 2px 2px 5px #fff, inset -2px -2px 5px rgba(180,83,9,0.1); }
}
.b-tile--highlighted {
  background: linear-gradient(145deg, #fffbeb 0%, #fef3c7 100%) !important;
  border: 1.5px solid #d97706 !important;
  box-shadow: 
    0 10px 22px rgba(245, 158, 11, 0.35),
    0 0 14px rgba(251, 191, 36, 0.45),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(180, 83, 9, 0.12) !important;
  animation: vip-glow-highlight 2s infinite ease-in-out !important;
}
@keyframes vip-glow-highlight {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.03); }
}
.b-tile-badge-cuan {
  position: absolute; top: -6px; right: -4px;
  background: linear-gradient(135deg, #ef4444, #dc2626);
  color: #fff; border: 1.2px solid #fff; border-radius: 999px;
  font-size: 7.5px; font-weight: 900; padding: 1px 5px;
  box-shadow: 0 2px 5px rgba(239, 68, 68, 0.4), inset 1px 1px 2px rgba(255,255,255,0.7);
  z-index: 5; display: inline-flex; align-items: center; gap: 2px;
}
.b-tile-badge-dot {
  position: absolute; top: -2px; right: -2px; width: 9px; height: 9px;
  background: #ef4444; border: 1.5px solid #fff; border-radius: 50%;
  box-shadow: 0 2px 4px rgba(239,68,68,0.5);
  animation: pulse-dot 1.4s infinite;
}
@keyframes pulse-dot {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.2); opacity: 0.85; }
}

/* ── RECOMMENDATION UPGRADE STRIP (BORDER TEGAS) ── */
.btn-upgrade-highlight {
  position: relative; overflow: hidden;
  background: linear-gradient(145deg, #1e2230 0%, #151822 100%) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 18px !important;
  padding: 9px 12px !important; margin-top: 8px; margin-bottom: 2px;
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  text-decoration: none;
  box-shadow: 
    0 10px 22px rgba(0, 0, 0, 0.25),
    0 0 14px rgba(245, 158, 11, 0.25),
    inset 2px 2px 4px rgba(255, 255, 255, 0.15),
    inset -2px -2px 4px rgba(0, 0, 0, 0.5) !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.btn-upgrade-highlight:active {
  transform: translateY(2px) scale(0.97) !important;
}
.buh-content { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.buh-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 9px; font-weight: 900; color: #fde68a; letter-spacing: 0.3px;
}
.buh-badge-tag {
  background: #f59e0b; color: #78350f; border-radius: 5px;
  padding: 1px 5px; font-size: 8px; font-weight: 900;
}
.buh-text { font-size: 11px; font-weight: 800; color: #f8fafc; line-height: 1.3; }
.buh-text strong { color: #fde047; font-weight: 900; }
.buh-arrow {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.2px solid #fef3c7; border-radius: 10px;
  padding: 5px 10px; color: #78350f; font-size: 10.5px; font-weight: 900;
  display: inline-flex; align-items: center; gap: 4px; white-space: nowrap;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.35), inset 1px 1px 2px rgba(255,255,255,0.7);
  flex-shrink: 0;
}

/* ── IN-FEED SURVEI BERHADIAH BANNER (BORDER TEGAS) ── */
.survey-invite-banner {
  background: linear-gradient(145deg, #fef3c7 0%, #fde68a 100%) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 20px !important;
  padding: 10px 12px !important; margin-bottom: 12px !important;
  box-shadow: 
    0 10px 22px rgba(217, 119, 6, 0.18),
    inset 3px 3px 7px #ffffff,
    inset -3px -3px 7px rgba(180, 83, 9, 0.12) !important;
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  text-decoration: none; cursor: pointer;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  position: relative; overflow: hidden;
}
.survey-invite-banner:active { transform: translateY(2px) scale(0.97) !important; }
.sib-left { display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1; }
.sib-icon-box {
  width: 38px; height: 38px; border-radius: 12px !important;
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  border: 1.5px solid rgba(255, 255, 255, 0.9) !important;
  display: flex; align-items: center; justify-content: center;
  color: #ffffff; font-size: 18px; flex-shrink: 0;
  box-shadow: 0 4px 10px rgba(245, 158, 11, 0.35), inset 2px 2px 4px rgba(255, 255, 255, 0.7), inset -2px -2px 4px rgba(180, 83, 9, 0.3) !important;
}
.sib-icon-box--done {
  background: linear-gradient(135deg, #10b981, #059669) !important;
  box-shadow: 0 4px 10px rgba(16, 185, 129, 0.35), inset 2px 2px 4px rgba(255, 255, 255, 0.7), inset -2px -2px 4px rgba(6, 78, 59, 0.3) !important;
}
.sib-text { min-width: 0; }
.sib-title {
  font-size: 12px !important; font-weight: 900 !important; color: #78350f !important;
  display: flex; align-items: center; gap: 5px; margin-bottom: 2px;
}
.sib-badge {
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  color: #ffffff !important; font-size: 8px !important; font-weight: 900 !important;
  padding: 1px 6px !important; border-radius: 999px !important;
  box-shadow: 0 2px 4px rgba(245,158,11,0.3) !important;
}
.sib-badge--done {
  background: linear-gradient(135deg, #10b981, #059669) !important;
}
.sib-desc { font-size: 9.5px !important; font-weight: 700 !important; color: #92400e !important; line-height: 1.3; }
.sib-cta {
  background: linear-gradient(135deg, #78350f, #92400e) !important;
  border: 1.2px solid rgba(254, 240, 138, 0.8) !important;
  border-radius: 10px !important; padding: 6px 10px !important;
  color: #ffffff; font-size: 10.5px !important; font-weight: 900 !important;
  display: inline-flex; align-items: center; gap: 4px;
  box-shadow: 0 3px 8px rgba(120, 53, 15, 0.25), inset 1px 1px 2px rgba(255, 255, 255, 0.3) !important;
  white-space: nowrap; flex-shrink: 0;
}
.sib-cta--done { background: linear-gradient(135deg, #065f46, #047857) !important; }

/* ── IN-FEED PROMO BANNER (BORDER TEGAS) ── */
.promo-invite-banner {
  background: linear-gradient(145deg, #27272a 0%, #18181b 100%) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 20px !important;
  padding: 10px 12px !important; margin-bottom: 14px !important;
  box-shadow: 
    0 12px 26px rgba(0, 0, 0, 0.32),
    inset 3px 3px 6px rgba(255, 255, 255, 0.15),
    inset -3px -3px 6px rgba(0, 0, 0, 0.45) !important;
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  cursor: pointer;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  position: relative; overflow: hidden;
}
.promo-invite-banner:active { transform: translateY(2px) scale(0.97) !important; }
.pib-left { display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1; }
.pib-icon-box {
  width: 38px; height: 38px; border-radius: 12px !important;
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  border: 1.5px solid rgba(255, 255, 255, 0.9) !important;
  display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 18px; flex-shrink: 0;
  box-shadow: 0 4px 10px rgba(245, 158, 11, 0.35), inset 2px 2px 4px rgba(255, 255, 255, 0.7), inset -2px -2px 4px rgba(180, 83, 9, 0.3) !important;
}
.pib-text { min-width: 0; }
.pib-title {
  font-size: 12px !important; font-weight: 900 !important; color: #fde68a !important;
  display: flex; align-items: center; gap: 5px; margin-bottom: 2px;
}
.pib-badge {
  background: linear-gradient(135deg, #10b981, #059669) !important;
  color: #ffffff !important; font-size: 8px !important; font-weight: 900 !important;
  padding: 1px 6px !important; border-radius: 999px !important;
}
.pib-desc { font-size: 9.5px !important; font-weight: 700 !important; color: #e2e8f0 !important; line-height: 1.3; }
.pib-cta {
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  border: 1.2px solid #fef3c7 !important; border-radius: 10px !important;
  padding: 6px 10px !important; color: #78350f !important; font-size: 10.5px !important; font-weight: 900 !important;
  display: inline-flex; align-items: center; gap: 4px;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.35), inset 1px 1px 2px rgba(255, 255, 255, 0.7) !important;
  white-space: nowrap; flex-shrink: 0;
}

/* ── THREADS PROMO HOME CARD (BORDER TEGAS) ── */
.threads-promo-home-card {
  background: linear-gradient(145deg, #27272a 0%, #18181b 100%) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 22px !important;
  padding: 13px !important; margin-bottom: 14px !important;
  box-shadow: 
    0 14px 30px rgba(0, 0, 0, 0.35),
    inset 3px 3px 7px rgba(255, 255, 255, 0.16),
    inset -3px -3px 7px rgba(0, 0, 0, 0.45) !important;
  position: relative; overflow: hidden;
}
.tph-badge-strip {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;
}
.tph-badge-event {
  background: #fef3c7 !important; color: #78350f !important; border: 1.2px solid #fde68a !important;
  border-radius: 8px !important; padding: 2px 7px !important; font-size: 9px !important; font-weight: 900 !important;
}
.tph-badge-cuan {
  background: linear-gradient(135deg, #10b981, #059669) !important;
  color: #ffffff !important; border-radius: 8px !important; padding: 2px 8px !important;
  font-size: 9.5px !important; font-weight: 900 !important;
  box-shadow: 0 2px 6px rgba(16,185,129,0.3) !important;
}
.tph-content { display: flex; align-items: center; gap: 10px; margin-bottom: 11px; }
.tph-icon-box {
  width: 44px; height: 44px;
  background: rgba(255,255,255,0.08) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 14px !important;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  box-shadow: 0 4px 10px rgba(0,0,0,0.3), inset 2px 2px 4px rgba(255,255,255,0.18) !important;
}
.tph-mascot-img { width: 34px; height: 34px; object-fit: contain; }
.tph-text-col { flex: 1; min-width: 0; }
.tph-title { font-size: 13px !important; font-weight: 900 !important; color: #fde68a !important; line-height: 1.2; margin-bottom: 3px; }
.tph-desc { font-size: 10px !important; font-weight: 700 !important; color: #e2e8f0 !important; line-height: 1.35; }
.tph-desc strong { color: #fde047; }
.tph-actions { display: flex; align-items: center; gap: 6px; }
.tph-btn-primary {
  flex: 1; background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  border: 1.5px solid #fef3c7 !important;
  border-radius: 12px !important; padding: 8px 12px !important;
  color: #78350f !important; font-size: 11px !important; font-weight: 900 !important;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 5px;
  box-shadow: 0 6px 14px rgba(245, 158, 11, 0.35), inset 2px 2px 4px rgba(255,255,255,0.7), inset -2px -2px 4px rgba(180,83,9,0.3) !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.tph-btn-primary:active { transform: translateY(2px) scale(0.96) !important; }
.tph-btn-info {
  background: rgba(255,255,255,0.12) !important;
  border: 1.2px solid rgba(255,255,255,0.3) !important;
  border-radius: 12px !important; padding: 7px 12px !important;
  color: #f8fafc !important; font-size: 10.5px !important; font-weight: 900 !important;
  cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
  box-shadow: 0 3px 8px rgba(0,0,0,0.2), inset 1px 1px 3px rgba(255,255,255,0.15) !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.tph-btn-info:active { transform: scale(0.95) !important; }

/* ── FEATURED VIDEO GRID (CLAY FRAMES DENGAN BORDER TEGAS) ── */
.v-grid-mini {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 9px !important;
  margin-top: 10px;
}
.v-card-mini {
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 18px !important;
  box-shadow: 
    0 8px 18px rgba(120, 53, 15, 0.08),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(180, 83, 9, 0.05) !important;
  overflow: hidden !important; text-decoration: none;
  display: flex; flex-direction: column;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s !important;
}
.v-card-mini:active { transform: translateY(2px) scale(0.95) !important; }
.v-thumb-wrap {
  position: relative; aspect-ratio: 16/9; background: #0f172a; overflow: hidden;
  border-radius: 16px 16px 0 0 !important;
}
.v-thumb-wrap img { width: 100%; height: 100%; object-fit: cover; }
.v-badge-reward {
  position: absolute; top: 5px; right: 5px;
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  color: #ffffff !important; font-size: 8.5px !important; font-weight: 900 !important;
  padding: 2px 6px !important; border-radius: 999px !important;
  border: 1px solid rgba(255, 255, 255, 0.8) !important;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.35), inset 1px 1px 2px rgba(255, 255, 255, 0.7) !important;
}
.v-badge-duration {
  position: absolute; bottom: 5px; left: 5px;
  background: rgba(15, 23, 42, 0.8) !important; color: #fff; font-size: 8px !important; font-weight: 800 !important;
  padding: 1px 5px !important; border-radius: 999px !important;
}
.v-play-overlay {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.2);
}
.v-play-btn-circle {
  width: 28px; height: 28px; border-radius: 50% !important;
  background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
  border: 1.5px solid rgba(255, 255, 255, 0.9) !important;
  display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 13px;
  box-shadow: 0 3px 8px rgba(0,0,0,0.2), inset 1px 1px 3px rgba(255, 255, 255, 0.8) !important;
}
.v-info-mini {
  padding: 8px !important; display: flex; flex-direction: column; justify-content: space-between; flex: 1;
}
.v-title-mini {
  font-size: 10.5px !important; font-weight: 800 !important; color: #1e293b !important; line-height: 1.25;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.v-status-mini {
  margin-top: 6px !important; font-size: 9.5px !important; font-weight: 900 !important; display: flex; align-items: center; gap: 3px;
}
.v-status--done { color: #059669 !important; }
.v-status--ready { color: #d97706 !important; }

/* ── SIDEJOB: CLAY EMERALD 3D BEE FARM TABLET (COMPACT & BORDER TEGAS) ── */
.sidejob-3d-card {
  background: linear-gradient(145deg, #16a34a 0%, #15803d 60%, #166534 100%) !important;
  border: 2px solid #059669 !important;
  border-radius: 24px !important;
  box-shadow: 
    0 16px 36px rgba(21, 128, 61, 0.32),
    0 4px 12px rgba(0, 0, 0, 0.06),
    inset 4px 4px 10px rgba(255, 255, 255, 0.35),
    inset -4px -4px 10px rgba(20, 83, 45, 0.45) !important;
  padding: 15px 14px !important; margin-bottom: 14px !important;
  position: relative; overflow: hidden; color: #fff;
}
.sidejob-badge-tag {
  display: inline-flex; align-items: center; gap: 4px;
  background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
  color: #78350f !important; font-size: 9px !important; font-weight: 900 !important;
  padding: 3px 8px !important; border-radius: 999px !important;
  border: 1.2px solid rgba(255, 255, 255, 0.8) !important;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15), inset 1px 1px 2px rgba(255, 255, 255, 0.7) !important;
  text-transform: uppercase; margin-bottom: 8px;
}
.sidejob-title-row {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;
}
.sidejob-title { font-size: 15px !important; font-weight: 900 !important; color: #fff; text-shadow: 0 1.5px 3px rgba(20, 83, 45, 0.4); }
.sidejob-desc { font-size: 10.5px !important; font-weight: 700 !important; color: #dcfce7 !important; line-height: 1.4; margin-bottom: 12px; }

/* 3D Farm Live Metrics (Puffy Inset Cushions) */
.sidejob-metrics-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px !important;
  margin-bottom: 12px;
}
.sidejob-metric-pill {
  background: rgba(255, 255, 255, 0.95) !important;
  border: 1.2px solid rgba(22, 101, 52, 0.25) !important;
  border-radius: 13px !important;
  padding: 7px 3px !important; text-align: center;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1), inset 2px 2px 4px #ffffff, inset -1px -1px 3px rgba(20, 83, 45, 0.08) !important;
}
.sidejob-metric-val { font-size: 12.5px !important; font-weight: 900 !important; color: #b45309 !important; }
.sidejob-metric-lbl { font-size: 8px !important; font-weight: 900 !important; color: #64748b !important; text-transform: uppercase; margin-top: 1px; }

.btn-open-3d-farm {
  width: 100%; padding: 11px !important; border-radius: 16px !important;
  background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 60%, #d97706 100%) !important;
  border: 2px solid #b45309 !important;
  box-shadow: 
    0 10px 22px rgba(245, 158, 11, 0.4),
    inset 3px 3px 6px rgba(255, 255, 255, 0.8),
    inset -3px -3px 6px rgba(180, 83, 9, 0.3) !important;
  color: #fff !important; font-size: 13px !important; font-weight: 900 !important;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 6px;
  text-shadow: 0 1px 2px rgba(120, 53, 15, 0.4) !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  font-family: 'Nunito', sans-serif;
}
.btn-open-3d-farm:active {
  transform: translateY(2px) scale(0.96) !important;
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.3) !important;
}

.sidejob-sub-links {
  display: flex; justify-content: center; gap: 14px; margin-top: 9px;
}
.sidejob-sub-link {
  font-size: 10.5px !important; font-weight: 900 !important; color: #fef08a !important; text-decoration: underline;
}

/* ── TIPS & ANNOUNCEMENTS (CLAY BOX) ── */
.amber-tips-box {
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 18px !important;
  box-shadow: 
    0 8px 20px rgba(120, 53, 15, 0.07),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(180, 83, 9, 0.05) !important;
  padding: 10px 12px !important; display: flex; gap: 10px; align-items: center;
  margin-bottom: 14px;
}
.amber-tips-icon {
  width: 36px; height: 36px; border-radius: 12px !important;
  background: linear-gradient(135deg, #fef3c7, #fde68a) !important;
  border: 1.2px solid #fcd34d !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 19px; flex-shrink: 0;
  box-shadow: 0 3px 8px rgba(217, 119, 6, 0.15), inset 1px 1px 3px #ffffff !important;
}

/* ── CLAY MODAL POPUPS (COMPACT & BORDER TEGAS) ── */
.amber-modal-backdrop {
  position: fixed; inset: 0; background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(6px); z-index: 100000; display: none;
  align-items: center; justify-content: center; padding: 16px;
}
.amber-modal-box {
  background: #ffffff !important;
  border-radius: 28px !important;
  padding: 22px 18px 18px !important;
  max-width: 330px; width: 100%;
  border: 2px solid #fed7aa !important;
  box-shadow: 
    0 24px 50px rgba(0, 0, 0, 0.35),
    inset 4px 4px 10px #ffffff,
    inset -4px -4px 10px rgba(120, 53, 15, 0.08) !important;
  position: relative; text-align: center;
  transform: scale(0.85); opacity: 0;
  transition: all 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.amber-modal-close {
  position: absolute; top: -10px; right: -10px;
  background: linear-gradient(135deg, #ef4444, #dc2626) !important;
  color: #fff; border: 2px solid #ffffff !important;
  width: 32px; height: 32px; border-radius: 50% !important;
  font-size: 15px; font-weight: 900; display: flex; align-items: center;
  justify-content: center; cursor: pointer;
  box-shadow: 0 4px 10px rgba(239, 68, 68, 0.4), inset 1px 1px 3px rgba(255,255,255,0.6) !important;
  z-index: 10;
  transition: transform 0.15s;
}
.amber-modal-close:hover { transform: scale(1.1); }
.amber-modal-badge {
  width: 64px; height: 64px; border-radius: 22px !important;
  background: linear-gradient(145deg, #fbbf24, #f59e0b) !important;
  border: 2px solid #fde68a !important;
  box-shadow: 
    0 10px 20px rgba(245, 158, 11, 0.38),
    inset 3px 3px 6px rgba(255, 255, 255, 0.8),
    inset -3px -3px 6px rgba(180, 83, 9, 0.3) !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 30px; color: #78350f; margin: -50px auto 12px;
}
.amber-modal-title {
  font-size: 16.5px !important; font-weight: 900 !important; color: #78350f !important; margin-bottom: 6px;
  line-height: 1.25;
}
.amber-modal-body {
  font-size: 11.5px !important; font-weight: 700 !important; color: #64748b !important; line-height: 1.45;
  margin-bottom: 16px;
}
.btn-modal-primary {
  display: flex; align-items: center; justify-content: center; gap: 7px;
  width: 100%; font-size: 12.5px !important; font-weight: 900 !important;
  padding: 11px 14px !important; border-radius: 16px !important;
  background: linear-gradient(145deg, #f59e0b, #d97706) !important;
  border: 1.5px solid #fed7aa !important;
  box-shadow: 
    0 8px 18px rgba(245, 158, 11, 0.35),
    inset 2px 2px 5px rgba(255, 255, 255, 0.75),
    inset -2px -2px 5px rgba(180, 83, 9, 0.3) !important;
  color: #fff !important; text-decoration: none; text-shadow: 0 1px 2px rgba(120, 53, 15, 0.4) !important;
  cursor: pointer; transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.btn-modal-primary:active {
  transform: translateY(2px) scale(0.96) !important;
}
.btn-modal-dismiss {
  width: 100%; padding: 8px; background: transparent; border: none;
  font-size: 11.5px; font-weight: 800; color: #94a3b8; cursor: pointer; margin-top: 6px;
}

/* ── INTERACTIVE TOUR SELECTOR (CLAY STYLE) ── */
.btn-tour-pill {
  display: inline-flex; align-items: center; gap: 4px;
  background: linear-gradient(135deg, #fef3c7, #fde68a) !important;
  border: 1.2px solid #fcd34d !important;
  border-radius: 999px !important;
  padding: 3px 10px !important; font-size: 10.5px !important; font-weight: 900 !important;
  color: #78350f !important; cursor: pointer;
  box-shadow: 0 3px 8px rgba(180, 83, 9, 0.14), inset 1px 1px 3px rgba(255, 255, 255, 0.8) !important;
  transition: all 0.12s ease;
}
.btn-tour-pill:hover { transform: translateY(-1px); }
.btn-tour-pill:active { transform: translateY(1px) scale(0.96); }

/* Tour Invite Card */
.tour-invite-card {
  background: linear-gradient(145deg, #ffffff 0%, #fffdf8 50%, #fef8eb 100%) !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 22px !important;
  box-shadow: 
    0 10px 22px rgba(120, 53, 15, 0.1),
    inset 3px 3px 7px #ffffff,
    inset -3px -3px 7px rgba(180, 83, 9, 0.05) !important;
  padding: 12px !important; margin-bottom: 14px !important;
  position: relative; overflow: hidden;
}
.tour-invite-header {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;
}
.tour-invite-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: #78350f !important; color: #fef08a !important;
  font-size: 9px; font-weight: 900; letter-spacing: 0.4px;
  padding: 2px 8px; border-radius: 999px;
}
.tour-invite-close {
  background: #f1f5f9; border: none; font-size: 14px; color: #94a3b8;
  cursor: pointer; width: 24px; height: 24px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
}
.tour-invite-title {
  font-size: 12.5px !important; font-weight: 900 !important; color: #78350f !important; margin-bottom: 2px; line-height: 1.25;
}
.tour-invite-desc {
  font-size: 10.5px !important; font-weight: 700 !important; color: #64748b !important; margin-bottom: 9px; line-height: 1.35;
}
.tour-invite-buttons {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.btn-tour-choice {
  display: flex; align-items: center; gap: 7px;
  padding: 8px !important; border-radius: 14px !important;
  border: 1.5px solid #fed7aa !important;
  background: #ffffff !important; cursor: pointer; text-align: left;
  box-shadow: 0 4px 10px rgba(120, 53, 15, 0.07), inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(180, 83, 9, 0.05) !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.btn-tour-choice:active { transform: translateY(2px) scale(0.95) !important; }
.btn-tour-choice-icon {
  width: 32px; height: 32px; border-radius: 10px !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0; color: #fff;
  border: 1.2px solid rgba(255, 255, 255, 0.8) !important;
  box-shadow: 0 3px 6px rgba(0, 0, 0, 0.12), inset 1px 1px 3px rgba(255, 255, 255, 0.6) !important;
}
.btn-tour-choice--bee .btn-tour-choice-icon {
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
}
.btn-tour-choice--watch .btn-tour-choice-icon {
  background: linear-gradient(135deg, #f97316, #ea580c) !important;
}
.btn-tour-choice-title {
  display: block; font-size: 10.5px !important; font-weight: 900 !important; color: #78350f !important;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.btn-tour-choice-sub {
  display: block; font-size: 9px !important; font-weight: 700 !important; color: #94a3b8 !important;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Modal Selector Card (Full Option) */
.tour-select-option {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px !important; border-radius: 18px !important;
  border: 1.5px solid #fed7aa !important;
  background: #fff; cursor: pointer; text-align: left;
  box-shadow: 0 6px 14px rgba(120, 53, 15, 0.08), inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(180, 83, 9, 0.05) !important;
  margin-bottom: 9px; transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  width: 100%;
}
.tour-select-option:active { transform: translateY(2px) scale(0.96) !important; }
.tour-select-icon {
  width: 40px; height: 40px; border-radius: 13px !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: #fff; flex-shrink: 0;
  border: 1.5px solid rgba(255, 255, 255, 0.85) !important;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12), inset 2px 2px 4px rgba(255, 255, 255, 0.65) !important;
}
.tour-select-icon--bee { background: linear-gradient(135deg, #fbbf24, #d97706) !important; }
.tour-select-icon--watch { background: linear-gradient(135deg, #f97316, #ea580c) !important; }

/* ── FLOATING PROMO BUTTON (AJAKAN PROMOSI CUAN - BORDER TEGAS) ── */
.floating-promo-btn {
  position: fixed; bottom: 82px; left: 12px; z-index: 998;
  display: inline-flex; align-items: center; gap: 7px;
  background: linear-gradient(145deg, #27272a 0%, #18181b 100%) !important;
  border: 1.5px solid #f59e0b !important;
  border-radius: 999px !important;
  padding: 6px 12px 6px 7px !important; color: #fde68a !important;
  box-shadow: 
    0 12px 26px rgba(0, 0, 0, 0.35),
    0 0 10px rgba(245, 158, 11, 0.3),
    inset 2px 2px 5px rgba(255, 255, 255, 0.2),
    inset -2px -2px 5px rgba(0, 0, 0, 0.5) !important;
  cursor: pointer; text-decoration: none;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  animation: promoFloatPulseClay 3.5s infinite ease-in-out;
  font-family: 'Nunito', sans-serif;
}
.floating-promo-btn:active { transform: translateY(2px) scale(0.95) !important; }
@keyframes promoFloatPulseClay {
  0%, 100% { transform: translateY(0); box-shadow: 0 12px 26px rgba(0,0,0,0.35), 0 0 10px rgba(245,158,11,0.3); }
  50% { transform: translateY(-3px); box-shadow: 0 16px 32px rgba(0,0,0,0.45), 0 0 18px rgba(245,158,11,0.6); }
}
.fpb-icon-wrap {
  width: 28px; height: 28px;
  background: linear-gradient(135deg, #f59e0b, #d97706) !important;
  border: 1.2px solid #fef3c7 !important; border-radius: 10px !important;
  display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 14px; flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3), inset 1px 1px 2px rgba(255, 255, 255, 0.7) !important;
}
.fpb-title { font-size: 8.5px; font-weight: 900; color: #94a3b8; text-transform: uppercase; }
.fpb-sub { font-size: 11px; font-weight: 900; color: #fde047; display: flex; align-items: center; gap: 3px; }
.fpb-badge {
  background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff;
  font-size: 7.5px; font-weight: 900; padding: 1px 4px; border-radius: 999px;
}

/* ── PROMO HUB MODAL STYLES (BORDER TEGAS) ── */
.promo-hub-card-item {
  background: #ffffff !important;
  border: 1.5px solid #fed7aa !important;
  border-radius: 16px !important;
  padding: 10px 12px !important; margin-bottom: 10px !important; text-align: left;
  box-shadow: 0 6px 14px rgba(120, 53, 15, 0.07), inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(180, 83, 9, 0.05) !important;
}
.phc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 5px; }
.phc-tag {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 8.5px; font-weight: 900; padding: 2px 7px; border-radius: 7px; text-transform: uppercase;
}
.phc-tag--threads { background: #18181b; color: #fde68a; border: 1px solid #f59e0b; }
.phc-tag--referral { background: #ecfdf5; color: #065f46; border: 1px solid #10b981; }
.phc-reward { font-size: 10.5px; font-weight: 900; color: #059669; }
.phc-title { font-size: 12px !important; font-weight: 900 !important; color: #78350f !important; margin-bottom: 2px; }
.phc-desc { font-size: 10px !important; font-weight: 700 !important; color: #64748b !important; line-height: 1.35; margin-bottom: 8px; }
.phc-actions { display: flex; align-items: center; gap: 6px; }
.btn-phc-action {
  flex: 1; padding: 8px 9px !important; border-radius: 12px !important;
  font-size: 10.5px !important; font-weight: 900 !important; text-align: center; text-decoration: none;
  display: inline-flex; align-items: center; justify-content: center; gap: 4px;
  cursor: pointer; border: 1.2px solid #fed7aa !important;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important; font-family: inherit;
}
.btn-phc-action:active { transform: translateY(2px) scale(0.96) !important; }
.btn-phc-action--primary {
  background: linear-gradient(135deg, #f59e0b, #d97706) !important; color: #ffffff !important;
  border-color: #f59e0b !important;
  box-shadow: 0 4px 10px rgba(245, 158, 11, 0.35), inset 1px 1px 3px rgba(255, 255, 255, 0.7) !important;
}
.btn-phc-action--share {
  background: linear-gradient(135deg, #34d399, #10b981) !important; color: #ffffff !important;
  border-color: #10b981 !important;
  box-shadow: 0 4px 10px rgba(16, 185, 129, 0.35), inset 1px 1px 3px rgba(255, 255, 255, 0.7) !important;
}
.btn-phc-action--copy {
  background: #fef3c7 !important; color: #78350f !important;
  border-color: #fcd34d !important;
  box-shadow: 0 3px 8px rgba(180, 83, 9, 0.12), inset 1px 1px 3px #ffffff !important;
}
.phc-caption-box {
  background: #fffbeb !important; border: 1.2px dashed #f59e0b !important;
  border-radius: 12px !important; padding: 7px 9px !important;
  font-size: 9.5px !important; font-weight: 700 !important; color: #78350f !important;
  line-height: 1.35 !important; margin-bottom: 8px !important;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO SECTION: NONTON VIDEO & DISPLAY SALDO VIP (FLEX READY)
     ══════════════════════════════════════════════════════════ -->
<div class="amber-hero-wrap">
  <!-- User Profile Row -->
  <div class="hero-user-bar">
    <div class="hero-user-left">
      <div class="hero-avatar-box">
        <img src="<?= htmlspecialchars(site_logo($pdo)) ?>" alt="MancingCuan">
        <span class="hero-avatar-badge"><i class="ph-bold ph-check"></i></span>
      </div>
      <div class="hero-user-meta">
        <div class="hero-user-name">
          <span><?= htmlspecialchars($user['username']) ?></span>
          <i class="ph-fill ph-crown" style="font-size:16px;color:#fbbf24;"></i>
        </div>
        <div class="hero-tier-pill">
          <i class="ph-fill ph-crown"></i>
          <span><?= htmlspecialchars($membership_name) ?></span>
        </div>
      </div>
    </div>
    <?php if ($is_guest): ?>
      <a href="/login" class="hero-profile-btn">
        <i class="ph-bold ph-sign-in"></i> Masuk
      </a>
    <?php else: ?>
      <a href="/profile" class="hero-profile-btn">
        <i class="ph-bold ph-gear"></i> Profil
      </a>
    <?php endif; ?>
  </div>

  <!-- Mascot Speech Bubble -->
  <div class="mascot-dialogue">
    <div class="mascot-dialogue__icon">
      <i class="ph-fill ph-sparkle" style="font-size:18px;color:#d97706;"></i>
    </div>
    <div class="mascot-dialogue__text">
      Halo <strong><?= htmlspecialchars($user['username']) ?></strong>! Nonton video hari ini & kumpulkan saldo Rupiah. Bagikan screenshot bukti cuanmu ke teman untuk raih komisi!
    </div>
  </div>

  <!-- ── THE VIP FLEX CARD (SCREENSHOT TARGET) ── -->
  <div class="cuan-flex-card" id="cuan-ss-target">
    <!-- Header Card -->
    <div class="cuan-card-header">
      <div class="cuan-card-brand">
        <span class="cuan-card-brand-badge"><i class="ph-fill ph-lightning"></i> VIP CASH</span>
        <span>MANCINGCUAN RESMI</span>
      </div>
      <div style="display:flex;align-items:center;gap:6px;">
        <div class="cuan-card-status-verified">
          <span class="pulse-dot"></span>
          <span>Siap Cair 24 Jam</span>
        </div>
        <a href="/history" class="cuan-card-history-link" title="Buka Riwayat Keuangan Lengkap">
          <i class="ph-bold ph-clock-counter-clockwise"></i>
          <span>Riwayat</span>
        </a>
      </div>
    </div>

    <!-- Main Balance Display -->
    <div class="cuan-card-balance-box" id="tour-balance-box">
      <div class="cuan-balance-sub">
        <i class="ph-fill ph-shield-check" style="color:#059669;font-size:13px;"></i>
        <span>Saldo Siap Tarik (WD)</span>
      </div>
      <div class="cuan-balance-main">
        <span class="curr">Rp</span>
        <span><?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></span>
      </div>
    </div>

    <!-- Dual Stat Pills: Cuan Hari Ini & Total Dihasilkan -->
    <div class="cuan-stats-pills">
      <div class="cuan-pill">
        <div class="cuan-pill-lbl">
          <i class="ph-fill ph-sparkle" style="color:#10b981;"></i> Cuan Hari Ini
        </div>
        <div class="cuan-pill-val cuan-pill-val--today">
          +Rp <?= number_format($today_watch_earned, 0, ',', '.') ?>
        </div>
      </div>
      <div class="cuan-pill">
        <div class="cuan-pill-lbl">
          <i class="ph-fill ph-trophy" style="color:#f59e0b;"></i> Total Dihasilkan
        </div>
        <div class="cuan-pill-val cuan-pill-val--total">
          Rp <?= number_format((float)($user['total_earned'] ?? 0), 0, ',', '.') ?>
        </div>
      </div>
    </div>

    <!-- Referral Code Strip on Card (High Visibility for Screenshots!) -->
    <div class="cuan-ref-banner" onclick="copyRefCode('<?= htmlspecialchars($ref_code) ?>')" title="Klik untuk menyalin kode referral">
      <div class="cuan-ref-left">
        <span class="cuan-ref-tag"><i class="ph-bold ph-ticket"></i> KODE REFF</span>
        <span class="cuan-ref-code"><?= htmlspecialchars($ref_code) ?></span>
      </div>
      <button type="button" class="cuan-ref-copy-btn">
        <i class="ph-bold ph-copy"></i>
        <span>Salin</span>
      </button>
    </div>

    <!-- Integrated Video Task Mission Bar -->
    <div class="cuan-video-mission" id="tour-video-mission">
      <div class="cvm-head">
        <span class="cvm-badge"><i class="ph-fill ph-film-strip"></i> Misi Video Hari Ini</span>
        <span class="cvm-quota">Kuota: <?= $watch_today ?> / <?= $watch_limit ?></span>
      </div>

      <div class="cvm-bar-wrap">
        <div class="cvm-bar-fill" style="width: <?= $watch_pct ?>%;"></div>
      </div>

      <div class="cvm-foot">
        <span>Progres: <strong><?= $watch_pct ?>% Selesai</strong></span>
        <span><?= $watch_remaining > 0 ? "Sisa {$watch_remaining} video lagi" : "✓ Kuota Penuh!" ?></span>
      </div>
    </div>

    <!-- Highlight Tombol Upgrade jika User Sudah Berpenghasilan Lumayan -->
    <?php if ($user_has_good_income): ?>
      <a href="/upgrade" class="btn-upgrade-highlight" title="Upgrade VIP untuk lipatgandakan kuota dan cuan">
        <div class="buh-content">
          <div class="buh-badge">
            <i class="ph-fill ph-crown" style="color:#f59e0b;"></i>
            <span>REKOMENDASI UPGRADE VIP</span>
            <span class="buh-badge-tag">PANEN S/D 120 VIDEO</span>
          </div>
          <div class="buh-text">
            <span>Sudah berpenghasilan? Buka kuota <strong>s/d 120 video/hari</strong> &amp; panen jutaan!</span>
          </div>
        </div>
        <div class="buh-arrow">
          <span>Upgrade</span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </a>
    <?php endif; ?>

    <!-- Primary CTA: Mulai Nonton Video Sekarang -->
    <a href="/videos" class="btn-cuan-watch-now" id="tour-btn-watch">
      <i class="ph-fill ph-play-circle" style="font-size:20px;"></i>
      <span><?= $watch_remaining > 0 ? 'Mulai Tonton Video Sekarang' : 'Buka Galeri Video & Cuan' ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Quick Action Buttons -->
    <div class="cuan-card-actions">
      <a href="/withdraw" class="cuan-btn-action cuan-btn-action--wd" id="tour-btn-withdraw">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik Saldo
      </a>
      <a href="/deposit" class="cuan-btn-action cuan-btn-action--dep">
        <i class="ph-bold ph-wallet"></i> Top Up
      </a>
      <a href="/history" class="cuan-btn-action cuan-btn-action--history" title="Riwayat Keuangan Lengkap">
        <i class="ph-bold ph-clock-counter-clockwise"></i> Riwayat
      </a>
    </div>
  </div>
</div>

<div class="home-feed">

  <!-- BANNER AJAKAN TOUR INTERAKTIF UNTUK PENGGUNA BARU -->
  <div id="tour-invite-card" class="tour-invite-card">
    <div class="tour-invite-header">
      <div class="tour-invite-badge">
        <span class="tour-pulse-icon"><i class="ph-fill ph-compass"></i></span>
        <span>PANDUAN PENGGUNA BARU</span>
      </div>
      <button type="button" class="tour-invite-close" onclick="dismissTourBanner()" title="Tutup Banner">
        <i class="ph-bold ph-x"></i>
      </button>
    </div>
    <div class="tour-invite-title">Baru di MancingCuan? Yuk Ikuti Panduan Singkat! 🎣</div>
    <div class="tour-invite-desc">Pelajari langkah mudah menghasilkan saldo rupiah dari nonton video dan selesaikan misi:</div>
    <div class="tour-invite-buttons">
      <button type="button" class="btn-tour-choice btn-tour-choice--watch" onclick="startInteractiveTour('watch')" style="width:100%;">
        <div class="btn-tour-choice-icon"><i class="ph-fill ph-film-strip"></i></div>
        <div class="btn-tour-choice-info">
          <span class="btn-tour-choice-title">Panduan Cuan Nonton Video</span>
          <span class="btn-tour-choice-sub">Misi Tontonan & Tarik Saldo WD</span>
        </div>
        <i class="ph-bold ph-caret-right btn-tour-choice-arrow"></i>
      </button>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       1. MENU CEPAT & AKSES UTAMA (QUICK ACCESS)
       ══════════════════════════════════════════════════════════ -->
  <div style="font-size:13px;font-weight:900;color:#78350f;display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
    <div style="display:flex;align-items:center;gap:6px;">
      <i class="ph-fill ph-squares-four" style="color:#d97706;font-size:18px;"></i>
      <span>Menu Cepat & Fitur Cuan</span>
    </div>
    <button type="button" onclick="openTourSelectModal()" class="btn-tour-pill">
      <i class="ph-bold ph-compass"></i> Panduan Tour
    </button>
  </div>

  <div class="bento-menu-grid">
    <!-- Tile 1: Nonton Video -->
    <a href="/videos" class="b-tile" id="tour-tile-video">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#f97316,#ea580c);">
        <i class="ph-fill ph-film-strip"></i>
      </div>
      <span class="b-tile__lbl">Tonton</span>
    </a>

    <!-- Tile 2: Minigame Putar Roda -->
    <a href="/minigame" class="b-tile" id="tour-tile-minigame">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#6366f1,#4f46e5);border-color:#3730a3;">
        <i class="ph-fill ph-game-controller"></i>
      </div>
      <span class="b-tile__lbl">Minigame</span>
    </a>

    <!-- Tile 3: Bonus Promo Threads -->
    <a href="/threads" class="b-tile" id="tour-tile-threads">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#18181b,#27272a);border-color:#52525b;">
        <i class="ph-bold ph-threads-logo"></i>
      </div>
      <span class="b-tile__lbl">Threads</span>
    </a>

    <!-- Tile 4: Survei Berbayar -->
    <a href="/survey" class="b-tile" id="tour-tile-survey">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#059669,#047857);border-color:#064e3b;">
        <i class="ph-fill ph-clipboard-text"></i>
      </div>
      <span class="b-tile__lbl">Survei</span>
    </a>

    <!-- Tile 5: Absen Hadiah Harian (Checkin) -->
    <a href="/checkin" class="b-tile" id="tour-tile-checkin">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);border-color:#78350f;">
        <i class="ph-fill ph-calendar-check"></i>
        <?php if (!$has_checked_in_today): ?>
          <span class="b-tile-badge-dot"></span>
        <?php endif; ?>
      </div>
      <span class="b-tile__lbl">Check-in</span>
    </a>

    <!-- Tile 6: Misi Harian -->
    <a href="/missions" class="b-tile" id="tour-tile-missions">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);border-color:#4c1d95;">
        <i class="ph-fill ph-target"></i>
      </div>
      <span class="b-tile__lbl">Misi</span>
    </a>

    <!-- Tile 7: Undang Teman -->
    <a href="/referral" class="b-tile" id="tour-tile-referral">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#10b981,#047857);border-color:#064e3b;">
        <i class="ph-fill ph-users-three"></i>
      </div>
      <span class="b-tile__lbl">Undang</span>
    </a>

    <!-- Tile 8: Upgrade VIP (Hanya muncul jika sudah menghasilkan cuan lumayan) / Panduan Cuan jika masih baru -->
    <?php if ($show_upgrade_feature): ?>
      <a href="/upgrade" class="b-tile <?= $user_has_good_income ? 'b-tile--highlighted' : 'b-tile--vip-glow' ?>" id="tour-tile-upgrade" style="position:relative;">
        <?php if ($user_has_good_income): ?>
          <span class="b-tile-badge-cuan"><i class="ph-fill ph-sparkle"></i> REKOMENDASI</span>
        <?php endif; ?>
        <div class="b-tile__icon" style="background:linear-gradient(135deg,#fbbf24,#d97706);border-color:#78350f;">
          <i class="ph-fill ph-crown" style="color:#78350f;"></i>
        </div>
        <span class="b-tile__lbl" style="color:#b45309;font-weight:900;">VIP Upgrade</span>
      </a>
    <?php else: ?>
      <a href="/panduan" class="b-tile">
        <div class="b-tile__icon" style="background:linear-gradient(135deg,#64748b,#475569);border-color:#334155;">
          <i class="ph-fill ph-book-open"></i>
        </div>
        <span class="b-tile__lbl">Panduan</span>
      </a>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       SURVEI PENGGUNA BERHADIAH RP 15.000 SALDO TARIK
       ══════════════════════════════════════════════════════════ -->
  <a href="/survey" class="survey-invite-banner" title="Isi Survei Singkat & Dapatkan Rp 15.000 Saldo Tarik">
    <div class="sib-left">
      <div class="sib-icon-box <?= $user_has_surveyed ? 'sib-icon-box--done' : '' ?>">
        <i class="ph-bold <?= $user_has_surveyed ? 'ph-check-circle' : 'ph-clipboard-text' ?>"></i>
      </div>
      <div class="sib-text">
        <div class="sib-title">
          <span><?= $user_has_surveyed ? 'Survei Selesai (Klaim Berhasil)' : 'Survei Pengguna Berhadiah' ?></span>
          <span class="sib-badge <?= $user_has_surveyed ? 'sib-badge--done' : '' ?>"><?= $user_has_surveyed ? 'Klaim Berhasil' : '+Rp 15.000 WD' ?></span>
        </div>
        <div class="sib-desc">
          <?= $user_has_surveyed ? 'Terima kasih telah berpartisipasi! Saldo tarik Rp 15.000 sudah masuk.' : 'Bantu kami berkembang 2 menit & dapatkan Rp 15.000 saldo tarik langsung!' ?>
        </div>
      </div>
    </div>
    <div class="sib-cta <?= $user_has_surveyed ? 'sib-cta--done' : '' ?>">
      <span><?= $user_has_surveyed ? 'Lihat' : 'Isi Survei' ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </div>
  </a>

  <!-- ══════════════════════════════════════════════════════════
       AJAKAN PROMOSI BANNER (PROMO HUB TRIGGER)
       ══════════════════════════════════════════════════════════ -->
  <div class="promo-invite-banner" onclick="openPromoHubModal()" title="Klik untuk Buka Event Promosi & Raih Saldo">
    <div class="pib-left">
      <div class="pib-icon-box">
        <i class="ph-bold ph-megaphone"></i>
      </div>
      <div class="pib-text">
        <div class="pib-title">
          <span>Ajak Teman &amp; Promosi Cuan</span>
          <span class="pib-badge">+Rp 75.000</span>
        </div>
        <div class="pib-desc">Viral di Threads &amp; bagikan link untuk panen komisi saldo tunai!</div>
      </div>
    </div>
    <div class="pib-cta">
      <span>Mulai</span>
      <i class="ph-bold ph-arrow-right"></i>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       1.5 LANJUTKAN MENONTON (MAKSIMAL 3 RIWAYAT)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($continue_watching_home)): ?>
    <div class="amber-section-card" style="padding:14px;margin-bottom:16px;border-color:#d97706;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <div style="font-size:13.5px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:6px;">
          <i class="ph-fill ph-clock-counter-clockwise" style="color:#d97706;font-size:18px;"></i>
          <span>Lanjutkan Menonton</span>
        </div>
        <span style="font-size:10.5px;font-weight:900;background:#fef3c7;color:#92400e;border:1.5px solid #f59e0b;padding:2px 8px;border-radius:10px;">
          <?= count($continue_watching_home) ?> / 3 Video
        </span>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($continue_watching_home as $cwh): 
          $cwh_pct = min(99, max(1, (int)round(($cwh['seconds_watched'] / $cwh['watch_duration']) * 100)));
        ?>
        <a href="/watch?id=<?= $cwh['id'] ?>" style="display:flex;align-items:center;gap:12px;background:#ffffff;border:2px solid rgba(255,255,255,0.95);border-radius:20px;padding:10px 12px;text-decoration:none;color:inherit;box-shadow:0 8px 18px rgba(120,53,15,0.08), inset 2px 2px 5px #fff, inset -2px -2px 5px rgba(180,83,9,0.05);transition:transform 0.15s ease;">
          <div style="position:relative;width:66px;height:42px;border-radius:12px;overflow:hidden;border:1.5px solid rgba(255,255,255,0.8);flex-shrink:0;background:#0f172a;box-shadow:0 4px 8px rgba(0,0,0,0.15);">
            <img src="<?= yt_thumb($cwh['youtube_id']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.src='https://img.youtube.com/vi/<?= $cwh['youtube_id'] ?>/hqdefault.jpg'">
            <div style="position:absolute;inset:0;background:rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;"><i class="ph-fill ph-play"></i></div>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:11.5px;font-weight:800;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:5px;">
              <?= htmlspecialchars($cwh['title']) ?>
            </div>
            <div style="height:6px;background:#fed7aa;border-radius:999px;overflow:hidden;margin-bottom:4px;box-shadow:inset 1px 1px 2px rgba(180,83,9,0.2);">
              <div style="height:100%;background:linear-gradient(90deg,#f59e0b,#10b981);border-radius:999px;width:<?= $cwh_pct ?>%;"></div>
            </div>
            <div style="font-size:9.5px;font-weight:800;color:#64748b;display:flex;justify-content:space-between;">
              <span><?= $cwh['seconds_watched'] ?>s / <?= $cwh['watch_duration'] ?>s (<?= $cwh_pct ?>%)</span>
              <span style="color:#d97706;font-weight:900;">+<?= format_rp((float)$cwh['reward_amount']) ?></span>
            </div>
          </div>
          <div style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-size:11px;font-weight:900;padding:7px 12px;border-radius:12px;border:1.5px solid rgba(255,255,255,0.8);flex-shrink:0;display:flex;align-items:center;gap:4px;box-shadow:0 4px 10px rgba(245,158,11,0.35), inset 1px 1px 2px rgba(255,255,255,0.7);">
            Lanjut <i class="ph-bold ph-arrow-right"></i>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       1.7 CARD PROMOSI THREADS (EVENT CUAN & PROMOTOR KHUSUS)
       ══════════════════════════════════════════════════════════ -->
  <?php if ($threads_campaign_enabled): ?>
    <div class="threads-promo-home-card" id="tour-threads-promo-card">
      <div class="tph-badge-strip">
        <span class="tph-badge-event"><i class="ph-bold ph-threads-logo"></i> EVENT PROMOSI THREADS</span>
        <span class="tph-badge-cuan">+Rp 75.000 &amp; Gaji Pasti</span>
      </div>
      <div class="tph-content">
        <div class="tph-icon-box" style="display:flex;align-items:center;justify-content:center;background:#fffbeb;border:2px solid #f59e0b;border-radius:14px;width:48px;height:48px;flex-shrink:0;">
          <i class="ph-bold ph-threads-logo" style="font-size:26px;color:#d97706;"></i>
        </div>
        <div class="tph-text-col">
          <div class="tph-title">Viral di Threads &amp; Jadi Promotor Khusus! 👑</div>
          <div class="tph-desc">
            Posting ceritamu, raih <strong><?= format_rp($threads_step1_reward) ?></strong> di Langkah 1 &amp; raih <strong><?= format_rp($threads_step2_reward) ?> + Gaji Pasti &amp; Chat Admin WA</strong> jika postinganmu tembus 5K views!
          </div>
        </div>
      </div>
      <div class="tph-actions">
        <a href="/threads" class="tph-btn-primary">
          <i class="ph-bold ph-threads-logo"></i>
          <span>Ikuti Event Threads</span>
          <i class="ph-bold ph-arrow-right"></i>
        </a>
        <button type="button" class="tph-btn-info" onclick="openThreadsPromoModal()" title="Lihat Ketentuan Lengkap">
          <i class="ph-bold ph-info"></i> Detail
        </button>
      </div>
    </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       2. TUGAS VIDEO PILIHAN HARI INI (FEATURED VIDEOS)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($featured_videos)): ?>
    <div class="amber-section-card" style="padding:14px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
        <div style="font-size:14px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:6px;">
          <i class="ph-fill ph-sparkle" style="color:#f59e0b;font-size:18px;"></i>
          <span>Video Pilihan Berhadiah</span>
        </div>
        <a href="/videos" style="font-size:11px;font-weight:900;color:#d97706;text-decoration:none;display:flex;align-items:center;gap:2px;">
          Lihat Semua &rarr;
        </a>
      </div>
      <div style="font-size:10.5px;font-weight:700;color:#94a3b8;margin-bottom:12px;">
        Tonton video pilihan berdurasi singkat dan saldo cuan langsung bertambah otomatis!
      </div>

      <div class="v-grid-mini">
        <?php foreach ($featured_videos as $fv):
          $done = (bool)$fv['watched_today'];
          $thumbUrl = yt_thumb($fv['youtube_id']);
        ?>
          <a href="<?= $done ? '/videos' : '/watch?id='.$fv['id'] ?>" class="v-card-mini">
            <div class="v-thumb-wrap">
              <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($fv['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $fv['youtube_id'] ?>/hqdefault.jpg'">
              <div class="v-badge-reward">+Rp <?= number_format((float)$fv['reward_amount'], 0, ',', '.') ?></div>
              <div class="v-badge-duration"><i class="ph-bold ph-clock"></i> <?= $fv['watch_duration'] ?>s</div>
              <div class="v-play-overlay">
                <?php if ($done): ?>
                  <i class="ph-fill ph-check-circle" style="color:#10b981;font-size:26px;"></i>
                <?php else: ?>
                  <div class="v-play-btn-circle"><i class="ph-fill ph-play"></i></div>
                <?php endif; ?>
              </div>
            </div>
            <div class="v-info-mini">
              <div class="v-title-mini"><?= htmlspecialchars($fv['title']) ?></div>
              <div class="v-status-mini <?= $done ? 'v-status--done' : 'v-status--ready' ?>">
                <?= $done ? '<i class="ph-bold ph-check"></i> Selesai' : '<i class="ph-fill ph-play" style="color:#f59e0b;"></i> Tonton Sekarang' ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>


  <!-- ══════════════════════════════════════════════════════════
       3. FITUR SERU: MINIGAME PUTAR RODA & MISI CUAN
       ══════════════════════════════════════════════════════════ -->
  <div class="sidejob-3d-card" id="tour-sidejob-card" style="background:linear-gradient(145deg, #b45309 0%, #d97706 60%, #f59e0b 100%) !important;border:2px solid #fcd34d !important;">
    <div class="sidejob-badge-tag"><i class="ph-fill ph-game-controller"></i> Game Berhadiah</div>
    
    <div class="sidejob-title-row">
      <div class="sidejob-title">Putar Roda & Minigame <i class="ph-fill ph-sparkle" style="color:#fef08a;font-size:16px;"></i></div>
      <div style="width:38px;height:38px;border-radius:12px;background:#ffffff;display:flex;align-items:center;justify-content:center;color:#b45309;font-size:22px;box-shadow:0 4px 10px rgba(0,0,0,0.15);border:1.5px solid #fed7aa;">
        <i class="ph-fill ph-spinner"></i>
      </div>
    </div>

    <div class="sidejob-desc">
      Raih kejutan bonus saldo tunai setiap hari! Mainkan minigame putar roda keberuntungan dan kumpulkan hadiah uang tunai langsung ke saldo akunmu.
    </div>

    <!-- CTA Button directly to Minigame -->
    <a href="/minigame" class="btn-open-3d-farm">
      <i class="ph-fill ph-game-controller" style="font-size:18px;"></i>
      <span>Mainkan Minigame Sekarang</span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Sub links for Missions & Threads -->
    <div class="sidejob-sub-links">
      <a href="/missions" class="sidejob-sub-link"><i class="ph-bold ph-target"></i> Misi Tantangan</a>
      <a href="/threads" class="sidejob-sub-link"><i class="ph-bold ph-threads-logo"></i> Event Promo Rp 75K</a>
    </div>
  </div>

  <!-- ── TIPS CUAN PLATFORM ── -->
  <div class="amber-tips-box">
    <div class="amber-tips-icon"><i class="ph-fill ph-lightbulb" style="color:#d97706;font-size:22px;"></i></div>
    <div>
      <div style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:2px;">Tips Cuan Harian</div>
      <div style="font-size:10.5px;font-weight:700;color:#78350f;line-height:1.35;">
        Tonton video sampai kuota harianmu penuh, selesaikan misi berhadiah, dan undang teman untuk meraih komisi pasif tanpa batas!
      </div>
    </div>
  </div>

  <!-- ── NOTIFIKASI INBOX ── -->
  <?php if (!empty($notif_preview)): ?>
    <div style="background:#ffffff;border:2.5px solid rgba(255,255,255,0.95);border-radius:24px;padding:14px 16px;box-shadow:0 14px 28px rgba(120,53,15,0.08), inset 2px 2px 5px #ffffff, inset -3px -3px 6px rgba(120,53,15,0.05);margin-bottom:16px;">
      <div style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-bell-ringing" style="color:#e11d48;"></i> Informasi & Pengumuman
      </div>
      <?php foreach ($notif_preview as $nt): ?>
        <div style="padding:4px 0;border-bottom:1px solid #fef3c7;">
          <div style="font-size:11.5px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($nt['title']) ?></div>
          <div style="font-size:10px;font-weight:700;color:#64748b;"><?= htmlspecialchars(mb_substr($nt['message'], 0, 75)) ?>...</div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ── FLOATING BUTTON: AJAKAN PROMOSI ── -->
  <div class="floating-promo-btn" id="btn-floating-promo" onclick="openPromoHubModal()" title="Klik untuk Buka Event Promosi Cuan">
    <div class="fpb-icon-wrap">
      <i class="ph-bold ph-megaphone"></i>
    </div>
    <div class="fpb-text-wrap">
      <span class="fpb-title">Bonus Promosi</span>
      <span class="fpb-sub">
        Raih Rp 75K <span class="fpb-badge">Hot</span>
      </span>
    </div>
  </div>

</div>

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
