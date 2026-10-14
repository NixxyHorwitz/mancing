<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);
    $data = [
        'name'        => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60),
        'qty'         => max(1, min(9999, (int)($_POST['qty'] ?? 1))),
        'price'       => max(0, (float)($_POST['price'] ?? 0)),
        'tag'         => mb_substr(trim((string)($_POST['tag'] ?? '')), 0, 20),
        'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 160),
        'color'       => preg_match('/^#[0-9a-f]{6}$/i', (string)($_POST['color'] ?? '')) ? strtolower((string)$_POST['color']) : '#f472b6',
        'sort_order'  => (int)($_POST['sort_order'] ?? 0),
    ];

    if ($action === 'add' || $action === 'edit') {
        if ($data['name'] === '' || $data['price'] <= 0) { $flash = 'Nama dan harga wajib diisi.'; $flashType = 'error'; }
        elseif ($action === 'add') {
            $pdo->prepare("INSERT INTO fishing_bait_packs (name, qty, price, tag, description, color, sort_order, is_active) VALUES (?,?,?,?,?,?,?,1)")
                ->execute(array_values($data));
            fishing_admin_log($pdo, 'bait_pack.add', null, $data + ['id' => (int)$pdo->lastInsertId()]);
            $flash = 'Paket "' . $data['name'] . '" ditambahkan.';
        } else {
            $pdo->prepare("UPDATE fishing_bait_packs SET name=?, qty=?, price=?, tag=?, description=?, color=?, sort_order=? WHERE id=?")
                ->execute([...array_values($data), $id]);
            fishing_admin_log($pdo, 'bait_pack.edit', null, $data + ['id' => $id]);
            $flash = 'Paket "' . $data['name'] . '" diperbarui.';
        }
    } elseif ($action === 'toggle') {
        $pdo->prepare("UPDATE fishing_bait_packs SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        fishing_admin_log($pdo, 'bait_pack.toggle', null, ['id' => $id]);
        $flash = 'Status paket diperbarui.';
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM fishing_bait_packs WHERE id = ?")->execute([$id]);
        fishing_admin_log($pdo, 'bait_pack.delete', null, ['id' => $id]);
        $flash = 'Paket dihapus. Riwayat pembelian tetap tersimpan.';
    }
}

$packs = fishing_bait_packs($pdo, false);
$stats = [];
foreach ($pdo->query("SELECT pack_id, COUNT(*) n, SUM(price) v, SUM(qty) q FROM fishing_bait_purchases GROUP BY pack_id") as $r) $stats[(int)$r['pack_id']] = $r;

$from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
$to   = (string)($_GET['to'] ?? date('Y-m-d'));
$fu   = trim((string)($_GET['u'] ?? ''));
$where = ['p.created_at >= ?', 'p.created_at < ?'];
$params = [$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
$uid = fs_find_user_id($pdo, $fu);
if ($uid !== null) { $where[] = 'p.user_id = ?'; $params[] = $uid; }
$w = implode(' AND ', $where);
$sum = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(p.price),0) v, COALESCE(SUM(p.qty),0) q, COUNT(DISTINCT p.user_id) u FROM fishing_bait_purchases p WHERE $w");
$sum->execute($params);
$sum = $sum->fetch(PDO::FETCH_ASSOC);
$s = $pdo->prepare("SELECT p.*, u.username FROM fishing_bait_purchases p LEFT JOIN users u ON u.id = p.user_id WHERE $w ORDER BY p.id DESC LIMIT 500");
$s->execute($params);
$buys = $s->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Fish System · Umpan';
$activePage = 'fishing_baits';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <?php fs_head('Toko Umpan', 'Paket umpan yang dijual di Toko Umpan minigame (dibayar dengan Saldo Beli).'); ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#packModal" onclick="fsPack(null)">+ Tambah Paket</button>
</div>
<?php fs_tabs('fishing_baits'); ?>
<?php fs_flash($flash, $flashType); ?>

<div class="c-card mb-3"><div class="c-card-header"><b>Paket Umpan</b></div><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table no-dt w-100">
    <thead><tr><th>Urut</th><th>Paket</th><th>Isi</th><th>Harga</th><th>Per Umpan</th><th>Terjual</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$packs): ?><tr><td colspan="8" class="text-center fs-muted py-4">Belum ada paket.</td></tr><?php endif; ?>
    <?php foreach ($packs as $p): $st = $stats[(int)$p['id']] ?? null; ?>
      <tr style="<?= $p['is_active'] ? '' : 'opacity:.55' ?>">
        <td class="fs-num"><?= (int)$p['sort_order'] ?></td>
        <td>
          <span class="d-inline-block me-2" style="width:12px;height:12px;border-radius:4px;background:<?= htmlspecialchars($p['color']) ?>;vertical-align:-1px"></span>
          <b><?= htmlspecialchars($p['name']) ?></b>
          <?php if ($p['tag']): ?><span class="fs-pill ms-1" style="color:#34d399;border-color:#34d39955"><?= htmlspecialchars($p['tag']) ?></span><?php endif; ?>
          <div class="fs-muted"><?= htmlspecialchars((string)$p['description']) ?></div>
        </td>
        <td class="fs-num"><?= (int)$p['qty'] ?> umpan</td>
        <td class="fs-num" style="font-weight:700"><?= format_rp((float)$p['price']) ?></td>
        <td class="fs-num fs-muted"><?= format_rp(round((float)$p['price'] / max(1, (int)$p['qty']))) ?></td>
        <td class="fs-num"><?= $st ? (int)$st['n'] . 'x · <span style="color:#fb923c">' . format_rp((float)$st['v']) . '</span>' : '-' ?></td>
        <td><?= $p['is_active'] ? '<span class="fs-pill" style="color:#34d399;border-color:#34d39955">Aktif</span>' : '<span class="fs-pill" style="color:#94a3b8;border-color:#94a3b855">Nonaktif</span>' ?></td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-info py-0 px-2" data-bs-toggle="modal" data-bs-target="#packModal" onclick='fsPack(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'><i class="ph-bold ph-pencil-simple"></i></button>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-outline-warning py-0 px-2" title="Aktif/Nonaktif"><i class="ph-bold ph-power"></i></button></form>
          <form method="post" class="d-inline" onsubmit="return confirm('Hapus paket ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="ph-bold ph-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<div class="c-card mb-3"><div class="c-card-body">
  <form class="fs-filter" method="get">
    <div><label class="c-label">Pemain (ID / username)</label><input class="c-form-control" name="u" value="<?= htmlspecialchars($fu) ?>" placeholder="semua"></div>
    <div><label class="c-label">Dari</label><input type="date" class="c-form-control" name="from" value="<?= htmlspecialchars($from) ?>"></div>
    <div><label class="c-label">Sampai</label><input type="date" class="c-form-control" name="to" value="<?= htmlspecialchars($to) ?>"></div>
    <div style="flex:0 0 auto;min-width:0"><button class="btn btn-primary">Terapkan</button></div>
  </form>
</div></div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?php fs_stat('Pembelian', number_format((int)$sum['c']), 'ph-shopping-bag', '#22d3ee'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Pendapatan', format_rp((float)$sum['v']), 'ph-coins', '#fb923c'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Umpan Terjual', number_format((int)$sum['q']), 'ph-bug', '#f472b6'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Pembeli Unik', number_format((int)$sum['u']), 'ph-users-three', '#a78bfa'); ?></div>
</div>

<div class="c-card"><div class="c-card-header"><b>Riwayat Pembelian Umpan</b></div><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table w-100">
    <thead><tr><th>#</th><th>Waktu</th><th>Pemain</th><th>Paket</th><th>Isi</th><th class="text-end">Harga</th></tr></thead>
    <tbody>
    <?php foreach ($buys as $b): ?>
      <tr>
        <td class="fs-muted"><?= (int)$b['id'] ?></td>
        <td data-order="<?= strtotime((string)$b['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$b['created_at'])) ?></td>
        <td><?= fs_user_link((int)$b['user_id'], $b['username']) ?></td>
        <td><?= htmlspecialchars($b['pack_name']) ?></td>
        <td class="fs-num"><?= (int)$b['qty'] ?></td>
        <td class="text-end fs-num" style="color:#fb923c;font-weight:700"><?= format_rp((float)$b['price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<div class="modal fade" id="packModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" id="pk-action" value="add"><input type="hidden" name="id" id="pk-id">
      <div class="modal-header border-0"><h5 class="modal-title text-white" id="pk-title">Tambah Paket</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-8"><label class="c-label">Nama Paket</label><input class="c-form-control" name="name" id="pk-name" maxlength="60" required></div>
          <div class="col-4"><label class="c-label">Warna Toples</label><input type="color" class="c-form-control" name="color" id="pk-color" value="#f472b6" style="height:38px;padding:3px"></div>
          <div class="col-4"><label class="c-label">Isi (umpan)</label><input type="number" min="1" class="c-form-control" name="qty" id="pk-qty" required></div>
          <div class="col-4"><label class="c-label">Harga (Rp)</label><input type="number" min="1" class="c-form-control" name="price" id="pk-price" required></div>
          <div class="col-4"><label class="c-label">Urutan</label><input type="number" class="c-form-control" name="sort_order" id="pk-sort" value="0"></div>
          <div class="col-12"><label class="c-label">Label Promo (opsional)</label><input class="c-form-control" name="tag" id="pk-tag" maxlength="20" placeholder="HEMAT 20%"></div>
          <div class="col-12"><label class="c-label">Deskripsi</label><input class="c-form-control" name="description" id="pk-desc" maxlength="160"></div>
        </div>
      </div>
      <div class="modal-footer border-0"><button class="btn btn-primary w-100">Simpan</button></div>
    </form>
  </div></div>
</div>
<script>
function fsPack(p) {
  const v = (id, val) => { document.getElementById(id).value = val; };
  v('pk-action', p ? 'edit' : 'add'); v('pk-id', p ? p.id : '');
  document.getElementById('pk-title').textContent = p ? 'Edit Paket' : 'Tambah Paket';
  v('pk-name', p ? p.name : ''); v('pk-color', p ? p.color : '#f472b6'); v('pk-qty', p ? p.qty : 5);
  v('pk-price', p ? Math.round(p.price) : 5000); v('pk-sort', p ? p.sort_order : 0); v('pk-tag', p ? (p.tag || '') : ''); v('pk-desc', p ? (p.description || '') : '');
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
