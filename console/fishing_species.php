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
        foreach (FISHING_ALL_BADGES as $b) {
            $in = (array)($_POST['sp'][$b] ?? []);
            $r = [
                'name'       => mb_substr(trim((string)($in['name'] ?? '')), 0, 80),
                'chance'     => round(max(0, min(100, (float)($in['chance'] ?? 0))), 2),
                'weight_min' => round(max(0.01, (float)($in['weight_min'] ?? 0)), 2),
                'weight_max' => round(max(0.01, (float)($in['weight_max'] ?? 0)), 2),
                'price_min'  => round(max(1, (float)($in['price_min'] ?? 0))),
                'price_max'  => round(max(1, (float)($in['price_max'] ?? 0))),
                'hardness'   => round(max(0.3, min(3.0, (float)($in['hardness'] ?? 1))), 2),
            ];
            if ($b === FISHING_ZONK) { $r['price_min'] = 0; $r['price_max'] = 0; } // sampah selalu tidak bernilai
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
            $up = $pdo->prepare("INSERT INTO fishing_species (badge, name, chance, weight_min, weight_max, price_min, price_max, hardness) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE name = VALUES(name), chance = VALUES(chance), weight_min = VALUES(weight_min),
                                 weight_max = VALUES(weight_max), price_min = VALUES(price_min), price_max = VALUES(price_max), hardness = VALUES(hardness)");
            $diff = [];
            foreach ($rows as $b => $r) {
                $up->execute([$b, ...array_values($r)]);
                foreach ($r as $k => $v) if ((string)($old[$b][$k] ?? '') !== '' && (float)$old[$b][$k] != (float)$v && $k !== 'name') $diff[$b][$k] = [(float)$old[$b][$k], $v];
            }
            fishing_admin_log($pdo, 'species.save', null, $diff ?: ['no_change' => true]);
            $flash = 'Harga, peluang & kesulitan ikan disimpan. Berlaku untuk tangkapan berikutnya.';
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
$globalHard = max(0.3, min(3.0, (float)(home_cms_config($pdo)['fishing_engine']['fight_hardness'] ?? 1)));
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

$pageTitle  = 'Fish System · Harga & Kesulitan Ikan';
$activePage = 'fishing_species';
require __DIR__ . '/partials/header.php';
fs_styles();
?>
<?php fs_head('Harga & Kesulitan Ikan', 'Atur peluang muncul, rentang berat, harga dasar & tingkat kesulitan tarik-ulur tiap jenis ikan. Harga jual = harga dasar × pengali pasar harian.'); ?>
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
    <table class="c-table no-dt w-100" id="price-table">
      <thead><tr><th>Jenis</th><th>Nama Ikan</th><th>Peluang</th><th>Berat (kg)</th><th>Harga Dasar (Rp)</th><th>Jual Hari Ini</th></tr></thead>
      <tbody>
      <?php foreach ($species as $b => $s): $m = $market[$b]['mult'] ?? 1; ?>
        <tr data-b="<?= $b ?>" data-m="<?= $m ?>">
          <td><?= fs_badge($b) ?><div class="fs-muted mt-1"><?= FISHING_SPECIES[$b] ?></div></td>
          <?php if ($b === FISHING_ZONK): ?>
          <td><input type="hidden" name="sp[<?= $b ?>][name]" value="<?= htmlspecialchars($s['name']) ?>"><b>Sampah acak</b>
            <div class="fs-muted" style="max-width:220px"><?= htmlspecialchars(implode(', ', array_column(FISHING_JUNK, 0))) ?></div></td>
          <?php else: ?>
          <td><input class="c-form-control" name="sp[<?= $b ?>][name]" value="<?= htmlspecialchars($s['name']) ?>" maxlength="80" style="min-width:170px" required></td>
          <?php endif; ?>
          <td style="min-width:110px">
            <div class="d-flex align-items-center gap-1"><input type="number" step="0.01" min="0" max="100" class="c-form-control fs-num" name="sp[<?= $b ?>][chance]" value="<?= (float)$s['chance'] ?>" style="width:76px" oninput="fsCalc()"><span class="fs-muted">bobot</span></div>
            <div class="fs-muted mt-1" data-pct>≈ <?= number_format((float)$s['chance'] / $chanceTotal * 100, 1) ?>%</div>
          </td>
          <?php if ($b === FISHING_ZONK): ?>
          <td><input type="hidden" name="sp[<?= $b ?>][weight_min]" value="<?= (float)$s['weight_min'] ?>"><input type="hidden" name="sp[<?= $b ?>][weight_max]" value="<?= (float)$s['weight_max'] ?>"><span class="fs-muted">otomatis per jenis</span></td>
          <?php else: ?>
          <td style="min-width:170px"><div class="d-flex align-items-center gap-1">
            <input type="number" step="0.01" min="0.01" class="c-form-control fs-num" name="sp[<?= $b ?>][weight_min]" value="<?= (float)$s['weight_min'] ?>" style="width:76px">
            <span class="fs-muted">–</span>
            <input type="number" step="0.01" min="0.01" class="c-form-control fs-num" name="sp[<?= $b ?>][weight_max]" value="<?= (float)$s['weight_max'] ?>" style="width:76px"></div></td>
          <?php endif; ?>
          <?php if ($b === FISHING_ZONK): ?>
          <td><input type="hidden" name="sp[<?= $b ?>][price_min]" value="0"><input type="hidden" name="sp[<?= $b ?>][price_max]" value="0">
            <span class="fs-muted">Sampah, tidak bernilai. Langsung dibuang, tidak masuk Ember.</span></td>
          <td class="fs-muted" data-sell>-</td>
          <?php else: ?>
          <td style="min-width:230px"><div class="d-flex align-items-center gap-1">
            <input type="number" step="1" min="1" class="c-form-control fs-num" name="sp[<?= $b ?>][price_min]" value="<?= round((float)$s['price_min']) ?>" style="width:104px" oninput="fsCalc()" data-price>
            <span class="fs-muted">–</span>
            <input type="number" step="1" min="1" class="c-form-control fs-num" name="sp[<?= $b ?>][price_max]" value="<?= round((float)$s['price_max']) ?>" style="width:104px" oninput="fsCalc()" data-price></div>
            <div class="fs-muted mt-1">ikan paling ringan → paling berat</div></td>
          <td class="fs-num" style="color:#34d399;white-space:nowrap" data-sell><?= format_rp(round((float)$s['price_min'] * $m)) ?> – <?= format_rp(round((float)$s['price_max'] * $m)) ?><div class="fs-muted">pasar <?= round($m * 100) ?>%</div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-top:1px solid #1f2235">
    <b>Kesulitan Tarik-Ulur</b>
    <span class="fs-muted">Skala global: <b style="color:#e2e8f0">×<?= rtrim(rtrim(number_format($globalHard, 2), '0'), '.') ?></b> · <a href="/console/fishing_settings.php" style="color:#67e8f9">ubah</a></span>
  </div>
  <div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table no-dt w-100" id="hard-table">
      <thead><tr><th>Jenis</th><th style="min-width:250px">Kesulitan</th><th>Tarikan · Jarak</th><th title="Bot bermain rapi dengan joran bambu">Main Rapi</th><th title="Menahan tombol gulung terus">Tahan Terus</th><th title="Pakai Kunci Ikan">Pakai Kunci</th></tr></thead>
      <tbody>
      <?php foreach ($species as $b => $s): [$bp, $bd] = FISHING_FIGHT_BASE[$b]; ?>
        <tr data-b="<?= $b ?>" data-pull="<?= $bp ?>" data-dist="<?= $bd ?>">
          <td><?= fs_badge($b) ?><div class="fs-muted mt-1"><?= FISHING_SPECIES[$b] ?></div></td>
          <td><div class="d-flex align-items-center gap-2">
            <input type="range" min="0.3" max="2.5" step="0.05" value="<?= (float)$s['hardness'] ?>" class="form-range flex-grow-1" oninput="this.nextElementSibling.value=this.value;fsHard()">
            <input type="number" min="0.3" max="3" step="0.05" class="c-form-control fs-num" name="sp[<?= $b ?>][hardness]" value="<?= (float)$s['hardness'] ?>" style="width:80px" oninput="this.previousElementSibling.value=this.value;fsHard()">
          </div></td>
          <td class="fs-num" data-eff style="white-space:nowrap">-</td>
          <td class="fs-num" data-t0>-</td>
          <td class="fs-num" data-t1>-</td>
          <td class="fs-num" data-t2>-</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <div class="px-3 pt-2 fs-muted">Perkiraan durasi tangkap (median simulasi) memakai joran bambu tanpa bonus. Pemain sungguhan biasanya lebih lambat dari angka "Main Rapi". Merah = ikan hampir mustahil ditarik dengan cara itu.</div>
  <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="fs-muted">Perkiraan rata-rata per lemparan setelah diubah: <b id="sp-ev" style="color:#22d3ee"><?= format_rp(round($ev)) ?></b></div>
    <button class="btn btn-primary px-4">Simpan Harga &amp; Kesulitan</button>
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
  const rows = [...document.querySelectorAll('#price-table tbody tr')];
  const val = (tr, f) => parseFloat(tr.querySelector('[name$="[' + f + ']"]').value) || 0;
  const total = rows.reduce((a, tr) => a + val(tr, 'chance'), 0) || 1;
  let ev = 0;
  rows.forEach((tr) => {
    const c = val(tr, 'chance'), lo = val(tr, 'price_min'), hi = val(tr, 'price_max'), m = parseFloat(tr.dataset.m) || 1;
    ev += c / total * (lo + hi) / 2;
    tr.querySelector('[data-pct]').textContent = '≈ ' + (c / total * 100).toFixed(1) + '%';
    if (tr.dataset.b !== 'zonk') tr.querySelector('[data-sell]').innerHTML = fsRp(lo * m) + ' – ' + fsRp(hi * m) + '<div class="fs-muted">pasar ' + Math.round(m * 100) + '%</div>';
  });
  document.getElementById('sp-ev').textContent = fsRp(ev);
}
/* Simulasi tarik-ulur: rumus sama dengan updateFight di user/minigame.php */
const FS_GLOBAL_HARD = <?= json_encode($globalHard) ?>;
function fsSimFight(pull, D0, mode, locked) {
  const rand = (a, b) => a + Math.random() * (b - a);
  let dist = D0, tension = 30, surge = 0, surgeCd = 1.6, reelLock = 0, holding = false, t = 0;
  const dt = 1 / 30;
  while (dist > 0 && t < 120) {
    surgeCd -= dt;
    if (surgeCd <= 0) { surge = rand(0.45, 0.9); surgeCd = rand(1.5, 3.0) / (0.7 + pull * 0.4); }
    const surging = surge > 0; if (surging) surge -= dt;
    if (reelLock > 0) reelLock -= dt;
    if (mode === 'careful') { if (!holding && tension < 45) holding = true; if (holding && tension >= 75) holding = false; } else holding = true;
    let dT;
    if (holding && reelLock <= 0) {
      dT = (10 + pull * 8) * dt + (surging ? 18 * pull * dt : 0);
      dist -= (2.6 - pull * 0.3) * (surging ? 0.65 : 1) * dt;
    } else {
      dT = -32 * dt + (surging ? 10 * pull * dt : 0);
      if (!locked) dist = Math.min(D0, dist + (surging ? 0.9 : 0.2) * pull * dt);
    }
    tension = Math.max(0, Math.min(100, tension + dT));
    if (tension >= 100) { tension = locked ? 50 : 55; reelLock = locked ? 0.6 : 1.0; if (!locked) dist = Math.min(D0, dist + 4); }
    t += dt;
  }
  return t;
}
function fsMedian(pull, D0, mode, locked) {
  const a = []; for (let i = 0; i < 81; i++) a.push(fsSimFight(pull, D0, mode, locked));
  a.sort((x, y) => x - y); return a[40];
}
function fsCell(td, sec) {
  td.textContent = sec >= 120 ? 'macet' : '±' + Math.round(sec) + ' dtk';
  td.style.color = sec >= 120 ? '#fb7185' : (sec > 45 ? '#fbbf24' : '#34d399');
}
let fsHardT = null;
function fsHard() {
  clearTimeout(fsHardT);
  fsHardT = setTimeout(() => {
    document.querySelectorAll('#hard-table tbody tr').forEach((tr) => {
      const h = Math.max(0.3, Math.min(3, (parseFloat(tr.querySelector('[name$="[hardness]"]').value) || 1) * FS_GLOBAL_HARD));
      const pull = parseFloat(tr.dataset.pull) * h, D0 = Math.round(parseFloat(tr.dataset.dist) * (0.6 + 0.4 * h));
      tr.querySelector('[data-eff]').textContent = pull.toFixed(2) + ' · ' + D0 + ' m';
      fsCell(tr.querySelector('[data-t0]'), fsMedian(pull, D0, 'careful', false));
      fsCell(tr.querySelector('[data-t1]'), fsMedian(pull, D0, 'hold', false));
      fsCell(tr.querySelector('[data-t2]'), fsMedian(pull, D0, 'hold', true));
    });
  }, 120);
}
fsHard();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
