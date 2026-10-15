<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// ── Daftar misi dari database (tabel `missions`, satu sumber untuk halaman ini, riwayat & console) ──
$ALL_MISSIONS = [];
foreach ($pdo->query("SELECT * FROM missions WHERE is_active = 1 ORDER BY FIELD(category, 'daily', 'weekly', 'lifetime'), sort_order, id") as $r) {
    $ALL_MISSIONS[] = [
        'slug'     => (string)$r['slug'],
        'category' => (string)$r['category'],
        'title'    => (string)$r['title'],
        'desc'     => (string)$r['description'],
        'target'   => max(1, (int)$r['target_value']),
        'reward'   => (float)$r['reward_amount'],
        'bait'     => max(0, (int)($r['reward_bait'] ?? 0)),   // hadiah umpan pancing (opsional)
        'icon'     => (string)($r['icon'] ?: 'ph-target'),
    ];
}

/** Jenis misi → warna ikon & tujuan tombol "Mulai" */
function mission_kind(string $slug): array {
    return match (true) {
        str_contains($slug, 'ref')                                   => ['ref', '/referral'],
        str_contains($slug, '_wd')                                   => ['wd', '/withdraw'],
        str_contains($slug, 'upgrade')                               => ['up', '/upgrade'],
        str_contains($slug, 'active')                                => ['mix', '/home'],
        (bool)preg_match('/cast|sell|catch|rare|fish|jackpot/', $slug) => ['fish', '/minigame'],
        default                                                      => ['video', '/videos'],
    };
}

// ── Progress real-time ─────────────────────────────────────────────────
function get_progress(PDO $pdo, array $user, array $mission): int {
    $uid = (int)$user['id'];
    $q = static function (string $sql, array $p) use ($pdo): int {
        try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); } catch (\Throwable) { return 0; }
    };
    $week  = "YEARWEEK(%s, 1) = YEARWEEK(CURDATE(), 1)";
    $notJunk = "UPPER(rarity) <> 'JUNK'";
    switch ($mission['slug']) {
        // Video
        case 'daily_watch_3':
        case 'daily_watch_5':
        case 'daily_watch_6':
            return $q("SELECT COUNT(*) FROM watch_history WHERE user_id = ? AND DATE(watched_at) = CURDATE()", [$uid]);
        case 'weekly_watch_20':
            return $q("SELECT COUNT(*) FROM watch_history WHERE user_id = ? AND " . sprintf($week, 'watched_at'), [$uid]);
        case 'lifetime_100_videos':
            return $q("SELECT COUNT(*) FROM watch_history WHERE user_id = ?", [$uid]);
        // Mancing (zonk/sampah tidak dihitung sebagai tangkapan)
        case 'daily_cast_5':
            return $q("SELECT COUNT(*) FROM fishing_logs WHERE user_id = ? AND DATE(created_at) = CURDATE()", [$uid])
                 + $q("SELECT COUNT(*) FROM fishing_released WHERE user_id = ? AND DATE(caught_at) = CURDATE()", [$uid]);
        case 'daily_sell_fish':
            return $q("SELECT COUNT(*) FROM fishing_sales WHERE user_id = ? AND DATE(created_at) = CURDATE()", [$uid]);
        case 'weekly_catch_30':
            return $q("SELECT COUNT(*) FROM fishing_logs WHERE user_id = ? AND $notJunk AND " . sprintf($week, 'created_at'), [$uid]);
        case 'weekly_rare_3':
            return $q("SELECT COUNT(*) FROM fishing_logs WHERE user_id = ? AND UPPER(rarity) IN ('RARE', 'EPIC', 'JACKPOT') AND " . sprintf($week, 'created_at'), [$uid]);
        case 'lifetime_100_fish':
            return $q("SELECT COUNT(*) FROM fishing_logs WHERE user_id = ? AND $notJunk", [$uid]);
        case 'lifetime_jackpot':
            return $q("SELECT COUNT(*) FROM fishing_logs WHERE user_id = ? AND UPPER(rarity) = 'JACKPOT'", [$uid]);
        // Gabungan: hari berbeda dengan aktivitas nonton ATAU mancing
        case 'weekly_active_5':
            return $q("SELECT COUNT(*) FROM (
                         SELECT DATE(watched_at) d FROM watch_history WHERE user_id = ? AND " . sprintf($week, 'watched_at') . "
                         UNION SELECT DATE(created_at) FROM fishing_logs WHERE user_id = ? AND " . sprintf($week, 'created_at') . "
                       ) x", [$uid, $uid]);
        // Lainnya
        case 'lifetime_first_ref':
        case 'lifetime_5_refs':
            return $q("SELECT COUNT(*) FROM users WHERE TRIM(UPPER(referred_by)) = TRIM(UPPER(?))", [(string)$user['referral_code']]);
        case 'lifetime_first_wd':
            return $q("SELECT COUNT(*) FROM withdrawals WHERE user_id = ?", [$uid]);
        case 'lifetime_upgrade':
            return !empty($user['membership_id']) ? 1 : 0;
    }
    return 0;
}

function get_period_key(string $category): ?string {
    if ($category === 'daily')  return date('Y-m-d');
    if ($category === 'weekly') return date('o-\WW');
    return null; // lifetime
}

function is_claimed(PDO $pdo, int $user_id, string $slug, ?string $period_key): bool {
    $s = $pdo->prepare("SELECT 1 FROM user_missions WHERE user_id = ? AND mission_slug = ? AND period_key <=> ? AND claimed_at IS NOT NULL LIMIT 1");
    $s->execute([$user_id, $slug, $period_key]);
    return (bool)$s->fetchColumn();
}

// ── Klaim hadiah ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_mission') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok' => false, 'msg' => 'Sesi kedaluwarsa, muat ulang halaman.']); exit; }

    $slug = trim((string)($_POST['slug'] ?? ''));
    $mission = null;
    foreach ($ALL_MISSIONS as $m) if ($m['slug'] === $slug) { $mission = $m; break; }
    if (!$mission) { echo json_encode(['ok' => false, 'msg' => 'Misi tidak ditemukan.']); exit; }

    $period   = get_period_key($mission['category']);
    $progress = get_progress($pdo, $user, $mission);
    if ($progress < $mission['target']) {
        echo json_encode(['ok' => false, 'msg' => "Belum selesai ({$progress}/{$mission['target']})."]); exit;
    }

    try {
        $pdo->beginTransaction();
        // Kunci baris user agar klik ganda tidak membayar dua kali
        $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE")->execute([$user['id']]);
        if (is_claimed($pdo, (int)$user['id'], $slug, $period)) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Misi ini sudah diklaim.']); exit;
        }
        $pdo->prepare("INSERT INTO user_missions (user_id, mission_slug, progress, completed_at, claimed_at, period_key) VALUES (?, ?, ?, NOW(), NOW(), ?)")
            ->execute([$user['id'], $slug, $progress, $period]);
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, fishing_baits = fishing_baits + ? WHERE id = ?")
            ->execute([$mission['reward'], $mission['bait'], $user['id']]);
        $pdo->commit();

        $bait_txt = $mission['bait'] > 0 ? ' + ' . $mission['bait'] . ' umpan' : '';
        $tg  = "🎯 <b>MEMBER KLAIM MISI!</b>\n";
        $tg .= "Username: <code>" . htmlspecialchars((string)$user['username']) . "</code>\n";
        $tg .= "Misi: <b>" . htmlspecialchars($mission['title']) . "</b>\n";
        $tg .= "Reward: " . format_rp($mission['reward']) . $bait_txt;
        send_telegram_notif($pdo, $tg, [], 'misi');

        $msg = $mission['reward'] > 0 ? 'Hadiah +' . format_rp($mission['reward']) . ' masuk ke Saldo Tarik' : 'Misi selesai';
        echo json_encode(['ok' => true, 'msg' => $msg . ($mission['bait'] > 0 ? ' dan +' . $mission['bait'] . ' umpan pancing!' : '!'), 'reward' => $mission['reward']]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Gagal mengklaim, coba lagi.']);
    }
    exit;
}

// ── Data tampilan ──────────────────────────────────────────────────────
$groups = ['daily' => [], 'weekly' => [], 'lifetime' => []];
$ready_total = 0.0; $ready_count = 0;
foreach ($ALL_MISSIONS as $m) {
    $period   = get_period_key($m['category']);
    $progress = get_progress($pdo, $user, $m);
    $claimed  = is_claimed($pdo, (int)$user['id'], $m['slug'], $period);
    $done     = $progress >= $m['target'];
    [$kind, $go] = mission_kind($m['slug']);
    if ($done && !$claimed) { $ready_total += $m['reward']; $ready_count++; }
    $groups[$m['category']][] = $m + [
        'progress' => min($progress, $m['target']), 'claimed' => $claimed, 'done' => $done, 'kind' => $kind, 'go' => $go,
    ];
}
$TABS = ['daily' => ['Harian', 'ph-sun', 'Reset tiap 00:00'], 'weekly' => ['Mingguan', 'ph-calendar-blank', 'Reset tiap Senin'], 'lifetime' => ['Pencapaian', 'ph-trophy', 'Sekali seumur akun']];
$active_tab = 'daily';
foreach ($groups as $cat => $list) { foreach ($list as $m) if ($m['done'] && !$m['claimed']) { $active_tab = $cat; break 2; } }

$pageTitle  = 'Misi Harian';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════════════════
   MISI — Living Coral Reef, ringkas & minimalis
   Token warna & latar laut dari partials/header.php (--reef-*)
   ══════════════════════════════════════════════════════════ */
.ms-wrap { position: relative; z-index: 1; width: 100%; max-width: 440px; margin: 0 auto; padding: 6px 12px 96px; color: var(--reef-ink); display: flex; flex-direction: column; gap: 10px; }
.ms-top-nav { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 2px 2px; }
.ms-back-btn { width: 36px; height: 36px; border-radius: 12px; border: 1.5px solid #ffffff; background: #ffffff; display: inline-flex; align-items: center; justify-content: center; color: var(--reef-ink); font-size: 17px; text-decoration: none; box-shadow: 0 3px 8px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.95); transition: transform 0.12s ease; }
.ms-back-btn:active { transform: scale(0.92); }
.ms-nav-title { display: flex; align-items: center; gap: 6px; background: rgba(255, 255, 255, 0.92); border: 1.5px solid #ffffff; border-radius: 14px; padding: 6px 14px; font-size: 13px; font-weight: 900; color: var(--reef-ink); box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04); }
.ms-bounty-badge { background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%); border: 1.5px solid #ffffff; color: #78350f; font-weight: 900; font-size: 11px; padding: 6px 11px; border-radius: 14px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.22), inset 1px 1px 2px #ffffff; }
.ms-sum { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 2px solid #fff; border-radius: 20px; color: #fff;
  background: linear-gradient(150deg, #0e7490, #22d3ee); box-shadow: 0 10px 22px rgba(8, 145, 178, 0.24), inset 2px 2px 5px rgba(255, 255, 255, 0.3); }
.ms-sum-ic { width: 42px; height: 42px; flex-shrink: 0; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; background: rgba(255, 255, 255, 0.2); border: 2px solid rgba(255, 255, 255, 0.6); }
.ms-sum-tx { flex: 1; min-width: 0; }
.ms-sum-tx small { display: block; font-size: 9.5px; font-weight: 900; letter-spacing: 0.5px; text-transform: uppercase; opacity: 0.9; }
.ms-sum-tx b { display: block; font-size: 20px; font-weight: 900; line-height: 1.15; }
.ms-sum-pill { flex-shrink: 0; padding: 5px 11px; border-radius: 999px; font-size: 11px; font-weight: 900; background: #fff; color: #0e7490; box-shadow: 0 2px 6px rgba(0,0,0,0.06); }
.ms-tabs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; margin: 0; padding: 4px; border: 2px solid #fff; border-radius: 16px; background: rgba(255, 255, 255, 0.7); box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04); }
.ms-tab { position: relative; display: flex; align-items: center; justify-content: center; gap: 5px; padding: 8px 4px; border: none; border-radius: 12px; background: none; cursor: pointer; font-family: inherit; font-size: 11.5px; font-weight: 900; color: var(--reef-muted); transition: all 0.15s ease; }
.ms-tab i { font-size: 14px; }
.ms-tab.on { color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 5px 12px rgba(225, 29, 72, 0.28), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
.ms-tab .dot { position: absolute; top: 4px; right: 6px; width: 8px; height: 8px; border-radius: 50%; background: #f59e0b; border: 1.5px solid #fff; }
.ms-panel { display: none; }
.ms-panel.on { display: block; }
.ms-note { margin: 0 4px 6px; font-size: 10px; font-weight: 800; color: var(--reef-muted); display: flex; align-items: center; gap: 4px; }
.ms-card { border: 2px solid #fff; border-radius: 18px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }
.ms-row { display: flex; align-items: center; gap: 9px; padding: 10px 12px; }
.ms-row + .ms-row { border-top: 1.5px dashed rgba(251, 146, 60, 0.22); }
.ms-row.is-claimed { opacity: 0.55; }
.ms-ic { width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -2px -2px 4px rgba(0, 0, 0, 0.14); }
.ms-ic.k-video { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.ms-ic.k-fish { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.ms-ic.k-mix { background: linear-gradient(145deg, #6ee7b7, #059669); }
.ms-ic.k-ref { background: linear-gradient(145deg, #fda4af, #e11d48); }
.ms-ic.k-wd { background: linear-gradient(145deg, #fcd34d, #f59e0b); }
.ms-ic.k-up { background: linear-gradient(145deg, #fde68a, #d97706); }
.ms-body { flex: 1; min-width: 0; }
.ms-title { display: flex; align-items: baseline; gap: 6px; font-size: 12.5px; font-weight: 900; }
.ms-title span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-title em { flex-shrink: 0; margin-left: auto; font-style: normal; font-size: 11.5px; font-weight: 900; color: #059669; }
.ms-bar { height: 6px; margin: 4px 0 3px; border-radius: 999px; background: rgba(8, 145, 178, 0.13); overflow: hidden; }
.ms-bar i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #22d3ee, #34d399); }
.ms-meta { display: flex; justify-content: space-between; gap: 6px; font-size: 10px; font-weight: 700; color: var(--reef-muted); }
.ms-meta span:first-child { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-meta b { flex-shrink: 0; font-weight: 900; color: #0e7490; }
.ms-act { flex-shrink: 0; }
.ms-btn { display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-width: 64px; padding: 7px 11px; border: 2px solid #fff; border-radius: 999px; cursor: pointer; text-decoration: none; font-family: inherit; font-size: 11px; font-weight: 900; transition: transform 0.1s ease; }
.ms-btn:active { transform: scale(0.95); }
.ms-btn.claim { color: #fff; background: linear-gradient(145deg, #fcd34d, #f59e0b); box-shadow: 0 5px 12px rgba(245, 158, 11, 0.35), inset 2px 2px 3px rgba(255, 255, 255, 0.45); animation: msPulse 1.6s ease-in-out infinite; }
.ms-btn.go { color: #0e7490; background: #fff; box-shadow: 0 3px 8px rgba(8, 145, 178, 0.12), inset 1.5px 1.5px 3px #fff; }
.ms-btn.done { color: #059669; background: #d1fae5; cursor: default; }
@keyframes msPulse { 50% { box-shadow: 0 0 0 5px rgba(245, 158, 11, 0.18), inset 2px 2px 3px rgba(255, 255, 255, 0.45); } }
@keyframes msSpin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .ms-btn.claim { animation: none; } }
</style>

<div class="ms-wrap">

  <!-- ── COMPACT TOP NAVIGATOR ── -->
  <div class="ms-top-nav">
    <a href="/home" class="ms-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="ms-nav-title">
      <i class="ph-fill ph-target" style="color:var(--reef-coral-500);"></i>
      <span>Misi Samudra</span>
    </div>
    <div class="ms-bounty-badge">
      <i class="ph-bold ph-coins"></i>
      <span>Saldo Tarik</span>
    </div>
  </div>

  <section class="ms-sum">
    <span class="ms-sum-ic"><i class="ph-fill ph-target"></i></span>
    <span class="ms-sum-tx"><small>Siap diklaim</small><b id="ms-ready-amt"><?= format_rp($ready_total) ?></b></span>
    <span class="ms-sum-pill" id="ms-ready-n"><?= $ready_count ?> misi</span>
  </section>

  <div class="ms-tabs" role="tablist">
    <?php foreach ($TABS as $cat => [$lbl, $ic]):
      $hasReady = (bool)array_filter($groups[$cat], fn($m) => $m['done'] && !$m['claimed']); ?>
      <button type="button" class="ms-tab <?= $cat === $active_tab ? 'on' : '' ?>" data-tab="<?= $cat ?>" onclick="msTab('<?= $cat ?>')">
        <i class="ph-bold <?= $ic ?>"></i><?= $lbl ?><?php if ($hasReady): ?><span class="dot"></span><?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach ($TABS as $cat => [$lbl, $ic, $note]): ?>
  <div class="ms-panel <?= $cat === $active_tab ? 'on' : '' ?>" id="ms-<?= $cat ?>">
    <div class="ms-note"><i class="ph-bold ph-clock-counter-clockwise"></i> <?= $note ?></div>
    <div class="ms-card">
      <?php foreach ($groups[$cat] as $m): $pct = (int)round($m['progress'] / $m['target'] * 100); ?>
      <div class="ms-row <?= $m['claimed'] ? 'is-claimed' : '' ?>" id="mr-<?= htmlspecialchars($m['slug']) ?>" data-reward="<?= (float)$m['reward'] ?>">
        <span class="ms-ic k-<?= $m['kind'] ?>"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></span>
        <div class="ms-body">
          <div class="ms-title"><span><?= htmlspecialchars($m['title']) ?></span><em>+<?= format_rp($m['reward']) ?><?php if (!empty($m['bait'])): ?> · <i class="ph-fill ph-fish-simple"></i>+<?= (int)$m['bait'] ?> umpan<?php endif; ?></em></div>
          <div class="ms-bar"><i style="width:<?= $pct ?>%"></i></div>
          <div class="ms-meta"><span><?= htmlspecialchars($m['desc']) ?></span><b><?= $m['progress'] ?>/<?= $m['target'] ?></b></div>
        </div>
        <div class="ms-act">
          <?php if ($m['claimed']): ?>
            <span class="ms-btn done"><i class="ph-bold ph-check"></i> Beres</span>
          <?php elseif ($m['done']): ?>
            <button type="button" class="ms-btn claim" onclick="claimMission('<?= htmlspecialchars($m['slug'], ENT_QUOTES) ?>', this)"><i class="ph-fill ph-gift"></i> Klaim</button>
          <?php else: ?>
            <a href="<?= $m['go'] ?>" class="ms-btn go">Mulai <i class="ph-bold ph-arrow-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const _csrf = '<?= csrf_token() ?>';
function msTab(cat) {
  document.querySelectorAll('.ms-tab').forEach((t) => t.classList.toggle('on', t.dataset.tab === cat));
  document.querySelectorAll('.ms-panel').forEach((p) => p.classList.toggle('on', p.id === 'ms-' + cat));
}
function msRefreshSummary() {
  let amt = 0, n = 0;
  document.querySelectorAll('.ms-btn.claim').forEach((b) => { n++; amt += +b.closest('.ms-row').dataset.reward || 0; });
  document.getElementById('ms-ready-amt').textContent = 'Rp ' + Math.round(amt).toLocaleString('id-ID');
  document.getElementById('ms-ready-n').textContent = n + ' misi';
  document.querySelectorAll('.ms-tab').forEach((t) => {
    const has = document.querySelector('#ms-' + t.dataset.tab + ' .ms-btn.claim');
    const dot = t.querySelector('.dot'); if (!has && dot) dot.remove();
  });
}
function claimMission(slug, btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:msSpin .8s linear infinite"></i>';
  const fd = new FormData();
  fd.append('action', 'claim_mission'); fd.append('slug', slug); fd.append('_csrf', _csrf);
  fetch(location.href, { method: 'POST', body: fd })
    .then((r) => r.json())
    .then((data) => {
      if (data.ok) {
        const row = document.getElementById('mr-' + slug);
        if (row) row.classList.add('is-claimed');
        const done = document.createElement('span');
        done.className = 'ms-btn done'; done.innerHTML = '<i class="ph-bold ph-check"></i> Beres';
        btn.replaceWith(done);
        msRefreshSummary();
        if (typeof nToast !== 'undefined') nToast(data.msg, 'success');
      } else {
        btn.disabled = false; btn.innerHTML = '<i class="ph-fill ph-gift"></i> Klaim';
        if (typeof nToast !== 'undefined') nToast(data.msg, 'error');
      }
    })
    .catch(() => {
      btn.disabled = false; btn.innerHTML = '<i class="ph-fill ph-gift"></i> Klaim';
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error');
    });
}
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
