<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $cfg = home_cms_config($pdo);
    $old = $cfg['fishing_engine'] ?? [];
    $new = [
        'enabled'          => isset($_POST['enabled']),
        'daily_free_baits' => max(0, min(100, (int)($_POST['daily_free_baits'] ?? 3))),
        'bait_per_video'   => max(0, min(10, (int)($_POST['bait_per_video'] ?? 1))),
    ];
    {
        $cfg['fishing_engine'] = array_merge($old, $new);
        home_cms_config_set($pdo, $cfg);
        $diff = [];
        foreach ($new as $k => $v) if (($old[$k] ?? null) != $v) $diff[$k] = ['from' => $old[$k] ?? null, 'to' => $v];
        fishing_admin_log($pdo, 'settings.save', null, $diff ?: ['no_change' => true]);
        $flash = 'Pengaturan Fish System disimpan.';
    }
}

$fe = home_cms_config($pdo)['fishing_engine'] ?? [];
$species = fishing_species($pdo);
$chanceTotal = max(0.0001, array_sum(array_map(fn($x) => (float)$x['chance'], $species)));

// Audit log
$fa = trim((string)($_GET['action'] ?? ''));
$actions = $pdo->query("SELECT DISTINCT action FROM fishing_admin_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$s = $pdo->prepare("SELECT a.*, u.username FROM fishing_admin_logs a LEFT JOIN users u ON u.id = a.target_user_id"
                 . ($fa !== '' ? " WHERE a.action = ?" : '') . " ORDER BY a.id DESC LIMIT 500");
$s->execute($fa !== '' ? [$fa] : []);
$audit = $s->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Fish System · Pengaturan & Audit';
$activePage = 'fishing_settings';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Pengaturan & Audit', 'Konfigurasi mesin mancing dan catatan semua perubahan yang dilakukan admin.'); ?>
<?php fs_tabs('fishing_settings'); ?>
<?php fs_flash($flash, $flashType); ?>

<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="c-card h-100"><div class="c-card-header"><b>Mesin Mancing</b></div><div class="c-card-body">
      <form method="post" class="row g-3">
        <?= csrf_field() ?>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="fe-en" name="enabled" <?= !empty($fe['enabled']) ? 'checked' : '' ?>>
            <label class="form-check-label text-white" for="fe-en" style="font-weight:700">Fitur mancing aktif untuk pengguna</label>
          </div>
        </div>
        <div class="col-6"><label class="c-label">Umpan gratis harian</label><input type="number" min="0" max="100" class="c-form-control" name="daily_free_baits" value="<?= (int)($fe['daily_free_baits'] ?? 3) ?>">
          <div class="fs-muted mt-1">Diisi ulang sekali sehari bila umpan pemain di bawah angka ini.</div></div>
        <div class="col-6"><label class="c-label">Umpan per video ditonton</label><input type="number" min="0" max="10" class="c-form-control" name="bait_per_video" value="<?= (int)($fe['bait_per_video'] ?? 1) ?>"></div>
        <div class="col-12"><button class="btn btn-primary w-100">Simpan Pengaturan</button></div>
        <div class="col-12 fs-muted">Harga dasar ikan diatur di tab <a href="/console/fishing_species.php" style="color:#67e8f9">Harga Ikan</a>, paket umpan di tab <a href="/console/fishing_baits.php" style="color:#67e8f9">Umpan</a>, pengali harga jual di tab <a href="/console/fishing_market.php" style="color:#67e8f9">Harga Pasar</a>.</div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="c-card h-100"><div class="c-card-header d-flex justify-content-between"><b>Peluang & Harga Dasar</b><a href="/console/fishing_species.php" class="fs-muted" style="text-decoration:none">Ubah di Harga Ikan →</a></div>
      <div class="c-card-body p-0"><table class="c-table no-dt w-100">
        <thead><tr><th>Ikan</th><th>Peluang</th><th>Berat</th><th>Harga Dasar</th></tr></thead>
        <tbody>
        <?php foreach ($species as $b => $x): ?>
          <tr><td><?= fs_badge($b) ?> <?= htmlspecialchars($x['name']) ?></td><td class="fs-num"><?= number_format((float)$x['chance'] / $chanceTotal * 100, 1) ?>%</td>
            <td class="fs-num"><?= (float)$x['weight_min'] ?>–<?= (float)$x['weight_max'] ?> kg</td><td class="fs-num"><?= format_rp((float)$x['price_min']) ?> – <?= format_rp((float)$x['price_max']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<div class="c-card">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <b>Audit Log Admin</b>
    <form method="get" class="d-flex gap-2">
      <select class="c-form-control" name="action" style="min-width:180px" onchange="this.form.submit()">
        <option value="">Semua aksi</option>
        <?php foreach ($actions as $a): ?><option value="<?= htmlspecialchars($a) ?>" <?= $fa === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option><?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table w-100">
      <thead><tr><th>#</th><th>Waktu</th><th>Admin</th><th>Aksi</th><th>Pemain</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($audit as $a): ?>
        <tr>
          <td class="fs-muted"><?= (int)$a['id'] ?></td>
          <td data-order="<?= strtotime((string)$a['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$a['created_at'])) ?></td>
          <td><?= htmlspecialchars($a['admin']) ?></td>
          <td><code style="font-size:11.5px"><?= htmlspecialchars($a['action']) ?></code></td>
          <td><?= $a['target_user_id'] ? fs_user_link((int)$a['target_user_id'], $a['username']) : '<span class="fs-muted">-</span>' ?></td>
          <td style="max-width:420px;font-size:11.5px;color:#94a3b8;word-break:break-all"><?= htmlspecialchars(mb_substr((string)$a['detail'], 0, 300)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
