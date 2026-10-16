<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/lib/wd_levels.php';
staff_require('settings');
csrf_enforce();

$flash     = $_SESSION['settings_flash']      ?? '';
$flashType = $_SESSION['settings_flash_type'] ?? '';
unset($_SESSION['settings_flash'], $_SESSION['settings_flash_type']);
global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_wd_settings') {
        setting_set($pdo, 'wd_global_enabled', isset($_POST['wd_global_enabled']) ? '1' : '0');
        setting_set($pdo, 'wd_require_level', isset($_POST['wd_require_level']) ? '1' : '0');
        setting_set($pdo, 'min_withdraw', clean_input($_POST['min_withdraw'] ?? '50000'));
        setting_set($pdo, 'wd_min_level', clean_input($_POST['wd_min_level'] ?? '0'));

        setting_set($pdo, 'wd_free_require_1day', isset($_POST['wd_free_require_1day']) ? '1' : '0');
        setting_set($pdo, 'wd_free_limit_1x', isset($_POST['wd_free_limit_1x']) ? '1' : '0');
        setting_set($pdo, 'wd_free_only_dana', isset($_POST['wd_free_only_dana']) ? '1' : '0');

        // Jadwal lock
        setting_set($pdo, 'wd_lock_start', trim($_POST['wd_lock_start'] ?? ''));
        setting_set($pdo, 'wd_lock_end',   trim($_POST['wd_lock_end'] ?? ''));
        setting_set($pdo, 'wd_lock_notice', trim($_POST['wd_lock_notice'] ?? 'Penarikan hanya bisa dilakukan pada jam tertentu.'));

        $flash = 'Pengaturan penarikan (WD) berhasil disimpan!';
        $flashType = 'success';
    }

    if ($action === 'save_level_status') {
        $cfg = [];
        foreach ((array)($_POST['lv'] ?? []) as $mid => $row) {
            $mode = in_array($row['mode'] ?? 'auto', ['auto', 'normal', 'busy', 'overload'], true) ? $row['mode'] : 'auto';
            $busy = max(1, (int)($row['busy_at'] ?? 10));
            $cfg[(string)(int)$mid] = [
                'mode'        => $mode,
                'busy_at'     => $busy,
                'overload_at' => max($busy + 1, (int)($row['overload_at'] ?? 25)),
                'show_normal' => !empty($row['show_normal']),
                'messages'    => array_map(fn($v) => mb_substr(trim((string)$v), 0, 300), array_intersect_key((array)($row['messages'] ?? []), WD_LEVEL_STATES)),
                'etas'        => array_map(fn($v) => mb_substr(trim((string)$v), 0, 40), array_intersect_key((array)($row['etas'] ?? []), WD_LEVEL_STATES)),
            ];
        }
        setting_set($pdo, 'wd_level_status', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        $flash = 'Status antrian per level berhasil disimpan!';
        $flashType = 'success';
    }
}

$s = fn($k, $d = '') => setting($pdo, $k, $d);

// Status jam lock
$wd_locked  = is_wd_locked($pdo);
$start_lock = $s('wd_lock_start');
$end_lock   = $s('wd_lock_end');
$lock_text  = 'Tanpa jadwal lock';
if ($start_lock && $end_lock) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $start_lock);
    $e_ts = strtotime(date('Y-m-d ') . $end_lock);
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $lock_text = 'Dibuka lagi ' . floor($diff / 3600) . 'j ' . floor(($diff % 3600) / 60) . 'm';
    } else {
        if ($s_ts <= $now_ts) $s_ts += 86400;
        $diff = $s_ts - $now_ts;
        $lock_text = 'Ditutup ' . floor($diff / 3600) . 'j ' . floor(($diff % 3600) / 60) . 'm lagi';
    }
}
$wd_on = $s('wd_global_enabled', '1') === '1';

// Antrian & level
$levels = $pdo->query("SELECT id, name, price, sort_order, min_wd, max_wd, wd_hold, is_wd_disabled FROM memberships WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll();
$queue  = wd_level_queue($pdo);
$lvCfg  = wd_level_config($pdo);
$q_total = array_sum(array_column($queue, 'n'));
$q_sum   = array_sum(array_column($queue, 'sum'));
$q_pend  = array_sum(array_column($queue, 'pending'));
$q_hold  = array_sum(array_column($queue, 'hold'));
$free_name = get_free_tier_name($pdo);

$pageTitle  = 'Pengaturan Withdraw';
$activePage = 'wd_settings';
require __DIR__ . '/partials/header.php';
?>

<style>
.wds-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
@media (max-width: 900px) { .wds-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.wds-stat { background: #1a1d27; border: 1px solid #2d3149; border-radius: 12px; padding: 12px 14px; position: relative; overflow: hidden; }
.wds-stat::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--c, var(--brand)); }
.wds-stat small { display: block; font-size: 11px; color: #888; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.wds-stat b { display: block; font-size: 19px; font-weight: 800; color: var(--c, #fff); margin-top: 2px; }
.wds-stat span { font-size: 11.5px; color: #8b93a7; }
.wds-sec { font-size: 13px; font-weight: 800; color: var(--brand); margin: 18px 0 10px; display: flex; align-items: center; gap: 6px; }
.wds-sec:first-child { margin-top: 0; }
.wds-switch { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid #2d3149; border-radius: 10px; background: #151823; margin-bottom: 8px; }
.wds-switch .form-check-input { margin-top: 2px; flex-shrink: 0; cursor: pointer; }
.wds-switch label { cursor: pointer; }
.wds-switch b { display: block; font-size: 13px; color: #e5e7eb; }
.wds-switch span { display: block; font-size: 11.5px; color: #888; }
.wds-time { display: flex; gap: 4px; }
.wds-time select { padding: 6px !important; }
.lv-card { border: 1px solid #2d3149; border-radius: 12px; background: #151823; padding: 12px; margin-bottom: 10px; }
.lv-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.lv-name { font-weight: 800; font-size: 14px; color: #fff; }
.lv-meta { font-size: 11.5px; color: #888; }
.lv-pill { margin-left: auto; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 800; }
.lv-pill.normal { background: rgba(34,197,94,.15); color: #86efac; }
.lv-pill.busy { background: rgba(245,158,11,.15); color: #fcd34d; }
.lv-pill.overload { background: rgba(239,68,68,.18); color: #fca5a5; }
.lv-grid { display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 8px; align-items: end; }
@media (max-width: 700px) { .lv-grid { grid-template-columns: 1fr 1fr; } .lv-grid .lv-mode { grid-column: 1 / -1; } }
.lv-grid label, .lv-msgs label { font-size: 11px; color: #888; font-weight: 700; margin-bottom: 3px; display: block; }
.lv-msgs { display: none; margin-top: 10px; border-top: 1px dashed #2d3149; padding-top: 10px; }
.lv-card.open .lv-msgs { display: block; }
.lv-msgs .row-st { display: grid; grid-template-columns: 1fr 110px; gap: 6px; margin-bottom: 8px; }
.lv-toggle { background: none; border: 0; color: var(--brand); font-size: 12px; font-weight: 700; padding: 0; margin-top: 8px; cursor: pointer; }
.lv-auto-only { transition: opacity .15s; }
.lv-card[data-mode]:not([data-mode="auto"]) .lv-auto-only { opacity: .35; pointer-events: none; }
</style>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">💸 Pengaturan Withdraw (WD)</h5>
    <div style="font-size:12px;color:#888;margin-top:2px">Aturan penarikan, jam lock, dan status antrian per level yang tampil di halaman Withdraw user</div>
  </div>
  <a href="/console/withdrawals.php" class="btn btn-sm btn-outline-info">Kelola penarikan →</a>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'danger' : 'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="wds-stats">
  <div class="wds-stat" style="--c:<?= $wd_on ? '#22c55e' : '#ef4444' ?>"><small>Fitur WD</small><b><?= $wd_on ? 'Aktif' : 'Maintenance' ?></b><span>Global semua user</span></div>
  <div class="wds-stat" style="--c:<?= $wd_locked ? '#ef4444' : '#38bdf8' ?>"><small>Jam lock</small><b><?= $wd_locked ? 'Ditutup' : 'Dibuka' ?></b><span><?= htmlspecialchars($lock_text) ?></span></div>
  <div class="wds-stat" style="--c:#f59e0b"><small>Antrian pending</small><b><?= $q_pend ?></b><span>Total antrian <?= format_rp($q_sum) ?></span></div>
  <div class="wds-stat" style="--c:#a78bfa"><small>Hold</small><b><?= $q_hold ?></b><span><?= $q_total ?> WD belum selesai</span></div>
</div>

<div class="row g-3">
  <!-- ── Aturan dasar & jam lock ─────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">⚙️ Aturan Penarikan</span></div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_wd_settings">

          <div class="wds-sec">🌐 Umum</div>
          <label class="wds-switch form-switch" for="wd_global_enabled">
            <input class="form-check-input" type="checkbox" role="switch" name="wd_global_enabled" id="wd_global_enabled" value="1" <?= $wd_on ? 'checked' : '' ?>>
            <span><b>Fitur withdraw aktif</b><span>Matikan untuk maintenance total: tidak ada user yang bisa menarik.</span></span>
          </label>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="c-label">Minimum WD global (Rp)</label>
              <input type="number" name="min_withdraw" class="c-form-control" value="<?= htmlspecialchars($s('min_withdraw', '50000')) ?>" min="0">
            </div>
            <div class="col-6">
              <label class="c-label">Level minimum WD</label>
              <select name="wd_min_level" class="c-form-control">
                <option value="0" <?= (int)$s('wd_min_level', '0') === 0 ? 'selected' : '' ?>>Semua level</option>
                <?php
                $cur_min = (int)$s('wd_min_level', '0');
                if ($cur_min > 0 && !in_array($cur_min, array_map('intval', array_column($levels, 'sort_order')), true)): ?>
                  <option value="<?= $cur_min ?>" selected>Level <?= $cur_min ?> (tidak ada level aktif)</option>
                <?php endif; ?>
                <?php foreach ($levels as $lv): if ((int)$lv['sort_order'] <= 0) continue; ?>
                  <option value="<?= (int)$lv['sort_order'] ?>" <?= (int)$s('wd_min_level', '0') === (int)$lv['sort_order'] ? 'selected' : '' ?>><?= htmlspecialchars($lv['name']) ?> ke atas</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <label class="wds-switch form-switch" for="wd_require_level_chk">
            <input class="form-check-input" type="checkbox" role="switch" name="wd_require_level" id="wd_require_level_chk" value="1" <?= $s('wd_require_level', '0') === '1' ? 'checked' : '' ?>>
            <span><b>Wajibkan level minimum</b><span>Bila mati, semua user bisa WD tanpa syarat level.</span></span>
          </label>

          <div class="wds-sec">🆓 Khusus <?= htmlspecialchars($free_name) ?> (gratis)</div>
          <label class="wds-switch form-switch" for="wd_free_require_1day">
            <input class="form-check-input" type="checkbox" role="switch" name="wd_free_require_1day" id="wd_free_require_1day" value="1" <?= $s('wd_free_require_1day', '1') === '1' ? 'checked' : '' ?>>
            <span><b>Akun minimal berumur 1 hari</b><span>User baru daftar belum bisa langsung WD.</span></span>
          </label>
          <label class="wds-switch form-switch" for="wd_free_limit_1x">
            <input class="form-check-input" type="checkbox" role="switch" name="wd_free_limit_1x" id="wd_free_limit_1x" value="1" <?= $s('wd_free_limit_1x', '1') === '1' ? 'checked' : '' ?>>
            <span><b>Maksimal 1× WD seumur hidup</b><span>Setelah 1 WD sukses, harus upgrade level.</span></span>
          </label>
          <label class="wds-switch form-switch" for="wd_free_only_dana">
            <input class="form-check-input" type="checkbox" role="switch" name="wd_free_only_dana" id="wd_free_only_dana" value="1" <?= $s('wd_free_only_dana', '1') === '1' ? 'checked' : '' ?>>
            <span><b>Hanya ke e-wallet DANA</b><span>Rekening selain DANA ditolak untuk level gratis.</span></span>
          </label>

          <div class="wds-sec">🔒 Jam lock penarikan</div>
          <div class="alert alert-<?= $wd_locked ? 'warning' : 'success' ?> py-2 mb-2" style="border-radius:8px;font-size:12.5px">
            <?= $wd_locked ? '⏳ Saat ini WD <strong>DITUTUP</strong>' : '✅ Saat ini WD <strong>DIBUKA</strong>' ?> · <?= htmlspecialchars($lock_text) ?>
          </div>
          <input type="hidden" name="wd_lock_start" id="wd_lock_start" value="<?= htmlspecialchars($s('wd_lock_start')) ?>">
          <input type="hidden" name="wd_lock_end" id="wd_lock_end" value="<?= htmlspecialchars($s('wd_lock_end')) ?>">
          <div class="row g-2 mb-1">
            <div class="col-6">
              <label class="c-label">Mulai lock</label>
              <div class="wds-time">
                <select id="start_h" class="c-form-control"><option value="">--</option><?php for ($i = 1; $i <= 12; $i++) { $v = str_pad((string)$i, 2, '0', STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                <select id="start_m" class="c-form-control"><option value="">--</option><?php for ($i = 0; $i < 60; $i++) { $v = str_pad((string)$i, 2, '0', STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                <select id="start_p" class="c-form-control"><option value="">--</option><option value="AM">AM</option><option value="PM">PM</option></select>
              </div>
            </div>
            <div class="col-6">
              <label class="c-label">Selesai lock</label>
              <div class="wds-time">
                <select id="end_h" class="c-form-control"><option value="">--</option><?php for ($i = 1; $i <= 12; $i++) { $v = str_pad((string)$i, 2, '0', STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                <select id="end_m" class="c-form-control"><option value="">--</option><?php for ($i = 0; $i < 60; $i++) { $v = str_pad((string)$i, 2, '0', STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                <select id="end_p" class="c-form-control"><option value="">--</option><option value="AM">AM</option><option value="PM">PM</option></select>
              </div>
            </div>
          </div>
          <small style="color:#888;font-size:11px">Kosongkan jam untuk menghapus jadwal lock.</small>
          <div class="c-form-group mt-2">
            <label class="c-label">Pesan saat WD dikunci</label>
            <input type="text" name="wd_lock_notice" class="c-form-control" value="<?= htmlspecialchars($s('wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.')) ?>">
          </div>

          <button type="submit" class="btn btn-sm text-white mt-3 w-100" style="background:var(--brand)">💾 Simpan Aturan Penarikan</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Status antrian per level (Withdraw Overload) ───────────────────── -->
  <div class="col-lg-7">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">🚦 Status Antrian per Level (Withdraw Overload)</span></div>
      <div class="c-card-body">
        <div style="font-size:12px;color:#888;margin-bottom:12px">
          Informasi ini muncul di halaman <b>Withdraw</b> dan hanya terlihat oleh user di level tersebut.
          <b>Otomatis</b> = status dihitung dari jumlah WD pending/hold level itu; atau paksa <b>Normal / Ramai / Overload</b>.
        </div>
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_level_status">
          <?php foreach ($levels as $lv):
            $mid = (int)$lv['id'];
            $c = $lvCfg[(string)$mid] ?? [];
            $mode = $c['mode'] ?? 'auto';
            $st = wd_level_status($pdo, $mid, $queue, $lvCfg);
            $q = $queue[$mid] ?? ['n' => 0, 'sum' => 0, 'pending' => 0, 'hold' => 0];
          ?>
          <div class="lv-card" data-mode="<?= htmlspecialchars($mode) ?>">
            <div class="lv-head">
              <div>
                <div class="lv-name"><?= htmlspecialchars($lv['name']) ?><?= (float)$lv['price'] <= 0 ? ' <span class="lv-meta">(gratis)</span>' : '' ?></div>
                <div class="lv-meta">Antrian sekarang: <b style="color:#e5e7eb"><?= (int)$q['n'] ?> WD</b> · <?= format_rp((float)$q['sum']) ?> · <?= (int)$q['pending'] ?> pending, <?= (int)$q['hold'] ?> hold
                  <?= !empty($lv['is_wd_disabled']) ? ' · <span style="color:#fca5a5">WD level ini dimatikan</span>' : '' ?></div>
              </div>
              <span class="lv-pill <?= $st['state'] ?>" title="Yang dilihat user sekarang"><?= $st['state'] === 'overload' ? '⛔' : ($st['state'] === 'busy' ? '⏳' : '✅') ?> <?= htmlspecialchars($st['label']) ?></span>
            </div>
            <div class="lv-grid">
              <div class="lv-mode">
                <label>Mode status</label>
                <select name="lv[<?= $mid ?>][mode]" class="c-form-control" onchange="this.closest('.lv-card').dataset.mode=this.value">
                  <option value="auto" <?= $mode === 'auto' ? 'selected' : '' ?>>🤖 Otomatis (dari antrian)</option>
                  <option value="normal" <?= $mode === 'normal' ? 'selected' : '' ?>>✅ Paksa Normal / Lancar</option>
                  <option value="busy" <?= $mode === 'busy' ? 'selected' : '' ?>>⏳ Paksa Ramai</option>
                  <option value="overload" <?= $mode === 'overload' ? 'selected' : '' ?>>⛔ Paksa Withdraw Overload</option>
                </select>
              </div>
              <div class="lv-auto-only">
                <label>Ramai bila antrian ≥</label>
                <input type="number" min="1" name="lv[<?= $mid ?>][busy_at]" class="c-form-control" value="<?= (int)($c['busy_at'] ?? 10) ?>">
              </div>
              <div class="lv-auto-only">
                <label>Overload bila antrian ≥</label>
                <input type="number" min="2" name="lv[<?= $mid ?>][overload_at]" class="c-form-control" value="<?= (int)($c['overload_at'] ?? 25) ?>">
              </div>
            </div>
            <div class="form-check form-switch mt-2">
              <input class="form-check-input" type="checkbox" role="switch" id="sn<?= $mid ?>" name="lv[<?= $mid ?>][show_normal]" value="1" <?= !empty($c['show_normal']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="sn<?= $mid ?>" style="font-size:12px;color:#aaa">Tampilkan juga info saat status Lancar</label>
            </div>
            <button type="button" class="lv-toggle" onclick="this.closest('.lv-card').classList.toggle('open')">✏️ Pesan & estimasi untuk user ▾</button>
            <div class="lv-msgs">
              <?php foreach (WD_LEVEL_STATES as $key => $def): ?>
                <label><?= $key === 'overload' ? '⛔' : ($key === 'busy' ? '⏳' : '✅') ?> Saat <?= htmlspecialchars($def['label']) ?> — pesan &amp; estimasi proses</label>
                <div class="row-st">
                  <input type="text" class="c-form-control" name="lv[<?= $mid ?>][messages][<?= $key ?>]" value="<?= htmlspecialchars((string)($c['messages'][$key] ?? '')) ?>" placeholder="<?= htmlspecialchars($def['msg']) ?>">
                  <input type="text" class="c-form-control" name="lv[<?= $mid ?>][etas][<?= $key ?>]" value="<?= htmlspecialchars((string)($c['etas'][$key] ?? '')) ?>" placeholder="<?= htmlspecialchars($def['eta']) ?>">
                </div>
              <?php endforeach; ?>
              <small style="color:#666;font-size:11px">Kosongkan untuk memakai teks bawaan (abu-abu).</small>
            </div>
          </div>
          <?php endforeach; ?>
          <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand)">💾 Simpan Status Antrian Level</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
<script>
// Jam lock: pilih format 12 jam, simpan sebagai HH:MM (24 jam)
function parseTime(val) {
  if (!val) return { h: '', m: '', p: '' };
  const parts = val.split(':');
  if (parts.length < 2) return { h: '', m: '', p: '' };
  let h = parseInt(parts[0], 10);
  const p = h >= 12 ? 'PM' : 'AM';
  h = h % 12; if (h === 0) h = 12;
  return { h: String(h).padStart(2, '0'), m: parts[1].padStart(2, '0'), p };
}
function initTimeSelects(id) {
  const parsed = parseTime(document.getElementById(id).value);
  const pfx = id === 'wd_lock_start' ? 'start_' : 'end_';
  document.getElementById(pfx + 'h').value = parsed.h;
  document.getElementById(pfx + 'm').value = parsed.m;
  document.getElementById(pfx + 'p').value = parsed.p;
}
function syncTime(id) {
  const pfx = id === 'wd_lock_start' ? 'start_' : 'end_';
  let h = document.getElementById(pfx + 'h').value;
  const m = document.getElementById(pfx + 'm').value;
  const p = document.getElementById(pfx + 'p').value;
  if (!h || !m || !p) { document.getElementById(id).value = ''; return; }
  h = parseInt(h, 10);
  if (p === 'PM' && h < 12) h += 12;
  if (p === 'AM' && h === 12) h = 0;
  document.getElementById(id).value = String(h).padStart(2, '0') + ':' + m;
}
initTimeSelects('wd_lock_start');
initTimeSelects('wd_lock_end');
['start_h', 'start_m', 'start_p'].forEach(x => document.getElementById(x).addEventListener('change', () => syncTime('wd_lock_start')));
['end_h', 'end_m', 'end_p'].forEach(x => document.getElementById(x).addEventListener('change', () => syncTime('wd_lock_end')));
</script>
