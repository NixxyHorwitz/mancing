<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $date = (string)($_POST['date'] ?? '');
    if (!in_array($date, [$today, $tomorrow], true)) { $flash = 'Hanya harga hari ini & besok yang bisa diubah.'; $flashType = 'error'; }
    elseif (($_POST['action'] ?? '') === 'reset') {
        $upd = $pdo->prepare("UPDATE fishing_market_prices SET mult = ?, is_manual = 0 WHERE price_date = ? AND badge = ?");
        foreach (FISHING_BADGES as $b) $upd->execute([fishing_market_auto($date, $b), $date, $b]);
        fishing_admin_log($pdo, 'market.reset', null, ['date' => $date]);
        $flash = 'Harga ' . $date . ' dikembalikan ke otomatis.';
    } else {
        fishing_market_for($pdo, $date); // pastikan baris ada
        $upd = $pdo->prepare("UPDATE fishing_market_prices SET mult = ?, is_manual = 1 WHERE price_date = ? AND badge = ?");
        $changes = [];
        foreach (FISHING_BADGES as $b) {
            $pct = (float)($_POST['pct'][$b] ?? 100);
            $mult = round(max(50, min(200, $pct)) / 100, 3);
            $upd->execute([$mult, $date, $b]);
            $changes[$b] = $mult;
        }
        fishing_admin_log($pdo, 'market.set', null, ['date' => $date, 'mult' => $changes]);
        $flash = 'Harga pasar ' . $date . ' disimpan (manual).';
    }
}

$mToday = fishing_market_for($pdo, $today);
$mTom   = fishing_market_for($pdo, $tomorrow);
$mYday  = fishing_market_for($pdo, date('Y-m-d', strtotime('-1 day')));

// Nilai Ember saat ini bila dijual dengan harga hari ini
$ember = array_fill_keys(FISHING_BADGES, ['n' => 0, 'base' => 0.0]);
foreach ($pdo->query("SELECT " . FS_BADGE_SQL . " b, COUNT(*) n, SUM(reward) base FROM fishing_logs WHERE sold_at IS NULL GROUP BY b") as $r)
    $ember[$r['b']] = ['n' => (int)$r['n'], 'base' => (float)$r['base']];

// Riwayat 14 hari
$hist = [];
$s = $pdo->prepare("SELECT price_date, badge, mult, is_manual FROM fishing_market_prices WHERE price_date >= ? ORDER BY price_date DESC");
$s->execute([date('Y-m-d', strtotime('-13 days'))]);
foreach ($s as $r) $hist[$r['price_date']][$r['badge']] = $r;

$pageTitle  = 'Fish System · Harga Pasar';
$activePage = 'fishing_market';
require __DIR__ . '/partials/header.php';
fs_styles();

function fs_market_form(string $date, string $label, array $m, array $ref): void { ?>
  <div class="c-card h-100">
    <div class="c-card-header d-flex justify-content-between align-items-center">
      <b><?= $label ?> <span class="fs-muted ms-1"><?= date('d M Y', strtotime($date)) ?></span></b>
      <form method="post" onsubmit="return confirm('Kembalikan semua harga tanggal ini ke otomatis?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="date" value="<?= $date ?>">
        <button class="btn btn-sm btn-outline-secondary py-0">Reset Otomatis</button>
      </form>
    </div>
    <div class="c-card-body">
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="date" value="<?= $date ?>">
        <?php foreach (FISHING_BADGES as $b): $pct = round($m[$b]['mult'] * 100, 1); $prev = $ref[$b]['mult'] * 100; ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <div style="width:72px;flex:0 0 auto" title="<?= FISHING_SPECIES[$b] ?>"><?= fs_badge($b) ?></div>
          <input type="range" min="50" max="200" step="0.5" value="<?= $pct ?>" class="form-range flex-grow-1" oninput="this.nextElementSibling.value=this.value">
          <input type="number" min="50" max="200" step="0.1" name="pct[<?= $b ?>]" value="<?= $pct ?>" class="c-form-control fs-num" style="width:84px" oninput="this.previousElementSibling.value=this.value">
          <span class="fs-muted" style="width:20px">%</span>
          <span class="fs-num <?= $pct > $prev + 0.5 ? 'up' : ($pct < $prev - 0.5 ? 'down' : 'flat') ?>" style="width:58px;font-size:12px"><?= ($pct - $prev >= 0 ? '+' : '') . number_format($pct - $prev, 1) ?></span>
          <span class="fs-pill" style="color:<?= $m[$b]['manual'] ? '#fbbf24' : '#64748b' ?>;border-color:currentColor"><?= $m[$b]['manual'] ? 'manual' : 'auto' ?></span>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary w-100 mt-2">Simpan Harga <?= $label ?></button>
      </form>
    </div>
  </div>
<?php }
?>
<?php fs_head('Harga Pasar Ikan', 'Pengali harga jual per jenis ikan. Otomatis berubah tiap hari (85%–115%), bisa ditimpa manual (50%–200%).'); ?>
<?php fs_tabs('fishing_market'); ?>
<?php fs_flash($flash, $flashType); ?>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><?php fs_market_form($today, 'Hari Ini', $mToday, $mYday); ?></div>
  <div class="col-lg-6"><?php fs_market_form($tomorrow, 'Besok', $mTom, $mToday); ?></div>
</div>

<div class="c-card mb-3">
  <div class="c-card-header"><b>Dampak ke Ember Pemain</b> <span class="fs-muted ms-2">nilai seluruh ikan yang belum dijual bila dijual hari ini</span></div>
  <div class="c-card-body p-0"><table class="c-table no-dt w-100">
    <thead><tr><th>Jenis</th><th>Jumlah</th><th>Nilai Dasar</th><th>Pengali</th><th class="text-end">Nilai Jual Hari Ini</th></tr></thead>
    <tbody>
    <?php $tb = $tv = 0; foreach (FISHING_BADGES as $b): $v = $ember[$b]['base'] * $mToday[$b]['mult']; $tb += $ember[$b]['base']; $tv += $v; ?>
      <tr><td><?= fs_badge($b) ?> <?= FISHING_SPECIES[$b] ?></td><td class="fs-num"><?= $ember[$b]['n'] ?></td><td class="fs-num"><?= format_rp($ember[$b]['base']) ?></td>
          <td class="fs-num"><?= round($mToday[$b]['mult'] * 100, 1) ?>%</td><td class="text-end fs-num" style="color:#34d399"><?= format_rp(round($v)) ?></td></tr>
    <?php endforeach; ?>
      <tr style="font-weight:800"><td>Total</td><td></td><td class="fs-num"><?= format_rp($tb) ?></td><td></td><td class="text-end fs-num" style="color:#34d399"><?= format_rp(round($tv)) ?></td></tr>
    </tbody>
  </table></div>
</div>

<div class="c-card">
  <div class="c-card-header"><b>Riwayat 14 Hari</b></div>
  <div class="c-card-body p-0"><div class="table-responsive"><table class="c-table no-dt w-100">
    <thead><tr><th>Tanggal</th><?php foreach (FISHING_BADGES as $b): ?><th><?= fs_badge($b) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($hist as $d => $row): ?>
      <tr><td style="white-space:nowrap"><?= date('D, d/m/Y', strtotime($d)) ?><?= $d === $today ? ' <span class="fs-pill" style="color:#22d3ee;border-color:#22d3ee55">hari ini</span>' : '' ?></td>
      <?php foreach (FISHING_BADGES as $b): $r = $row[$b] ?? null; ?>
        <td class="fs-num"><?= $r ? round((float)$r['mult'] * 100, 1) . '%' . ($r['is_manual'] ? ' <span style="color:#fbbf24" title="manual">●</span>' : '') : '-' ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
