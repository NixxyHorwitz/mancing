<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('fishing');
require_once __DIR__ . '/partials/fishing_common.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $old = fishing_species($pdo);
        $rows = []; $err = '';
        foreach (FISHING_BADGES as $b) {
            $in = (array)($_POST['sp'][$b] ?? []);
            $r = [
                'name'       => mb_substr(trim((string)($in['name'] ?? '')), 0, 80),
                'chance'     => round(max(0, min(100, (float)($in['chance'] ?? 0))), 2),
                'weight_min' => round(max(0.01, (float)($in['weight_min'] ?? 0)), 2),
                'weight_max' => round(max(0.01, (float)($in['weight_max'] ?? 0)), 2),
                'price_min'  => round(max(1, (float)($in['price_min'] ?? 0))),
                'price_max'  => round(max(1, (float)($in['price_max'] ?? 0))),
            ];
            $label = FISHING_BADGE_LABEL[$b];
            if ($r['name'] === '') $err = "Nama ikan $label wajib diisi.";
            elseif ($r['weight_max'] < $r['weight_min']) $err = "Berat maksimum $label harus ≥ berat minimum.";
            elseif ($r['price_max'] < $r['price_min']) $err = "Harga maksimum $label harus ≥ harga minimum.";
            if ($err) break;
            $rows[$b] = $r;
        }
        if (!$err && array_sum(array_column($rows, 'chance')) <= 0) $err = 'Total peluang harus lebih dari 0.';
        if ($err) { $flash = $err; $flashType = 'error'; }
        else {
            $up = $pdo->prepare("INSERT INTO fishing_species (badge, name, chance, weight_min, weight_max, price_min, price_max) VALUES (?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE name = VALUES(name), chance = VALUES(chance), weight_min = VALUES(weight_min),
                                 weight_max = VALUES(weight_max), price_min = VALUES(price_min), price_max = VALUES(price_max)");
            $diff = [];
            foreach ($rows as $b => $r) {
                $up->execute([$b, ...array_values($r)]);
                foreach ($r as $k => $v) if ((string)($old[$b][$k] ?? '') !== '' && (float)$old[$b][$k] != (float)$v && $k !== 'name') $diff[$b][$k] = [(float)$old[$b][$k], $v];
            }
            fishing_admin_log($pdo, 'species.save', null, $diff ?: ['no_change' => true]);
            $flash = 'Harga & peluang ikan disimpan. Berlaku untuk tangkapan berikutnya.';
        }
    } elseif ($action === 'reprice') {
        // Hitung ulang nilai dasar ikan yang belum dijual memakai tabel harga terbaru
        $species = fishing_species($pdo);
        $rows = $pdo->query("SELECT id, rarity, weight_kg, reward FROM fishing_logs WHERE sold_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE fishing_logs SET reward = ? WHERE id = ? AND sold_at IS NULL");
        $before = $after = 0.0; $n = 0;
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $sp = $species[fishing_badge_of($r['rarity'])] ?? null;
            if (!$sp) continue;
            $new = fishing_species_price($sp, (float)$r['weight_kg']);
            $before += (float)$r['reward']; $after += $new;
            if ($new != (float)$r['reward']) { $upd->execute([$new, $r['id']]); $n++; }
        }
        $pdo->commit();
        fishing_admin_log($pdo, 'species.reprice_ember', null, ['fish' => count($rows), 'changed' => $n, 'before' => $before, 'after' => $after]);
        $flash = "Nilai $n ikan di Ember diperbarui: " . format_rp($before) . ' → ' . format_rp($after) . '.';
    }
}

$species = fishing_species($pdo);
$market  = fishing_market_today($pdo);
$chanceTotal = max(0.0001, array_sum(array_map(fn($s) => (float)$s['chance'], $species)));
$ev = 0.0;
foreach ($species as $b => $s) $ev += (float)$s['chance'] / $chanceTotal * ((float)$s['price_min'] + (float)$s['price_max']) / 2;
$baitCost = null;
foreach (fishing_bait_packs($pdo) as $p) {
    $c = (float)$p['price'] / max(1, (int)$p['qty']);
    $baitCost = $baitCost === null ? $c : min($baitCost, $c);
}
$ember = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(reward),0) v FROM fishing_logs WHERE sold_at IS NULL")->fetch(PDO::FETCH_ASSOC);
$emberNew = 0.0;
foreach ($pdo->query("SELECT rarity, weight_kg FROM fishing_logs WHERE sold_at IS NULL") as $r) {
    $sp = $species[fishing_badge_of($r['rarity'])] ?? null;
    if ($sp) $emberNew += fishing_species_price($sp, (float)$r['weight_kg']);
}

$pageTitle  = 'Fish System · Harga Ikan';
$activePage = 'fishing_species';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Harga Dasar Ikan', 'Atur peluang muncul, rentang berat & harga dasar tiap jenis ikan. Harga jual = harga dasar × pengali pasar harian.'); ?>
<?php fs_tabs('fishing_species'); ?>
<?php fs_flash($flash, $flashType); ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><?php fs_stat('Rata-rata per Lemparan', format_rp(round($ev)), 'ph-fish-simple', '#22d3ee', 'Nilai dasar rata-rata 1 tangkapan'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Harga Umpan Termurah', $baitCost !== null ? format_rp(round($baitCost)) : '-', 'ph-bug', '#fb923c', 'Per umpan dari paket aktif'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Rasio Hasil : Umpan', $baitCost ? number_format($ev / $baitCost, 2) . '×' : '-', 'ph-scales', $baitCost && $ev / $baitCost > 3 ? '#fb7185' : '#34d399', 'Hasil rata-rata dibanding biaya 1 umpan'); ?></div>
  <div class="col-6 col-lg-3"><?php fs_stat('Isi Ember Saat Ini', format_rp((float)$ember['v']), 'ph-basket', '#fbbf24', (int)$ember['n'] . ' ikan · dengan harga baru ' . format_rp($emberNew)); ?></div>
</div>

<form method="post" class="c-card mb-3" id="sp-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <b>Katalog Ikan</b>
    <div class="d-flex align-items-center gap-1 flex-wrap">
      <span class="fs-muted me-1">Kalikan semua harga:</span>
      <?php foreach ([0.5, 2, 5, 10] as $k): ?><button type="button" class="btn btn-sm btn-outline-info py-0" onclick="fsScale(<?= $k ?>)">×<?= $k ?></button><?php endforeach; ?>
    </div>
  </div>
  <div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table no-dt w-100">
      <thead><tr><th>Jenis</th><th>Nama Ikan</th><th>Peluang</th><th>Berat (kg)</th><th>Harga Dasar (Rp)</th><th>Jual Hari Ini</th></tr></thead>
      <tbody>
      <?php foreach ($species as $b => $s): $m = $market[$b]['mult'] ?? 1; ?>
        <tr data-b="<?= $b ?>" data-m="<?= $m ?>">
          <td><?= fs_badge($b) ?><div class="fs-muted mt-1"><?= FISHING_SPECIES[$b] ?></div></td>
          <td><input class="c-form-control" name="sp[<?= $b ?>][name]" value="<?= htmlspecialchars($s['name']) ?>" maxlength="80" style="min-width:170px" required></td>
          <td style="min-width:110px">
            <div class="d-flex align-items-center gap-1"><input type="number" step="0.01" min="0" max="100" class="c-form-control fs-num" name="sp[<?= $b ?>][chance]" value="<?= (float)$s['chance'] ?>" style="width:76px" oninput="fsCalc()"><span class="fs-muted">bobot</span></div>
            <div class="fs-muted mt-1" data-pct>≈ <?= number_format((float)$s['chance'] / $chanceTotal * 100, 1) ?>%</div>
          </td>
          <td style="min-width:170px"><div class="d-flex align-items-center gap-1">
            <input type="number" step="0.01" min="0.01" class="c-form-control fs-num" name="sp[<?= $b ?>][weight_min]" value="<?= (float)$s['weight_min'] ?>" style="width:76px">
            <span class="fs-muted">–</span>
            <input type="number" step="0.01" min="0.01" class="c-form-control fs-num" name="sp[<?= $b ?>][weight_max]" value="<?= (float)$s['weight_max'] ?>" style="width:76px"></div></td>
          <td style="min-width:230px"><div class="d-flex align-items-center gap-1">
            <input type="number" step="1" min="1" class="c-form-control fs-num" name="sp[<?= $b ?>][price_min]" value="<?= round((float)$s['price_min']) ?>" style="width:104px" oninput="fsCalc()" data-price>
            <span class="fs-muted">–</span>
            <input type="number" step="1" min="1" class="c-form-control fs-num" name="sp[<?= $b ?>][price_max]" value="<?= round((float)$s['price_max']) ?>" style="width:104px" oninput="fsCalc()" data-price></div>
            <div class="fs-muted mt-1">ikan paling ringan → paling berat</div></td>
          <td class="fs-num" style="color:#34d399;white-space:nowrap" data-sell><?= format_rp(round((float)$s['price_min'] * $m)) ?> – <?= format_rp(round((float)$s['price_max'] * $m)) ?><div class="fs-muted">pasar <?= round($m * 100) ?>%</div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="fs-muted">Perkiraan rata-rata per lemparan setelah diubah: <b id="sp-ev" style="color:#22d3ee"><?= format_rp(round($ev)) ?></b></div>
    <button class="btn btn-primary px-4">Simpan Harga Ikan</button>
  </div>
</form>

<div class="c-card">
  <div class="c-card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
    <div>
      <b class="text-white">Terapkan harga ke ikan yang sudah di Ember</b>
      <div class="fs-muted">Harga baru hanya berlaku untuk tangkapan berikutnya. Tombol ini menghitung ulang nilai <?= (int)$ember['n'] ?> ikan yang belum dijual
        (<?= format_rp((float)$ember['v']) ?> → <?= format_rp($emberNew) ?>). Ikan yang sudah terjual tidak berubah.</div>
    </div>
    <form method="post" onsubmit="return confirm('Hitung ulang nilai semua ikan di Ember pemain dengan harga saat ini?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="reprice">
      <button class="btn btn-outline-warning" <?= (int)$ember['n'] ? '' : 'disabled' ?>>Terapkan ke Ember</button>
    </form>
  </div>
</div>

<script>
function fsRp(v) { return 'Rp ' + Math.round(v).toLocaleString('id-ID'); }
function fsScale(k) {
  document.querySelectorAll('#sp-form [data-price]').forEach((el) => { el.value = Math.max(1, Math.round((parseFloat(el.value) || 0) * k)); });
  fsCalc();
}
function fsCalc() {
  const rows = [...document.querySelectorAll('#sp-form tbody tr')];
  const val = (tr, f) => parseFloat(tr.querySelector('[name$="[' + f + ']"]').value) || 0;
  const total = rows.reduce((a, tr) => a + val(tr, 'chance'), 0) || 1;
  let ev = 0;
  rows.forEach((tr) => {
    const c = val(tr, 'chance'), lo = val(tr, 'price_min'), hi = val(tr, 'price_max'), m = parseFloat(tr.dataset.m) || 1;
    ev += c / total * (lo + hi) / 2;
    tr.querySelector('[data-pct]').textContent = '≈ ' + (c / total * 100).toFixed(1) + '%';
    tr.querySelector('[data-sell]').innerHTML = fsRp(lo * m) + ' – ' + fsRp(hi * m) + '<div class="fs-muted">pasar ' + Math.round(m * 100) + '%</div>';
  });
  document.getElementById('sp-ev').textContent = fsRp(ev);
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
