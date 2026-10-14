<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';

// Hapus ikan yang masih di Ember (moderasi tangkapan tidak wajar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $s = $pdo->prepare("SELECT * FROM fishing_logs WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $flash = 'Tangkapan tidak ditemukan.'; $flashType = 'error'; }
        elseif ($row['sold_at']) { $flash = 'Ikan yang sudah terjual tidak bisa dihapus.'; $flashType = 'error'; }
        else {
            $pdo->prepare("DELETE FROM fishing_logs WHERE id = ? AND sold_at IS NULL")->execute([$id]);
            fishing_admin_log($pdo, 'catch.delete', (int)$row['user_id'], ['log_id' => $id, 'fish' => $row['fish_name'], 'reward' => (float)$row['reward']]);
            $flash = 'Tangkapan #' . $id . ' dihapus dari Ember pemain.';
        }
    }
}

// Filter
$fu     = trim((string)($_GET['u'] ?? ''));
$fb     = (string)($_GET['badge'] ?? '');
$fs     = (string)($_GET['status'] ?? '');
$from   = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
$to     = (string)($_GET['to'] ?? date('Y-m-d'));
$where  = ['l.created_at >= ?', 'l.created_at < ?'];
$params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
$uid = fs_find_user_id($pdo, $fu);
if ($uid !== null) { $where[] = 'l.user_id = ?'; $params[] = $uid; }
if (isset(FISHING_RARITY[$fb])) { $where[] = 'UPPER(l.rarity) = ?'; $params[] = strtoupper(FISHING_RARITY[$fb]); }
if ($fs === 'unsold') $where[] = 'l.sold_at IS NULL';
if ($fs === 'sold')   $where[] = 'l.sold_at IS NOT NULL';
$w = implode(' AND ', $where);

$sum = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(l.reward),0) v, COALESCE(SUM(l.sold_price),0) sp, COALESCE(SUM(l.sold_at IS NULL),0) un FROM fishing_logs l WHERE $w");
$sum->execute($params);
$sum = $sum->fetch(PDO::FETCH_ASSOC);
$s = $pdo->prepare("SELECT l.*, u.username FROM fishing_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC LIMIT 500");
$s->execute($params);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Fish System · Log Tangkapan';
$activePage = 'fishing_catches';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Log Tangkapan', 'Semua ikan yang ditangkap pemain. Nilai dasar dikali harga pasar saat dijual.'); ?>
<?php fs_tabs('fishing_catches'); ?>
<?php fs_flash($flash, $flashType); ?>

<div class="c-card mb-3"><div class="c-card-body">
  <form class="fs-filter" method="get">
    <div><label class="c-label">Pemain (ID / username)</label><input class="c-form-control" name="u" value="<?= htmlspecialchars($fu) ?>" placeholder="semua"></div>
    <div><label class="c-label">Jenis</label>
      <select class="c-form-control" name="badge"><option value="">Semua</option>
        <?php foreach (FISHING_BADGES as $b): ?><option value="<?= $b ?>" <?= $fb === $b ? 'selected' : '' ?>><?= FISHING_BADGE_LABEL[$b] ?> · <?= FISHING_SPECIES[$b] ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="c-label">Status</label>
      <select class="c-form-control" name="status">
        <option value="">Semua</option><option value="unsold" <?= $fs === 'unsold' ? 'selected' : '' ?>>Di Ember</option><option value="sold" <?= $fs === 'sold' ? 'selected' : '' ?>>Terjual</option>
      </select></div>
    <div><label class="c-label">Dari</label><input type="date" class="c-form-control" name="from" value="<?= htmlspecialchars($from) ?>"></div>
    <div><label class="c-label">Sampai</label><input type="date" class="c-form-control" name="to" value="<?= htmlspecialchars($to) ?>"></div>
    <div style="flex:0 0 auto;min-width:0"><button class="btn btn-primary">Terapkan</button></div>
  </form>
</div></div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?php fs_stat('Jumlah Tangkapan', number_format((int)$sum['c']), 'ph-fish-simple', '#22d3ee'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Nilai Dasar', format_rp((float)$sum['v']), 'ph-coins', '#fbbf24'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Masih di Ember', number_format((int)$sum['un']), 'ph-basket', '#fb923c'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Hasil Terjual', format_rp((float)$sum['sp']), 'ph-hand-coins', '#34d399'); ?></div>
</div>

<div class="c-card"><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table w-100">
    <thead><tr><th>#</th><th>Waktu</th><th>Pemain</th><th>Ikan</th><th>Berat</th><th>Nilai Dasar</th><th>Status</th><th>Harga Jual</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="fs-muted"><?= (int)$r['id'] ?></td>
        <td data-order="<?= strtotime((string)$r['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$r['created_at'])) ?></td>
        <td><?= fs_user_link((int)$r['user_id'], $r['username']) ?></td>
        <td><?= fs_badge(fishing_badge_of($r['rarity'])) ?> <span class="ms-1"><?= htmlspecialchars($r['fish_name']) ?></span></td>
        <td class="fs-num"><?= number_format((float)$r['weight_kg'], 2) ?> kg</td>
        <td class="fs-num"><?= format_rp((float)$r['reward']) ?></td>
        <td><?= $r['sold_at']
              ? '<span class="fs-pill" style="color:#34d399;border-color:#34d39955">Terjual</span><div class="fs-muted">' . date('d/m H:i', strtotime((string)$r['sold_at'])) . ($r['sale_id'] ? ' · <a href="/console/fishing_sales.php?sale=' . (int)$r['sale_id'] . '" style="color:#67e8f9">#' . (int)$r['sale_id'] . '</a>' : '') . '</div>'
              : '<span class="fs-pill" style="color:#fbbf24;border-color:#fbbf2455">Di Ember</span>' ?></td>
        <td class="fs-num" style="color:#34d399"><?= $r['sold_price'] !== null ? format_rp((float)$r['sold_price']) : '-' ?></td>
        <td>
          <?php if (!$r['sold_at']): ?>
          <form method="post" onsubmit="return confirm('Hapus tangkapan ini dari Ember pemain?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus dari Ember"><i class="ph-bold ph-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>
<?php if (count($rows) >= 500): ?><div class="fs-muted mt-2">Menampilkan 500 data terbaru. Persempit filter untuk melihat lebih spesifik.</div><?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
