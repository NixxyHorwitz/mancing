<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('global_chat');
require_once __DIR__ . '/partials/gchat_common.php';

$flash = $flashType = '';
$cfg = gc_config($pdo);
$admin = gc_console_admin();
$tri = static fn($v) => $v === '' || $v === null ? null : (int)$v;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $act = (string)($_POST['action'] ?? '');
    $target = gc_find_user($pdo, (string)($_POST['user'] ?? ''));
    if (!$target) { $flash = 'User tidak ditemukan.'; $flashType = 'error'; }
    elseif ($act === 'save') {
        $hours = max(0, (int)($_POST['mute_hours'] ?? 0));
        $data = [
            $tri($_POST['can_view'] ?? ''), $tri($_POST['can_send'] ?? ''), $tri($_POST['can_image'] ?? ''),
            max(0, (int)($_POST['extra_msg'] ?? 0)), max(0, (int)($_POST['extra_img'] ?? 0)),
            $hours ? date('Y-m-d H:i:s', time() + $hours * 3600) : (isset($_POST['keep_mute']) ? ($_POST['muted_until'] ?: null) : null),
            mb_substr(trim((string)($_POST['note'] ?? '')), 0, 160),
        ];
        $pdo->prepare("INSERT INTO gc_user_overrides (user_id, can_view, can_send, can_image, extra_msg, extra_img, muted_until, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_send = VALUES(can_send), can_image = VALUES(can_image),
                       extra_msg = VALUES(extra_msg), extra_img = VALUES(extra_img), muted_until = VALUES(muted_until), note = VALUES(note)")
            ->execute([(int)$target['id'], ...$data]);
        gc_admin_log($pdo, $admin, 'user.override', (int)$target['id'], ['view' => $data[0], 'send' => $data[1], 'image' => $data[2], 'extra_msg' => $data[3], 'extra_img' => $data[4], 'muted_until' => $data[5]]);
        $flash = 'Izin khusus untuk ' . $target['username'] . ' disimpan.';
    } elseif ($act === 'remove') {
        $pdo->prepare("DELETE FROM gc_user_overrides WHERE user_id = ?")->execute([(int)$target['id']]);
        gc_admin_log($pdo, $admin, 'user.override_remove', (int)$target['id']);
        $flash = $target['username'] . ' kembali mengikuti aturan pangkat.';
    } elseif ($act === 'reset_usage') {
        $pdo->prepare("DELETE FROM gc_daily_usage WHERE user_id = ? AND period_key = ?")->execute([(int)$target['id'], gc_period_key($cfg)]);
        gc_admin_log($pdo, $admin, 'user.reset_quota', (int)$target['id']);
        $flash = 'Kuota hari ini untuk ' . $target['username'] . ' direset.';
    }
}

$sel = gc_find_user($pdo, (string)($_GET['u'] ?? ($_POST['user'] ?? '')));
$selOv = $sel ? gc_override($pdo, (int)$sel['id']) : [];
$selPerm = $sel ? gc_permissions($pdo, $sel, $cfg) : null;

$period = gc_period_key($cfg);
$list = $pdo->prepare("SELECT o.*, u.username, COALESCE(d.msg_count,0) msg_today, COALESCE(d.img_count,0) img_today
                       FROM gc_user_overrides o JOIN users u ON u.id = o.user_id
                       LEFT JOIN gc_daily_usage d ON d.user_id = o.user_id AND d.period_key = ?
                       ORDER BY o.updated_at DESC");
$list->execute([$period]);
$list = $list->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Global Chat · User Khusus';
$activePage = 'global_chat_users';
require __DIR__ . '/partials/header.php';
gc_styles();
$opt = static function (string $name, $val): string {
    $v = $val === null ? '' : (string)(int)$val;
    $o = '';
    foreach (['' => 'Ikut pangkat', '1' => 'Izinkan', '0' => 'Larang'] as $k => $l) $o .= '<option value="' . $k . '"' . ($v === (string)$k ? ' selected' : '') . '>' . $l . '</option>';
    return '<select class="c-form-control" name="' . $name . '">' . $o . '</select>';
};
?>
<div class="mb-3"><h4 class="mb-1 text-white" style="font-weight:800">💬 Global Chat</h4><div class="gc-muted">Kecualikan user tertentu dari aturan pangkat: beri akses, larang, tambah kuota, atau bisukan.</div></div>
<?php gc_tabs('global_chat_users'); ?>
<?php gc_flash($flash, $flashType); ?>

<div class="c-card mb-3"><div class="c-card-body">
  <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
    <div style="min-width:240px"><label class="c-label">Cari user (ID / username)</label><input class="c-form-control" name="u" value="<?= htmlspecialchars((string)($_GET['u'] ?? '')) ?>" required></div>
    <button class="btn btn-primary">Buka</button>
  </form>
</div></div>

<?php if ($sel): ?>
<div class="c-card mb-3">
  <div class="c-card-header d-flex justify-content-between flex-wrap gap-2">
    <b><?= htmlspecialchars($sel['username']) ?> <span class="gc-muted">#<?= (int)$sel['id'] ?> · <?= htmlspecialchars($selPerm['tier']['name']) ?></span></b>
    <span class="gc-muted">Efektif sekarang: lihat <?= $selPerm['view'] ? '✅' : '❌' ?> · kirim <?= $selPerm['send'] ? '✅' : '❌' ?> · gambar <?= $selPerm['image'] ? '✅' : '❌' ?> · sisa <?= $selPerm['msg_left'] ?>/<?= $selPerm['limit_msg'] ?> pesan, <?= $selPerm['img_left'] ?>/<?= $selPerm['limit_img'] ?> gambar</span>
  </div>
  <div class="c-card-body">
    <?php if ($selPerm['reason']): ?><div class="gc-muted mb-2">ℹ️ <?= htmlspecialchars($selPerm['reason']) ?></div><?php endif; ?>
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="user" value="<?= (int)$sel['id'] ?>">
      <div class="col-md-4 col-6"><label class="c-label">Lihat chat</label><?= $opt('can_view', $selOv['can_view'] ?? null) ?></div>
      <div class="col-md-4 col-6"><label class="c-label">Kirim pesan</label><?= $opt('can_send', $selOv['can_send'] ?? null) ?></div>
      <div class="col-md-4 col-6"><label class="c-label">Kirim gambar</label><?= $opt('can_image', $selOv['can_image'] ?? null) ?></div>
      <div class="col-md-3 col-6"><label class="c-label">Tambahan kuota pesan / hari</label><input type="number" min="0" class="c-form-control" name="extra_msg" value="<?= (int)($selOv['extra_msg'] ?? 0) ?>"></div>
      <div class="col-md-3 col-6"><label class="c-label">Tambahan kuota gambar / hari</label><input type="number" min="0" class="c-form-control" name="extra_img" value="<?= (int)($selOv['extra_img'] ?? 0) ?>"></div>
      <div class="col-md-3 col-6"><label class="c-label">Bisukan (jam, 0 = tidak)</label><input type="number" min="0" class="c-form-control" name="mute_hours" value="0"></div>
      <div class="col-md-3 col-6"><label class="c-label">Catatan</label><input class="c-form-control" name="note" maxlength="160" value="<?= htmlspecialchars((string)($selOv['note'] ?? '')) ?>"></div>
      <?php if (!empty($selOv['muted_until']) && strtotime((string)$selOv['muted_until']) > time()): ?>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="km" name="keep_mute" checked><input type="hidden" name="muted_until" value="<?= htmlspecialchars((string)$selOv['muted_until']) ?>">
          <label class="form-check-label text-white" for="km">Tetap bisukan sampai <?= date('d M H:i', strtotime((string)$selOv['muted_until'])) ?> (hapus centang untuk membuka)</label></div></div>
      <?php endif; ?>
      <div class="col-12 d-flex gap-2 flex-wrap">
        <button class="btn btn-primary">Simpan Izin Khusus</button>
      </div>
    </form>
    <div class="d-flex gap-2 mt-2 flex-wrap">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_usage"><input type="hidden" name="user" value="<?= (int)$sel['id'] ?>"><button class="btn btn-sm btn-outline-warning">🔄 Reset kuota hari ini</button></form>
      <?php if ($selOv): ?><form method="post" onsubmit="return confirm('Hapus semua izin khusus user ini?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user" value="<?= (int)$sel['id'] ?>"><button class="btn btn-sm btn-outline-danger">Kembalikan ke aturan pangkat</button></form><?php endif; ?>
    </div>
  </div>
</div>
<?php elseif (isset($_GET['u'])): ?>
<div class="alert alert-danger py-2 px-3" style="font-size:13px;border-radius:10px">User tidak ditemukan.</div>
<?php endif; ?>

<div class="c-card"><div class="c-card-header"><b>Daftar User Khusus</b> <span class="gc-muted ms-2"><?= count($list) ?> user</span></div><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table w-100">
    <thead><tr><th>User</th><th>Lihat</th><th>Kirim</th><th>Gambar</th><th>Tambahan kuota</th><th>Bisu</th><th>Pakai hari ini</th><th>Catatan</th></tr></thead>
    <tbody>
    <?php foreach ($list as $o): ?>
      <tr>
        <td><a href="?u=<?= (int)$o['user_id'] ?>" style="color:#e2e8f0;font-weight:700;text-decoration:none"><?= htmlspecialchars($o['username']) ?></a> <span class="gc-muted">#<?= (int)$o['user_id'] ?></span></td>
        <td><?= gc_tristate($o['can_view']) ?></td><td><?= gc_tristate($o['can_send']) ?></td><td><?= gc_tristate($o['can_image']) ?></td>
        <td>+<?= (int)$o['extra_msg'] ?> pesan · +<?= (int)$o['extra_img'] ?> gambar</td>
        <td><?= !empty($o['muted_until']) && strtotime((string)$o['muted_until']) > time() ? '<span class="gc-pill" style="color:#fbbf24">s/d ' . date('d/m H:i', strtotime((string)$o['muted_until'])) . '</span>' : '-' ?></td>
        <td><?= (int)$o['msg_today'] ?> pesan · <?= (int)$o['img_today'] ?> gambar</td>
        <td class="gc-muted"><?= htmlspecialchars((string)$o['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
