<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';
$uid = (int)($_GET['u'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = (string)($_POST['action'] ?? '');
    $target = (int)($_POST['user_id'] ?? 0);
    $s = $pdo->prepare("SELECT id, username, fishing_baits FROM users WHERE id = ?");
    $s->execute([$target]);
    $tu = $s->fetch(PDO::FETCH_ASSOC);
    if (!$tu) { $flash = 'Pemain tidak ditemukan.'; $flashType = 'error'; }
    elseif ($action === 'baits') {
        $mode = (string)($_POST['mode'] ?? 'add');
        $amt  = (int)($_POST['amount'] ?? 0);
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120);
        $old  = (int)$tu['fishing_baits'];
        $new  = max(0, min(99999, $mode === 'set' ? $amt : $old + $amt));
        $pdo->prepare("UPDATE users SET fishing_baits = ? WHERE id = ?")->execute([$new, $target]);
        fishing_admin_log($pdo, 'player.baits', $target, ['from' => $old, 'to' => $new, 'mode' => $mode, 'amount' => $amt, 'note' => $note]);
        $flash = 'Umpan ' . $tu['username'] . ': ' . $old . ' → ' . $new . '.';
    } elseif ($action === 'reset_prefs') {
        $pdo->prepare("UPDATE fishing_prefs SET tutorial_seen = '', sound_muted = 0 WHERE user_id = ?")->execute([$target]);
        fishing_admin_log($pdo, 'player.reset_prefs', $target, ['username' => $tu['username']]);
        $flash = 'Panduan & suara ' . $tu['username'] . ' direset. Panduan akan muncul lagi.';
    } elseif ($action === 'reset_rod') {
        fishing_prefs_save($pdo, $target, ['rod_skin' => 'bambu']);
        fishing_admin_log($pdo, 'player.reset_rod', $target, ['username' => $tu['username']]);
        $flash = 'Joran ' . $tu['username'] . ' dikembalikan ke Joran Bambu.';
    }
    $uid = $target ?: $uid;
}

$pageTitle  = 'Fish System · Pemain';
$activePage = 'fishing_players';

if ($uid > 0) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$uid]);
    $pu = $s->fetch(PDO::FETCH_ASSOC);
}

if (!empty($pu)) {
    $rods  = fishing_rod_catalog($pdo, false);
    $state = fishing_rod_state($pdo, $pu, $rods);
    $prefs = fishing_prefs($pdo, $uid);
    $agg = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(sold_at IS NULL),0) un, COALESCE(SUM(CASE WHEN sold_at IS NULL THEN reward END),0) unv,
                                 COALESCE(SUM(sold_price),0) sold, COALESCE(SUM(UPPER(rarity)='JACKPOT'),0) j, MAX(created_at) last
                          FROM fishing_logs WHERE user_id = ?");
    $agg->execute([$uid]);
    $agg = $agg->fetch(PDO::FETCH_ASSOC);
    $sp = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(price),0) v, COALESCE(SUM(qty),0) q FROM fishing_bait_purchases WHERE user_id = ?");
    $sp->execute([$uid]);
    $sp = $sp->fetch(PDO::FETCH_ASSOC);
    $catches = $pdo->prepare("SELECT * FROM fishing_logs WHERE user_id = ? ORDER BY id DESC LIMIT 30");
    $catches->execute([$uid]);
    $catches = $catches->fetchAll(PDO::FETCH_ASSOC);
    $sales = $pdo->prepare("SELECT * FROM fishing_sales WHERE user_id = ? ORDER BY id DESC LIMIT 15");
    $sales->execute([$uid]);
    $sales = $sales->fetchAll(PDO::FETCH_ASSOC);
    $buys = $pdo->prepare("SELECT * FROM fishing_bait_purchases WHERE user_id = ? ORDER BY id DESC LIMIT 15");
    $buys->execute([$uid]);
    $buys = $buys->fetchAll(PDO::FETCH_ASSOC);
    $audit = $pdo->prepare("SELECT * FROM fishing_admin_logs WHERE target_user_id = ? ORDER BY id DESC LIMIT 15");
    $audit->execute([$uid]);
    $audit = $audit->fetchAll(PDO::FETCH_ASSOC);
} else {
    $q = trim((string)($_GET['q'] ?? ''));
    $where = '(l.c IS NOT NULL OR b.spent IS NOT NULL)';
    $params = [];
    if ($q !== '') { $where .= ' AND (u.username LIKE ? OR u.id = ?)'; $params = ['%' . $q . '%', ctype_digit($q) ? (int)$q : 0]; }
    $s = $pdo->prepare("
        SELECT u.id, u.username, u.fishing_baits, l.c, l.un, l.unv, l.sold, l.j, l.last, b.spent, p.rod_skin
        FROM users u
        LEFT JOIN (SELECT user_id, COUNT(*) c, SUM(sold_at IS NULL) un, SUM(CASE WHEN sold_at IS NULL THEN reward END) unv,
                          SUM(sold_price) sold, SUM(UPPER(rarity)='JACKPOT') j, MAX(created_at) last
                   FROM fishing_logs GROUP BY user_id) l ON l.user_id = u.id
        LEFT JOIN (SELECT user_id, SUM(price) spent FROM fishing_bait_purchases GROUP BY user_id) b ON b.user_id = u.id
        LEFT JOIN fishing_prefs p ON p.user_id = u.id
        WHERE $where ORDER BY l.last DESC LIMIT 500");
    $s->execute($params);
    $players = $s->fetchAll(PDO::FETCH_ASSOC);
    $rodNames = array_map(fn($r) => $r['name'], fishing_rod_catalog($pdo, false));
}

require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php if (!empty($pu)): ?>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
    <?php fs_head('Pemain: ' . $pu['username'], 'ID #' . $uid . ' · terakhir mancing ' . ($agg['last'] ? date('d/m/Y H:i', strtotime((string)$agg['last'])) : '-')); ?>
    <a href="/console/fishing_players.php" class="btn btn-outline-secondary btn-sm">← Semua Pemain</a>
  </div>
  <?php fs_tabs('fishing_players'); ?>
  <?php fs_flash($flash, $flashType); ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><?php fs_stat('Umpan', number_format((int)$pu['fishing_baits']), 'ph-bug', '#f472b6', 'Dibeli ' . (int)$sp['q'] . ' umpan · ' . format_rp((float)$sp['v'])); ?></div>
    <div class="col-6 col-lg-3"><?php fs_stat('Isi Ember', number_format((int)$agg['un']), 'ph-basket', '#fbbf24', 'Nilai dasar ' . format_rp((float)$agg['unv'])); ?></div>
    <div class="col-6 col-lg-3"><?php fs_stat('Total Terjual', format_rp((float)$agg['sold']), 'ph-hand-coins', '#34d399', (int)$agg['c'] . ' tangkapan · ' . (int)$agg['j'] . ' peti'); ?></div>
    <div class="col-6 col-lg-3"><?php fs_stat('Saldo', format_rp((float)$pu['balance_wd']), 'ph-wallet', '#22d3ee', 'Saldo Beli ' . format_rp((float)$pu['balance_dep'])); ?></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="c-card mb-3"><div class="c-card-header"><b>Atur Umpan</b></div><div class="c-card-body">
        <form method="post" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="action" value="baits"><input type="hidden" name="user_id" value="<?= $uid ?>">
          <div class="col-5"><label class="c-label">Mode</label>
            <select class="c-form-control" name="mode"><option value="add">Tambah / kurangi</option><option value="set">Setel jadi</option></select></div>
          <div class="col-7"><label class="c-label">Jumlah (minus untuk kurangi)</label><input type="number" class="c-form-control" name="amount" value="5" required></div>
          <div class="col-12"><label class="c-label">Catatan (masuk audit)</label><input class="c-form-control" name="note" maxlength="120" placeholder="mis. kompensasi gangguan"></div>
          <div class="col-12"><button class="btn btn-primary w-100">Simpan Umpan</button></div>
        </form>
      </div></div>

      <div class="c-card"><div class="c-card-header"><b>Joran & Preferensi</b></div><div class="c-card-body">
        <div class="mb-2" style="font-size:13px">Joran dipakai: <b><?= htmlspecialchars($rods[$state['selected']]['name'] ?? $state['selected']) ?></b></div>
        <div class="mb-2 fs-muted">Tier membership: <?= (int)$state['tier'] ?> · Suara: <?= $prefs['sound_muted'] ? 'dimatikan' : 'aktif' ?> · Panduan dilihat: <?= $prefs['tutorial_seen'] ? htmlspecialchars(implode(', ', $prefs['tutorial_seen'])) : '-' ?></div>
        <div class="d-flex flex-wrap gap-1 mb-3">
          <?php foreach ($state['rods'] as $r): ?>
            <span class="fs-pill" style="color:<?= $r['unlocked'] ? '#34d399' : '#64748b' ?>;border-color:currentColor" title="<?= htmlspecialchars($r['req']) ?>"><?= $r['unlocked'] ? '✓' : '🔒' ?> <?= htmlspecialchars($r['name']) ?></span>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2">
          <form method="post" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="action" value="reset_rod"><input type="hidden" name="user_id" value="<?= $uid ?>"><button class="btn btn-sm btn-outline-warning w-100">Reset Joran</button></form>
          <form method="post" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="action" value="reset_prefs"><input type="hidden" name="user_id" value="<?= $uid ?>"><button class="btn btn-sm btn-outline-warning w-100">Reset Panduan & Suara</button></form>
        </div>
      </div></div>
    </div>

    <div class="col-lg-7">
      <div class="c-card h-100"><div class="c-card-header d-flex justify-content-between"><b>30 Tangkapan Terakhir</b><a href="/console/fishing_catches.php?u=<?= $uid ?>&from=2000-01-01" class="fs-muted" style="text-decoration:none">Semua →</a></div>
        <div class="c-card-body p-0" style="max-height:470px;overflow:auto"><table class="c-table no-dt w-100">
          <thead><tr><th>Waktu</th><th>Ikan</th><th>Berat</th><th>Nilai</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$catches): ?><tr><td colspan="5" class="text-center fs-muted py-4">Belum pernah mancing.</td></tr><?php endif; ?>
          <?php foreach ($catches as $c): ?>
            <tr><td style="white-space:nowrap"><?= date('d/m H:i', strtotime((string)$c['created_at'])) ?></td>
              <td><?= fs_badge(fishing_badge_of($c['rarity'])) ?> <span class="ms-1"><?= htmlspecialchars($c['fish_name']) ?></span></td>
              <td class="fs-num"><?= number_format((float)$c['weight_kg'], 2) ?> kg</td>
              <td class="fs-num"><?= format_rp((float)$c['reward']) ?></td>
              <td><?= $c['sold_at'] ? '<span style="color:#34d399">' . format_rp((float)$c['sold_price']) . '</span>' : '<span style="color:#fbbf24">Di Ember</span>' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4"><div class="c-card h-100"><div class="c-card-header"><b>Penjualan</b></div><div class="c-card-body p-0"><table class="c-table no-dt w-100">
      <tbody><?php if (!$sales): ?><tr><td class="text-center fs-muted py-3">-</td></tr><?php endif; ?>
      <?php foreach ($sales as $x): ?><tr><td><a href="/console/fishing_sales.php?sale=<?= (int)$x['id'] ?>" style="color:#67e8f9">#<?= (int)$x['id'] ?></a> <span class="fs-muted"><?= date('d/m H:i', strtotime((string)$x['created_at'])) ?></span></td><td class="fs-num"><?= (int)$x['fish_count'] ?> ikan</td><td class="text-end fs-num" style="color:#34d399"><?= format_rp((float)$x['total']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div></div>
    <div class="col-lg-4"><div class="c-card h-100"><div class="c-card-header"><b>Pembelian Umpan</b></div><div class="c-card-body p-0"><table class="c-table no-dt w-100">
      <tbody><?php if (!$buys): ?><tr><td class="text-center fs-muted py-3">-</td></tr><?php endif; ?>
      <?php foreach ($buys as $x): ?><tr><td><?= htmlspecialchars($x['pack_name']) ?> <span class="fs-muted"><?= date('d/m H:i', strtotime((string)$x['created_at'])) ?></span></td><td class="fs-num">+<?= (int)$x['qty'] ?></td><td class="text-end fs-num" style="color:#fb923c"><?= format_rp((float)$x['price']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div></div>
    <div class="col-lg-4"><div class="c-card h-100"><div class="c-card-header"><b>Audit Admin</b></div><div class="c-card-body p-0"><table class="c-table no-dt w-100">
      <tbody><?php if (!$audit): ?><tr><td class="text-center fs-muted py-3">-</td></tr><?php endif; ?>
      <?php foreach ($audit as $x): ?><tr><td><code style="font-size:11px"><?= htmlspecialchars($x['action']) ?></code><div class="fs-muted"><?= htmlspecialchars($x['admin']) ?> · <?= date('d/m H:i', strtotime((string)$x['created_at'])) ?></div></td><td class="fs-muted" style="font-size:11px;word-break:break-all"><?= htmlspecialchars(mb_substr((string)$x['detail'], 0, 120)) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div></div>
  </div>

<?php else: ?>
  <?php fs_head('Pemain', 'Semua pemain yang pernah memancing atau membeli umpan. Klik nama untuk detail & penyesuaian.'); ?>
  <?php fs_tabs('fishing_players'); ?>
  <?php fs_flash($flash, $flashType); ?>
  <?php if ($uid > 0): ?><div class="alert alert-danger py-2 px-3" style="font-size:13px;border-radius:10px">Pemain #<?= $uid ?> tidak ditemukan.</div><?php endif; ?>

  <div class="c-card mb-3"><div class="c-card-body">
    <form class="fs-filter" method="get">
      <div><label class="c-label">Cari (ID / username)</label><input class="c-form-control" name="q" value="<?= htmlspecialchars($q) ?>"></div>
      <div style="flex:0 0 auto;min-width:0"><button class="btn btn-primary">Cari</button></div>
    </form>
  </div></div>

  <div class="c-card"><div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table w-100">
      <thead><tr><th>Pemain</th><th>Umpan</th><th>Tangkapan</th><th>Peti</th><th>Di Ember</th><th>Total Terjual</th><th>Belanja Umpan</th><th>Joran</th><th>Terakhir</th></tr></thead>
      <tbody>
      <?php foreach ($players as $p): ?>
        <tr>
          <td><?= fs_user_link((int)$p['id'], $p['username']) ?></td>
          <td class="fs-num"><?= (int)$p['fishing_baits'] ?></td>
          <td class="fs-num"><?= (int)$p['c'] ?></td>
          <td class="fs-num"><?= (int)$p['j'] ?></td>
          <td class="fs-num" data-order="<?= (float)$p['unv'] ?>"><?= (int)$p['un'] ?> <span class="fs-muted"><?= format_rp((float)$p['unv']) ?></span></td>
          <td class="fs-num" data-order="<?= (float)$p['sold'] ?>" style="color:#34d399"><?= format_rp((float)$p['sold']) ?></td>
          <td class="fs-num" data-order="<?= (float)$p['spent'] ?>" style="color:#fb923c"><?= format_rp((float)$p['spent']) ?></td>
          <td><?= htmlspecialchars($rodNames[$p['rod_skin'] ?? 'bambu'] ?? ($p['rod_skin'] ?? 'Bambu')) ?></td>
          <td data-order="<?= $p['last'] ? strtotime((string)$p['last']) : 0 ?>" style="white-space:nowrap"><?= $p['last'] ? date('d/m/y H:i', strtotime((string)$p['last'])) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div></div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
