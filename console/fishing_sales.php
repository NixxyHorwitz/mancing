<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$fu   = trim((string)($_GET['u'] ?? ''));
$from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
$to   = (string)($_GET['to'] ?? date('Y-m-d'));
$sale = (int)($_GET['sale'] ?? 0);

$where  = [];
$params = [];
if ($sale > 0) { $where[] = 's.id = ?'; $params[] = $sale; }
else {
    $where[] = 's.created_at >= ?'; $params[] = $from . ' 00:00:00';
    $where[] = 's.created_at < ?';  $params[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    $uid = fs_find_user_id($pdo, $fu);
    if ($uid !== null) { $where[] = 's.user_id = ?'; $params[] = $uid; }
}
$w = implode(' AND ', $where);

$sum = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(s.total),0) v, COALESCE(SUM(s.fish_count),0) f, COUNT(DISTINCT s.user_id) u FROM fishing_sales s WHERE $w");
$sum->execute($params);
$sum = $sum->fetch(PDO::FETCH_ASSOC);

$s = $pdo->prepare("SELECT s.*, u.username FROM fishing_sales s LEFT JOIN users u ON u.id = s.user_id WHERE $w ORDER BY s.id DESC LIMIT 300");
$s->execute($params);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

// Rincian ikan per transaksi
$items = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $d = $pdo->prepare("SELECT sale_id, fish_name, rarity, weight_kg, reward, sold_price FROM fishing_logs WHERE sale_id IN ($in) ORDER BY id");
    $d->execute($ids);
    foreach ($d as $f) $items[(int)$f['sale_id']][] = $f;
}

$pageTitle  = 'Fish System · Penjualan';
$activePage = 'fishing_sales';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Penjualan Ikan', 'Setiap transaksi jual di Pasar Ikan. Hasil penjualan masuk ke Saldo Tarik pemain.'); ?>
<?php fs_tabs('fishing_sales'); ?>

<div class="c-card mb-3"><div class="c-card-body">
  <form class="fs-filter" method="get">
    <div><label class="c-label">Pemain (ID / username)</label><input class="c-form-control" name="u" value="<?= htmlspecialchars($fu) ?>" placeholder="semua"></div>
    <div><label class="c-label">Dari</label><input type="date" class="c-form-control" name="from" value="<?= htmlspecialchars($from) ?>"></div>
    <div><label class="c-label">Sampai</label><input type="date" class="c-form-control" name="to" value="<?= htmlspecialchars($to) ?>"></div>
    <div style="flex:0 0 auto;min-width:0"><button class="btn btn-primary">Terapkan</button></div>
  </form>
  <?php if ($sale > 0): ?><div class="fs-muted mt-2">Menampilkan transaksi #<?= $sale ?> saja. <a href="/console/fishing_sales.php" style="color:#67e8f9">Tampilkan semua</a></div><?php endif; ?>
</div></div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?php fs_stat('Transaksi', number_format((int)$sum['c']), 'ph-receipt', '#22d3ee'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Total Dibayarkan', format_rp((float)$sum['v']), 'ph-hand-coins', '#34d399'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Ikan Terjual', number_format((int)$sum['f']), 'ph-fish-simple', '#fbbf24'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Penjual Unik', number_format((int)$sum['u']), 'ph-users-three', '#a78bfa'); ?></div>
</div>

<div class="c-card"><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table w-100">
    <thead><tr><th>#</th><th>Waktu</th><th>Pemain</th><th>Ikan</th><th>Rincian</th><th class="text-end">Total</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $list = $items[(int)$r['id']] ?? []; ?>
      <tr>
        <td class="fs-muted"><?= (int)$r['id'] ?></td>
        <td data-order="<?= strtotime((string)$r['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$r['created_at'])) ?></td>
        <td><?= fs_user_link((int)$r['user_id'], $r['username']) ?></td>
        <td class="fs-num"><?= (int)$r['fish_count'] ?></td>
        <td style="max-width:460px">
          <?php foreach (array_slice($list, 0, 8) as $f): ?>
            <span class="d-inline-block me-1 mb-1" title="<?= htmlspecialchars($f['fish_name']) ?> · <?= number_format((float)$f['weight_kg'], 2) ?> kg · dasar <?= format_rp((float)$f['reward']) ?>">
              <?= fs_badge(fishing_badge_of($f['rarity'])) ?> <span class="fs-num" style="font-size:11.5px;color:#cbd5e1"><?= format_rp((float)$f['sold_price']) ?></span>
            </span>
          <?php endforeach; ?>
          <?php if (count($list) > 8): ?><span class="fs-muted">+<?= count($list) - 8 ?> lainnya</span><?php endif; ?>
        </td>
        <td class="text-end fs-num" style="color:#34d399;font-weight:700"><?= format_rp((float)$r['total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
