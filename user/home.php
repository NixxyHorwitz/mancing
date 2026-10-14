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
   MANCINGCUAN — FANTASY OCEANIC CLAYMORPHISM 3D SYSTEM
   Tactile Marine Cushions, Bioluminescent Biota & Aquatic Fantasy
   ══════════════════════════════════════════════════════════ */

/* Background Underwater Caustics & Bubbles */
.fantasy-ocean-stage {
  position: relative;
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 10px 14px 28px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  overflow: hidden;
}

/* Floating Ambient Water Bubbles */
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
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.8) 0%, rgba(56, 189, 248, 0.35) 60%, rgba(2, 132, 199, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.6);
  box-shadow: inset 1px 1px 2px #fff, 0 2px 6px rgba(2, 132, 199, 0.2);
  animation: bubbleAscend 9s infinite ease-in;
}
.b-1 { width: 14px; height: 14px; left: 8%; bottom: -20px; animation-duration: 8s; animation-delay: 0s; }
.b-2 { width: 22px; height: 22px; left: 85%; bottom: -30px; animation-duration: 11s; animation-delay: 2.5s; }
.b-3 { width: 10px; height: 10px; left: 45%; bottom: -15px; animation-duration: 7s; animation-delay: 4s; }
.b-4 { width: 18px; height: 18px; left: 22%; bottom: -25px; animation-duration: 10s; animation-delay: 1.2s; }
.b-5 { width: 12px; height: 12px; left: 68%; bottom: -20px; animation-duration: 9s; animation-delay: 5s; }

@keyframes bubbleAscend {
  0% { transform: translateY(0) translateX(0) scale(0.85); opacity: 0; }
  15% { opacity: 0.85; }
  50% { transform: translateY(-350px) translateX(12px) scale(1.05); }
  85% { opacity: 0.75; }
  100% { transform: translateY(-750px) translateX(-10px) scale(1.2); opacity: 0; }
}

/* ── 1. THE FANTASY HORIZON VAULT CARD (3D CLAY CAPTAIN DECK) ── */
.clay-vault-card {
  position: relative;
  z-index: 1;
  background: linear-gradient(155deg, #ffffff 0%, #f0f9ff 65%, #e0f2fe 100%);
  border: 2.5px solid #bae6fd;
  border-radius: 28px;
  padding: 22px 18px 18px;
  box-shadow: 
    0 16px 36px rgba(2, 132, 199, 0.16),
    0 4px 10px rgba(0, 0, 0, 0.03),
    inset 2.5px 2.5px 5px #ffffff,
    inset -3px -3px 6px rgba(2, 132, 199, 0.08);
  overflow: hidden;
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* Decorative Swimming Clownfish in Vault */
.vault-marine-mascot {
  position: absolute;
  top: 14px;
  right: 14px;
  width: 90px;
  height: 58px;
  pointer-events: none;
  z-index: 1;
  animation: mascotSwimFloat 4.5s ease-in-out infinite;
}
@keyframes mascotSwimFloat {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-4px) rotate(2.5deg); }
}

/* Decorative Coral Flora Silhouette */
.vault-coral-accent {
  position: absolute;
  bottom: -6px;
  left: -6px;
  width: 60px;
  height: 60px;
  opacity: 0.22;
  pointer-events: none;
  z-index: 0;
}

/* Vault Header */
.vault-profile-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
  position: relative;
  z-index: 2;
}
.vault-clay-avatar {
  width: 44px;
  height: 44px;
  border-radius: 16px;
  background: linear-gradient(145deg, #38bdf8 0%, #0284c7 100%);
  border: 2.5px solid #ffffff;
  color: #ffffff;
  font-size: 16px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 
    0 6px 16px rgba(2, 132, 199, 0.32),
    inset 2px 2px 4px rgba(255, 255, 255, 0.75),
    inset -2px -2px 4px rgba(3, 105, 161, 0.35);
  flex-shrink: 0;
}
.vault-user-info {
  display: flex;
  flex-direction: column;
}
.vault-username-txt {
  font-size: 14px;
  font-weight: 900;
  color: #0f172a;
  letter-spacing: -0.3px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.vault-rank-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f0fdf4;
  border: 1.2px solid #86efac;
  border-radius: 999px;
  padding: 1.5px 8px;
  font-size: 9.5px;
  font-weight: 900;
  color: #166534;
  box-shadow: inset 1px 1px 2px #fff;
  margin-top: 2px;
}

/* Clay Balance Core */
.vault-balance-showcase {
  margin-bottom: 16px;
  position: relative;
  z-index: 2;
}
.vault-balance-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #ffffff;
  border: 1.2px solid #bae6fd;
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 9.5px;
  font-weight: 900;
  color: #0369a1;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  box-shadow: 0 2px 6px rgba(2, 132, 199, 0.08), inset 1px 1px 2px #fff;
  margin-bottom: 5px;
}
.vault-balance-main-num {
  font-size: 34px;
  font-weight: 900;
  color: #0f172a;
  letter-spacing: -0.8px;
  line-height: 1.1;
  font-family: 'Nunito', sans-serif;
  text-shadow: 0 2px 4px rgba(2, 132, 199, 0.08);
}
.vault-stats-ribbon {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}
.vault-stat-capsule {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: rgba(255, 255, 255, 0.85);
  border: 1.2px solid #bae6fd;
  border-radius: 10px;
  padding: 3px 8px;
  font-size: 10.5px;
  font-weight: 800;
  color: #475569;
  box-shadow: 0 2px 4px rgba(2, 132, 199, 0.04), inset 1px 1px 2px #fff;
}
.vault-stat-capsule strong {
  color: #0284c7;
  font-weight: 900;
}
.vault-stat-capsule--baits {
  background: #fefce8;
  border-color: #fef08a;
  color: #854d0e;
}
.vault-stat-capsule--baits strong {
  color: #d97706;
}

/* 3 Clay Action Buttons */
.vault-actions-cluster {
  display: grid;
  grid-template-columns: 1.35fr 1fr 1fr;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.clay-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  height: 44px;
  border-radius: 16px;
  font-size: 12px;
  font-weight: 900;
  text-decoration: none;
  cursor: pointer;
  user-select: none;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s;
}
.clay-btn:active {
  transform: translateY(2px) scale(0.96);
}
.clay-btn--primary {
  background: linear-gradient(145deg, #38bdf8 0%, #0284c7 60%, #0369a1 100%);
  color: #ffffff !important;
  border: 2px solid #ffffff;
  box-shadow: 
    0 8px 18px rgba(2, 132, 199, 0.38),
    inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.8),
    inset -2.5px -2.5px 5px rgba(3, 105, 161, 0.45);
}
.clay-btn--secondary {
  background: #ffffff;
  color: #0284c7 !important;
  border: 2px solid #bae6fd;
  box-shadow: 
    0 6px 14px rgba(2, 132, 199, 0.1),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.08);
}
.clay-btn--ghost {
  background: #f8fafc;
  color: #475569 !important;
  border: 2px solid #e2e8f0;
  box-shadow: 
    0 4px 10px rgba(0, 0, 0, 0.04),
    inset 2px 2px 4px #ffffff;
}

/* ── 2. THE TWIN FANTASY PORTALS (CLAY CARDS WITH MARINE ART) ── */
.fantasy-portals-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  position: relative;
  z-index: 1;
}
.fantasy-portal-card {
  position: relative;
  background: linear-gradient(155deg, #ffffff 0%, #f0f9ff 100%);
  border: 2.5px solid #bae6fd;
  border-radius: 24px;
  padding: 16px 14px 14px;
  box-shadow: 
    0 10px 24px rgba(2, 132, 199, 0.1),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(2, 132, 199, 0.06);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  text-decoration: none;
  color: inherit;
  overflow: hidden;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s;
}
.fantasy-portal-card:active {
  transform: translateY(2px) scale(0.97);
}

/* Aquatic Portal Header Art */
.portal-top-bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 8px;
}
.portal-clay-icon {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  box-shadow: 
    0 6px 14px rgba(2, 132, 199, 0.2),
    inset 2px 2px 4px rgba(255, 255, 255, 0.8),
    inset -2px -2px 4px rgba(0, 0, 0, 0.1);
  position: relative;
  overflow: hidden;
}
.portal-clay-icon--fish {
  background: linear-gradient(145deg, #38bdf8, #0284c7);
  color: #fff;
}
.portal-clay-icon--watch {
  background: linear-gradient(145deg, #2dd4bf, #0891b2);
  color: #fff;
}

.portal-sparkle-tag {
  font-size: 8.5px;
  font-weight: 900;
  text-transform: uppercase;
  padding: 2.5px 8px;
  border-radius: 999px;
  letter-spacing: 0.3px;
}
.portal-card--fish .portal-sparkle-tag {
  background: #e0f2fe;
  color: #0369a1;
  border: 1px solid #7dd3fc;
}
.portal-card--watch .portal-sparkle-tag {
  background: #ccfbf1;
  color: #0f766e;
  border: 1px solid #5eead4;
}

.portal-heading {
  font-size: 14.5px;
  font-weight: 900;
  color: #0f172a;
  margin-bottom: 2px;
  letter-spacing: -0.2px;
}
.portal-subtext {
  font-size: 10.5px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 10px;
}

/* Water Wave Progress Bar */
.portal-wave-progress {
  width: 100%;
  height: 6px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
  margin-bottom: 9px;
  box-shadow: inset 1px 1px 2px rgba(0,0,0,0.1);
}
.portal-wave-fill {
  height: 100%;
  background: linear-gradient(90deg, #38bdf8, #0284c7);
  border-radius: 999px;
  transition: width 0.3s;
  box-shadow: 0 0 6px rgba(56, 189, 248, 0.6);
}

.portal-cta-badge {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 10px;
  border-radius: 12px;
  background: #ffffff;
  border: 1.2px solid #bae6fd;
  font-size: 11px;
  font-weight: 900;
  color: #0284c7;
  box-shadow: 0 3px 6px rgba(2, 132, 199, 0.06), inset 1px 1px 2px #fff;
  transition: background 0.15s;
}
.fantasy-portal-card:hover .portal-cta-badge {
  background: #f0f9ff;
}

/* ── 3. FANTASY REEF QUICK SERVICES (5 CLAY TOKENS) ── */
.reef-services-deck {
  position: relative;
  z-index: 1;
  background: linear-gradient(155deg, #ffffff 0%, #f8fafc 100%);
  border: 2.5px solid #bae6fd;
  border-radius: 26px;
  padding: 14px 10px;
  box-shadow: 
    0 12px 28px rgba(2, 132, 199, 0.1),
    inset 2px 2px 5px #ffffff,
    inset -2px -2px 5px rgba(2, 132, 199, 0.05);
}
.reef-tokens-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 4px;
}
.reef-token-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-decoration: none;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 4px 2px;
  border-radius: 16px;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
  width: 100%;
}
.reef-token-btn:active {
  transform: translateY(2px) scale(0.92);
}
.clay-token-icon {
  width: 42px;
  height: 42px;
  border-radius: 15px;
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  box-shadow: 
    0 6px 14px rgba(0, 0, 0, 0.08),
    inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.9),
    inset -2px -2px 4px rgba(0, 0, 0, 0.12);
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.reef-token-btn:hover .clay-token-icon {
  transform: translateY(-3px) scale(1.05);
}

.clay-token-icon.t-checkin {
  background: linear-gradient(135deg, #fcd34d, #f59e0b);
  color: #78350f;
}
.clay-token-icon.t-threads {
  background: linear-gradient(135deg, #27272a, #18181b);
  color: #fde68a;
}
.clay-token-icon.t-survey {
  background: linear-gradient(135deg, #6ee7b7, #10b981);
  color: #064e3b;
}
.clay-token-icon.t-invite {
  background: linear-gradient(135deg, #38bdf8, #0284c7);
  color: #ffffff;
}
.clay-token-icon.t-vip {
  background: linear-gradient(135deg, #e879f9, #a855f7);
  color: #ffffff;
}

.reef-token-label {
  font-size: 10px;
  font-weight: 900;
  color: #334155;
  white-space: nowrap;
}

/* ── 4. CURATED VIDEOS: SAMUDRA VIDEO DECK ── */
.fantasy-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4px;
  position: relative;
  z-index: 1;
}
.fsh-title {
  font-size: 14px;
  font-weight: 900;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 6px;
  letter-spacing: -0.3px;
}
.fsh-link {
  font-size: 11px;
  font-weight: 900;
  color: #0284c7;
  text-decoration: none;
}
.fsh-link:hover { text-decoration: underline; }

.fantasy-video-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  position: relative;
  z-index: 1;
}
.clay-video-card {
  background: #ffffff;
  border: 2px solid #bae6fd;
  border-radius: 20px;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  box-shadow: 
    0 8px 20px rgba(2, 132, 199, 0.08),
    inset 2px 2px 4px #ffffff;
  display: flex;
  flex-direction: column;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s;
}
.clay-video-card:active {
  transform: translateY(2px) scale(0.97);
}
.clay-video-thumb-wrap {
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #0f172a;
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
  background: rgba(15, 23, 42, 0.85);
  backdrop-filter: blur(8px);
  color: #38bdf8;
  font-size: 9.5px;
  font-weight: 900;
  border-radius: 8px;
  padding: 2.5px 7px;
  border: 1px solid rgba(56, 189, 248, 0.5);
  box-shadow: 0 2px 6px rgba(0,0,0,0.25);
}
.clay-duration-pill {
  position: absolute;
  bottom: 7px;
  right: 7px;
  background: rgba(0, 0, 0, 0.75);
  color: #ffffff;
  font-size: 8.5px;
  font-weight: 800;
  border-radius: 5px;
  padding: 1.5px 5px;
}
.clay-video-body {
  padding: 10px 10px 12px;
  display: flex;
  flex-direction: column;
  flex: 1;
  justify-content: space-between;
}
.clay-video-title-txt {
  font-size: 11.5px;
  font-weight: 800;
  color: #0f172a;
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
  color: #0284c7;
}

/* Toast Notification */
.cuan-toast {
  position: fixed;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%) translateY(30px);
  background: #0f172a;
  color: #fff;
  padding: 10px 18px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 800;
  box-shadow: 0 10px 24px rgba(0,0,0,0.25);
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
   MODAL DIALOGS — CLAYMORPHIC OCEAN AESTHETIC
   ══════════════════════════════════════════════════════════ */
.amber-modal-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.amber-modal-backdrop.open {
  display: flex !important;
}
.amber-modal-box {
  background: linear-gradient(155deg, #ffffff 0%, #f0f9ff 100%);
  border: 2.5px solid #bae6fd;
  border-radius: 28px;
  padding: 24px 20px 20px;
  width: 100%;
  max-width: 380px;
  position: relative;
  box-shadow: 
    0 22px 50px rgba(2, 132, 199, 0.25),
    inset 2.5px 2.5px 5px #ffffff,
    inset -2.5px -2.5px 5px rgba(2, 132, 199, 0.08);
  text-align: center;
}
.amber-modal-close {
  position: absolute;
  top: 14px; right: 14px;
  background: #f1f5f9;
  border: none;
  width: 28px; height: 28px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; color: #64748b;
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(0,0,0,0.06);
}
.amber-modal-badge {
  width: 52px; height: 52px;
  border-radius: 18px;
  background: linear-gradient(145deg, #38bdf8, #0284c7);
  color: #fff;
  border: 2px solid #ffffff;
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; margin: 0 auto 14px;
  box-shadow: 0 6px 16px rgba(2, 132, 199, 0.28), inset 2px 2px 4px rgba(255,255,255,0.7);
}
.amber-modal-title {
  font-size: 16px; font-weight: 900; color: #0f172a; margin-bottom: 6px; letter-spacing: -0.3px; line-height: 1.3;
}
.amber-modal-body {
  font-size: 12px; font-weight: 700; color: #64748b; line-height: 1.55; margin-bottom: 18px;
}
.btn-modal-primary {
  background: linear-gradient(145deg, #38bdf8 0%, #0284c7 60%, #0369a1 100%);
  color: #ffffff !important;
  padding: 12px 18px;
  border-radius: 16px;
  text-align: center;
  font-size: 12.5px;
  font-weight: 900;
  text-decoration: none;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  border: 2px solid #ffffff;
  box-shadow: 0 6px 18px rgba(2, 132, 199, 0.35), inset 2px 2px 4px rgba(255,255,255,0.75);
  cursor: pointer; width: 100%;
}
.btn-modal-dismiss {
  background: transparent; border: none; color: #94a3b8; font-size: 11.5px; font-weight: 800; cursor: pointer; padding: 8px; width: 100%; text-align: center;
}

/* Tour Select Modal */
.tour-select-option {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px; border-radius: 18px;
  border: 2px solid #bae6fd;
  background: #ffffff;
  cursor: pointer; text-align: left; width: 100%; margin-bottom: 10px;
  box-shadow: 0 4px 12px rgba(2, 132, 199, 0.06), inset 2px 2px 4px #ffffff;
}
.tour-select-icon {
  width: 40px; height: 40px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: #ffffff; flex-shrink: 0;
}
.tour-select-icon--watch { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
.tour-select-title { font-size: 12.5px; font-weight: 900; color: #0f172a; margin-bottom: 2px; }
.tour-select-desc { font-size: 10px; font-weight: 700; color: #64748b; line-height: 1.35; }

/* Promo Hub Modal */
.promo-hub-card-item {
  background: #ffffff; border: 2px solid #bae6fd; border-radius: 18px;
  padding: 12px 14px; margin-bottom: 10px; text-align: left;
  box-shadow: 0 4px 12px rgba(2, 132, 199, 0.06), inset 1.5px 1.5px 3px #ffffff;
}
.phc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.phc-tag { display: inline-flex; align-items: center; gap: 4px; font-size: 8.5px; font-weight: 900; padding: 2px 7px; border-radius: 6px; text-transform: uppercase; }
.phc-tag--threads { background: #18181b; color: #bae6fd; border: 1px solid #38bdf8; }
.phc-tag--referral { background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc; }
.phc-reward { font-size: 10.5px; font-weight: 900; color: #059669; }
.phc-title { font-size: 12.5px; font-weight: 900; color: #0f172a; margin-bottom: 2px; }
.phc-desc { font-size: 10px; font-weight: 700; color: #64748b; line-height: 1.35; margin-bottom: 8px; }
.phc-caption-box { background: #f0f9ff; border: 1.2px dashed #7dd3fc; border-radius: 12px; padding: 8px 10px; font-size: 9.5px; font-weight: 700; color: #0369a1; line-height: 1.4; margin-bottom: 8px; }
.phc-actions { display: flex; align-items: center; gap: 6px; }
.btn-phc-action { flex: 1; padding: 8px 10px; border-radius: 12px; font-size: 10.5px; font-weight: 900; text-align: center; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 4px; border: 1px solid #e0f2fe; cursor: pointer; }
.btn-phc-action--primary { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: #ffffff !important; border: none; box-shadow: 0 3px 8px rgba(2, 132, 199, 0.25); }
.btn-phc-action--share { background: linear-gradient(135deg, #10b981, #059669); color: #ffffff !important; border: none; box-shadow: 0 3px 8px rgba(5, 150, 105, 0.25); }
.btn-phc-action--copy { background: #f0f9ff; color: #0369a1 !important; border-color: #bae6fd; }
</style>

<div class="fantasy-ocean-stage">

  <!-- Ambient Rising Bubbles Layer -->
  <div class="ambient-bubble-layer">
    <div class="sea-bubble b-1"></div>
    <div class="sea-bubble b-2"></div>
    <div class="sea-bubble b-3"></div>
    <div class="sea-bubble b-4"></div>
    <div class="sea-bubble b-5"></div>
  </div>
  
  <!-- ── 1. THE FANTASY HORIZON VAULT CARD (3D CLAY CAPTAIN DECK) ── -->
  <div class="clay-vault-card" id="tour-balance-box">
    
    <!-- Swimming Clownfish Mascot SVG Illustration -->
    <div class="vault-marine-mascot" title="Ikan Cuan Samudra">
      <svg viewBox="0 0 110 70" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;">
        <defs>
          <linearGradient id="fishBodyGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#fb923c" />
            <stop offset="50%" stop-color="#f97316" />
            <stop offset="100%" stop-color="#ea580c" />
          </linearGradient>
          <linearGradient id="fishFinGrad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#38bdf8" />
            <stop offset="100%" stop-color="#0284c7" />
          </linearGradient>
          <filter id="clayFishGlow" x="-20%" y="-20%" width="140%" height="140%">
            <feDropShadow dx="0" dy="3" stdDeviation="2.5" flood-color="#0284c7" flood-opacity="0.25" />
          </filter>
        </defs>
        <!-- Tail Fin -->
        <path d="M22 35 C10 18, 5 15, 2 24 C-1 33, 4 37, 2 46 C5 55, 10 52, 22 35 Z" fill="url(#fishFinGrad)" />
        <!-- Top Fin -->
        <path d="M48 18 C58 6, 76 9, 84 17 C75 19, 58 19, 48 18 Z" fill="url(#fishFinGrad)" />
        <!-- Bottom Fin -->
        <path d="M60 40 C58 50, 48 56, 42 50 C46 44, 53 41, 60 40 Z" fill="url(#fishFinGrad)" />
        <!-- Main Fish Body with Clay Depth -->
        <ellipse cx="65" cy="35" rx="36" ry="22" fill="url(#fishBodyGrad)" filter="url(#clayFishGlow)" />
        <!-- White Clownfish Stripes with Specular Clay Highlight -->
        <path d="M52 14 C56 22, 56 48, 52 56 C57 56, 61 54, 61 48 C61 38, 61 24, 57 14 Z" fill="#ffffff" opacity="0.95" />
        <path d="M76 16 C80 23, 80 47, 76 54 C80 53, 83 50, 83 45 C83 36, 83 25, 80 16 Z" fill="#ffffff" opacity="0.95" />
        <!-- Big Cute Eye -->
        <circle cx="87" cy="30" r="5" fill="#ffffff" />
        <circle cx="89" cy="29" r="2.6" fill="#0f172a" />
        <circle cx="90" cy="28" r="0.9" fill="#ffffff" />
        <!-- Cute Smile -->
        <path d="M95 36 C97 38, 96 40, 93 40" stroke="#7c2d12" stroke-width="1.6" stroke-linecap="round" />
        <!-- Bubbles rising from mouth -->
        <circle cx="98" cy="22" r="3" fill="#bae6fd" opacity="0.85" />
        <circle cx="104" cy="14" r="4" fill="#7dd3fc" opacity="0.7" />
        <circle cx="108" cy="6" r="2" fill="#bae6fd" opacity="0.9" />
      </svg>
    </div>

    <div id="cuan-ss-target">
      <!-- Profile Header Row -->
      <div class="vault-profile-row">
        <div class="vault-clay-avatar">
          <i class="ph-fill ph-fish"></i>
        </div>
        <div class="vault-user-info">
          <div class="vault-username-txt">
            Halo, <?= htmlspecialchars($user['username'] ?? 'Kapten Cuan') ?>
          </div>
          <div>
            <span class="vault-rank-pill">
              <i class="ph-fill ph-seal-check"></i> <?= htmlspecialchars($membership_name ?? 'Kru Nelayan') ?>
            </span>
          </div>
        </div>
      </div>

      <!-- Centerpiece: Saldo Tunai -->
      <div class="vault-balance-showcase">
        <div class="vault-balance-tag">
          <i class="ph-fill ph-sparkle"></i> Saldo Siap Tarik
        </div>
        <div class="vault-balance-main-num">
          Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?>
        </div>
        
        <div class="vault-stats-ribbon">
          <span class="vault-stat-capsule">
            <i class="ph-bold ph-wallet"></i> Kas: <strong>Rp <?= number_format((float)($user['balance_dep'] ?? 0), 0, ',', '.') ?></strong>
          </span>
          <span class="vault-stat-capsule vault-stat-capsule--baits">
            <i class="ph-fill ph-fish"></i> <strong><?= (int)($user['fishing_baits'] ?? 0) ?></strong> Umpan Siap
          </span>
          <span class="vault-stat-capsule">
            <i class="ph-bold ph-trend-up"></i> Hari Ini: <strong>+Rp <?= number_format($today_watch_earned, 0, ',', '.') ?></strong>
          </span>
        </div>
      </div>

      <!-- 3 Tactile 3D Action Buttons -->
      <div class="vault-actions-cluster">
        <a href="/withdraw" class="clay-btn clay-btn--primary" id="tour-btn-withdraw">
          <i class="ph-bold ph-arrow-up-right"></i> Tarik Saldo
        </a>
        <a href="/deposit" class="clay-btn clay-btn--secondary">
          <i class="ph-bold ph-plus-circle"></i> Isi Kas
        </a>
        <a href="/history" class="clay-btn clay-btn--ghost">
          <i class="ph-bold ph-clock-counter-clockwise"></i> Riwayat
        </a>
      </div>
    </div>
  </div>

  <!-- ── 2. THE TWIN FANTASY PORTALS (KOLAM MANCING & BIOSKOP SAMUDRA) ── -->
  <div class="fantasy-portals-grid">
    <!-- Portal 1: Kolam Mancing Cuan -->
    <a href="/minigame" class="fantasy-portal-card portal-card--fish" id="tour-sidejob-card">
      <div>
        <div class="portal-top-bar">
          <div class="portal-clay-icon portal-clay-icon--fish">
            <i class="ph-fill ph-fish-simple"></i>
          </div>
          <span class="portal-sparkle-tag">✨ KOLAM CUAN</span>
        </div>
        <div class="portal-heading">Mancing Cuan</div>
        <div class="portal-subtext">Pancing Ikan Emas Samudra</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:900;color:#0284c7;margin-bottom:6px;display:flex;align-items:center;gap:4px;">
          <i class="ph-fill ph-hook"></i> <?= (int)($user['fishing_baits'] ?? 0) ?> Umpan Tersedia
        </div>
        <div class="portal-cta-badge">
          <span>Lempar Kail</span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </div>
    </a>

    <!-- Portal 2: Bioskop Samudra (Nonton Video) -->
    <a href="/videos" class="fantasy-portal-card portal-card--watch" id="tour-video-mission">
      <div>
        <div class="portal-top-bar">
          <div class="portal-clay-icon portal-clay-icon--watch">
            <i class="ph-fill ph-play-circle"></i>
          </div>
          <span class="portal-sparkle-tag">🎬 MISI HARIAN</span>
        </div>
        <div class="portal-heading">Tonton Video</div>
        <div class="portal-subtext"><?= $watch_today ?> / <?= $watch_limit ?> Video Tuntas</div>
      </div>
      <div>
        <div class="portal-wave-progress">
          <div class="portal-wave-fill" style="width: <?= $watch_pct ?>%;"></div>
        </div>
        <div class="portal-cta-badge" id="tour-btn-watch">
          <span>Panen Cuan</span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </div>
    </a>
  </div>

  <!-- ── 3. FANTASY REEF QUICK SERVICES (5 CLAY TOKENS) ── -->
  <div class="reef-services-deck" id="tour-bento-grid">
    <div class="reef-tokens-grid">
      <!-- 1. Check-in Harian (Kerang Ajaib) -->
      <button type="button" class="reef-token-btn" onclick="openCheckinModal()" title="Klaim Hadiah Login Harian">
        <div class="clay-token-icon t-checkin">
          <i class="ph-fill ph-gift"></i>
        </div>
        <span class="reef-token-label"><?= $has_checked_in_today ? 'Absen' : 'Klaim' ?></span>
      </button>

      <!-- 2. Event Threads -->
      <button type="button" class="reef-token-btn" id="tour-threads-promo-card" onclick="openThreadsPromoModal()" title="Event Threads Rp 75K">
        <div class="clay-token-icon t-threads">
          <i class="ph-bold ph-threads-logo"></i>
        </div>
        <span class="reef-token-label">Threads</span>
      </button>

      <!-- 3. Survei Berhadiah -->
      <a href="/survey" class="reef-token-btn" title="Isi Survei Berhadiah">
        <div class="clay-token-icon t-survey">
          <i class="ph-bold ph-clipboard-text"></i>
        </div>
        <span class="reef-token-label">Survei</span>
      </a>

      <!-- 4. Ajak Kru Samudra -->
      <a href="/referral" class="reef-token-btn" title="Undang Teman & Raih Komisi">
        <div class="clay-token-icon t-invite">
          <i class="ph-bold ph-users-three"></i>
        </div>
        <span class="reef-token-label">Ajak Kru</span>
      </a>

      <!-- 5. VIP Kapal -->
      <a href="/upgrade" class="reef-token-btn" title="Upgrade Kapal VIP">
        <div class="clay-token-icon t-vip">
          <i class="ph-bold ph-crown-simple"></i>
        </div>
        <span class="reef-token-label">Kapal VIP</span>
      </a>
    </div>
  </div>

  <!-- ── 4. PALUNG VIDEO PILIHAN (REKOMENDASI VIDEO CUAN) ── -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
  <div>
    <div class="fantasy-section-head">
      <div class="fsh-title">
        <i class="ph-fill ph-sparkle" style="color:#0284c7;"></i> Rekomendasi Video Cuan
      </div>
      <a href="/videos" class="fsh-link">Lihat Semua &rarr;</a>
    </div>

    <div class="fantasy-video-grid" style="margin-top:10px;">
      <?php foreach ($featured_videos as $fv): ?>
      <a href="/watch/<?= (int)$fv['id'] ?>" class="clay-video-card">
        <div class="clay-video-thumb-wrap">
          <img src="<?= htmlspecialchars($fv['thumbnail_url'] ?: '/assets/placeholder-video.jpg') ?>" 
               alt="<?= htmlspecialchars($fv['title']) ?>" 
               class="clay-video-thumb-img" 
               loading="lazy"
               onerror="this.src='/assets/placeholder-video.jpg'">
          <div class="clay-reward-pill">+<?= format_rp((float)$fv['reward_amount']) ?></div>
          <div class="clay-duration-pill"><?= (int)$fv['duration_seconds'] ?>s</div>
        </div>
        <div class="clay-video-body">
          <div class="clay-video-title-txt"><?= htmlspecialchars($fv['title']) ?></div>
          <div class="clay-video-footer">
            <span>Tonton Video</span>
            <i class="ph-bold ph-play"></i>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

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
