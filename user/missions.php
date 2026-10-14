<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Dynamic level name for Daily Spin
$level2_name = $pdo->query("SELECT name FROM memberships WHERE id = 2")->fetchColumn() ?: 'Juragan Silver';


// â”€â”€ Mission definitions (hardcoded) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$ALL_MISSIONS = [
    // HARIAN
    ['slug'=>'daily_watch_3',       'category'=>'daily',    'title'=>'Tonton 3 Video',             'desc'=>'Tonton minimal 3 video hari ini.',              'target'=>3,   'reward'=>1000,  'icon'=>'ph-film-slate'],
    ['slug'=>'daily_watch_5',       'category'=>'daily',    'title'=>'Tonton 5 Video',             'desc'=>'Tonton minimal 5 video hari ini.',              'target'=>5,   'reward'=>2500,  'icon'=>'ph-film-reel'],
    // MINGGUAN
    ['slug'=>'weekly_streak_7',     'category'=>'weekly',   'title'=>'Streak 7 Hari',              'desc'=>'Check-in setiap hari selama 7 hari penuh.',  'target'=>7,   'reward'=>10000, 'icon'=>'ph-fire'],
    ['slug'=>'weekly_watch_20',     'category'=>'weekly',   'title'=>'Tonton 20 Video Minggu Ini', 'desc'=>'Tonton total 20 video minggu ini.',          'target'=>20,  'reward'=>8000,  'icon'=>'ph-television'],
    ['slug'=>'weekly_watch_7days',  'category'=>'weekly',   'title'=>'Aktif 7 Hari (Nonton)',      'desc'=>'Tonton video di 7 hari berbeda minggu ini.', 'target'=>7,   'reward'=>12000, 'icon'=>'ph-star'],
    // LIFETIME
    ['slug'=>'lifetime_first_ref',  'category'=>'lifetime', 'title'=>'Daftarkan 1 Referral',       'desc'=>'Ajak 1 teman bergabung via kode referralmu.','target'=>1,   'reward'=>5000,  'icon'=>'ph-user-plus'],
    ['slug'=>'lifetime_5_refs',     'category'=>'lifetime', 'title'=>'Agen Rekruter',               'desc'=>'Ajak 5 teman bergabung via kode referralmu.','target'=>5,   'reward'=>15000, 'icon'=>'ph-users-three'],
    ['slug'=>'lifetime_first_wd',   'category'=>'lifetime', 'title'=>'Penarikan Pertama',           'desc'=>'Lakukan penarikan saldo pertama kalinya.',   'target'=>1,   'reward'=>3000,  'icon'=>'ph-money'],
    ['slug'=>'lifetime_100_videos', 'category'=>'lifetime', 'title'=>'Penonton Sejati',             'desc'=>'Tonton total 100 video di MancingCuan.',        'target'=>100, 'reward'=>10000, 'icon'=>'ph-popcorn'],
    ['slug'=>'lifetime_upgrade',    'category'=>'lifetime', 'title'=>'Member Premium',              'desc'=>'Upgrade ke paket membership berbayar.',      'target'=>1,   'reward'=>8000,  'icon'=>'ph-crown'],
];

$today    = date('Y-m-d');
$weekKey  = date('Y-\WW'); // e.g. 2026-W23

// ── Helper: get real-time progress ─────────────────────────────────────
function get_progress(PDO $pdo, array $user, array $mission): int {
    $uid = $user['id'];
    switch ($mission['slug']) {
        case 'daily_watch_3':
        case 'daily_watch_5':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'daily_checkin':
            return ($user['last_checkin'] === date('Y-m-d')) ? 1 : 0;
        case 'weekly_streak_7':
            // Count distinct days this week with check-in (approximate via watch_history)
            $s = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return min(7, (int)$s->fetchColumn());
        case 'weekly_watch_20':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'weekly_watch_7days':
            $s = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_first_ref':
        case 'lifetime_5_refs':
            $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
            $s->execute([$user['referral_code']]);
            return (int)$s->fetchColumn();
        case 'lifetime_first_wd':
            $s = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=?");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_100_videos':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_upgrade':
            return ($user['membership_id'] ? 1 : 0);
    }
    return 0;
}

// ── Helper: get period key ─────────────────────────────────────────────
function get_period_key(string $category): ?string {
    if ($category === 'daily')   return date('Y-m-d');
    if ($category === 'weekly')  return date('Y-\WW');
    return null; // lifetime
}

// ── Helper: check if already claimed ───────────────────────────────────
function is_claimed(PDO $pdo, int|string $user_id, string $slug, ?string $period_key): bool {
    $user_id = (int)$user_id;
    $s = $pdo->prepare("SELECT claimed_at FROM user_missions WHERE user_id=? AND mission_slug=? AND period_key<=>?");
    $s->execute([$user_id, $slug, $period_key]);
    $row = $s->fetch();
    return $row && $row['claimed_at'] !== null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_mission') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'CSRF tidak valid.']); exit; }

    $slug = trim($_POST['slug'] ?? '');
    $mission = null;
    foreach ($ALL_MISSIONS as $m) {
        if ($m['slug'] === $slug) { $mission = $m; break; }
    }
    if (!$mission) { echo json_encode(['ok'=>false,'msg'=>'Misi tidak ditemukan.']); exit; }

    $period = get_period_key($mission['category']);
    if (is_claimed($pdo, (int)$user['id'], $slug, $period)) {
        echo json_encode(['ok'=>false,'msg'=>'Misi ini sudah pernah diklaim!']); exit;
    }

    $progress = get_progress($pdo, $user, $mission);
    if ($progress < $mission['target']) {
        echo json_encode(['ok'=>false,'msg'=>"Progress belum cukup. ({$progress}/{$mission['target']})"]); exit;
    }

    try {
        $pdo->beginTransaction();
        // Upsert record
        $pdo->prepare("INSERT INTO user_missions (user_id, mission_slug, progress, completed_at, claimed_at, period_key)
            VALUES (?, ?, ?, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE progress=VALUES(progress), completed_at=COALESCE(completed_at,NOW()), claimed_at=NOW()")
            ->execute([$user['id'], $slug, $progress, $period]);
        // Give reward
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id=?")
            ->execute([$mission['reward'], $user['id']]);

        $tgMsg = "🎯 <b>MEMBER KLAIM MISI!</b>\n";
        $tgMsg .= "Username: <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $tgMsg .= "Misi: <b>" . htmlspecialchars($mission['title']) . "</b>\n";
        $tgMsg .= "Reward: Rp " . number_format($mission['reward'], 0, ',', '.') . "\n";
        send_telegram_notif($pdo, $tgMsg, [], 'misi');

        $pdo->commit();
        
        $msg = '🎉 Reward diklaim! +'.number_format($mission['reward'],0,',','.').' ke Saldo Tarik.';
        echo json_encode(['ok'=>true,'msg'=>$msg,'reward'=>$mission['reward']]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kesalahan: '.$e->getMessage()]);
    }
    exit;
}

// ── Build mission data with progress ───────────────────────────────────
$missions_data = [];
foreach ($ALL_MISSIONS as $m) {
    $period   = get_period_key($m['category']);
    $progress = get_progress($pdo, $user, $m);
    $claimed  = is_claimed($pdo, (int)$user['id'], $m['slug'], $period);
    $done     = $progress >= $m['target'];

    $missions_data[] = array_merge($m, [
        'progress' => min($progress, $m['target']),
        'claimed'  => $claimed,
        'done'     => $done,
        'period'   => $period,
    ]);
}

$daily    = array_filter($missions_data, fn($m) => $m['category'] === 'daily');
$weekly   = array_filter($missions_data, fn($m) => $m['category'] === 'weekly');
$lifetime = array_filter($missions_data, fn($m) => $m['category'] === 'lifetime');

$claimed_today = count(array_filter($missions_data, fn($m) => $m['claimed']));

$pageTitle  = 'Misi & Tantangan — MancingCuan';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   MISSIONS PAGE — MANCINGCUAN THEME
   ══════════════════════════════════════════════════════════ */
html body {
  background: #fef8ee !important;
  font-family: 'Nunito', sans-serif;
  color: #78350f;
}

/* ── HERO TOP BANNER ── */
.wd-top {
  background: linear-gradient(180deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  position: relative;
  padding: 16px 16px 22px;
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 6px 20px rgba(120,53,15,0.28);
  overflow: hidden;
}
/* Subtle honeycomb dot pattern */
.wd-top::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 24px 24px;
  background-position: 0 0, 12px 12px;
  opacity: 0.2; pointer-events: none;
}
/* Amber glow */
.wd-top::after {
  content: ''; position: absolute; top: -30px; right: -30px;
  width: 140px; height: 140px; border-radius: 50%;
  background: radial-gradient(circle, rgba(254,240,138,0.3) 0%, transparent 70%);
  pointer-events: none;
}

.wd-top-inner {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  position: relative; z-index: 2;
}
.wd-top-left {
  display: flex; align-items: center; gap: 12px; min-width: 0;
}
.wd-back-btn {
  width: 36px; height: 36px; background: #ffffff;
  border: 2px solid #78350f; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 18px;
  box-shadow: 0 3px 0 #78350f; text-decoration: none; flex-shrink: 0;
  transition: transform 0.1s, box-shadow 0.1s;
}
.wd-back-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

.wd-top-title {
  font-size: 18px; font-weight: 900; color: #ffffff;
  text-shadow: 0 2px 0 #78350f; margin-bottom: 2px;
  display: flex; align-items: center; gap: 6px;
}
.wd-top-sub {
  font-size: 11px; font-weight: 800; color: #fef3c7;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.ms-hero-badge {
  background: rgba(120,53,15,0.45);
  border: 2px solid rgba(254,240,138,0.7);
  border-radius: 14px;
  padding: 6px 14px;
  text-align: center;
  flex-shrink: 0;
  box-shadow: 0 3px 8px rgba(0,0,0,0.12);
}
.ms-hero-badge__val {
  font-size: 22px; font-weight: 900; color: #fef08a;
  line-height: 1; text-shadow: 0 1px 2px rgba(0,0,0,0.3);
}
.ms-hero-badge__lbl {
  font-size: 9px; font-weight: 900; color: #fde68a;
  text-transform: uppercase; letter-spacing: 0.5px; margin-top: 1px;
}

/* ── BODY WRAPPER ── */
.wd-body {
  flex: 1;
  background: #fef8ee;
  padding: 16px 14px 110px;
  position: relative;
}
.wd-body::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(120,53,15,0.04) 12%, transparent 13%), radial-gradient(rgba(120,53,15,0.04) 12%, transparent 13%);
  background-size: 32px 32px; background-position: 0 0, 16px 16px;
  pointer-events: none;
}

/* ── TABS ── */
.ms-tabs {
  display: flex;
  background: #fef3c7;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  box-shadow: 0 4px 0 #78350f;
  padding: 4px;
  gap: 4px;
  margin-bottom: 16px;
  position: relative;
  z-index: 2;
}
.ms-tab {
  flex: 1; padding: 9px 6px; text-align: center;
  font-size: 11.5px; font-weight: 900; color: #78350f;
  background: transparent; border: none; border-radius: 12px;
  transition: all 0.15s ease; cursor: pointer;
  font-family: 'Nunito', sans-serif;
  -webkit-tap-highlight-color: transparent;
}
.ms-tab.active {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
  box-shadow: 0 2px 0 #78350f;
  text-shadow: 0 1px 2px rgba(120,53,15,0.35);
}

/* ── SECTION HEADER ── */
.ms-section-hdr {
  font-size: 11.5px; font-weight: 900; color: #78350f;
  text-transform: uppercase; letter-spacing: 0.6px;
  margin-bottom: 12px; display: flex; align-items: center; gap: 6px;
  position: relative; z-index: 2;
}
.ms-section-hdr i { color: #d97706; font-size: 17px; }

/* ── MISSION CARD ── */
.ms-card {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 4px 0 #78350f;
  margin-bottom: 12px;
  overflow: hidden;
  transition: all 0.2s ease;
  position: relative;
  z-index: 2;
}
.ms-card--done {
  background: #f0fdf4;
  border-color: #059669;
  box-shadow: 0 4px 0 #047857, 0 6px 16px rgba(5,150,105,0.12);
}
.ms-card--claimed {
  background: #f8fafc;
  border-color: #cbd5e1;
  box-shadow: 0 3px 0 #94a3b8;
  opacity: 0.75;
}

.ms-card__head {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 14px 10px;
}
.ms-card__icon {
  width: 44px; height: 44px; flex-shrink: 0;
  background: linear-gradient(135deg, #fef08a, #fde047);
  border: 2px solid #78350f; border-radius: 14px;
  box-shadow: 0 2.5px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: #78350f;
}
.ms-card--done .ms-card__icon {
  background: linear-gradient(135deg, #34d399, #10b981);
  border-color: #047857; box-shadow: 0 2.5px 0 #065f46; color: #ffffff;
}
.ms-card--claimed .ms-card__icon {
  background: linear-gradient(135deg, #e2e8f0, #cbd5e1);
  border-color: #94a3b8; box-shadow: 0 2px 0 #64748b; color: #64748b;
}

.ms-card__info { flex: 1; min-width: 0; }
.ms-card__title {
  font-size: 13.5px; font-weight: 900; color: #78350f;
  line-height: 1.25; margin-bottom: 2px;
}
.ms-card--done    .ms-card__title { color: #064e3b; }
.ms-card--claimed .ms-card__title { color: #64748b; }

.ms-card__desc {
  font-size: 10.5px; font-weight: 700; color: #92400e;
  line-height: 1.35;
}
.ms-card--claimed .ms-card__desc { color: #94a3b8; }

.ms-card__reward {
  background: #fef3c7; border: 1.5px solid #f59e0b;
  border-radius: 10px; padding: 4px 8px;
  font-size: 10.5px; font-weight: 900; color: #b45309;
  flex-shrink: 0; box-shadow: 0 2px 0 #d97706;
}
.ms-card--done .ms-card__reward {
  background: #dcfce7; border-color: #10b981; color: #047857; box-shadow: 0 2px 0 #059669;
}
.ms-card--claimed .ms-card__reward {
  background: #f1f5f9; border-color: #cbd5e1; color: #94a3b8; box-shadow: none;
}

/* ── PROGRESS ── */
.ms-prog { padding: 0 14px 12px; }
.ms-prog-bar-wrap {
  height: 10px; background: #fffbeb;
  border: 1.5px solid #fde68a; border-radius: 8px;
  overflow: hidden; box-shadow: inset 0 1px 3px rgba(120,53,15,0.06);
}
.ms-card--done    .ms-prog-bar-wrap { background: #ecfdf5; border-color: #a7f3d0; }
.ms-card--claimed .ms-prog-bar-wrap { background: #f1f5f9; border-color: #e2e8f0; box-shadow: none; }

.ms-prog-bar {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #d97706);
  border-radius: 6px; transition: width 0.5s ease;
}
.ms-card--done    .ms-prog-bar { background: linear-gradient(90deg, #34d399, #059669); }
.ms-card--claimed .ms-prog-bar { background: #cbd5e1; }

.ms-prog-meta {
  display: flex; justify-content: space-between;
  font-size: 9.5px; font-weight: 800; color: #92400e; margin-top: 4px;
}
.ms-card--claimed .ms-prog-meta { color: #94a3b8; }

/* ── BUTTONS ── */
.ms-btn {
  width: 100%; margin-top: 10px; padding: 10px;
  border-radius: 12px; font-size: 12px; font-weight: 900;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  cursor: pointer; transition: transform 0.1s, box-shadow 0.1s;
  font-family: 'Nunito', sans-serif;
}
.ms-btn:active:not(:disabled) {
  transform: translateY(2px); box-shadow: 0 1px 0 #064e3b !important;
}
.ms-btn--locked {
  background: #f8fafc; border: 2px solid #e2e8f0;
  color: #94a3b8; cursor: not-allowed;
}
.ms-btn--ready {
  background: linear-gradient(135deg, #10b981, #059669);
  border: 2.5px solid #064e3b; color: #ffffff;
  box-shadow: 0 4px 0 #064e3b;
  animation: pulseReady 2s infinite ease-in-out;
}
@keyframes pulseReady {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.015); box-shadow: 0 4px 12px rgba(16,185,129,0.35), 0 4px 0 #064e3b; }
}
.ms-btn--claimed {
  background: #ecfdf5; border: 2px solid #86efac;
  color: #059669; cursor: default;
}
.ms-btn--claimed:active { transform: none; }

/* ── PANELS ── */
.ms-panel { display: none; }
.ms-panel.active { display: block; animation: fade-in 0.25s ease-out; }
@keyframes fade-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
@keyframes spin    { to { transform: rotate(360deg); } }
</style>

<!-- TOP BANNER -->
<div class="wd-top">
  <div class="wd-top-inner">
    <div class="wd-top-left">
      <a href="/home" class="wd-back-btn" title="Kembali ke Beranda"><i class="ph-bold ph-arrow-left"></i></a>
      <div>
        <div class="wd-top-title">Misi & Tantangan 🎯</div>
        <div class="wd-top-sub">Selesaikan misi harian, klaim reward cuan!</div>
      </div>
    </div>
    <div class="ms-hero-badge">
      <div class="ms-hero-badge__val"><?= $claimed_today ?></div>
      <div class="ms-hero-badge__lbl">Diklaim</div>
    </div>
  </div>
</div>

<div class="wd-body">
  <!-- TABS -->
  <div class="ms-tabs" role="tablist">
    <button class="ms-tab active" id="tab-daily"    onclick="switchTab('daily')">Harian</button>
    <button class="ms-tab"        id="tab-weekly"   onclick="switchTab('weekly')">Mingguan</button>
    <button class="ms-tab"        id="tab-lifetime" onclick="switchTab('lifetime')">Pencapaian</button>
  </div>

  <!-- DAILY -->
  <div class="ms-panel active" id="panel-daily">
    <div class="ms-section-hdr"><i class="ph-fill ph-sun"></i> Misi Harian - Reset tiap hari</div>
    <?php foreach ($daily as $m):
      $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
      $cardClass = $m['claimed'] ? 'ms-card--claimed' : ($m['done'] ? 'ms-card--done' : '');
    ?>
    <div class="ms-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
      <div class="ms-card__head">
        <div class="ms-card__icon"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></div>
        <div class="ms-card__info">
          <div class="ms-card__title"><?= htmlspecialchars($m['title']) ?></div>
          <div class="ms-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
        <div class="ms-card__reward">+Rp<?= number_format($m['reward'],0,',','.') ?></div>
      </div>
      <div class="ms-prog">
        <div class="ms-prog-bar-wrap"><div class="ms-prog-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="ms-prog-meta"><span><?= $m['progress'] ?> / <?= $m['target'] ?></span><span><?= $pct ?>%</span></div>
        <?php if ($m['claimed']): ?>
          <button class="ms-btn ms-btn--claimed" disabled><i class="ph-bold ph-check-circle"></i> Selesai</button>
        <?php elseif ($m['done']): ?>
          <button class="ms-btn ms-btn--ready" onclick="claimMission('<?= $m['slug'] ?>', this)"><i class="ph-bold ph-gift"></i> Klaim Reward!</button>
        <?php else: ?>
          <button class="ms-btn ms-btn--locked" disabled><i class="ph-bold ph-lock"></i> Belum Selesai</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- WEEKLY -->
  <div class="ms-panel" id="panel-weekly">
    <div class="ms-section-hdr"><i class="ph-fill ph-calendar"></i> Misi Mingguan - Reset tiap Senin</div>
    <?php foreach ($weekly as $m):
      $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
      $cardClass = $m['claimed'] ? 'ms-card--claimed' : ($m['done'] ? 'ms-card--done' : '');
    ?>
    <div class="ms-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
      <div class="ms-card__head">
        <div class="ms-card__icon"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></div>
        <div class="ms-card__info">
          <div class="ms-card__title"><?= htmlspecialchars($m['title']) ?></div>
          <div class="ms-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
        <div class="ms-card__reward">+Rp<?= number_format($m['reward'],0,',','.') ?></div>
      </div>
      <div class="ms-prog">
        <div class="ms-prog-bar-wrap"><div class="ms-prog-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="ms-prog-meta"><span><?= $m['progress'] ?> / <?= $m['target'] ?></span><span><?= $pct ?>%</span></div>
        <?php if ($m['claimed']): ?>
          <button class="ms-btn ms-btn--claimed" disabled><i class="ph-bold ph-check-circle"></i> Selesai</button>
        <?php elseif ($m['done']): ?>
          <button class="ms-btn ms-btn--ready" onclick="claimMission('<?= $m['slug'] ?>', this)"><i class="ph-bold ph-gift"></i> Klaim Reward!</button>
        <?php else: ?>
          <button class="ms-btn ms-btn--locked" disabled><i class="ph-bold ph-lock"></i> Belum Selesai</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- LIFETIME -->
  <div class="ms-panel" id="panel-lifetime">
    <div class="ms-section-hdr"><i class="ph-fill ph-trophy"></i> Pencapaian - Klaim sekali selamanya</div>
    <?php foreach ($lifetime as $m):
      $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
      $cardClass = $m['claimed'] ? 'ms-card--claimed' : ($m['done'] ? 'ms-card--done' : '');
    ?>
    <div class="ms-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
      <div class="ms-card__head">
        <div class="ms-card__icon"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></div>
        <div class="ms-card__info">
          <div class="ms-card__title"><?= htmlspecialchars($m['title']) ?></div>
          <div class="ms-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
        <div class="ms-card__reward">+Rp<?= number_format($m['reward'],0,',','.') ?></div>
      </div>
      <div class="ms-prog">
        <div class="ms-prog-bar-wrap"><div class="ms-prog-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="ms-prog-meta"><span><?= $m['progress'] ?> / <?= $m['target'] ?></span><span><?= $pct ?>%</span></div>
        <?php if ($m['claimed']): ?>
          <button class="ms-btn ms-btn--claimed" disabled><i class="ph-bold ph-check-circle"></i> Selesai</button>
        <?php elseif ($m['done']): ?>
          <button class="ms-btn ms-btn--ready" onclick="claimMission('<?= $m['slug'] ?>', this)"><i class="ph-bold ph-gift"></i> Klaim Reward!</button>
        <?php else: ?>
          <button class="ms-btn ms-btn--locked" disabled><i class="ph-bold ph-lock"></i> Belum Selesai</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
const _csrf = '<?= csrf_token() ?>';

function switchTab(cat) {
  document.querySelectorAll('.ms-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.ms-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + cat).classList.add('active');
  document.getElementById('panel-' + cat).classList.add('active');
}

function claimMission(slug, btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:spin 0.8s linear infinite"></i> Mengklaim...';
  const fd = new FormData();
  fd.append('action', 'claim_mission');
  fd.append('slug', slug);
  fd.append('_csrf', _csrf);
  fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (data.tickets_added && data.tickets_added > 0) {
          const tc = document.getElementById('spin-tickets-count');
          if (tc) tc.innerText = parseInt(tc.innerText) + data.tickets_added;
        }
        const card = document.getElementById('mc-' + slug);
        if (card) { card.classList.remove('ms-card--done'); card.classList.add('ms-card--claimed'); }
        btn.className = 'ms-btn ms-btn--claimed';
        btn.innerHTML = '<i class="ph-bold ph-check-circle"></i> Selesai';
        btn.disabled = true;
        try {
          const AudioCtx = window.AudioContext || window.webkitAudioContext;
          if (AudioCtx) {
            const actx = new AudioCtx(), osc = actx.createOscillator(), gain = actx.createGain();
            osc.connect(gain); gain.connect(actx.destination);
            osc.frequency.value = 1046.5;
            gain.gain.setValueAtTime(0, actx.currentTime);
            gain.gain.linearRampToValueAtTime(0.2, actx.currentTime + 0.05);
            gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.3);
            osc.start(); osc.stop(actx.currentTime + 0.3);
          }
        } catch(e) {}
        if (typeof nToast !== 'undefined') nToast(data.msg, 'success');
      } else {
        btn.disabled = false;
        btn.className = 'ms-btn ms-btn--ready';
        btn.innerHTML = '<i class="ph-bold ph-gift"></i> Klaim Reward!';
        if (typeof nToast !== 'undefined') nToast(data.msg, 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.className = 'ms-btn ms-btn--ready';
      btn.innerHTML = '<i class="ph-bold ph-gift"></i> Klaim Reward!';
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error');
    });
}
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
