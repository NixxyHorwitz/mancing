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
   MANCINGCUAN — MINIMALIST OCEAN HORIZON ARCHITECTURE
   Spacious, Calm, Pristine Maritime Geometry & Deep Slate
   ══════════════════════════════════════════════════════════ */

.home-container {
  padding: 12px 14px 28px;
  max-width: 440px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* ── 1. THE HORIZON VAULT CARD (Primary Balance & Actions) ── */
.horizon-vault-card {
  background: #ffffff;
  border: 1.5px solid #e0f2fe;
  border-radius: 26px;
  padding: 20px 18px 18px;
  box-shadow: 0 10px 28px rgba(2, 132, 199, 0.05), 0 2px 4px rgba(0, 0, 0, 0.02);
  position: relative;
  overflow: hidden;
}

.horizon-vault-card::after {
  content: '';
  position: absolute;
  top: -50px; right: -50px;
  width: 140px; height: 140px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(186, 230, 253, 0.35) 0%, transparent 70%);
  pointer-events: none;
}

.vault-top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  position: relative;
  z-index: 2;
}
.vault-user-greet {
  display: flex;
  align-items: center;
  gap: 8px;
}
.vault-avatar-badge {
  width: 34px; height: 34px;
  border-radius: 12px;
  background: linear-gradient(135deg, #0284c7, #0369a1);
  color: #fff;
  font-size: 13px;
  font-weight: 900;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 3px 8px rgba(2, 132, 199, 0.22);
}
.vault-user-title {
  font-size: 13.5px;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.2;
}
.vault-tier-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 9px;
  font-weight: 800;
  color: #0369a1;
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  border-radius: 999px;
  padding: 1px 7px;
  margin-top: 2px;
}
.vault-bait-chip {
  display: flex;
  align-items: center;
  gap: 5px;
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 900;
  color: #0284c7;
  text-decoration: none;
  transition: transform 0.12s;
}
.vault-bait-chip:active { transform: scale(0.96); }

/* Balance Display */
.vault-balance-center {
  margin-bottom: 16px;
  position: relative;
  z-index: 2;
}
.vault-balance-label {
  font-size: 9.5px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  color: #64748b;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.vault-balance-number {
  font-size: 32px;
  font-weight: 900;
  color: #0f172a;
  letter-spacing: -0.8px;
  line-height: 1.1;
  font-family: 'Nunito', -apple-system, sans-serif;
}
.vault-sub-stats {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
}
.vault-sub-stat-item {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.vault-sub-stat-item strong {
  color: #0369a1;
  font-weight: 800;
}

/* Vault 3 Action Buttons */
.vault-actions-grid {
  display: grid;
  grid-template-columns: 1.3fr 1fr 1fr;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.vault-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 42px;
  border-radius: 14px;
  font-size: 12px;
  font-weight: 800;
  text-decoration: none;
  transition: transform 0.12s, box-shadow 0.12s;
  user-select: none;
}
.vault-btn:active { transform: scale(0.96); }

.vault-btn--primary {
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #ffffff;
  border: none;
  box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);
}
.vault-btn--secondary {
  background: #f0f9ff;
  color: #0284c7;
  border: 1.5px solid #bae6fd;
}
.vault-btn--ghost {
  background: #f8fafc;
  color: #475569;
  border: 1.5px solid #e2e8f0;
}

/* ── 2. THE TWIN CORE PORTALS (Mancing & Nonton Side-by-Side) ── */
.portals-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.portal-card {
  background: #ffffff;
  border: 1.5px solid #e0f2fe;
  border-radius: 22px;
  padding: 16px 14px 14px;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.04);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  text-decoration: none;
  color: inherit;
  transition: transform 0.15s, border-color 0.15s;
  position: relative;
  overflow: hidden;
}
.portal-card:active {
  transform: translateY(2px) scale(0.98);
}
.portal-card-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 10px;
}
.portal-icon-box {
  width: 38px; height: 38px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
}
.portal-card--fish .portal-icon-box {
  background: #e0f2fe;
  color: #0284c7;
}
.portal-card--watch .portal-icon-box {
  background: #ecfeff;
  color: #0891b2;
}

.portal-tag {
  font-size: 8.5px;
  font-weight: 800;
  text-transform: uppercase;
  padding: 2px 7px;
  border-radius: 999px;
}
.portal-card--fish .portal-tag {
  background: #e0f2fe;
  color: #0369a1;
}
.portal-card--watch .portal-tag {
  background: #cffafe;
  color: #0e7490;
}

.portal-title {
  font-size: 14px;
  font-weight: 900;
  color: #0f172a;
  margin-bottom: 2px;
}
.portal-desc {
  font-size: 10.5px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 10px;
}

.portal-progress-bar {
  width: 100%;
  height: 4px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
  margin-bottom: 8px;
}
.portal-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #38bdf8, #0284c7);
  border-radius: 999px;
  transition: width 0.3s;
}

.portal-action-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 800;
  color: #0284c7;
  padding-top: 6px;
  border-top: 1px dashed #f1f5f9;
}

/* ── 3. QUICK SERVICES STRIP (5 Essential Modern Squircles) ── */
.services-strip-wrap {
  background: #ffffff;
  border: 1.5px solid #e0f2fe;
  border-radius: 20px;
  padding: 12px 8px;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.04);
}
.services-strip-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 4px;
  text-align: center;
}
.service-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;
  text-decoration: none;
  color: #475569;
  padding: 4px 2px;
  border-radius: 12px;
  transition: background 0.12s, transform 0.12s;
  cursor: pointer;
  border: none;
  background: transparent;
  width: 100%;
}
.service-btn:active {
  transform: scale(0.94);
  background: #f0f9ff;
}
.service-icon-box {
  width: 36px; height: 36px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  transition: transform 0.12s;
}
.service-btn:hover .service-icon-box {
  transform: translateY(-2px);
}
.service-icon-box.checkin { background: #fef3c7; color: #d97706; }
.service-icon-box.threads { background: #f1f5f9; color: #0f172a; }
.service-icon-box.survey  { background: #ecfdf5; color: #059669; }
.service-icon-box.invite  { background: #e0f2fe; color: #0284c7; }
.service-icon-box.vip     { background: #fae8ff; color: #a855f7; }

.service-label {
  font-size: 10px;
  font-weight: 800;
  color: #334155;
  white-space: nowrap;
}

/* ── 4. CURATED VIDEOS SECTION ── */
.section-head-clean {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4px;
  margin-top: 4px;
}
.section-head-title {
  font-size: 13.5px;
  font-weight: 900;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 6px;
}
.section-head-link {
  font-size: 11px;
  font-weight: 800;
  color: #0284c7;
  text-decoration: none;
}
.section-head-link:hover { text-decoration: underline; }

.clean-videos-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.clean-video-card {
  background: #ffffff;
  border: 1.5px solid #e0f2fe;
  border-radius: 18px;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  box-shadow: 0 6px 16px rgba(2, 132, 199, 0.04);
  transition: transform 0.15s, box-shadow 0.15s;
  display: flex;
  flex-direction: column;
}
.clean-video-card:active {
  transform: scale(0.97);
}
.clean-thumb-wrap {
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #0f172a;
  position: relative;
  overflow: hidden;
}
.clean-thumb-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.clean-reward-badge {
  position: absolute;
  top: 6px; left: 6px;
  background: rgba(15, 23, 42, 0.8);
  backdrop-filter: blur(8px);
  color: #38bdf8;
  font-size: 9px;
  font-weight: 900;
  border-radius: 6px;
  padding: 2px 6px;
  border: 1px solid rgba(56, 189, 248, 0.4);
}
.clean-duration-badge {
  position: absolute;
  bottom: 6px; right: 6px;
  background: rgba(0, 0, 0, 0.7);
  color: #fff;
  font-size: 8.5px;
  font-weight: 800;
  border-radius: 4px;
  padding: 1px 4px;
}
.clean-video-meta {
  padding: 9px 10px 10px;
  display: flex;
  flex-direction: column;
  flex: 1;
  justify-content: space-between;
}
.clean-video-title {
  font-size: 11px;
  font-weight: 800;
  color: #0f172a;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  margin-bottom: 6px;
}
.clean-video-cta {
  font-size: 9.5px;
  font-weight: 800;
  color: #0284c7;
  display: flex;
  align-items: center;
  gap: 3px;
}

/* Toast */
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
</style>

<div class="home-container">
  
  <!-- ── 1. THE HORIZON VAULT CARD (Saluran Saldo Utama) ── -->
  <div class="horizon-vault-card" id="tour-balance-box">
    <div id="cuan-ss-target">
      <!-- Profile & Bait Row -->
      <div class="vault-top-row">
        <div class="vault-user-greet">
          <div class="vault-avatar-badge">
            <?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?>
          </div>
          <div>
            <div class="vault-user-title">Halo, <?= htmlspecialchars($user['username'] ?? 'Sobat Cuan') ?></div>
            <div class="vault-tier-badge">
              <i class="ph-bold ph-anchor"></i> <?= htmlspecialchars($membership_name ?? 'Member') ?>
            </div>
          </div>
        </div>

        <a href="/minigame" class="vault-bait-chip" title="Buka Kolam Mancing">
          <i class="ph-fill ph-fish"></i>
          <span><?= (int)($user['fishing_baits'] ?? 0) ?> Umpan</span>
        </a>
      </div>

      <!-- Centerpiece: Saldo Siap Tarik -->
      <div class="vault-balance-center">
        <div class="vault-balance-label">
          <i class="ph-bold ph-coins"></i> Saldo Siap Tarik
        </div>
        <div class="vault-balance-number">
          Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?>
        </div>
        <div class="vault-sub-stats">
          <span class="vault-sub-stat-item">
            Kas Belanja: <strong>Rp <?= number_format((float)($user['balance_dep'] ?? 0), 0, ',', '.') ?></strong>
          </span>
          <span>•</span>
          <span class="vault-sub-stat-item">
            Hari ini: <strong>+Rp <?= number_format($today_watch_earned, 0, ',', '.') ?></strong>
          </span>
        </div>
      </div>

      <!-- 3 Primary Action Buttons -->
      <div class="vault-actions-grid">
        <a href="/withdraw" class="vault-btn vault-btn--primary" id="tour-btn-withdraw">
          <i class="ph-bold ph-arrow-up-right"></i> Tarik Tunai
        </a>
        <a href="/deposit" class="vault-btn vault-btn--secondary">
          <i class="ph-bold ph-plus-circle"></i> Isi Kas
        </a>
        <a href="/history" class="vault-btn vault-btn--ghost">
          <i class="ph-bold ph-clock-counter-clockwise"></i> Riwayat
        </a>
      </div>
    </div>
  </div>

  <!-- ── 2. THE TWIN CORE PORTALS (Mancing & Nonton) ── -->
  <div class="portals-grid">
    <!-- Portal 1: Mancing Cuan -->
    <a href="/minigame" class="portal-card portal-card--fish" id="tour-sidejob-card">
      <div>
        <div class="portal-card-top">
          <div class="portal-icon-box">
            <i class="ph-fill ph-fish-simple"></i>
          </div>
          <span class="portal-tag">Mini Game</span>
        </div>
        <div class="portal-title">Mancing Cuan</div>
        <div class="portal-desc">Kolam Samudra Berhadiah</div>
      </div>
      <div>
        <div style="font-size:10px;font-weight:800;color:#0284c7;margin-bottom:6px;">
          🎣 <?= (int)($user['fishing_baits'] ?? 0) ?> Umpan Siap
        </div>
        <div class="portal-action-row">
          <span>Lempar Kail</span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </div>
    </a>

    <!-- Portal 2: Nonton Video Berhadiah -->
    <a href="/videos" class="portal-card portal-card--watch" id="tour-video-mission">
      <div>
        <div class="portal-card-top">
          <div class="portal-icon-box">
            <i class="ph-fill ph-play-circle"></i>
          </div>
          <span class="portal-tag">Misi Harian</span>
        </div>
        <div class="portal-title">Tonton Video</div>
        <div class="portal-desc"><?= $watch_today ?> / <?= $watch_limit ?> Video Selesai</div>
      </div>
      <div>
        <div class="portal-progress-bar">
          <div class="portal-progress-fill" style="width: <?= $watch_pct ?>%;"></div>
        </div>
        <div class="portal-action-row" id="tour-btn-watch">
          <span>Mulai Cuan</span>
          <i class="ph-bold ph-arrow-right"></i>
        </div>
      </div>
    </a>
  </div>

  <!-- ── 3. QUICK SERVICES STRIP (5 Ikon Esensial) ── -->
  <div class="services-strip-wrap" id="tour-bento-grid">
    <div class="services-strip-grid">
      <!-- 1. Checkin -->
      <button type="button" class="service-btn" onclick="openCheckinModal()" title="Klaim Hadiah Harian">
        <div class="service-icon-box checkin">
          <i class="ph-fill ph-gift"></i>
        </div>
        <span class="service-label"><?= $has_checked_in_today ? 'Absen' : 'Klaim' ?></span>
      </button>

      <!-- 2. Event Threads -->
      <button type="button" class="service-btn" id="tour-threads-promo-card" onclick="openThreadsPromoModal()" title="Event Threads Rp 75K">
        <div class="service-icon-box threads">
          <i class="ph-bold ph-threads-logo"></i>
        </div>
        <span class="service-label">Threads</span>
      </button>

      <!-- 3. Survei -->
      <a href="/survey" class="service-btn" title="Isi Survei Berhadiah">
        <div class="service-icon-box survey">
          <i class="ph-bold ph-clipboard-text"></i>
        </div>
        <span class="service-label">Survei</span>
      </a>

      <!-- 4. Undang Kru -->
      <a href="/referral" class="service-btn" title="Undang Teman & Komisi">
        <div class="service-icon-box invite">
          <i class="ph-bold ph-users-three"></i>
        </div>
        <span class="service-label">Ajak Kru</span>
      </a>

      <!-- 5. VIP / Pangkat -->
      <a href="/upgrade" class="service-btn" title="Upgrade Status VIP">
        <div class="service-icon-box vip">
          <i class="ph-bold ph-crown-simple"></i>
        </div>
        <span class="service-label">VIP Kapal</span>
      </a>
    </div>
  </div>

  <!-- ── 4. REKOMENDASI VIDEO CUAN (Jika Diaktifkan) ── -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
  <div>
    <div class="section-head-clean">
      <div class="section-head-title">
        <i class="ph-fill ph-sparkle" style="color:#0284c7;"></i> Video Pilihan
      </div>
      <a href="/videos" class="section-head-link">Semua &rarr;</a>
    </div>

    <div class="clean-videos-grid" style="margin-top:8px;">
      <?php foreach ($featured_videos as $fv): ?>
      <a href="/watch/<?= (int)$fv['id'] ?>" class="clean-video-card">
        <div class="clean-thumb-wrap">
          <img src="<?= htmlspecialchars($fv['thumbnail_url'] ?: '/assets/placeholder-video.jpg') ?>" 
               alt="<?= htmlspecialchars($fv['title']) ?>" 
               class="clean-thumb-img" 
               loading="lazy"
               onerror="this.src='/assets/placeholder-video.jpg'">
          <div class="clean-reward-badge">+<?= format_rp((float)$fv['reward_amount']) ?></div>
          <div class="clean-duration-badge"><?= (int)$fv['duration_seconds'] ?>s</div>
        </div>
        <div class="clean-video-meta">
          <div class="clean-video-title"><?= htmlspecialchars($fv['title']) ?></div>
          <div class="clean-video-cta">
            <span>Tonton</span> <i class="ph-bold ph-arrow-right"></i>
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
