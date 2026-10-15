<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('global_chat');
require_once __DIR__ . '/partials/gchat_common.php';

$flash = $flashType = '';
$cfg = gc_config($pdo);
$admin = gc_console_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $act = (string)($_POST['action'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($act === 'toggle_open') {
        $cfg['enabled'] = !$cfg['enabled'];
        gc_config_save($pdo, $cfg);
        gc_admin_log($pdo, $admin, $cfg['enabled'] ? 'chat.open' : 'chat.close');
        $flash = $cfg['enabled'] ? 'Global chat dibuka kembali.' : 'Global chat ditutup. User hanya bisa membaca.';
    } elseif ($act === 'post') {
        $text = trim((string)($_POST['message'] ?? ''));
        if ($text === '') { $flash = 'Pesan kosong.'; $flashType = 'error'; }
        else {
            $pdo->prepare("INSERT INTO gc_messages (user_id, username, tier_name, is_admin, message) VALUES (0, ?, 'Admin', 1, ?)")
                ->execute([(string)($_POST['as_name'] ?: 'Admin'), mb_substr($text, 0, 1000)]);
            gc_admin_log($pdo, $admin, 'msg.post', null, mb_substr($text, 0, 200));
            $flash = 'Pesan admin terkirim ke Global Chat.';
        }
    } elseif ($act === 'delete' && $id) {
        $pdo->prepare("UPDATE gc_messages SET is_deleted = 1, deleted_by = ?, deleted_at = NOW() WHERE id = ?")->execute([$admin, $id]);
        gc_admin_log($pdo, $admin, 'msg.delete', null, ['id' => $id]);
        $flash = 'Pesan #' . $id . ' dihapus.';
    } elseif ($act === 'restore' && $id) {
        $pdo->prepare("UPDATE gc_messages SET is_deleted = 0, deleted_by = NULL, deleted_at = NULL WHERE id = ?")->execute([$id]);
        gc_admin_log($pdo, $admin, 'msg.restore', null, ['id' => $id]);
        $flash = 'Pesan #' . $id . ' dipulihkan.';
    } elseif ($act === 'unshadow' && $id) {
        $pdo->prepare("UPDATE gc_messages SET is_shadow = 0 WHERE id = ?")->execute([$id]);
        gc_admin_log($pdo, $admin, 'msg.unshadow', null, ['id' => $id]);
        $flash = 'Pesan #' . $id . ' sekarang terlihat oleh semua user.';
    } elseif ($act === 'clear_all') {
        $n = $pdo->exec("UPDATE gc_messages SET is_deleted = 1, deleted_by = " . $pdo->quote($admin) . ", deleted_at = NOW() WHERE is_deleted = 0");
        gc_admin_log($pdo, $admin, 'msg.clear_all', null, ['count' => $n]);
        $flash = $n . ' pesan dihapus dari Global Chat.';
    } elseif (($act === 'mute' || $act === 'block') && $uid) {
        if ($act === 'mute') {
            $pdo->prepare("INSERT INTO gc_user_overrides (user_id, muted_until) VALUES (?, NOW() + INTERVAL 24 HOUR) ON DUPLICATE KEY UPDATE muted_until = NOW() + INTERVAL 24 HOUR")->execute([$uid]);
        } else {
            $pdo->prepare("INSERT INTO gc_user_overrides (user_id, can_send) VALUES (?, 0) ON DUPLICATE KEY UPDATE can_send = 0")->execute([$uid]);
        }
        gc_admin_log($pdo, $admin, 'user.' . $act, $uid);
        $flash = $act === 'mute' ? 'User #' . $uid . ' dibisukan 24 jam.' : 'User #' . $uid . ' diblokir mengirim pesan.';
    }
}

// Filter
$f  = (string)($_GET['f'] ?? 'all');
$qu = trim((string)($_GET['u'] ?? ''));
$where = ['1=1']; $params = [];
if ($f === 'shadow')  $where[] = 'm.is_shadow = 1';
if ($f === 'flagged') $where[] = 'm.flag_reason IS NOT NULL';
if ($f === 'image')   $where[] = 'm.image IS NOT NULL';
if ($f === 'deleted') $where[] = 'm.is_deleted = 1';
if ($f !== 'deleted') $where[] = 'm.is_deleted = 0';
if ($qu !== '') { $where[] = '(m.username LIKE ? OR m.user_id = ?)'; $params[] = '%' . $qu . '%'; $params[] = ctype_digit($qu) ? (int)$qu : -1; }
$s = $pdo->prepare("SELECT m.* FROM gc_messages m WHERE " . implode(' AND ', $where) . " ORDER BY m.id DESC LIMIT 300");
$s->execute($params);
$rows = $s->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(is_shadow),0) shadow, COALESCE(SUM(flag_reason IS NOT NULL),0) flagged,
                   COALESCE(SUM(image IS NOT NULL),0) imgs, COUNT(DISTINCT user_id) users
                   FROM gc_messages WHERE created_at >= CURDATE() AND is_admin = 0")->fetch(PDO::FETCH_ASSOC);

$pageTitle  = 'Global Chat · Moderasi';
$activePage = 'global_chat';
require __DIR__ . '/partials/header.php';
gc_styles();
?>
<div class="mb-3"><h4 class="mb-1 text-white" style="font-weight:800">💬 Global Chat</h4><div class="gc-muted">Pantau, hapus, dan kirim pengumuman ke ruang obrolan semua user.</div></div>
<?php gc_tabs('global_chat'); ?>
<?php gc_flash($flash, $flashType); ?>

<div class="gc-status">
  <span class="gc-dot" style="background:<?= $cfg['enabled'] ? '#34d399' : '#fb7185' ?>"></span>
  <b style="color:#e2e8f0"><?= $cfg['enabled'] ? 'Global chat DIBUKA' : 'Global chat DITUTUP (hanya baca)' ?></b>
  <a href="/global-chat" target="_blank" class="gc-muted" style="text-decoration:none"><i class="ph-bold ph-arrow-square-out"></i> Lihat sebagai user</a>
  <form method="post" class="ms-auto"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_open">
    <button class="btn btn-sm <?= $cfg['enabled'] ? 'btn-outline-danger' : 'btn-success' ?>"><?= $cfg['enabled'] ? '🔒 Tutup Chat' : '🔓 Buka Chat' ?></button></form>
  <form method="post" onsubmit="return confirm('Hapus SEMUA pesan di Global Chat?')"><?= csrf_field() ?><input type="hidden" name="action" value="clear_all">
    <button class="btn btn-sm btn-outline-warning">🧹 Kosongkan Semua</button></form>
</div>

<div class="row g-3 mb-3">
  <?php foreach ([['Pesan Hari Ini', $st['total'], '#22d3ee', 'ph-chats'], ['User Aktif', $st['users'], '#a78bfa', 'ph-users-three'], ['Disembunyikan (Shadow)', $st['shadow'], '#fb7185', 'ph-eye-slash'], ['Ditandai / Gambar', $st['flagged'] . ' / ' . $st['imgs'], '#fbbf24', 'ph-flag']] as [$l, $v, $c, $i]): ?>
  <div class="col-6 col-lg-3"><div class="c-stat h-100" style="border-top:3px solid <?= $c ?>">
    <div class="d-flex justify-content-between align-items-start mb-2"><div class="c-stat__lbl"><?= $l ?></div><div class="c-stat__icon" style="background:<?= $c ?>22;color:<?= $c ?>;font-size:18px"><i class="ph-bold <?= $i ?>"></i></div></div>
    <div class="c-stat__val" style="color:<?= $c ?>"><?= $v ?></div></div></div>
  <?php endforeach; ?>
</div>

<div class="c-card mb-3"><div class="c-card-body">
  <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
    <?= csrf_field() ?><input type="hidden" name="action" value="post">
    <div style="width:160px"><label class="c-label">Nama pengirim</label><input class="c-form-control" name="as_name" value="Admin" maxlength="40"></div>
    <div style="flex:1;min-width:220px"><label class="c-label">Kirim pengumuman ke Global Chat</label><input class="c-form-control" name="message" maxlength="1000" placeholder="Contoh: Event mancing malam ini jam 20.00!" required></div>
    <button class="btn btn-primary">Kirim</button>
  </form>
</div></div>

<div class="c-card mb-3"><div class="c-card-body">
  <form class="d-flex gap-2 flex-wrap align-items-end" method="get">
    <div><label class="c-label">Tampilkan</label>
      <select class="c-form-control" name="f">
        <?php foreach (['all' => 'Semua pesan', 'shadow' => 'Disembunyikan (shadow)', 'flagged' => 'Ditandai filter', 'image' => 'Dengan gambar', 'deleted' => 'Sudah dihapus'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $f === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="c-label">User (ID / username)</label><input class="c-form-control" name="u" value="<?= htmlspecialchars($qu) ?>"></div>
    <button class="btn btn-primary">Terapkan</button>
  </form>
</div></div>

<div class="c-card"><div class="c-card-body p-0"><div class="table-responsive">
  <table class="c-table w-100">
    <thead><tr><th>#</th><th>Waktu</th><th>User</th><th>Pesan</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="gc-muted"><?= (int)$r['id'] ?></td>
        <td data-order="<?= strtotime((string)$r['created_at']) ?>" style="white-space:nowrap"><?= date('d/m H:i', strtotime((string)$r['created_at'])) ?></td>
        <td style="white-space:nowrap"><?php if ((int)$r['is_admin']): ?><span class="gc-pill" style="color:#fbbf24">🛡 <?= htmlspecialchars($r['username']) ?></span>
          <?php else: ?><a href="/console/global_chat_users.php?u=<?= (int)$r['user_id'] ?>" style="color:#e2e8f0;font-weight:700;text-decoration:none"><?= htmlspecialchars($r['username']) ?></a>
          <div class="gc-muted">#<?= (int)$r['user_id'] ?> · <?= htmlspecialchars($r['tier_name']) ?></div><?php endif; ?></td>
        <td style="max-width:420px;word-break:break-word"><?= nl2br(htmlspecialchars((string)$r['message'])) ?>
          <?php if ($r['image']): ?><a href="/<?= htmlspecialchars($r['image']) ?>" target="_blank"><img class="gc-msg-img" src="/<?= htmlspecialchars($r['image']) ?>" alt=""></a><?php endif; ?></td>
        <td style="white-space:nowrap">
          <?php if ((int)$r['is_deleted']): ?><span class="gc-pill" style="color:#94a3b8">dihapus<?= $r['deleted_by'] ? ' · ' . htmlspecialchars($r['deleted_by']) : '' ?></span>
          <?php elseif ((int)$r['is_shadow']): ?><span class="gc-pill" style="color:#fb7185">shadow</span>
          <?php else: ?><span class="gc-pill" style="color:#34d399">tampil</span><?php endif; ?>
          <?php if ($r['flag_reason']): ?><div class="gc-muted mt-1"><?= htmlspecialchars($r['flag_reason']) ?></div><?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ((int)$r['is_deleted']): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-info py-0 px-2" title="Pulihkan"><i class="ph-bold ph-arrow-counter-clockwise"></i></button></form>
          <?php else: ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-2" title="Hapus"><i class="ph-bold ph-trash"></i></button></form>
            <?php if ((int)$r['is_shadow']): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="unshadow"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-success py-0 px-2" title="Tampilkan ke semua"><i class="ph-bold ph-eye"></i></button></form><?php endif; ?>
          <?php endif; ?>
          <?php if (!(int)$r['is_admin']): ?>
            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="mute"><input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>"><button class="btn btn-sm btn-outline-warning py-0 px-2" title="Mute 24 jam"><i class="ph-bold ph-speaker-slash"></i></button></form>
            <form method="post" class="d-inline" onsubmit="return confirm('Blokir user ini mengirim pesan?')"><?= csrf_field() ?><input type="hidden" name="action" value="block"><input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>"><button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Blokir kirim"><i class="ph-bold ph-prohibit"></i></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div></div>

<?php require __DIR__ . '/partials/footer.php'; ?>
