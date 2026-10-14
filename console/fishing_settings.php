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
        'reward_min'       => max(10, (float)($_POST['reward_min'] ?? 100)),
        'reward_max'       => max(50, (float)($_POST['reward_max'] ?? 5000)),
    ];
    if ($new['reward_max'] < $new['reward_min']) { $flash = 'Hadiah maksimum harus ≥ hadiah minimum.'; $flashType = 'error'; }
    else {
        $cfg['fishing_engine'] = array_merge($old, $new);
        home_cms_config_set($pdo, $cfg);
        $diff = [];
        foreach ($new as $k => $v) if (($old[$k] ?? null) != $v) $diff[$k] = ['from' => $old[$k] ?? null, 'to' => $v];
        fishing_admin_log($pdo, 'settings.save', null, $diff ?: ['no_change' => true]);
        $flash = 'Pengaturan Fish System disimpan.';
    }
}

$fe = home_cms_config($pdo)['fishing_engine'] ?? [];
$min = max(10, (float)($fe['reward_min'] ?? 100));
$max = max(50, (float)($fe['reward_max'] ?? 5000));
// Perkiraan rentang nilai dasar per jenis (rumus sama dengan user/minigame.php)
$ranges = [
    'biasa'   => [45, $min + (0.5 / 1.4) * $min * 1.5, $min + $min * 1.5],
    'bagus'   => [30, $min * 2.5 + (1.5 / 2.9) * $min * 3, $min * 2.5 + $min * 3],
    'langka'  => [15, $min * 6 + (3.0 / 4.8) * $min * 5, $min * 6 + $min * 5],
    'super'   => [7,  $max * 0.75 + (5.0 / 8.5) * $max * 0.5, $max * 0.75 + $max * 0.5],
    'jackpot' => [3,  $max * 1.5 + 1000, $max * 1.5 + 3000],
];
$ev = 0;
foreach ($ranges as [$p, $lo, $hi]) $ev += $p / 100 * ($lo + $hi) / 2;

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
        <div class="col-6"><label class="c-label">Hadiah dasar minimum (Rp)</label><input type="number" min="10" class="c-form-control" name="reward_min" value="<?= (float)$min ?>"></div>
        <div class="col-6"><label class="c-label">Hadiah dasar maksimum (Rp)</label><input type="number" min="50" class="c-form-control" name="reward_max" value="<?= (float)$max ?>"></div>
        <div class="col-12"><button class="btn btn-primary w-100">Simpan Pengaturan</button></div>
        <div class="col-12 fs-muted">Harga paket umpan diatur di tab <a href="/console/fishing_baits.php" style="color:#67e8f9">Umpan</a>; pengali harga jual di tab <a href="/console/fishing_market.php" style="color:#67e8f9">Harga Pasar</a>.</div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="c-card h-100"><div class="c-card-header"><b>Peluang & Rentang Nilai Dasar</b> <span class="fs-muted ms-2">berdasarkan pengaturan saat ini, sebelum pengali pasar</span></div>
      <div class="c-card-body p-0"><table class="c-table no-dt w-100">
        <thead><tr><th>Jenis</th><th>Peluang</th><th>Nilai Dasar</th></tr></thead>
        <tbody>
        <?php foreach ($ranges as $b => [$p, $lo, $hi]): ?>
          <tr><td><?= fs_badge($b) ?> <?= FISHING_SPECIES[$b] ?></td><td class="fs-num"><?= $p ?>%</td><td class="fs-num"><?= format_rp(round($lo)) ?> – <?= format_rp(round($hi)) ?></td></tr>
        <?php endforeach; ?>
          <tr style="font-weight:800"><td>Rata-rata per lemparan</td><td></td><td class="fs-num" style="color:#fbbf24">± <?= format_rp(round($ev)) ?></td></tr>
        </tbody>
      </table>
      <div class="px-3 py-2 fs-muted">Bandingkan dengan harga per umpan di Toko Umpan untuk menjaga ekonomi tetap seimbang.</div>
    </div></div>
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
