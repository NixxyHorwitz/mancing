<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = '';
if (!empty($_SESSION['flash_videos_msg'])) {
    $flash = $_SESSION['flash_videos_msg'];
    $flash_type = $_SESSION['flash_videos_type'] ?? 'success';
    unset($_SESSION['flash_videos_msg'], $_SESSION['flash_videos_type']);
}

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Cek status survei berhadiah untuk user
$user_has_surveyed = false;
if (!empty($user['id'])) {
    try {
        $survCheck = $pdo->prepare("SELECT 1 FROM user_surveys WHERE user_id = ? LIMIT 1");
        $survCheck->execute([$user['id']]);
        $user_has_surveyed = (bool)$survCheck->fetchColumn();
    } catch (\Throwable) {}
}

// Determine Sort Order
$sort_mode = setting($pdo, 'video_sort_mode', 'default');
$order_by = 'v.sort_order ASC, v.id DESC';
if ($sort_mode === 'newest') $order_by = 'v.id DESC';
if ($sort_mode === 'oldest') $order_by = 'v.id ASC';
if ($sort_mode === 'reward_desc') $order_by = 'v.reward_amount DESC, v.id DESC';
if ($sort_mode === 'reward_asc') $order_by = 'v.reward_amount ASC, v.id DESC';
if ($sort_mode === 'duration_asc') $order_by = 'v.watch_duration ASC, v.id DESC';
if ($sort_mode === 'random') $order_by = 'RAND()';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// All active videos with watch status and user liked status
$videos = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh
        WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today,
       (SELECT COUNT(*) FROM video_likes vl
        WHERE vl.user_id=? AND vl.video_id=v.id) AS user_liked
     FROM videos v
     WHERE v.is_active=1
     ORDER BY {$order_by}
     LIMIT {$limit} OFFSET {$offset}"
);
$videos->execute([$user['id'], $user['id']]);
$videos = $videos->fetchAll();

// AJAX: Hapus progres tontonan dari riwayat user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_watch_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    $del_vid = (int)($_POST['video_id'] ?? 0);
    if ($del_vid > 0) {
        $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")->execute([$user['id'], $del_vid]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// Bersihkan progres tontonan untuk video yang sudah tuntas ditonton hari ini
$pdo->prepare(
    "DELETE uwp FROM user_watch_progress uwp
     JOIN watch_history wh ON wh.user_id = uwp.user_id AND wh.video_id = uwp.video_id AND DATE(wh.watched_at) = CURDATE()
     WHERE uwp.user_id = ?"
)->execute([$user['id']]);

// Ambil riwayat tontonan yang sedang berjalan (maksimal 3 per user)
$cw_stmt = $pdo->prepare(
    "SELECT uwp.seconds_watched, uwp.duration, uwp.last_position, uwp.updated_at,
            v.id, v.title, v.youtube_id, v.watch_duration, v.reward_amount, v.total_watches, v.total_likes
     FROM user_watch_progress uwp
     JOIN videos v ON v.id = uwp.video_id
     WHERE uwp.user_id = ? AND v.is_active = 1
     ORDER BY uwp.updated_at DESC
     LIMIT 3"
);
$cw_stmt->execute([$user['id']]);
$continue_watching = $cw_stmt->fetchAll();

$saved_prog_map = [];
foreach ($continue_watching as $cw) {
    $saved_prog_map[$cw['id']] = $cw;
}

// Satu baris kartu video (dipakai halaman & infinite scroll)
$render_vcard = static function (array $v) use ($watch_today, $watch_limit, $saved_prog_map): void {
    $done    = (bool)$v['watched_today'];
    $blocked = !$done && ($watch_today >= $watch_limit);
    $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id=' . $v['id'];
    $status_class = $done ? 'done' : ($blocked ? 'blocked' : 'ready');
    $resume  = (!$done && isset($saved_prog_map[$v['id']]) && (int)$v['watch_duration'] > 0)
        ? (int)round(($saved_prog_map[$v['id']]['seconds_watched'] / $v['watch_duration']) * 100) : null;
    ?>
    <a href="<?= $href ?>" class="vcard vcard--<?= $status_class ?>" data-status="<?= $done ? 'done' : 'ready' ?>" <?= ($done || $blocked) ? 'style="pointer-events:none"' : '' ?>>
      <div class="vcard__thumb">
        <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
        <span class="vcard__dur"><i class="ph-bold ph-clock"></i><?= (int)$v['watch_duration'] ?>s</span>
        <?php if ($done): ?><span class="vcard__state ok"><i class="ph-bold ph-check"></i></span>
        <?php elseif ($blocked): ?><span class="vcard__state lock"><i class="ph-fill ph-lock-simple"></i></span><?php endif; ?>
        <?php if ($resume !== null): ?><span class="vcard__resume"><i style="width:<?= max(4, min(100, $resume)) ?>%"></i></span><?php endif; ?>
      </div>
      <div class="vcard__body">
        <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
        <div class="vcard__stats">
          <span><i class="ph-bold ph-eye"></i><?= number_format((int)$v['total_watches']) ?></span>
          <span><i class="ph-bold ph-thumbs-up"></i><?= number_format((int)($v['total_likes'] ?? 0)) ?></span>
        </div>
        <div class="vcard__foot">
          <span class="vcard__rw <?= $done ? 'done' : '' ?>"><?= $done ? 'Selesai' : '+' . format_rp((float)$v['reward_amount']) ?></span>
          <?php if (!$done && !$blocked): ?>
            <span class="vcard__go"><?= $resume !== null ? 'Lanjut ' . $resume . '%' : 'Tonton' ?><i class="ph-fill ph-play"></i></span>
          <?php endif; ?>
        </div>
      </div>
    </a>
    <?php
};

// AJAX Infinite Scroll Response
if (isset($_GET['ajax'])) {
    if (empty($videos)) {
        echo '';
        exit;
    }
    foreach ($videos as $v) $render_vcard($v);
    exit;
}

$pageTitle  = 'Pusat Nonton Video';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
$pct  = $watch_limit > 0 ? min(100, (int)round(($watch_today / $watch_limit) * 100)) : 0;
$left = max(0, $watch_limit - $watch_today);
?>

<style>
/* ══ VIDEOS — Bioskop Bawah Laut · living coral reef clay (seragam dengan /watch) ══ */
.vh { width: 100%; max-width: 440px; margin: 0 auto; padding: 6px 12px 28px; display: flex; flex-direction: column; gap: 11px; }

/* Panel kuota */
.vh-hero { border: 2px solid #fff; border-radius: 24px; padding: 6px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.vh-hero-in { position: relative; overflow: hidden; display: flex; align-items: center; gap: 13px; padding: 13px 14px; border-radius: 19px; color: #fff;
  background: radial-gradient(circle at 92% 120%, rgba(254, 240, 138, .45) 0, transparent 45%), radial-gradient(circle at 0 0, rgba(165, 243, 252, .5) 0, transparent 50%),
    linear-gradient(135deg, #0891b2 0%, #0e7490 50%, #155e75 100%);
  box-shadow: inset 2px 2px 6px rgba(255, 255, 255, .3), inset -3px -4px 10px rgba(8, 51, 68, .3); }
.vh-hero-in::before { content: ''; position: absolute; top: 5px; left: 14px; width: 40%; height: 9px; border-radius: 999px; background: linear-gradient(90deg, rgba(255, 255, 255, .3), transparent); }
.vh-ring { position: relative; width: 76px; height: 76px; flex-shrink: 0; }
.vh-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.vh-ring .tr { stroke: rgba(255, 255, 255, .2); }
.vh-ring .fl { stroke: url(#vhGrad); stroke-linecap: round; transition: stroke-dashoffset .8s cubic-bezier(.34, 1.56, .64, 1); }
.vh-ring div { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1; }
.vh-ring b { font-size: 20px; font-weight: 900; }
.vh-ring small { font-size: 9px; font-weight: 800; opacity: .85; margin-top: 2px; }
.vh-txt { position: relative; z-index: 1; flex: 1; min-width: 0; }
.vh-txt small { font-size: 9.5px; font-weight: 900; letter-spacing: .6px; text-transform: uppercase; opacity: .85; }
.vh-txt h1 { margin: 2px 0 3px; font-size: 18px; font-weight: 900; letter-spacing: -.4px; line-height: 1.1; text-shadow: 0 2px 6px rgba(8, 51, 68, .3); }
.vh-txt p { margin: 0; font-size: 10.5px; font-weight: 800; opacity: .92; }
.vh-up { display: inline-flex; align-items: center; gap: 4px; margin-top: 7px; padding: 5px 10px; border-radius: 10px; background: #fef08a; color: #78350f; font-size: 10.5px; font-weight: 900; text-decoration: none; box-shadow: 0 2px 0 #b45309; }
.vh-fish { position: absolute; right: 8px; top: 10px; width: 30px; opacity: .9; animation: vhSwim 4s ease-in-out infinite; }
@keyframes vhSwim { 50% { transform: translate(-6px, 4px) rotate(-6deg); } }

/* Survei */
.vh-survey { display: flex; align-items: center; gap: 9px; padding: 8px 10px 8px 8px; border-radius: 17px; border: 2px solid #fff; text-decoration: none; color: inherit;
  background: linear-gradient(135deg, #fffbeb, #fef3c7); box-shadow: 0 6px 14px rgba(245, 158, 11, .14); }
.vh-survey.done { background: linear-gradient(135deg, #ecfdf5, #d1fae5); box-shadow: 0 6px 14px rgba(16, 185, 129, .12); }
.vh-ico { width: 32px; height: 32px; border-radius: 11px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #fff;
  box-shadow: 0 3px 6px var(--sh, rgba(0, 0, 0, .12)), inset 1.5px 1.5px 3px rgba(255, 255, 255, .55), inset -1.5px -1.5px 3px rgba(0, 0, 0, .15); }
.vh-ico.sun { background: linear-gradient(145deg, #fde68a, #f59e0b); --sh: rgba(245, 158, 11, .3); }
.vh-ico.kelp { background: linear-gradient(145deg, #6ee7b7, #059669); --sh: rgba(5, 150, 105, .3); }
.vh-ico.lag { background: linear-gradient(145deg, #a5f3fc, #0891b2); --sh: rgba(8, 145, 178, .3); }
.vh-survey .t { flex: 1; min-width: 0; line-height: 1.25; }
.vh-survey b { display: block; font-size: 12px; font-weight: 900; color: var(--reef-ink); }
.vh-survey span.s { display: block; font-size: 10px; font-weight: 800; color: #92400e; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vh-survey.done span.s { color: #047857; }
.vh-survey .amt { flex-shrink: 0; padding: 5px 9px; border-radius: 10px; background: #fff; color: #b45309; font-size: 10.5px; font-weight: 900; }
.vh-survey.done .amt { color: #047857; }

/* Lanjutkan (carousel) */
.vh-sh { display: flex; align-items: center; gap: 7px; margin: 2px 2px 0; }
.vh-sh h2 { flex: 1; margin: 0; font-size: 12.5px; font-weight: 900; color: var(--reef-ink); }
.vh-sh span { font-size: 10px; font-weight: 900; color: #0e7490; background: #ecfeff; padding: 2px 8px; border-radius: 999px; }
.vhub-continue-list { display: flex; gap: 8px; overflow-x: auto; scroll-snap-type: x mandatory; padding: 2px 2px 6px; margin: 0 -2px; scrollbar-width: none; }
.vhub-continue-list::-webkit-scrollbar { display: none; }
.vhub-continue-item { position: relative; flex: 0 0 74%; scroll-snap-align: start; border-radius: 17px; border: 2px solid #fff; background: #fff; overflow: hidden;
  box-shadow: 0 6px 14px rgba(30, 27, 46, .07); transition: opacity .3s, transform .3s; }
.vhub-continue-list .vhub-continue-item:only-child { flex-basis: 100%; }
.cw-thumb { position: relative; display: block; aspect-ratio: 16 / 7; background: #0b2530; }
.cw-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; opacity: .9; }
.cw-thumb .pl { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  background: rgba(255, 255, 255, .92); color: #0891b2; font-size: 16px; box-shadow: 0 4px 10px rgba(8, 51, 68, .35); }
.cw-thumb .bar { position: absolute; left: 0; right: 0; bottom: 0; height: 5px; background: rgba(8, 51, 68, .5); }
.cw-thumb .bar i { display: block; height: 100%; background: linear-gradient(90deg, #22d3ee, #34d399); }
.cw-del { position: absolute; top: 6px; right: 6px; z-index: 2; width: 26px; height: 26px; border-radius: 50%; border: 0; background: rgba(8, 51, 68, .55); color: #fff; font-size: 13px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.cw-info { display: flex; align-items: center; gap: 8px; padding: 7px 9px; }
.cw-info a { flex: 1; min-width: 0; text-decoration: none; color: var(--reef-ink); font-size: 11.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cw-info span { flex-shrink: 0; font-size: 10px; font-weight: 900; color: #0e7490; }

/* Filter segmented */
.vh-seg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3px; padding: 3px; border-radius: 14px; background: #ffedd5; box-shadow: inset 1px 1px 3px rgba(251, 146, 60, .2); }
.vhub-chip { border: 0; border-radius: 11px; padding: 8px 4px; background: transparent; color: #9a3412; font: 900 11px/1 'Nunito', sans-serif; cursor: pointer; }
.vhub-chip.active { background: #fff; color: var(--reef-ink); box-shadow: 0 2px 6px rgba(251, 146, 60, .25); }

/* Daftar video (baris) */
.vgrid { display: flex; flex-direction: column; gap: 8px; }
.vcard { display: flex; gap: 10px; padding: 6px; border-radius: 18px; border: 2px solid #fff; background: #fff; text-decoration: none; color: inherit;
  box-shadow: 0 5px 14px rgba(30, 27, 46, .06), inset -2px -2px 5px rgba(251, 146, 60, .08); transition: transform .14s cubic-bezier(.34, 1.56, .64, 1); }
.vcard:active { transform: scale(.98); }
.vcard--done { background: #f8fafc; opacity: .75; }
.vcard--blocked { opacity: .6; }
.vcard__thumb { position: relative; width: 136px; flex-shrink: 0; aspect-ratio: 16 / 10; border-radius: 13px; overflow: hidden; background: #0b2530; align-self: center; }
.vcard__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.vcard__dur { position: absolute; left: 5px; bottom: 5px; display: inline-flex; align-items: center; gap: 2px; padding: 2px 6px; border-radius: 7px; background: rgba(8, 51, 68, .78); color: #fff; font-size: 9px; font-weight: 900; }
.vcard__state { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; }
.vcard__state.ok { background: rgba(16, 185, 129, .55); }
.vcard__state.lock { background: rgba(15, 23, 42, .5); }
.vcard__resume { position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: rgba(8, 51, 68, .5); }
.vcard__resume i { display: block; height: 100%; background: linear-gradient(90deg, #f59e0b, #fb923c); }
.vcard__body { flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 2px 2px 1px 0; }
.vcard__title { font-size: 12px; font-weight: 800; line-height: 1.3; color: var(--reef-ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.vcard__stats { display: flex; gap: 9px; margin-top: 3px; font-size: 9.5px; font-weight: 800; color: var(--reef-muted); }
.vcard__stats span { display: inline-flex; align-items: center; gap: 2px; }
.vcard__foot { margin-top: auto; padding-top: 6px; display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.vcard__rw { padding: 3px 8px; border-radius: 999px; background: #ecfdf5; color: #047857; font-size: 10.5px; font-weight: 900; white-space: nowrap; }
.vcard__rw.done { background: #e2e8f0; color: #64748b; }
.vcard__go { display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 10px; background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff; font-size: 10.5px; font-weight: 900;
  box-shadow: 0 2px 0 #0e7490; white-space: nowrap; }

.vh-empty { padding: 26px 16px; text-align: center; border-radius: 20px; border: 2px solid #fff; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.vh-empty .vh-ico { width: 48px; height: 48px; margin: 0 auto 8px; font-size: 24px; border-radius: 15px; }
.vh-empty b { display: block; font-size: 13px; font-weight: 900; color: var(--reef-ink); }
.vh-empty p { margin: 3px 0 0; font-size: 11px; font-weight: 700; color: var(--reef-muted); }
#loader { text-align: center; padding: 12px; display: none; }
#loader span { width: 34px; height: 34px; display: inline-block; border-radius: 50%; border: 4px solid #cffafe; border-top-color: #0891b2; animation: vhSpin .8s linear infinite; }
@keyframes vhSpin { to { transform: rotate(360deg); } }
</style>

<div class="vh">

  <!-- Panel kuota -->
  <section class="vh-hero">
    <div class="vh-hero-in">
      <div class="vh-ring">
        <svg viewBox="0 0 80 80">
          <defs><linearGradient id="vhGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fef08a"/><stop offset="1" stop-color="#34d399"/></linearGradient></defs>
          <circle class="tr" cx="40" cy="40" r="33" fill="none" stroke-width="8"/>
          <circle class="fl" cx="40" cy="40" r="33" fill="none" stroke-width="8" stroke-dasharray="207.3" stroke-dashoffset="<?= round(207.3 * (1 - $pct / 100), 1) ?>"/>
        </svg>
        <div><b><?= (int)$watch_today ?></b><small>/ <?= (int)$watch_limit ?></small></div>
      </div>
      <div class="vh-txt">
        <small>Bioskop Cuan</small>
        <h1>Nonton &amp; panen saldo</h1>
        <?php if ($watch_today >= $watch_limit): ?>
          <p>Kuota hari ini habis · reset 00:00</p>
          <a href="/upgrade" class="vh-up"><i class="ph-fill ph-crown"></i>Tambah kuota</a>
        <?php else: ?>
          <p>Sisa <b><?= $left ?></b> video hari ini · reset 00:00</p>
        <?php endif; ?>
      </div>
      <img class="vh-fish" src="/assets/img/fish/bagus.png" alt="" aria-hidden="true">
    </div>
  </section>

  <!-- Survei berhadiah (bisa ditutup admin) -->
  <?php if (setting($pdo, 'survey_enabled', '1') === '1'): ?>
  <a href="/survey" class="vh-survey <?= $user_has_surveyed ? 'done' : '' ?>">
    <span class="vh-ico <?= $user_has_surveyed ? 'kelp' : 'sun' ?>"><i class="ph-bold <?= $user_has_surveyed ? 'ph-check-circle' : 'ph-clipboard-text' ?>"></i></span>
    <span class="t">
      <b><?= $user_has_surveyed ? 'Survei selesai' : 'Survei berhadiah' ?></b>
      <span class="s"><?= $user_has_surveyed ? 'Terima kasih! Hadiah sudah masuk Saldo Tarik.' : 'Isi 2 menit, hadiah langsung ke Saldo Tarik' ?></span>
    </span>
    <span class="amt"><?= $user_has_surveyed ? 'Lihat' : '+Rp 15.000' ?></span>
  </a>
  <?php endif; ?>

  <?php if (!empty($continue_watching)): ?>
  <!-- Lanjutkan menonton -->
  <section id="continue-watching-box">
    <div class="vh-sh"><span class="vh-ico lag" style="width:26px;height:26px;border-radius:9px;font-size:13px"><i class="ph-fill ph-clock-counter-clockwise"></i></span>
      <h2>Lanjutkan menonton</h2><span id="continue-watching-count"><?= count($continue_watching) ?> / 3 Video</span></div>
    <div class="vhub-continue-list" id="continue-watching-list">
      <?php foreach ($continue_watching as $cw):
        $cw_pct = min(99, max(1, (int)round(($cw['seconds_watched'] / max(1, (int)$cw['watch_duration'])) * 100))); ?>
        <div class="vhub-continue-item" id="cw-item-<?= $cw['id'] ?>">
          <a href="/watch?id=<?= $cw['id'] ?>" class="cw-thumb">
            <img src="<?= yt_thumb($cw['youtube_id']) ?>" alt="<?= htmlspecialchars($cw['title']) ?>" onerror="this.src='https://img.youtube.com/vi/<?= $cw['youtube_id'] ?>/hqdefault.jpg'">
            <span class="pl"><i class="ph-fill ph-play"></i></span>
            <span class="bar"><i style="width:<?= $cw_pct ?>%"></i></span>
          </a>
          <button type="button" class="cw-del" onclick="dismissWatchProgress(<?= $cw['id'] ?>)" title="Hapus dari riwayat" aria-label="Hapus dari riwayat"><i class="ph-bold ph-x"></i></button>
          <div class="cw-info">
            <a href="/watch?id=<?= $cw['id'] ?>" title="<?= htmlspecialchars($cw['title']) ?>"><?= htmlspecialchars($cw['title']) ?></a>
            <span><?= $cw_pct ?>% · +<?= format_rp((float)$cw['reward_amount']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Filter -->
  <div class="vh-seg">
    <button type="button" class="vhub-chip active" onclick="filterVideos('all', this)">Semua</button>
    <button type="button" class="vhub-chip" onclick="filterVideos('ready', this)">Belum</button>
    <button type="button" class="vhub-chip" onclick="filterVideos('done', this)">Selesai</button>
  </div>

  <?php if (empty($videos)): ?>
    <div class="vh-empty">
      <span class="vh-ico lag"><i class="ph-fill ph-video-camera-slash"></i></span>
      <b>Belum ada video</b>
      <p>Video baru segera hadir. Cek lagi nanti ya.</p>
    </div>
  <?php else: ?>
    <div class="vgrid" id="vgrid">
      <?php foreach ($videos as $v) $render_vcard($v); ?>
    </div>
    <div id="loader"><span></span></div>
  <?php endif; ?>

</div>

<script>
// Filter Videos
function filterVideos(type, btn) {
  document.querySelectorAll('.vhub-chip').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  const cards = document.querySelectorAll('#vgrid .vcard');
  cards.forEach(card => {
    const st = card.getAttribute('data-status');
    if (type === 'all') {
      card.style.display = '';
    } else if (type === st) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// Infinite Scroll
document.addEventListener('DOMContentLoaded', function() {
  let page = 1;
  let isLoading = false;
  let hasMore = <?= count($videos) === $limit ? 'true' : 'false' ?>;
  const grid = document.getElementById('vgrid');
  const loader = document.getElementById('loader');

  if (!grid || !hasMore) return;

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !isLoading && hasMore) {
      loadMore();
    }
  }, { rootMargin: '100px' });

  const sentinel = document.createElement('div');
  sentinel.style.height = '1px';
  grid.parentNode.insertBefore(sentinel, grid.nextSibling);
  observer.observe(sentinel);

  async function loadMore() {
    isLoading = true;
    if (loader) loader.style.display = 'block';
    page++;

    try {
      const res = await fetch(`?ajax=1&page=${page}`);
      const html = await res.text();

      if (html.trim() === '') {
        hasMore = false;
        observer.unobserve(sentinel);
      } else {
        grid.insertAdjacentHTML('beforeend', html);
      }
    } catch (e) {
      console.error('Error loading more videos:', e);
      page--;
    } finally {
      isLoading = false;
      if (loader) loader.style.display = 'none';
    }
  }
});

// Dismiss Watch Progress
async function dismissWatchProgress(vidId) {
  const item = document.getElementById('cw-item-' + vidId);
  if (item) {
    item.style.opacity = '0.4';
    item.style.pointerEvents = 'none';
  }

  const fd = new FormData();
  fd.append('action', 'delete_watch_progress');
  fd.append('_csrf', '<?= csrf_token() ?>');
  fd.append('video_id', vidId);

  try {
    const res = await fetch('/videos', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (item) {
        item.style.transition = 'all 0.3s ease';
        item.style.transform = 'scale(0.9)';
        item.style.opacity = '0';
        setTimeout(() => {
          item.remove();
          const list = document.getElementById('continue-watching-list');
          const remaining = list ? list.querySelectorAll('.vhub-continue-item').length : 0;
          const countBadge = document.getElementById('continue-watching-count');
          if (countBadge) countBadge.textContent = remaining + ' / 3 Video';
          if (remaining === 0) {
            const box = document.getElementById('continue-watching-box');
            if (box) box.remove();
          }
        }, 300);
      }
      if (typeof nToast === 'function') nToast('Riwayat tontonan dihapus.', 'info');
    }
  } catch(e) {
    if (item) {
      item.style.opacity = '';
      item.style.pointerEvents = '';
    }
  }
}
</script>

<?php if (!empty($flash)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof nToast !== 'undefined') {
        nToast('<?= addslashes($flash) ?>', '<?= $flash_type ?>');
    }
});
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
