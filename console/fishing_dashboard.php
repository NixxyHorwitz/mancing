<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s; })();
$today = date('Y-m-d');
$d7    = date('Y-m-d', strtotime('-6 days'));

$catchToday = $q("SELECT COUNT(*) c, COALESCE(SUM(reward),0) v FROM fishing_logs WHERE created_at >= ?", [$today])->fetch(PDO::FETCH_ASSOC);
$unsold     = $q("SELECT COUNT(*) c, COALESCE(SUM(reward),0) v, COUNT(DISTINCT user_id) u FROM fishing_logs WHERE sold_at IS NULL")->fetch(PDO::FETCH_ASSOC);
$salesToday = $q("SELECT COUNT(*) c, COALESCE(SUM(total),0) v FROM fishing_sales WHERE created_at >= ?", [$today])->fetch(PDO::FETCH_ASSOC);
$sales7     = $q("SELECT COUNT(*) c, COALESCE(SUM(total),0) v FROM fishing_sales WHERE created_at >= ?", [$d7])->fetch(PDO::FETCH_ASSOC);
$baitToday  = $q("SELECT COUNT(*) c, COALESCE(SUM(price),0) v, COALESCE(SUM(qty),0) q FROM fishing_bait_purchases WHERE created_at >= ?", [$today])->fetch(PDO::FETCH_ASSOC);
$bait7      = $q("SELECT COALESCE(SUM(price),0) v FROM fishing_bait_purchases WHERE created_at >= ?", [$d7])->fetchColumn();
$players7   = (int)$q("SELECT COUNT(DISTINCT user_id) FROM fishing_logs WHERE created_at >= ?", [$d7])->fetchColumn();
$baitsHeld  = (int)$q("SELECT COALESCE(SUM(fishing_baits),0) FROM users")->fetchColumn();
$lock7      = $q("SELECT COUNT(*) c, COALESCE(SUM(is_free),0) f, COALESCE(SUM(price),0) v FROM fishing_lock_uses WHERE created_at >= ?", [$d7])->fetch(PDO::FETCH_ASSOC);
$released7  = $q("SELECT COUNT(*) c, COALESCE(SUM(reward),0) v FROM fishing_released WHERE created_at >= ?", [$d7])->fetch(PDO::FETCH_ASSOC);

// Grafik 14 hari: tangkapan & penjualan
$days = [];
for ($i = 13; $i >= 0; $i--) $days[date('Y-m-d', strtotime("-$i days"))] = ['c' => 0, 's' => 0, 'b' => 0];
$from14 = array_key_first($days);
foreach ($q("SELECT DATE(created_at) d, COUNT(*) c FROM fishing_logs WHERE created_at >= ? GROUP BY d", [$from14]) as $r) if (isset($days[$r['d']])) $days[$r['d']]['c'] = (int)$r['c'];
foreach ($q("SELECT DATE(created_at) d, SUM(total) s FROM fishing_sales WHERE created_at >= ? GROUP BY d", [$from14]) as $r) if (isset($days[$r['d']])) $days[$r['d']]['s'] = (float)$r['s'];
foreach ($q("SELECT DATE(created_at) d, SUM(price) b FROM fishing_bait_purchases WHERE created_at >= ? GROUP BY d", [$from14]) as $r) if (isset($days[$r['d']])) $days[$r['d']]['b'] = (float)$r['b'];
$maxC = max(1, max(array_column($days, 'c')));
$maxS = max(1, max(array_column($days, 's')), max(array_column($days, 'b')));

// Distribusi spesies 7 hari
$dist = array_fill_keys(FISHING_ALL_BADGES, 0);
foreach ($q("SELECT " . FS_BADGE_SQL . " b, COUNT(*) c FROM fishing_logs WHERE created_at >= ? GROUP BY b", [$d7]) as $r) $dist[$r['b']] = (int)$r['c'];
$distTotal = max(1, array_sum($dist));

// Pemain teratas 7 hari (nilai terjual)
$top = $q("SELECT s.user_id, u.username, COUNT(*) n, SUM(s.fish_count) fish, SUM(s.total) total
           FROM fishing_sales s LEFT JOIN users u ON u.id = s.user_id
           WHERE s.created_at >= ? GROUP BY s.user_id, u.username ORDER BY total DESC LIMIT 8", [$d7])->fetchAll(PDO::FETCH_ASSOC);

$market = fishing_market_today($pdo);
$recent = $pdo->query("SELECT l.id, l.user_id, u.username, l.fish_name, l.rarity, l.weight_kg, l.reward, l.sold_at, l.created_at
                       FROM fishing_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$enabled = !empty(home_cms_config($pdo)['fishing_engine']['enabled']);

$pageTitle  = 'Fish System · Dashboard';
$activePage = 'fishing_dashboard';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Fish System', 'Ringkasan ekonomi Pemancingan Bahari Nusantara: tangkapan, Ember, penjualan & umpan.'); ?>
<?php fs_tabs('fishing_dashboard'); ?>

<?php if (!$enabled): ?>
<div class="alert alert-warning py-2 px-3" style="font-size:13px;border-radius:10px">Fitur mancing sedang <b>nonaktif</b> untuk pengguna. Aktifkan di <a href="/console/fishing_settings.php">Pengaturan</a>.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?php fs_stat('Tangkapan Hari Ini', number_format((int)$catchToday['c']), 'ph-fish-simple', '#22d3ee', 'Nilai dasar ' . format_rp((float)$catchToday['v'])); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Isi Ember (belum dijual)', number_format((int)$unsold['c']), 'ph-basket', '#fbbf24', format_rp((float)$unsold['v']) . ' · ' . (int)$unsold['u'] . ' pemain'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Penjualan Hari Ini', format_rp((float)$salesToday['v']), 'ph-hand-coins', '#34d399', (int)$salesToday['c'] . ' transaksi · 7 hari ' . format_rp((float)$sales7['v'])); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Pendapatan Umpan Hari Ini', format_rp((float)$baitToday['v']), 'ph-bug', '#fb923c', (int)$baitToday['q'] . ' umpan · 7 hari ' . format_rp((float)$bait7)); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Pemain Aktif 7 Hari', number_format($players7), 'ph-users-three', '#a78bfa'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Umpan Beredar', number_format($baitsHeld), 'ph-package', '#f472b6', 'Total umpan di semua akun'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Kunci Ikan 7 Hari', format_rp((float)$lock7['v']), 'ph-lock-simple', '#c4b5fd', (int)$lock7['c'] . 'x dipakai · ' . (int)$lock7['f'] . 'x gratis'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Ikan Dilepas 7 Hari', number_format((int)$released7['c']), 'ph-flag', '#94a3b8', 'Nilai dasar ' . format_rp((float)$released7['v'])); ?></div>
  <div class="col-12 col-lg-6">
    <div class="c-stat h-100" style="border-top:3px solid #38bdf8">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="c-stat__lbl">Harga Pasar Hari Ini</div>
        <a href="/console/fishing_market.php" class="fs-muted" style="text-decoration:none">Atur →</a>
      </div>
      <div class="fs-mk">
        <?php foreach ($market as $b => $m): ?>
        <div>
          <div class="mb-1"><?= fs_badge($b) ?></div>
          <b class="fs-num <?= $m['trend'] ?>"><?= round($m['mult'] * 100) ?>%</b>
          <div class="fs-muted" style="font-size:10.5px"><?= $m['manual'] ? 'manual' : 'otomatis' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="c-card h-100">
      <div class="c-card-header"><b>Aktivitas 14 Hari</b> <span class="fs-muted ms-2">jumlah tangkapan · penjualan ikan (biru) vs pembelian umpan (oranye)</span></div>
      <div class="c-card-body">
        <div class="fs-chart">
          <?php foreach ($days as $d => $v): ?>
          <div class="col" title="<?= $d ?>: <?= $v['c'] ?> ikan">
            <span class="val"><?= $v['c'] ?: '' ?></span>
            <div class="bar" style="height:<?= round($v['c'] / $maxC * 100) ?>px"></div>
            <small><?= date('d/m', strtotime($d)) ?></small>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="fs-chart" style="height:110px">
          <?php foreach ($days as $d => $v): ?>
          <div class="col" title="<?= $d ?>: jual <?= format_rp($v['s']) ?> · umpan <?= format_rp($v['b']) ?>">
            <div class="d-flex align-items-end gap-1 w-100" style="height:90px">
              <div class="bar" style="height:<?= round($v['s'] / $maxS * 90) ?>px"></div>
              <div class="bar alt" style="height:<?= round($v['b'] / $maxS * 90) ?>px"></div>
            </div>
            <small><?= date('d/m', strtotime($d)) ?></small>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="c-card h-100">
      <div class="c-card-header"><b>Distribusi Tangkapan (7 Hari)</b></div>
      <div class="c-card-body">
        <?php foreach ($dist as $b => $n): $pct = $n / $distTotal * 100; ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1" style="font-size:12.5px">
            <span><?= fs_badge($b) ?> <span class="ms-1" style="color:#cbd5e1"><?= FISHING_SPECIES[$b] ?></span></span>
            <span class="fs-num" style="color:#94a3b8"><?= $n ?> · <?= number_format($pct, 1) ?>%</span>
          </div>
          <div class="fs-bar"><i style="width:<?= $pct ?>%;background:<?= FS_BADGE_COLOR[$b] ?>"></i></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="c-card h-100">
      <div class="c-card-header"><b>Penjual Teratas (7 Hari)</b></div>
      <div class="c-card-body p-0">
        <table class="c-table no-dt w-100">
          <thead><tr><th>Pemain</th><th>Ikan</th><th class="text-end">Total</th></tr></thead>
          <tbody>
          <?php if (!$top): ?><tr><td colspan="3" class="text-center fs-muted py-4">Belum ada penjualan.</td></tr><?php endif; ?>
          <?php foreach ($top as $t): ?>
            <tr><td><?= fs_user_link((int)$t['user_id'], $t['username']) ?></td><td class="fs-num"><?= (int)$t['fish'] ?> <span class="fs-muted">(<?= (int)$t['n'] ?>x)</span></td><td class="text-end fs-num" style="color:#34d399;font-weight:700"><?= format_rp((float)$t['total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between"><b>Tangkapan Terbaru</b><a href="/console/fishing_catches.php" class="fs-muted" style="text-decoration:none">Lihat semua →</a></div>
      <div class="c-card-body p-0">
        <table class="c-table no-dt w-100">
          <thead><tr><th>Pemain</th><th>Ikan</th><th>Berat</th><th>Nilai</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$recent): ?><tr><td colspan="5" class="text-center fs-muted py-4">Belum ada tangkapan.</td></tr><?php endif; ?>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= fs_user_link((int)$r['user_id'], $r['username']) ?></td>
              <td><?= fs_badge(fishing_badge_of($r['rarity'])) ?> <span class="ms-1"><?= htmlspecialchars($r['fish_name']) ?></span></td>
              <td class="fs-num"><?= number_format((float)$r['weight_kg'], 2) ?> kg</td>
              <td class="fs-num"><?= format_rp((float)$r['reward']) ?></td>
              <td><?= fs_status($r) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
