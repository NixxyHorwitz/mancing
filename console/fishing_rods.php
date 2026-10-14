<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = (string)($_POST['action'] ?? '');
    $key    = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['skin_key'] ?? ''));
    if ($action === 'edit' && $key !== '') {
        $data = [
            'name'         => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60),
            'rarity'       => in_array($_POST['rarity'] ?? '', FISHING_BADGES, true) ? (string)$_POST['rarity'] : 'biasa',
            'req_tier'     => max(0, (int)($_POST['req_tier'] ?? 0)),
            'req_catches'  => max(0, (int)($_POST['req_catches'] ?? 0)),
            'req_jackpots' => max(0, (int)($_POST['req_jackpots'] ?? 0)),
            'reel_bonus'   => max(0, min(60, (int)($_POST['reel_bonus'] ?? 0))),
            'bite_bonus'   => max(0, min(60, (int)($_POST['bite_bonus'] ?? 0))),
            'description'  => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 200),
            'sort_order'   => (int)($_POST['sort_order'] ?? 0),
        ];
        if ($data['name'] === '') { $flash = 'Nama joran wajib diisi.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE fishing_rod_skins SET name=?, rarity=?, req_tier=?, req_catches=?, req_jackpots=?, reel_bonus=?, bite_bonus=?, description=?, sort_order=? WHERE skin_key=?")
                ->execute([...array_values($data), $key]);
            fishing_admin_log($pdo, 'rod.edit', null, ['skin_key' => $key] + $data);
            $flash = 'Joran "' . $data['name'] . '" diperbarui.';
        }
    } elseif ($action === 'toggle' && $key !== '') {
        if ($key === 'bambu') { $flash = 'Joran bawaan (bambu) harus selalu aktif.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE fishing_rod_skins SET is_active = 1 - is_active WHERE skin_key = ?")->execute([$key]);
            fishing_admin_log($pdo, 'rod.toggle', null, ['skin_key' => $key]);
            $flash = 'Status joran diperbarui.';
        }
    }
}

$rods = $pdo->query("SELECT * FROM fishing_rod_skins ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
$usage = [];
foreach ($pdo->query("SELECT rod_skin, COUNT(*) n FROM fishing_prefs GROUP BY rod_skin") as $r) $usage[$r['rod_skin']] = (int)$r['n'];
$tiers = [];
foreach ($pdo->query("SELECT name, sort_order FROM memberships WHERE is_active = 1 ORDER BY sort_order ASC") as $m) $tiers[(int)$m['sort_order']] = $m['name'] . ' (tier ' . (int)$m['sort_order'] . ')';
$tiers[0] = 'Tanpa syarat level';
ksort($tiers);

$pageTitle  = 'Fish System · Joran';
$activePage = 'fishing_rods';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Skin Joran', 'Atur syarat buka & bonus tempo tiap joran. Bonus hanya mempercepat permainan, tidak menaikkan hadiah.'); ?>
<?php fs_tabs('fishing_rods'); ?>
<?php fs_flash($flash, $flashType); ?>

<div class="alert py-2 px-3 mb-3" style="font-size:12.5px;border-radius:10px;background:rgba(34,211,238,.08);border:1px solid rgba(34,211,238,.25);color:#a5f3fc">
  Bentuk 3D tiap joran terikat pada kuncinya (<code>skin_key</code>), jadi joran baru tidak bisa ditambahkan dari sini. Syarat tier memakai urutan paket di <a href="/console/memberships.php" style="color:#67e8f9">Paket Membership</a>.
</div>

<div class="c-card"><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table no-dt w-100">
    <thead><tr><th>Urut</th><th>Joran</th><th>Kelangkaan</th><th>Syarat Buka</th><th>Bonus Tarik</th><th>Bonus Umpan</th><th>Dipakai</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rods as $r):
      $req = [];
      if ((int)$r['req_tier'] > 0) $req[] = 'Level ' . htmlspecialchars($tiers[(int)$r['req_tier']] ?? ('tier ' . (int)$r['req_tier']));
      if ((int)$r['req_catches'] > 0) $req[] = (int)$r['req_catches'] . ' tangkapan';
      if ((int)$r['req_jackpots'] > 0) $req[] = (int)$r['req_jackpots'] . ' peti harta';
    ?>
      <tr style="<?= $r['is_active'] ? '' : 'opacity:.55' ?>">
        <td class="fs-num"><?= (int)$r['sort_order'] ?></td>
        <td><b><?= htmlspecialchars($r['name']) ?></b> <code class="ms-1" style="font-size:11px"><?= htmlspecialchars($r['skin_key']) ?></code><div class="fs-muted"><?= htmlspecialchars((string)$r['description']) ?></div></td>
        <td><?= fs_badge((string)$r['rarity']) ?></td>
        <td><?= $req ? implode('<br>', $req) : '<span class="fs-muted">Bawaan</span>' ?></td>
        <td class="fs-num" style="color:#22d3ee">+<?= (int)$r['reel_bonus'] ?>%</td>
        <td class="fs-num" style="color:#34d399">+<?= (int)$r['bite_bonus'] ?>%</td>
        <td class="fs-num"><?= $usage[$r['skin_key']] ?? 0 ?> pemain</td>
        <td><?= $r['is_active'] ? '<span class="fs-pill" style="color:#34d399;border-color:#34d39955">Aktif</span>' : '<span class="fs-pill" style="color:#94a3b8;border-color:#94a3b855">Nonaktif</span>' ?></td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-info py-0 px-2" data-bs-toggle="modal" data-bs-target="#rodModal" onclick='fsRod(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="ph-bold ph-pencil-simple"></i></button>
          <?php if ($r['skin_key'] !== 'bambu'): ?>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="skin_key" value="<?= htmlspecialchars($r['skin_key']) ?>">
            <button class="btn btn-sm btn-outline-warning py-0 px-2" title="Aktif/Nonaktif"><i class="ph-bold ph-power"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<div class="modal fade" id="rodModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="skin_key" id="rd-key">
      <div class="modal-header border-0"><h5 class="modal-title text-white">Edit Joran <code id="rd-keylbl" style="font-size:13px"></code></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="row g-2">
        <div class="col-8"><label class="c-label">Nama</label><input class="c-form-control" name="name" id="rd-name" maxlength="60" required></div>
        <div class="col-4"><label class="c-label">Kelangkaan</label>
          <select class="c-form-control" name="rarity" id="rd-rarity"><?php foreach (FISHING_BADGES as $x): ?><option value="<?= $x ?>"><?= FISHING_BADGE_LABEL[$x] ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="c-label">Syarat Level Membership</label>
          <select class="c-form-control" name="req_tier" id="rd-tier"><?php foreach ($tiers as $k => $lbl): ?><option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="c-label">Min. Tangkapan</label><input type="number" min="0" class="c-form-control" name="req_catches" id="rd-catch"></div>
        <div class="col-6"><label class="c-label">Min. Peti Harta</label><input type="number" min="0" class="c-form-control" name="req_jackpots" id="rd-jack"></div>
        <div class="col-4"><label class="c-label">Bonus Tarik (%)</label><input type="number" min="0" max="60" class="c-form-control" name="reel_bonus" id="rd-reel"></div>
        <div class="col-4"><label class="c-label">Bonus Umpan (%)</label><input type="number" min="0" max="60" class="c-form-control" name="bite_bonus" id="rd-bite"></div>
        <div class="col-4"><label class="c-label">Urutan</label><input type="number" class="c-form-control" name="sort_order" id="rd-sort"></div>
        <div class="col-12"><label class="c-label">Deskripsi</label><input class="c-form-control" name="description" id="rd-desc" maxlength="200"></div>
      </div></div>
      <div class="modal-footer border-0"><button class="btn btn-primary w-100">Simpan</button></div>
    </form>
  </div></div>
</div>
<script>
function fsRod(r) {
  const v = (id, val) => { document.getElementById(id).value = val; };
  v('rd-key', r.skin_key); document.getElementById('rd-keylbl').textContent = r.skin_key;
  v('rd-name', r.name); v('rd-rarity', r.rarity); v('rd-tier', r.req_tier); v('rd-catch', r.req_catches); v('rd-jack', r.req_jackpots);
  v('rd-reel', r.reel_bonus); v('rd-bite', r.bite_bonus); v('rd-sort', r.sort_order); v('rd-desc', r.description || '');
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
