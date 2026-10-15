<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('global_chat');
require_once __DIR__ . '/partials/gchat_common.php';

$flash = $flashType = '';
$cfg = gc_config($pdo);
$admin = gc_console_admin();
$tiers = $pdo->query("SELECT id, name, price FROM memberships ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save') {
        $old = $cfg;
        $cfg['enabled']        = isset($_POST['enabled']);
        $cfg['closed_message'] = mb_substr(trim((string)($_POST['closed_message'] ?? '')), 0, 200) ?: 'Global chat sedang ditutup admin.';
        $cfg['reset_hour']     = max(0, min(23, (int)($_POST['reset_hour'] ?? 0)));
        $cfg['max_len']        = max(20, min(1000, (int)($_POST['max_len'] ?? 300)));
        $cfg['slow_mode']      = max(0, min(600, (int)($_POST['slow_mode'] ?? 5)));
        $cfg['history_limit']  = max(20, min(200, (int)($_POST['history_limit'] ?? 80)));
        $cfg['banned_words']   = mb_substr(trim((string)($_POST['banned_words'] ?? '')), 0, 5000);
        $cfg['watch_words']    = mb_substr(trim((string)($_POST['watch_words'] ?? '')), 0, 5000);
        $cfg['shadow_links']   = isset($_POST['shadow_links']);
        $cfg['shadow_phone']   = isset($_POST['shadow_phone']);
        $cfg['notify_tg']      = isset($_POST['notify_tg']);
        foreach ($tiers as $t) {
            $k = (string)$t['id'];
            $in = (array)($_POST['tier'][$k] ?? []);
            $cfg['tiers'][$k] = [
                'view'      => isset($in['view']),
                'send'      => isset($in['send']),
                'image'     => isset($in['image']),
                'daily_msg' => max(0, min(10000, (int)($in['daily_msg'] ?? 0))),
                'daily_img' => max(0, min(1000, (int)($in['daily_img'] ?? 0))),
            ];
        }
        if ($cfg['reset_hour'] !== (int)$old['reset_hour']) $cfg['reset_seq'] = (int)$cfg['reset_seq'] + 1; // jam berubah → kuota mulai baru
        gc_config_save($pdo, $cfg);
        gc_admin_log($pdo, $admin, 'settings.save', null, ['enabled' => $cfg['enabled'], 'reset_hour' => $cfg['reset_hour'], 'tiers' => $cfg['tiers']]);
        $flash = 'Pengaturan Global Chat disimpan.';
    } elseif ($act === 'reset_now') {
        $cfg['reset_seq'] = (int)$cfg['reset_seq'] + 1;
        gc_config_save($pdo, $cfg);
        gc_admin_log($pdo, $admin, 'quota.reset_all');
        $flash = 'Kuota harian semua user direset sekarang.';
    }
}

$logs = $pdo->query("SELECT l.*, u.username FROM gc_admin_logs l LEFT JOIN users u ON u.id = l.target_user_id ORDER BY l.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Global Chat · Pengaturan';
$activePage = 'global_chat_settings';
require __DIR__ . '/partials/header.php';
gc_styles();
?>
<div class="mb-3"><h4 class="mb-1 text-white" style="font-weight:800">💬 Global Chat</h4><div class="gc-muted">Atur siapa yang bisa melihat, mengirim pesan & gambar, kuota harian, dan filter pesan.</div></div>
<?php gc_tabs('global_chat_settings'); ?>
<?php gc_flash($flash, $flashType); ?>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <div class="c-card mb-3"><div class="c-card-header"><b>Status Chat</b></div><div class="c-card-body">
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" role="switch" id="gc-en" name="enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>>
      <label class="form-check-label text-white" for="gc-en" style="font-weight:700">Global chat dibuka (user bisa mengirim pesan)</label>
    </div>
    <label class="c-label">Pesan saat chat ditutup</label>
    <input class="c-form-control" name="closed_message" maxlength="200" value="<?= htmlspecialchars((string)$cfg['closed_message']) ?>">
    <div class="gc-muted mt-1">Saat ditutup, user yang punya izin lihat tetap bisa membaca obrolan.</div>
  </div></div>

  <div class="c-card mb-3"><div class="c-card-header"><b>Izin per Pangkat</b> <span class="gc-muted ms-2">user khusus bisa dikecualikan di tab User Khusus</span></div>
    <div class="c-card-body p-0"><div class="table-responsive">
      <table class="c-table no-dt w-100 gc-matrix">
        <thead><tr><th>Pangkat</th><th>Lihat chat</th><th>Kirim pesan</th><th>Kirim gambar</th><th>Kuota pesan / hari</th><th>Kuota gambar / hari</th></tr></thead>
        <tbody>
        <?php foreach ($tiers as $t): $c = $cfg['tiers'][(string)$t['id']]; $k = (int)$t['id']; ?>
          <tr>
            <td><b style="color:#e2e8f0"><?= htmlspecialchars($t['name']) ?></b><div class="gc-muted"><?= (float)$t['price'] > 0 ? format_rp((float)$t['price']) : 'Gratis' ?></div></td>
            <td><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="tier[<?= $k ?>][view]" <?= $c['view'] ? 'checked' : '' ?>></div></td>
            <td><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="tier[<?= $k ?>][send]" <?= $c['send'] ? 'checked' : '' ?>></div></td>
            <td><div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="tier[<?= $k ?>][image]" <?= $c['image'] ? 'checked' : '' ?>></div></td>
            <td><input type="number" min="0" class="c-form-control" name="tier[<?= $k ?>][daily_msg]" value="<?= (int)$c['daily_msg'] ?>"></td>
            <td><input type="number" min="0" class="c-form-control" name="tier[<?= $k ?>][daily_img]" value="<?= (int)$c['daily_img'] ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="c-card h-100"><div class="c-card-header"><b>Kuota & Pembatasan</b></div><div class="c-card-body">
        <div class="row g-2">
          <div class="col-6"><label class="c-label">Jam reset kuota harian</label>
            <select class="c-form-control" name="reset_hour"><?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= (int)$cfg['reset_hour'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></div>
          <div class="col-6"><label class="c-label">Reset berikutnya</label><div class="c-form-control" style="background:transparent"><?= htmlspecialchars(gc_next_reset($cfg)) ?></div></div>
          <div class="col-6"><label class="c-label">Jeda antar pesan (detik)</label><input type="number" min="0" class="c-form-control" name="slow_mode" value="<?= (int)$cfg['slow_mode'] ?>"></div>
          <div class="col-6"><label class="c-label">Panjang maks pesan</label><input type="number" min="20" class="c-form-control" name="max_len" value="<?= (int)$cfg['max_len'] ?>"></div>
          <div class="col-6"><label class="c-label">Riwayat dimuat</label><input type="number" min="20" class="c-form-control" name="history_limit" value="<?= (int)$cfg['history_limit'] ?>"></div>
        </div>
        <div class="gc-muted mt-2">Pesan & gambar yang disembunyikan tetap memotong kuota pengirim (agar ia tidak curiga).</div>
      </div></div>
    </div>
    <div class="col-lg-7">
      <div class="c-card h-100"><div class="c-card-header"><b>Filter Pesan</b></div><div class="c-card-body">
        <div class="row g-2">
          <div class="col-md-6"><label class="c-label">🚫 Kata terlarang (disembunyikan + notifikasi)</label>
            <textarea class="c-form-control" name="banned_words" rows="6" placeholder="satu kata per baris"><?= htmlspecialchars((string)$cfg['banned_words']) ?></textarea>
            <div class="gc-muted mt-1">Pengirim melihat pesannya terkirim, user lain tidak menerimanya. Tahan trik <code>k0nt0l</code> / <code>k o n t o l</code>.</div></div>
          <div class="col-md-6"><label class="c-label">👁 Kata pantauan (tetap tampil + notifikasi)</label>
            <textarea class="c-form-control" name="watch_words" rows="6" placeholder="satu kata per baris"><?= htmlspecialchars((string)$cfg['watch_words']) ?></textarea></div>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-2">
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="gc-sl" name="shadow_links" <?= $cfg['shadow_links'] ? 'checked' : '' ?>><label class="form-check-label text-white" for="gc-sl">Sembunyikan pesan bertautan</label></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="gc-sp" name="shadow_phone" <?= $cfg['shadow_phone'] ? 'checked' : '' ?>><label class="form-check-label text-white" for="gc-sp">Sembunyikan nomor HP</label></div>
          <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="gc-tg" name="notify_tg" <?= $cfg['notify_tg'] ? 'checked' : '' ?>><label class="form-check-label text-white" for="gc-tg">Notifikasi Telegram (topik 🚨 Deteksi Abuse)</label></div>
        </div>
      </div></div>
    </div>
  </div>

  <button class="btn btn-primary px-4">Simpan Pengaturan</button>
</form>

<form method="post" class="d-inline-block mt-2" onsubmit="return confirm('Reset kuota harian SEMUA user sekarang?')">
  <?= csrf_field() ?><input type="hidden" name="action" value="reset_now">
  <button class="btn btn-outline-warning btn-sm">🔄 Reset Kuota Semua User Sekarang</button>
</form>

<div class="c-card mt-3"><div class="c-card-header"><b>Log Aksi Admin</b></div><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table w-100">
    <thead><tr><th>Waktu</th><th>Admin</th><th>Aksi</th><th>User</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr><td data-order="<?= strtotime((string)$l['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$l['created_at'])) ?></td>
        <td><?= htmlspecialchars($l['admin']) ?></td><td><code style="font-size:11.5px"><?= htmlspecialchars($l['action']) ?></code></td>
        <td><?= $l['target_user_id'] ? htmlspecialchars((string)($l['username'] ?? '')) . ' <span class="gc-muted">#' . (int)$l['target_user_id'] . '</span>' : '-' ?></td>
        <td style="max-width:380px;font-size:11.5px;color:#94a3b8;word-break:break-all"><?= htmlspecialchars(mb_substr((string)$l['detail'], 0, 220)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
