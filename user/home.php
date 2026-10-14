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

// ── KONFIGURASI HOME CMS ──
$cms = home_cms_config($pdo);

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
   MANCINGCUAN — OCEANIC CLAYMORPHISM 3D DESIGN SYSTEM
   Deep Sea, Aqua Reef, Tactile Marine Bubbles & Soft Depth
   ══════════════════════════════════════════════════════════ */

:root {
  --ocean-bg: #f0f7fc;
  --ocean-surface: #ffffff;
  --ocean-ink: #0f172a;
  --ocean-sub: #475569;
  --ocean-blue-deep: #075985;
  --ocean-blue-mid: #0284c7;
  --ocean-blue-light: #0ea5e9;
  --ocean-cyan: #38bdf8;
  --ocean-cyan-light: #bae6fd;
  --ocean-teal: #06b6d4;
  --ocean-emerald: #10b981;
  --ocean-gold: #f59e0b;
  --ocean-coral: #f43f5e;
}

body {
  background: var(--ocean-bg) !important;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
  color: var(--ocean-ink);
  -webkit-font-smoothing: antialiased;
}

/* ── 1. HERO SECTION: THE OCEAN CAPTAIN DECK ── */
.amber-hero-wrap {
  background: linear-gradient(180deg, #075985 0%, #0369a1 35%, #0284c7 70%, #0ea5e9 100%) !important;
  padding: 12px 12px 20px !important;
  position: relative;
  overflow: hidden;
  border-radius: 0 0 32px 32px !important;
  border-bottom: 3px solid #0284c7 !important;
  box-shadow: 
    0 16px 36px rgba(2, 132, 199, 0.28),
    0 4px 12px rgba(0, 0, 0, 0.08),
    inset 0 2px 6px rgba(255, 255, 255, 0.5) !important;
}

/* Ambient Sea Water Bubbles */
.amber-hero-wrap::before {
  content: ''; position: absolute; inset: 0;
  background-image: 
    radial-gradient(rgba(255,255,255,0.25) 15%, transparent 16%), 
    radial-gradient(rgba(255,255,255,0.2) 15%, transparent 16%);
  background-size: 26px 26px;
  background-position: 0 0, 13px 13px;
  opacity: 0.25; pointer-events: none;
}
.amber-hero-wrap::after {
  content: ''; position: absolute; top: -50px; right: -50px;
  width: 200px; height: 200px; border-radius: 50%;
  background: radial-gradient(circle, rgba(56, 189, 248, 0.35) 0%, transparent 70%);
  pointer-events: none;
}

/* User Profile Row */
.hero-user-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 9px; position: relative; z-index: 2;
}
.hero-user-left {
  display: flex; align-items: center; gap: 9px;
}

/* Clay Avatar Cushion (Diving Helmet Aesthetic) */
.hero-avatar-box {
  width: 42px; height: 42px;
  background: #ffffff !important;
  border: 2px solid #bae6fd !important;
  border-radius: 15px !important;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 
    0 6px 14px rgba(2, 132, 199, 0.22),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(2, 132, 199, 0.12) !important;
  position: relative; flex-shrink: 0;
  animation: oceanFloat 4s ease-in-out infinite;
}
.hero-avatar-box img { width: 28px; height: 28px; object-fit: contain; }
@keyframes oceanFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

.hero-avatar-badge {
  position: absolute; bottom: -3px; right: -3px;
  background: linear-gradient(135deg, #38bdf8, #0284c7) !important;
  color: #fff; border-radius: 50%;
  width: 16px; height: 16px;
  border: 1.5px solid #ffffff !important;
  display: flex; align-items: center; justify-content: center;
  font-size: 8.5px;
  box-shadow: 0 2px 6px rgba(2,132,199,0.5), inset 1px 1px 2px rgba(255,255,255,0.7) !important;
}

.hero-user-meta { line-height: 1.2; }
.hero-user-name {
  font-size: 15px !important; font-weight: 900 !important; color: #ffffff !important;
  text-shadow: 0 1.5px 3px rgba(7, 89, 133, 0.6) !important;
  display: flex; align-items: center; gap: 5px;
}
.hero-tier-pill {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(7, 89, 133, 0.55) !important;
  border: 1px solid rgba(186, 230, 253, 0.9) !important;
  border-radius: 999px !important;
  padding: 2px 8px !important; font-size: 8.5px !important; font-weight: 900 !important;
  color: #bae6fd !important; margin-top: 2px;
  box-shadow: inset 1px 1px 2px rgba(255,255,255,0.3) !important;
}

/* Clay Profile Button */
.hero-profile-btn {
  background: #ffffff !important;
  border: 1.5px solid #bae6fd !important;
  border-radius: 14px !important;
  padding: 6px 12px !important; font-size: 11px !important; font-weight: 900 !important;
  color: #0284c7 !important; text-decoration: none;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.16),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.08) !important;
  display: flex; align-items: center; gap: 4px;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
}
.hero-profile-btn:active {
  transform: translateY(2px) scale(0.94) !important;
  box-shadow: 0 2px 5px rgba(2, 132, 199, 0.12), inset 1px 1px 2px rgba(2, 132, 199, 0.12) !important;
}

/* ── MARINE MASCOT SPEECH BUBBLE ── */
.mascot-dialogue {
  background: #ffffff !important;
  border: 1.5px solid #bae6fd !important;
  border-radius: 18px !important;
  padding: 8px 12px !important;
  margin-bottom: 11px !important;
  position: relative;
  z-index: 2;
  box-shadow: 
    0 8px 20px rgba(2, 132, 199, 0.14),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(2, 132, 199, 0.06) !important;
  display: flex;
  align-items: center;
  gap: 9px;
}
.mascot-dialogue__icon { font-size: 20px; flex-shrink: 0; animation: fishFin 2.4s infinite ease-in-out; }
@keyframes fishFin { 0%, 100% { transform: scale(1) rotate(0deg); } 50% { transform: scale(1.12) rotate(6deg); } }
.mascot-dialogue__text { font-size: 11px !important; font-weight: 800 !important; color: #0f172a !important; line-height: 1.4 !important; }
.mascot-dialogue__text strong { color: #0284c7; font-weight: 900; }

/* ══════════════════════════════════════════════════════════
   THE DUAL OCEANIC TREASURE VAULT CARD (SCREENSHOT TARGET)
   ══════════════════════════════════════════════════════════ */
.cuan-flex-card {
  background: linear-gradient(145deg, #ffffff 0%, #f8fafc 45%, #f0f7fc 100%) !important;
  border: 2px solid #bae6fd !important;
  border-radius: 26px !important;
  box-shadow: 
    0 18px 40px rgba(2, 132, 199, 0.16),
    0 4px 10px rgba(0, 0, 0, 0.03),
    inset 3px 3px 8px #ffffff,
    inset -3px -3px 8px rgba(2, 132, 199, 0.06) !important;
  padding: 14px 14px 12px !important;
  position: relative;
  z-index: 2;
  overflow: hidden;
}

.cuan-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.cuan-card-brand {
  display: flex; align-items: center; gap: 6px;
  font-size: 10px; font-weight: 900; color: #0369a1; letter-spacing: 0.3px;
}
.cuan-card-brand-badge {
  background: linear-gradient(135deg, #38bdf8, #0284c7);
  color: #fff; padding: 2px 7px; border-radius: 6px; font-size: 8.5px;
  border: 1px solid rgba(255, 255, 255, 0.8);
  box-shadow: 0 2px 5px rgba(2, 132, 199, 0.3);
}
.cuan-card-status-verified {
  display: inline-flex; align-items: center; gap: 4px;
  background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 999px;
  padding: 2px 8px; font-size: 9px; font-weight: 900; color: #059669;
}
.cuan-card-status-verified .pulse-dot {
  width: 6px; height: 6px; border-radius: 50%; background: #10b981;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3);
  animation: pulseDot 2s infinite;
}
@keyframes pulseDot { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.6; transform: scale(1.2); } }

.cuan-card-history-link {
  display: inline-flex; align-items: center; gap: 3px;
  background: #e0f2fe; border: 1.2px solid #bae6fd; border-radius: 999px;
  padding: 2px 8px; font-size: 9px; font-weight: 900; color: #0284c7;
  text-decoration: none; transition: transform 0.12s;
}
.cuan-card-history-link:active { transform: scale(0.95); }

/* ── DUAL TREASURE VAULT (COMPARTMENTS) ── */
.ocean-vault-grid {
  display: grid;
  grid-template-columns: 1.15fr 0.85fr;
  gap: 10px;
  margin-bottom: 12px;
}
@media (max-width: 350px) {
  .ocean-vault-grid { grid-template-columns: 1fr; }
}

.vault-box {
  border-radius: 20px;
  padding: 11px 12px;
  position: relative;
  overflow: hidden;
  transition: transform 0.15s ease;
}
.vault-box--wd {
  background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
  border: 2px solid #38bdf8;
  color: #ffffff;
  box-shadow: 
    0 8px 18px rgba(2, 132, 199, 0.28),
    inset 2px 2px 4px rgba(255, 255, 255, 0.5),
    inset -2px -2px 4px rgba(7, 89, 133, 0.4);
}
.vault-box--dep {
  background: #ffffff;
  border: 2px solid #bae6fd;
  color: #0f172a;
  box-shadow: 
    0 8px 18px rgba(2, 132, 199, 0.08),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.06);
}

.vault-label {
  font-size: 8px;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  display: flex;
  align-items: center;
  gap: 4px;
  margin-bottom: 3px;
}
.vault-box--wd .vault-label { color: #e0f2fe; }
.vault-box--dep .vault-label { color: #64748b; }

.vault-val-row {
  display: flex;
  align-items: baseline;
  gap: 3px;
  margin-bottom: 6px;
}
.vault-curr {
  font-size: 11px;
  font-weight: 800;
  opacity: 0.9;
}
.vault-val-main {
  font-size: 18px;
  font-weight: 900;
  letter-spacing: -0.5px;
  line-height: 1;
}
.vault-box--wd .vault-val-main { color: #ffffff; text-shadow: 0 1.5px 3px rgba(7, 89, 133, 0.5); }
.vault-box--dep .vault-val-main { color: #0284c7; }

/* Mini quick button inside vault */
.vault-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 3px;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 9.5px;
  font-weight: 900;
  text-decoration: none;
  width: 100%;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1), inset 1px 1px 2px rgba(255,255,255,0.6);
  transition: transform 0.12s;
}
.vault-action-btn:active { transform: scale(0.96); }
.vault-action-btn--wd {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff;
  border: 1px solid #fde68a;
}
.vault-action-btn--dep {
  background: #e0f2fe;
  color: #0284c7;
  border: 1px solid #7dd3fc;
}

/* ── STATS PILLS: CUAN HARI INI & TOTAL ── */
.cuan-stats-pills {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 11px;
}
.cuan-pill {
  background: #ffffff;
  border: 1.5px solid #e2e8f0;
  border-radius: 16px;
  padding: 8px 10px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.04),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.03);
}
.cuan-pill-lbl {
  font-size: 8.5px;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
  margin-bottom: 2px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.cuan-pill-val {
  font-size: 13.5px;
  font-weight: 900;
  line-height: 1.1;
}
.cuan-pill-val--today { color: #059669; }
.cuan-pill-val--total { color: #0284c7; }

/* ── REFERRAL STRIP ── */
.cuan-ref-banner {
  background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
  border: 1.5px dashed #38bdf8;
  border-radius: 16px;
  padding: 7px 11px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 11px;
  cursor: pointer;
  box-shadow: inset 1px 1px 3px #ffffff;
}
.cuan-ref-left {
  display: flex;
  align-items: center;
  gap: 7px;
}
.cuan-ref-tag {
  background: #0284c7;
  color: #fff;
  font-size: 7.5px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  letter-spacing: 0.3px;
}
.cuan-ref-code {
  font-size: 12px;
  font-weight: 900;
  color: #0369a1;
  letter-spacing: 0.5px;
  font-family: monospace;
}
.cuan-ref-copy-btn {
  background: #ffffff;
  border: 1.2px solid #bae6fd;
  border-radius: 999px;
  padding: 3px 9px;
  font-size: 9.5px;
  font-weight: 900;
  color: #0284c7;
  display: flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  box-shadow: 0 2px 5px rgba(2, 132, 199, 0.12), inset 1px 1px 2px #fff;
}

/* ── RADAR SONAR MISI VIDEO ── */
.cuan-video-mission {
  background: #f8fafc;
  border: 1.5px solid #bae6fd;
  border-radius: 18px;
  padding: 9px 11px;
  margin-bottom: 11px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.05),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.03);
}
.cvm-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.cvm-badge {
  font-size: 10px;
  font-weight: 900;
  color: #0369a1;
  display: flex;
  align-items: center;
  gap: 4px;
}
.cvm-quota {
  font-size: 10px;
  font-weight: 900;
  color: #0284c7;
  background: #e0f2fe;
  padding: 2px 7px;
  border-radius: 8px;
  border: 1px solid #7dd3fc;
}
.cvm-bar-wrap {
  width: 100%;
  height: 8px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
  margin-bottom: 5px;
  box-shadow: inset 1px 1px 3px rgba(0,0,0,0.1);
}
.cvm-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #38bdf8 0%, #0284c7 60%, #10b981 100%);
  border-radius: 999px;
  transition: width 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
  box-shadow: 0 0 6px rgba(16, 185, 129, 0.4);
}
.cvm-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 9px;
  font-weight: 800;
  color: #64748b;
}

/* ── PRIMARY WATCH CTA BUTTON ── */
.btn-cuan-watch-now {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
  color: #ffffff !important;
  font-size: 13.5px !important;
  font-weight: 900 !important;
  padding: 12px 18px !important;
  border-radius: 18px !important;
  text-decoration: none !important;
  border: 2px solid rgba(255, 255, 255, 0.85) !important;
  box-shadow: 
    0 10px 24px rgba(217, 119, 6, 0.38),
    0 2px 6px rgba(0, 0, 0, 0.06),
    inset 2px 2px 5px rgba(255, 255, 255, 0.7),
    inset -2px -2px 5px rgba(180, 83, 9, 0.3) !important;
  margin-bottom: 11px !important;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  text-shadow: 0 1px 2px rgba(180, 83, 9, 0.5) !important;
}
.btn-cuan-watch-now:active {
  transform: translateY(2px) scale(0.97) !important;
  box-shadow: 0 4px 10px rgba(217, 119, 6, 0.3), inset 2px 2px 4px rgba(180, 83, 9, 0.4) !important;
}

/* ── QUICK ACTION PILLS (4 TOMBOL AKSI) ── */
.cuan-card-actions {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 8px;
}
.cuan-btn-action {
  padding: 9px 8px;
  border-radius: 15px;
  font-size: 10.5px;
  font-weight: 900;
  text-decoration: none;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  transition: transform 0.12s;
  border: 1.5px solid transparent;
}
.cuan-btn-action:active { transform: translateY(2px) scale(0.94); }
.cuan-btn-action--wd {
  background: #ffffff;
  color: #0284c7;
  border-color: #bae6fd;
  box-shadow: 0 4px 10px rgba(2, 132, 199, 0.1), inset 1px 1px 3px #ffffff;
}
.cuan-btn-action--dep {
  background: #ffffff;
  color: #0f766e;
  border-color: #99f6e4;
  box-shadow: 0 4px 10px rgba(15, 118, 110, 0.1), inset 1px 1px 3px #ffffff;
}
.cuan-btn-action--history {
  background: #ffffff;
  color: #64748b;
  border-color: #e2e8f0;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.04), inset 1px 1px 3px #ffffff;
}

/* ── HIGHLIGHT UPGRADE BUTTON ── */
.btn-upgrade-highlight {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  border: 1.5px solid #60a5fa;
  border-radius: 16px;
  padding: 8px 11px;
  margin-bottom: 11px;
  text-decoration: none;
  color: #1e40af;
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12), inset 1px 1px 2px #fff;
}
.buh-badge {
  display: flex; align-items: center; gap: 4px; font-size: 9px; font-weight: 900; margin-bottom: 2px;
}
.buh-badge-tag {
  background: #2563eb; color: #fff; font-size: 7.5px; padding: 1px 5px; border-radius: 4px;
}
.buh-text { font-size: 9.5px; font-weight: 700; line-height: 1.25; }
.buh-arrow {
  display: flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 900; color: #2563eb; flex-shrink: 0;
}

/* ══════════════════════════════════════════════════════════
   2. MAIN HOME FEED (CARDS & SECTIONS)
   ══════════════════════════════════════════════════════════ */
.home-feed {
  padding: 14px 12px 20px;
}

/* ── PANDUAN PENGGUNA BARU TOUR BANNER ── */
.tour-invite-card {
  background: #ffffff;
  border: 2px solid #bae6fd;
  border-radius: 22px;
  padding: 13px 14px;
  margin-bottom: 16px;
  box-shadow: 0 10px 24px rgba(2, 132, 199, 0.08), inset 2px 2px 4px #ffffff;
}
.tour-invite-header {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;
}
.tour-invite-badge {
  display: flex; align-items: center; gap: 4px; font-size: 9.5px; font-weight: 900; color: #0284c7;
}
.tour-invite-close {
  background: transparent; border: none; font-size: 14px; color: #94a3b8; cursor: pointer;
}
.tour-invite-title {
  font-size: 13px; font-weight: 900; color: #0f172a; margin-bottom: 3px;
}
.tour-invite-desc {
  font-size: 10.5px; font-weight: 700; color: #64748b; margin-bottom: 9px; line-height: 1.35;
}
.btn-tour-choice {
  background: #f0f9ff; border: 1.5px solid #7dd3fc; border-radius: 14px; padding: 8px 11px;
  display: flex; align-items: center; gap: 9px; cursor: pointer; text-align: left;
}
.btn-tour-choice-icon {
  width: 32px; height: 32px; background: #0284c7; color: #fff; border-radius: 10px;
  display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0;
}
.btn-tour-choice-info { flex: 1; }
.btn-tour-choice-title { display: block; font-size: 11px; font-weight: 900; color: #0369a1; }
.btn-tour-choice-sub { display: block; font-size: 9px; font-weight: 700; color: #64748b; }
.btn-tour-choice-arrow { font-size: 14px; color: #0284c7; }

/* ── 3. OCEAN BENTO ARCHIPELAGO (MENU KEPULAUAN FITUR) ── */
.bento-ocean-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-bottom: 16px;
}
.b-tile {
  background: #ffffff;
  border: 1.5px solid #bae6fd;
  border-radius: 18px;
  padding: 10px 4px 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  gap: 5px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.05),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.03);
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.b-tile:active {
  transform: translateY(2px) scale(0.93);
}
.b-tile__icon {
  width: 36px; height: 36px;
  border-radius: 13px;
  border: 1.5px solid rgba(255, 255, 255, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 18px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12), inset 1px 1px 3px rgba(255, 255, 255, 0.6);
}
.b-tile__label {
  font-size: 10px;
  font-weight: 900;
  color: #0f172a;
  text-align: center;
}

/* ── 4. CLAY CARDS & SECTIONS (OCEAN RETOUCH) ── */
.amber-section-card {
  background: #ffffff !important;
  border: 2px solid #bae6fd !important;
  border-radius: 24px !important;
  padding: 14px 14px 16px !important;
  margin-bottom: 16px !important;
  box-shadow: 
    0 12px 28px rgba(2, 132, 199, 0.08),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(2, 132, 199, 0.04) !important;
}

/* Mini video cards in aquarium grid */
.v-grid-mini {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.v-card-mini {
  background: #f8fafc;
  border: 1.5px solid #bae6fd;
  border-radius: 18px;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  box-shadow: 0 4px 10px rgba(2, 132, 199, 0.05);
  transition: transform 0.12s;
}
.v-card-mini:active { transform: scale(0.96); }
.v-thumb-wrap {
  position: relative;
  width: 100%;
  aspect-ratio: 16/9;
  background: #0f172a;
  overflow: hidden;
}
.v-thumb-wrap img {
  width: 100%; height: 100%; object-fit: cover;
}
.v-badge-reward {
  position: absolute; top: 5px; left: 5px;
  background: rgba(15, 23, 42, 0.85); color: #38bdf8;
  font-size: 9px; font-weight: 900; padding: 2px 6px; border-radius: 6px;
  border: 1px solid rgba(56, 189, 248, 0.5); backdrop-filter: blur(4px);
}
.v-badge-duration {
  position: absolute; bottom: 5px; right: 5px;
  background: rgba(15, 23, 42, 0.85); color: #fff;
  font-size: 8.5px; font-weight: 800; padding: 2px 5px; border-radius: 6px;
}
.v-play-overlay {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  background: rgba(0, 0, 0, 0.2);
}
.v-play-btn-circle {
  width: 30px; height: 30px; border-radius: 50%; background: #0284c7; color: #fff;
  display: flex; align-items: center; justify-content: center; font-size: 14px;
  box-shadow: 0 4px 10px rgba(2, 132, 199, 0.4);
}
.v-info-mini {
  padding: 8px 10px;
}
.v-title-mini {
  font-size: 11px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px;
}
.v-status-mini {
  font-size: 9.5px; font-weight: 900;
}
.v-status--ready { color: #0284c7; }
.v-status--done { color: #10b981; }

/* ── EVENT THREADS CARD ── */
.threads-promo-home-card {
  background: #ffffff !important;
  border: 2px solid #18181b !important;
  border-radius: 24px !important;
  padding: 14px !important;
  margin-bottom: 16px !important;
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.08), inset 2px 2px 4px #ffffff !important;
}
.tph-badge-strip {
  display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;
}
.tph-badge-event {
  background: #18181b; color: #fff; font-size: 8.5px; font-weight: 900; padding: 2px 7px; border-radius: 6px;
  display: inline-flex; align-items: center; gap: 3px;
}
.tph-badge-cuan {
  background: #ecfdf5; color: #059669; font-size: 9.5px; font-weight: 900; padding: 2px 8px; border-radius: 8px;
  border: 1px solid #a7f3d0;
}
.tph-content {
  display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
}
.tph-text-col { flex: 1; }
.tph-title { font-size: 12.5px; font-weight: 900; color: #0f172a; margin-bottom: 2px; }
.tph-desc { font-size: 10px; font-weight: 700; color: #64748b; line-height: 1.35; }
.tph-actions { display: flex; align-items: center; gap: 8px; }
.tph-btn-primary {
  flex: 1; background: #18181b; color: #fff; padding: 8px 12px; border-radius: 12px; text-decoration: none;
  font-size: 11px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; gap: 5px;
}
.tph-btn-info {
  background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 8px 12px; border-radius: 12px;
  font-size: 11px; font-weight: 900; cursor: pointer;
}

/* ── BANNERS: SURVEI & PROMO HUB ── */
.survey-invite-banner, .promo-invite-banner {
  background: #ffffff !important;
  border: 2px solid #bae6fd !important;
  border-radius: 20px !important;
  padding: 10px 12px !important;
  margin-bottom: 14px !important;
  display: flex; align-items: center; justify-content: space-between;
  text-decoration: none; color: inherit;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.08), inset 2px 2px 4px #ffffff !important;
}
.sib-left, .pib-left { display: flex; align-items: center; gap: 10px; flex: 1; }
.sib-icon-box, .pib-icon-box {
  width: 38px; height: 38px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.sib-icon-box { background: #e0f2fe; color: #0284c7; border: 1.2px solid #7dd3fc; }
.pib-icon-box { background: #ecfdf5; color: #059669; border: 1.2px solid #a7f3d0; }
.sib-title, .pib-title {
  font-size: 12px; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 5px; margin-bottom: 2px;
}
.sib-badge, .pib-badge {
  font-size: 8.5px; font-weight: 900; padding: 1px 6px; border-radius: 6px;
}
.sib-badge { background: #e0f2fe; color: #0284c7; }
.pib-badge { background: #ecfdf5; color: #059669; }
.sib-desc, .pib-desc { font-size: 9.5px; font-weight: 700; color: #64748b; line-height: 1.3; }
.sib-cta, .pib-cta {
  background: #0284c7; color: #fff; padding: 6px 11px; border-radius: 10px; font-size: 10.5px; font-weight: 900;
  display: flex; align-items: center; gap: 3px; flex-shrink: 0;
}
.pib-cta { background: #059669; }

/* ── TIPS CUAN BAHARI ── */
.amber-tips-box {
  background: #ffffff !important;
  border: 1.5px solid #bae6fd !important;
  border-radius: 18px !important;
  padding: 10px 12px !important;
  margin-bottom: 14px !important;
  display: flex; align-items: center; gap: 10px;
  box-shadow: 0 4px 12px rgba(2, 132, 199, 0.05), inset 1px 1px 3px #ffffff !important;
}
.amber-tips-icon {
  width: 34px; height: 34px; border-radius: 10px; background: #e0f2fe; color: #0284c7;
  display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
}

/* ── FLOATING PROMO BUTTON ── */
.floating-promo-btn {
  position: fixed; bottom: 84px; left: 16px; z-index: 500;
  background: linear-gradient(135deg, #0284c7, #0369a1);
  color: #fff; border: 2px solid #bae6fd; border-radius: 999px;
  padding: 6px 14px 6px 6px; display: flex; align-items: center; gap: 7px;
  box-shadow: 0 10px 24px rgba(2, 132, 199, 0.4), inset 1px 1px 2px rgba(255, 255, 255, 0.6);
  cursor: pointer;
}
.fpb-icon-wrap {
  width: 28px; height: 28px; border-radius: 50%; background: #ffffff; color: #0284c7;
  display: flex; align-items: center; justify-content: center; font-size: 14px;
}
.fpb-title { display: block; font-size: 10.5px; font-weight: 900; line-height: 1; }
.fpb-sub { display: block; font-size: 8.5px; font-weight: 700; color: #bae6fd; }
.fpb-badge { background: #f43f5e; color: #fff; padding: 1px 4px; border-radius: 4px; font-size: 7.5px; font-weight: 900; }

/* ── MODALS (OCEAN THEMED) ── */
.amber-modal-backdrop {
  display: none; position: fixed; inset: 0; background: rgba(12, 74, 110, 0.5);
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); z-index: 99999;
  align-items: center; justify-content: center; padding: 16px;
}
.amber-modal-backdrop.open { display: flex; }
.amber-modal-box {
  background: #ffffff; border: 2.5px solid #bae6fd; border-radius: 26px; padding: 20px;
  width: 100%; max-width: 400px; position: relative;
  box-shadow: 0 20px 48px rgba(2, 132, 199, 0.25), inset 2px 2px 5px #ffffff;
}
.amber-modal-close {
  position: absolute; top: 12px; right: 12px; background: #f1f5f9; border: none;
  width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 16px; color: #64748b; cursor: pointer;
}
.amber-modal-badge {
  width: 48px; height: 48px; border-radius: 16px; background: #e0f2fe; color: #0284c7;
  display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 12px;
}
.amber-modal-title { font-size: 16px; font-weight: 900; color: #0f172a; margin-bottom: 6px; }
.amber-modal-body { font-size: 12px; font-weight: 700; color: #64748b; line-height: 1.45; margin-bottom: 16px; }
.btn-modal-primary {
  background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; padding: 12px; border-radius: 16px;
  text-align: center; font-size: 13px; font-weight: 900; text-decoration: none; display: flex;
  align-items: center; justify-content: center; gap: 6px; border: 1.5px solid #38bdf8;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.35);
}
.btn-modal-dismiss {
  background: transparent; border: none; color: #94a3b8; font-size: 11.5px; font-weight: 800; cursor: pointer; padding: 6px;
}

/* ── PROMO HUB MODAL (OCEANIC RETOUCH) ── */
.phc-card {
  background: #ffffff !important; border: 1.5px solid #bae6fd !important;
  border-radius: 18px !important; padding: 12px !important; margin-bottom: 10px !important;
  box-shadow: 0 4px 12px rgba(2, 132, 199, 0.06), inset 1px 1px 3px #ffffff !important;
}
.phc-tag--threads { background: #18181b; color: #bae6fd; border: 1px solid #38bdf8; }
.phc-tag--referral { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
.phc-reward { font-size: 11px; font-weight: 900; color: #059669; }
.phc-title { font-size: 12.5px !important; font-weight: 900 !important; color: #0f172a !important; margin-bottom: 2px; }
.phc-desc { font-size: 10px !important; font-weight: 700 !important; color: #64748b !important; line-height: 1.35; margin-bottom: 8px; }
.btn-phc-action--primary {
  background: linear-gradient(135deg, #0284c7, #0369a1) !important; color: #ffffff !important; border-color: #38bdf8 !important;
}
.btn-phc-action--share {
  background: linear-gradient(135deg, #10b981, #059669) !important; color: #ffffff !important; border-color: #6ee7b7 !important;
}
.btn-phc-action--copy {
  background: #e0f2fe !important; color: #0284c7 !important; border-color: #bae6fd !important;
}
.phc-caption-box {
  background: #f0f9ff !important; border: 1.2px dashed #38bdf8 !important;
  border-radius: 12px !important; padding: 7px 9px !important;
  font-size: 9.5px !important; font-weight: 700 !important; color: #0369a1 !important;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO SECTION: THE OCEAN CAPTAIN DECK
     ══════════════════════════════════════════════════════════ -->
<div class="amber-hero-wrap">
  <!-- User Profile Row -->
  <?php if (!empty($cms['hero']['show_user_bar'])): ?>
  <div class="hero-user-bar">
    <div class="hero-user-left">
      <div class="hero-avatar-box">
        <img src="<?= htmlspecialchars(site_logo($pdo)) ?>" alt="MancingCuan">
        <span class="hero-avatar-badge"><i class="ph-bold ph-anchor"></i></span>
      </div>
      <div class="hero-user-meta">
        <div class="hero-user-name">
          <span><?= htmlspecialchars($user['username']) ?></span>
          <i class="ph-fill ph-crown" style="font-size:16px;color:#fde047;"></i>
        </div>
        <div class="hero-tier-pill">
          <i class="ph-fill ph-anchor"></i>
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
        <i class="ph-bold ph-gear"></i> Kabin
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Mascot Biota Laut Speech Bubble -->
  <?php if (!empty($cms['hero']['show_mascot'])): 
    $mascotRaw = $cms['hero']['mascot_text'] ?? 'Halo {username}! Nonton video hari ini & kumpulkan saldo Rupiah. Pancing ikan cuan & bagikan bukti hasilmu!';
    $mascotFormatted = str_replace('{username}', '<strong>' . htmlspecialchars($user['username']) . '</strong>', htmlspecialchars($mascotRaw));
  ?>
  <div class="mascot-dialogue">
    <div class="mascot-dialogue__icon">
      <i class="ph-fill ph-fish" style="color:#0284c7;font-size:22px;"></i>
    </div>
    <div class="mascot-dialogue__text">
      <?= $mascotFormatted ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── THE DUAL OCEANIC TREASURE VAULT CARD (SCREENSHOT TARGET) ── -->
  <?php if (!empty($cms['hero']['show_balance_card'])): ?>
  <div class="cuan-flex-card" id="cuan-ss-target">
    <!-- Header Card -->
    <div class="cuan-card-header">
      <div class="cuan-card-brand">
        <span class="cuan-card-brand-badge"><i class="ph-fill ph-shield-check"></i> SAMUDRA TREASURE</span>
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

    <!-- DUAL COMPARTMENT: SALDO WD & SALDO DEPO + UMPAN -->
    <div class="ocean-vault-grid">
      <!-- Compartment 1: Saldo Tarik WD -->
      <div class="vault-box vault-box--wd" id="tour-balance-box">
        <div class="vault-label">
          <i class="ph-fill ph-coins"></i> Saldo Siap Tarik (WD)
        </div>
        <div class="vault-val-row">
          <span class="vault-curr">Rp</span>
          <span class="vault-val-main"><?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></span>
        </div>
        <a href="/withdraw" class="vault-action-btn vault-action-btn--wd">
          <i class="ph-bold ph-arrow-up-right"></i> Tarik Cuan
        </a>
      </div>

      <!-- Compartment 2: Kas Depo & Umpan Pancing -->
      <div class="vault-box vault-box--dep">
        <div class="vault-label">
          <i class="ph-fill ph-wallet"></i> Kas Pembelian
        </div>
        <div class="vault-val-row">
          <span class="vault-curr" style="color:#0284c7;">Rp</span>
          <span class="vault-val-main" style="font-size:16px;"><?= number_format((float)($user['balance_dep'] ?? 0), 0, ',', '.') ?></span>
        </div>
        <div style="font-size:9.5px;font-weight:900;color:#0f766e;margin-bottom:6px;display:flex;align-items:center;gap:3px;">
          <i class="ph-fill ph-fish"></i> <?= (int)($user['fishing_baits'] ?? 0) ?> Umpan Pancing
        </div>
        <a href="/deposit" class="vault-action-btn vault-action-btn--dep">
          <i class="ph-bold ph-plus"></i> + Isi Kas
        </a>
      </div>
    </div>

    <!-- Dual Stat Pills: Cuan Hari Ini & Total Dihasilkan -->
    <?php if (!empty($cms['hero']['show_today_stats'])): ?>
    <div class="cuan-stats-pills">
      <div class="cuan-pill">
        <div class="cuan-pill-lbl">
          <i class="ph-fill ph-sparkle" style="color:#059669;"></i> Cuan Nonton Hari Ini
        </div>
        <div class="cuan-pill-val cuan-pill-val--today">
          +Rp <?= number_format($today_watch_earned, 0, ',', '.') ?>
        </div>
      </div>
      <div class="cuan-pill">
        <div class="cuan-pill-lbl">
          <i class="ph-fill ph-trophy" style="color:#0284c7;"></i> Total Panen Akun
        </div>
        <div class="cuan-pill-val cuan-pill-val--total">
          Rp <?= number_format((float)($user['total_earned'] ?? 0), 0, ',', '.') ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Referral Code Strip on Card (High Visibility for Screenshots!) -->
    <?php if (!empty($cms['hero']['show_ref_strip'])): ?>
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
    <?php endif; ?>

    <!-- Sonar Radar Misi Nonton Video -->
    <?php if (!empty($cms['hero']['show_watch_mission'])): ?>
    <div class="cuan-video-mission" id="tour-video-mission">
      <div class="cvm-head">
        <span class="cvm-badge"><i class="ph-fill ph-film-strip"></i> Sonar Misi Video Hari Ini</span>
        <span class="cvm-quota">Kuota: <?= $watch_today ?> / <?= $watch_limit ?></span>
      </div>

      <div class="cvm-bar-wrap">
        <div class="cvm-bar-fill" style="width: <?= $watch_pct ?>%;"></div>
      </div>

      <div class="cvm-foot">
        <span>Sonar: <strong><?= $watch_pct ?>% Selesai</strong></span>
        <span><?= $watch_remaining > 0 ? "Sisa {$watch_remaining} video lagi" : "✓ Kuota Harian Penuh!" ?></span>
      </div>
    </div>
    <?php endif; ?>

    <!-- Highlight Tombol Upgrade jika User Sudah Berpenghasilan Lumayan -->
    <?php if ($user_has_good_income && !empty($cms['hero']['show_upgrade_btn'])): ?>
      <a href="/upgrade" class="btn-upgrade-highlight" title="Upgrade VIP untuk lipatgandakan kuota dan cuan">
        <div>
          <div class="buh-badge">
            <i class="ph-fill ph-crown" style="color:#0284c7;"></i>
            <span>REKOMENDASI ARMADA VIP</span>
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
    <?php if (!empty($cms['hero']['show_watch_cta'])): ?>
    <a href="/videos" class="btn-cuan-watch-now" id="tour-btn-watch">
      <i class="ph-fill ph-play-circle" style="font-size:22px;"></i>
      <span><?= htmlspecialchars($cms['hero']['watch_cta_text'] ?? ($watch_remaining > 0 ? 'Mulai Tonton Video Sekarang' : 'Buka Galeri Video & Cuan')) ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>
    <?php endif; ?>

    <!-- Quick Action Buttons -->
    <?php if (!empty($cms['hero']['show_quick_actions'])): ?>
    <div class="cuan-card-actions">
      <a href="/minigame" class="cuan-btn-action" style="background:#e0f2fe;color:#0284c7;border-color:#bae6fd;">
        <i class="ph-fill ph-fish"></i> Mancing
      </a>
      <a href="/withdraw" class="cuan-btn-action cuan-btn-action--wd" id="tour-btn-withdraw">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik Saldo
      </a>
      <a href="/deposit" class="cuan-btn-action cuan-btn-action--dep">
        <i class="ph-bold ph-wallet"></i> Top Up Kas
      </a>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="home-feed">

  <!-- BANNER AJAKAN TOUR INTERAKTIF UNTUK PENGGUNA BARU -->
  <?php if (!empty($cms['tour']['show_banner'])): ?>
  <div id="tour-invite-card" class="tour-invite-card">
    <div class="tour-invite-header">
      <div class="tour-invite-badge">
        <i class="ph-fill ph-compass" style="font-size:16px;"></i>
        <span>PANDUAN KAPTEN SAMUDRA BARU</span>
      </div>
      <button type="button" class="tour-invite-close" onclick="dismissTourBanner()" title="Tutup Banner">
        <i class="ph-bold ph-x"></i>
      </button>
    </div>
    <div class="tour-invite-title"><?= htmlspecialchars($cms['tour']['title'] ?? 'Baru di MancingCuan? Yuk Ikuti Panduan Singkat! 🎣') ?></div>
    <div class="tour-invite-desc"><?= htmlspecialchars($cms['tour']['desc'] ?? 'Pelajari langkah mudah menghasilkan saldo rupiah dari nonton video dan mancing cuan:') ?></div>
    <div class="tour-invite-buttons">
      <button type="button" class="btn-tour-choice" onclick="startInteractiveTour('watch')" style="width:100%;">
        <div class="btn-tour-choice-icon"><i class="ph-fill ph-film-strip"></i></div>
        <div class="btn-tour-choice-info">
          <span class="btn-tour-choice-title">Panduan Cuan Nonton Video</span>
          <span class="btn-tour-choice-sub">Misi Tontonan & Tarik Saldo WD</span>
        </div>
        <i class="ph-bold ph-caret-right btn-tour-choice-arrow"></i>
      </button>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       1. WAHANA UTAMA: KOLAM PEMANCINGAN CUAN 🎣
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($cms['fishing_spotlight']['enabled'])): ?>
  <div class="fishing-spotlight-card" id="tour-sidejob-card" style="position:relative;background:linear-gradient(135deg, #0284c7 0%, #0369a1 55%, #075985 100%);border:2.5px solid #38bdf8;border-radius:26px;padding:16px;margin-bottom:16px;box-shadow:0 16px 36px rgba(2,132,199,0.3), inset 2px 2px 5px rgba(255,255,255,0.6), inset -3px -3px 6px rgba(3,105,161,0.5);overflow:hidden;color:#ffffff;">
    <div style="position:absolute;top:-25px;right:-20px;width:120px;height:120px;background:radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);border-radius:50%;pointer-events:none;"></div>
    
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.2);backdrop-filter:blur(4px);border:1.5px solid rgba(255,255,255,0.4);border-radius:999px;padding:3px 10px;font-size:10px;font-weight:900;letter-spacing:0.3px;box-shadow:0 2px 6px rgba(0,0,0,0.1);">
        <?= htmlspecialchars($cms['fishing_spotlight']['badge'] ?? '🎣 WAHANA UTAMA SAMUDRA') ?>
      </div>
      <div style="display:inline-flex;align-items:center;gap:4px;background:#fef08a;color:#78350f;border:1px solid #fcd34d;border-radius:999px;padding:2px 8px;font-size:9.5px;font-weight:900;">
        <i class="ph-fill ph-fish"></i> <?= (int)($user['fishing_baits'] ?? 0) ?> Umpan Siap
      </div>
    </div>
    
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
      <div style="font-size:16.5px;font-weight:900;letter-spacing:-0.3px;text-shadow:0 2px 4px rgba(0,0,0,0.2);">
        <?= htmlspecialchars($cms['fishing_spotlight']['title'] ?? 'Kolam Pemancingan Cuan 🐟') ?>
      </div>
      <div style="width:42px;height:42px;border-radius:14px;background:#ffffff;display:flex;align-items:center;justify-content:center;color:#0284c7;font-size:24px;box-shadow:0 6px 14px rgba(0,0,0,0.2);border:2px solid #bae6fd;flex-shrink:0;">
        <i class="ph-fill ph-fish"></i>
      </div>
    </div>

    <div style="font-size:11px;font-weight:700;line-height:1.4;color:#e0f2fe;margin-bottom:12px;text-shadow:0 1px 2px rgba(0,0,0,0.15);">
      <?= htmlspecialchars($cms['fishing_spotlight']['desc'] ?? 'Lempar kailmu, strike ikan berkantong uang tunai, dan kumpulkan saldo tarik rupiah!') ?>
    </div>

    <!-- CTA Button to Minigame -->
    <a href="<?= htmlspecialchars($cms['fishing_spotlight']['url'] ?? '/minigame') ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%);color:#ffffff;font-size:13px;font-weight:900;padding:11px 16px;border-radius:16px;text-decoration:none;border:2px solid rgba(255,255,255,0.85);box-shadow:0 8px 18px rgba(217,119,6,0.4), inset 2px 2px 4px rgba(255,255,255,0.6);margin-bottom:10px;transition:transform 0.15s ease;">
      <i class="ph-fill ph-fish" style="font-size:18px;"></i>
      <span><?= htmlspecialchars($cms['fishing_spotlight']['cta'] ?? 'Mulai Mancing Sekarang') ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Sub links for Missions & Threads -->
    <div style="display:flex;align-items:center;gap:8px;">
      <a href="/missions" style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.35);padding:6px 10px;border-radius:12px;color:#fff;font-size:10px;font-weight:800;text-decoration:none;box-shadow:0 2px 6px rgba(0,0,0,0.1);">
        <i class="ph-bold ph-target"></i> Misi Cuan
      </a>
      <a href="/threads" style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.35);padding:6px 10px;border-radius:12px;color:#fff;font-size:10px;font-weight:800;text-decoration:none;box-shadow:0 2px 6px rgba(0,0,0,0.1);">
        <i class="ph-bold ph-threads-logo"></i> Event Rp 75K
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       2. KEPULAUAN FITUR (OCEAN BENTO GRID ARCHIPELAGO)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($cms['bento']['show_section'])): ?>
  <div style="font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
    <div style="display:flex;align-items:center;gap:6px;">
      <i class="ph-fill ph-squares-four" style="color:#0284c7;font-size:18px;"></i>
      <span><?= htmlspecialchars($cms['bento']['title'] ?? 'Kepulauan Fitur Cuan') ?></span>
    </div>
    <span style="font-size:10px;font-weight:800;color:#64748b;">Akses Cepat</span>
  </div>

  <div class="bento-ocean-grid" id="tour-bento-grid">
    <?php foreach (($cms['bento']['tiles'] ?? []) as $tile): 
      if (empty($tile['enabled'])) continue;
      $tileBg = !empty($tile['bg']) ? $tile['bg'] : 'linear-gradient(135deg,#0284c7,#0369a1)';
      $tileIcon = !empty($tile['icon']) ? $tile['icon'] : 'ph-sparkle';
      $tileLabel = !empty($tile['label']) ? $tile['label'] : 'Menu';
      $tileUrl = !empty($tile['url']) ? $tile['url'] : '/home';
    ?>
    <a href="<?= htmlspecialchars($tileUrl) ?>" class="b-tile">
      <div class="b-tile__icon" style="background: <?= htmlspecialchars($tileBg) ?>;">
        <i class="ph-fill <?= htmlspecialchars($tileIcon) ?>"></i>
      </div>
      <span class="b-tile__label"><?= htmlspecialchars($tileLabel) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       3. SURVEI PENGGUNA BERHADIAH RP 15.000 SALDO TARIK
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($cms['survey_banner']['enabled'])): ?>
  <a href="/survey" class="survey-invite-banner" title="Isi Survei Singkat & Dapatkan Rp 15.000 Saldo Tarik">
    <div class="sib-left">
      <div class="sib-icon-box <?= $user_has_surveyed ? 'sib-icon-box--done' : '' ?>">
        <i class="ph-bold <?= $user_has_surveyed ? 'ph-check-circle' : 'ph-clipboard-text' ?>"></i>
      </div>
      <div class="sib-text">
        <div class="sib-title">
          <span><?= $user_has_surveyed ? 'Survei Selesai (Klaim Berhasil)' : htmlspecialchars($cms['survey_banner']['title'] ?? 'Survei Pengguna Berhadiah') ?></span>
          <span class="sib-badge <?= $user_has_surveyed ? 'sib-badge--done' : '' ?>"><?= $user_has_surveyed ? 'Klaim Berhasil' : htmlspecialchars($cms['survey_banner']['badge'] ?? '+Rp 15.000 WD') ?></span>
        </div>
        <div class="sib-desc">
          <?= $user_has_surveyed ? 'Terima kasih telah berpartisipasi! Saldo tarik Rp 15.000 sudah masuk.' : htmlspecialchars($cms['survey_banner']['desc'] ?? 'Bantu kami berkembang 2 menit & dapatkan Rp 15.000 saldo tarik langsung!') ?>
        </div>
      </div>
    </div>
    <div class="sib-cta <?= $user_has_surveyed ? 'sib-cta--done' : '' ?>">
      <span><?= $user_has_surveyed ? 'Lihat' : htmlspecialchars($cms['survey_banner']['cta'] ?? 'Isi Survei') ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </div>
  </a>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       4. AJAKAN PROMOSI BANNER (PROMO HUB TRIGGER)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($cms['promo_banner']['enabled'])): ?>
  <div class="promo-invite-banner" onclick="openPromoHubModal()" title="Klik untuk Buka Event Promosi & Raih Saldo">
    <div class="pib-left">
      <div class="pib-icon-box">
        <i class="ph-bold ph-megaphone"></i>
      </div>
      <div class="pib-text">
        <div class="pib-title">
          <span><?= htmlspecialchars($cms['promo_banner']['title'] ?? 'Ajak Teman & Promosi Cuan') ?></span>
          <span class="pib-badge"><?= htmlspecialchars($cms['promo_banner']['badge'] ?? '+Rp 75.000') ?></span>
        </div>
        <div class="pib-desc"><?= htmlspecialchars($cms['promo_banner']['desc'] ?? 'Viral di Threads & bagikan link untuk panen komisi saldo tunai!') ?></div>
      </div>
    </div>
    <div class="pib-cta">
      <span><?= htmlspecialchars($cms['promo_banner']['cta'] ?? 'Mulai') ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       5. JEJAK TONTONAN (CONTINUE WATCHING)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($continue_watching_home) && !empty($cms['continue_watching']['enabled'])): ?>
    <div class="amber-section-card" style="padding:14px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <div style="font-size:13.5px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
          <i class="ph-fill ph-clock-counter-clockwise" style="color:#0284c7;font-size:18px;"></i>
          <span><?= htmlspecialchars($cms['continue_watching']['title'] ?? 'Lanjutkan Menonton') ?></span>
        </div>
        <span style="font-size:10px;font-weight:900;background:#e0f2fe;color:#0284c7;border:1.2px solid #7dd3fc;padding:2px 8px;border-radius:10px;">
          <?= count($continue_watching_home) ?> / <?= $cw_limit ?> Video
        </span>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($continue_watching_home as $cwh): 
          $cwh_pct = min(99, max(1, (int)round(($cwh['seconds_watched'] / $cwh['watch_duration']) * 100)));
        ?>
        <a href="/watch?id=<?= $cwh['id'] ?>" style="display:flex;align-items:center;gap:12px;background:#f8fafc;border:1.5px solid #bae6fd;border-radius:18px;padding:9px 11px;text-decoration:none;color:inherit;box-shadow:0 4px 10px rgba(0,0,0,0.04);transition:transform 0.15s ease;">
          <div style="position:relative;width:66px;height:42px;border-radius:12px;overflow:hidden;border:1.5px solid #bae6fd;flex-shrink:0;background:#0f172a;">
            <img src="<?= yt_thumb($cwh['youtube_id']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.src='https://img.youtube.com/vi/<?= $cwh['youtube_id'] ?>/hqdefault.jpg'">
            <div style="position:absolute;inset:0;background:rgba(0,0,0,0.25);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;"><i class="ph-fill ph-play"></i></div>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:11.5px;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px;">
              <?= htmlspecialchars($cwh['title']) ?>
            </div>
            <div style="height:6px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-bottom:4px;">
              <div style="height:100%;background:linear-gradient(90deg,#0284c7,#10b981);border-radius:999px;width:<?= $cwh_pct ?>%;"></div>
            </div>
            <div style="font-size:9.5px;font-weight:800;color:#64748b;display:flex;justify-content:space-between;">
              <span><?= $cwh['seconds_watched'] ?>s / <?= $cwh['watch_duration'] ?>s (<?= $cwh_pct ?>%)</span>
              <span style="color:#0284c7;font-weight:900;">+<?= format_rp((float)$cwh['reward_amount']) ?></span>
            </div>
          </div>
          <div style="background:linear-gradient(135deg,#0284c7,#0369a1);color:#fff;font-size:10.5px;font-weight:900;padding:6px 11px;border-radius:11px;border:1.2px solid rgba(255,255,255,0.7);flex-shrink:0;display:flex;align-items:center;gap:3px;box-shadow:0 3px 8px rgba(2,132,199,0.3);">
            Lanjut <i class="ph-bold ph-arrow-right"></i>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ══════════════════════════════════════════════════════════
       6. CARD PROMOSI THREADS (EVENT CUAN & PROMOTOR KHUSUS)
       ══════════════════════════════════════════════════════════ -->
  <?php if ($threads_campaign_enabled && !empty($cms['threads_card']['enabled'])): ?>
    <div class="threads-promo-home-card" id="tour-threads-promo-card">
      <div class="tph-badge-strip">
        <span class="tph-badge-event"><i class="ph-bold ph-threads-logo"></i> <?= htmlspecialchars($cms['threads_card']['badge_event'] ?? 'EVENT PROMOSI THREADS') ?></span>
        <span class="tph-badge-cuan"><?= htmlspecialchars($cms['threads_card']['badge_cuan'] ?? '+Rp 75.000 & Gaji Pasti') ?></span>
      </div>
      <div class="tph-content">
        <div class="tph-icon-box" style="display:flex;align-items:center;justify-content:center;background:#f8fafc;border:2px solid #18181b;border-radius:14px;width:48px;height:48px;flex-shrink:0;">
          <i class="ph-bold ph-threads-logo" style="font-size:26px;color:#18181b;"></i>
        </div>
        <div class="tph-text-col">
          <div class="tph-title"><?= htmlspecialchars($cms['threads_card']['title'] ?? 'Viral di Threads & Jadi Promotor Khusus! 👑') ?></div>
          <div class="tph-desc">
            <?= htmlspecialchars($cms['threads_card']['desc'] ?? 'Posting ceritamu, raih reward di Langkah 1 & bonus gaji pasti jika tembus 5K views!') ?>
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
       7. TUGAS VIDEO PILIHAN HARI INI (FEATURED VIDEOS AQUARIUM)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
    <div class="amber-section-card" style="padding:14px;margin-bottom:16px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
        <div style="font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
          <i class="ph-fill ph-film-strip" style="color:#0284c7;font-size:18px;"></i>
          <span><?= htmlspecialchars($cms['featured_videos']['title'] ?? 'Video Pilihan Berhadiah') ?></span>
        </div>
        <a href="/videos" style="font-size:11px;font-weight:900;color:#0284c7;text-decoration:none;display:flex;align-items:center;gap:2px;">
          Lihat Semua &rarr;
        </a>
      </div>
      <div style="font-size:10.5px;font-weight:700;color:#64748b;margin-bottom:12px;">
        <?= htmlspecialchars($cms['featured_videos']['desc'] ?? 'Tonton video pilihan berdurasi singkat dan saldo cuan langsung bertambah otomatis!') ?>
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
                <?= $done ? '<i class="ph-bold ph-check"></i> Selesai' : '<i class="ph-fill ph-play" style="color:#0284c7;"></i> Tonton Sekarang' ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ── 8. BOTOL PESAN LAUTAN (TIPS CUAN PLATFORM) ── -->
  <?php if (!empty($cms['tips']['enabled'])): ?>
  <div class="amber-tips-box">
    <div class="amber-tips-icon"><i class="ph-fill ph-compass" style="color:#0284c7;font-size:20px;"></i></div>
    <div>
      <div style="font-size:12px;font-weight:900;color:#0f172a;margin-bottom:2px;"><?= htmlspecialchars($cms['tips']['title'] ?? 'Tips Pelaut Cuan') ?></div>
      <div style="font-size:10px;font-weight:700;color:#64748b;line-height:1.4;">
        <?= htmlspecialchars($cms['tips']['text'] ?? 'Tonton video sampai kuota harianmu penuh, pancing ikan cuan di kolam samudra, dan undang kru teman untuk komisi pasif tanpa batas!') ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 9. NOTIFIKASI INBOX BAHARI ── -->
  <?php if (!empty($cms['notif_inbox']['enabled']) && !empty($notif_preview)): ?>
    <div style="background:#ffffff;border:2px solid #bae6fd;border-radius:22px;padding:14px 16px;box-shadow:0 10px 24px rgba(2,132,199,0.08), inset 2px 2px 4px #ffffff;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:900;color:#0f172a;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-bell-ringing" style="color:#0284c7;"></i> Pengumuman & Lonceng Bahari
      </div>
      <?php foreach ($notif_preview as $nt): ?>
        <div style="padding:5px 0;border-bottom:1px solid #f1f5f9;">
          <div style="font-size:11.5px;font-weight:900;color:#0f172a;"><?= htmlspecialchars($nt['title']) ?></div>
          <div style="font-size:10px;font-weight:700;color:#64748b;"><?= htmlspecialchars(mb_substr($nt['message'], 0, 75)) ?>...</div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- ── 10. FLOATING BUTTON: AJAKAN PROMOSI SAMUDRA ── -->
  <?php if (!empty($cms['floating_promo']['enabled'])): ?>
  <div class="floating-promo-btn" id="btn-floating-promo" onclick="openPromoHubModal()" title="Klik untuk Buka Event Promosi Cuan">
    <div class="fpb-icon-wrap">
      <i class="ph-bold ph-megaphone"></i>
    </div>
    <div class="fpb-text-wrap">
      <span class="fpb-title"><?= htmlspecialchars($cms['floating_promo']['title'] ?? 'Bonus Promosi') ?></span>
      <span class="fpb-sub">
        <?= htmlspecialchars($cms['floating_promo']['sub'] ?? 'Raih Rp 75K') ?> <span class="fpb-badge"><?= htmlspecialchars($cms['floating_promo']['badge'] ?? 'Hot') ?></span>
      </span>
    </div>
  </div>
  <?php endif; ?>

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
