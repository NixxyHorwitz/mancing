<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('videos');
csrf_enforce();

$flash = $flashType = '';

// Handle CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $yt_raw   = trim($_POST['youtube_url'] ?? '');
        $yt_id    = extract_youtube_id($yt_raw);
        $reward   = (float)preg_replace('/[^\d.]/', '', $_POST['reward_amount'] ?? '0');
        $duration = (int)($_POST['watch_duration'] ?? 30);
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);

        if (!$title || !$yt_id) { $flash = 'Judul dan URL YouTube wajib diisi & valid.'; $flashType = 'error'; }
        elseif ($reward < 1) { $flash = 'Reward harus lebih dari 0.'; $flashType = 'error'; }
        elseif ($duration < 5) { $flash = 'Durasi minimal 5 detik.'; $flashType = 'error'; }
        else {
            if ($action === 'add') {
                $fake_min    = max(0, (int)($_POST['fake_likes_min'] ?? 50));
                $fake_max    = max($fake_min, (int)($_POST['fake_likes_max'] ?? 250));
                $fake_likes  = ($fake_min < $fake_max) ? mt_rand($fake_min, $fake_max) : $fake_min;
                $total_likes = $fake_likes;

                $fake_v_min   = max(0, (int)($_POST['fake_views_min'] ?? 150));
                $fake_v_max   = max($fake_v_min, (int)($_POST['fake_views_max'] ?? 1200));
                $fake_watches = ($fake_v_min < $fake_v_max) ? mt_rand($fake_v_min, $fake_v_max) : $fake_v_min;
                $total_watches = $fake_watches;

                $pdo->prepare("INSERT INTO videos (title,youtube_id,reward_amount,watch_duration,is_active,sort_order,fake_likes,total_likes,fake_watches,total_watches) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$title, $yt_id, $reward, $duration, $active, $sort, $fake_likes, $total_likes, $fake_watches, $total_watches]);
                $flash = "Video '{$title}' berhasil ditambahkan (Fake: {$fake_watches} views, {$fake_likes} likes).";
            } else {
                $fake_likes  = max(0, (int)($_POST['fake_likes'] ?? 0));
                $real_likes  = (int)$pdo->query("SELECT COUNT(*) FROM video_likes WHERE video_id = " . (int)$id)->fetchColumn();
                $total_likes = $fake_likes + $real_likes;

                $fake_watches  = max(0, (int)($_POST['fake_watches'] ?? 0));
                $real_watches  = (int)$pdo->query("SELECT COUNT(*) FROM watch_history WHERE video_id = " . (int)$id)->fetchColumn();
                $total_watches = $fake_watches + $real_watches;

                $pdo->prepare("UPDATE videos SET title=?,youtube_id=?,reward_amount=?,watch_duration=?,is_active=?,sort_order=?,fake_likes=?,total_likes=?,fake_watches=?,total_watches=? WHERE id=?")
                    ->execute([$title, $yt_id, $reward, $duration, $active, $sort, $fake_likes, $total_likes, $fake_watches, $total_watches, $id]);
                $flash = "Video berhasil diperbarui.";
            }
        }
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        $pdo->prepare("UPDATE videos SET is_active=? WHERE id=?")->execute([$val, $id]);
        $flash = 'Status video diperbarui.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM videos WHERE id=?")->execute([$id]);
        $flash = 'Video dihapus.';
    }

    if ($action === 'delete_all') {
        $confirm = trim(strtoupper($_POST['confirm_delete'] ?? ''));
        if ($confirm !== 'HAPUS') {
            $flash = 'Konfirmasi gagal. Anda harus mengetik kata "HAPUS" untuk menghapus semua video.';
            $flashType = 'error';
        } else {
            try {
                $totalBefore = (int)$pdo->query("SELECT COUNT(*) FROM videos")->fetchColumn();
                $pdo->exec("DELETE FROM videos");
                try {
                    $pdo->exec("ALTER TABLE videos AUTO_INCREMENT = 1");
                } catch (\Throwable) {}
                $flash = "Seluruh video ({$totalBefore} video) telah berhasil dihapus dari database.";
                $flashType = 'success';
            } catch (\Throwable $e) {
                $flash = 'Gagal menghapus video: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    }

    if ($action === 'import_json') {
        $rawJson = trim($_POST['json_data'] ?? '');
        if (isset($_FILES['json_file']) && !empty($_FILES['json_file']['tmp_name']) && $_FILES['json_file']['error'] === UPLOAD_ERR_OK) {
            $uploaded = file_get_contents($_FILES['json_file']['tmp_name']);
            if ($uploaded !== false && trim($uploaded) !== '') {
                $rawJson = trim($uploaded);
            }
        }

        if (empty($rawJson)) {
            $flash = 'Data JSON tidak boleh kosong. Silakan salin JSON dari ekstensi scrapper atau upload file .json.';
            $flashType = 'error';
        } else {
            $data = json_decode($rawJson, true);
            if (!is_array($data)) {
                $flash = 'Format JSON tidak valid atau rusak. Pastikan JSON berupa array data video.';
                $flashType = 'error';
            } else {
                if (isset($data['videos']) && is_array($data['videos'])) {
                    $data = $data['videos'];
                } elseif (isset($data['data']) && is_array($data['data'])) {
                    $data = $data['data'];
                }

                $reward_mode   = $_POST['reward_mode'] ?? 'random'; // random | fixed | json | rate_duration
                $reward_min    = max(1.0, (float)($_POST['reward_min'] ?? 50));
                $reward_max    = max($reward_min, (float)($_POST['reward_max'] ?? 200));
                $reward_fixed  = max(1.0, (float)($_POST['reward_fixed'] ?? 100));

                // Parameter Rasio Durasi : Benefit (Contoh: per 60 detik = Rp 2.000)
                $rate_amount    = max(1.0, (float)($_POST['rate_amount'] ?? 2000));
                $rate_seconds   = max(1, (int)($_POST['rate_seconds'] ?? 60));
                $rate_min_floor = max(1.0, (float)($_POST['rate_min_floor'] ?? 50));
                $rate_max_cap   = !empty($_POST['rate_max_cap']) ? (float)$_POST['rate_max_cap'] : 0.0;

                $duration_mode  = $_POST['duration_mode'] ?? 'random';
                $duration_min   = max(5, (int)($_POST['duration_min'] ?? 15));
                $duration_max   = max($duration_min, (int)($_POST['duration_max'] ?? 60));
                $duration_fixed = max(5, (int)($_POST['duration_fixed'] ?? 30));

                // Parameter Like Fake Awal (Min - Max Rate)
                $like_mode      = $_POST['like_mode'] ?? 'random'; // random | fixed | none
                $like_min       = max(0, (int)($_POST['like_min'] ?? 50));
                $like_max       = max($like_min, (int)($_POST['like_max'] ?? 350));
                $like_fixed     = max(0, (int)($_POST['like_fixed'] ?? 100));

                // Parameter View / Tayangan Fake Awal (Min - Max Rate)
                $view_mode      = $_POST['view_mode'] ?? 'random'; // random | fixed | none
                $view_min       = max(0, (int)($_POST['view_min'] ?? 150));
                $view_max       = max($view_min, (int)($_POST['view_max'] ?? 1200));
                $view_fixed     = max(0, (int)($_POST['view_fixed'] ?? 500));

                $skip_duplicate  = isset($_POST['skip_duplicate']) && $_POST['skip_duplicate'] == '1';
                $is_active       = isset($_POST['is_active']) ? 1 : 0;
                $sort_order_mode = $_POST['sort_order_mode'] ?? 'auto';

                $existingIds = [];
                if ($skip_duplicate) {
                    $sStmt = $pdo->query("SELECT youtube_id FROM videos");
                    while ($row = $sStmt->fetchColumn()) {
                        $existingIds[$row] = true;
                    }
                }

                $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM videos")->fetchColumn();
                $imported = 0;
                $skipped = 0;

                $insertStmt = $pdo->prepare("INSERT INTO videos (title, youtube_id, reward_amount, watch_duration, is_active, sort_order, fake_likes, total_likes, fake_watches, total_watches) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $pdo->beginTransaction();
                try {
                    foreach ($data as $item) {
                        if (!is_array($item)) continue;

                        $rawYt = trim((string)($item['youtube_id'] ?? $item['youtubeId'] ?? $item['id'] ?? $item['video_id'] ?? $item['url'] ?? ''));
                        $ytId = extract_youtube_id($rawYt);
                        if (!$ytId && preg_match('/^[a-zA-Z0-9_-]{11}$/', $rawYt)) {
                            $ytId = $rawYt;
                        }

                        if (!$ytId) {
                            $skipped++;
                            continue;
                        }

                        if ($skip_duplicate && isset($existingIds[$ytId])) {
                            $skipped++;
                            continue;
                        }

                        $title = trim((string)($item['title'] ?? ''));
                        if ($title === '') {
                            $title = 'Video YouTube ' . $ytId;
                        }
                        if (mb_strlen($title) > 250) {
                            $title = mb_substr($title, 0, 247) . '...';
                        }

                        // 1. Tentukan Durasi Tonton Terlebih Dahulu
                        if ($duration_mode === 'random') {
                            $duration = ($duration_min < $duration_max) ? mt_rand($duration_min, $duration_max) : $duration_min;
                        } elseif ($duration_mode === 'fixed') {
                            $duration = $duration_fixed;
                        } else {
                            $duration = (int)($item['watch_duration'] ?? $item['duration'] ?? $duration_fixed);
                            if ($duration < 5) $duration = $duration_fixed;
                        }

                        // 2. Tentukan Benefit / Reward Pengguna Berdasarkan Mode
                        if ($reward_mode === 'rate_duration') {
                            // Kalkulasi rasio durasi:benefit (contoh: per 60 detik = Rp 2.000)
                            $calcReward = ($duration / $rate_seconds) * $rate_amount;
                            if ($calcReward < $rate_min_floor) $calcReward = $rate_min_floor;
                            if ($rate_max_cap > 0 && $calcReward > $rate_max_cap) $calcReward = $rate_max_cap;
                            $reward = round($calcReward / 10) * 10; // Rapi kelipatan Rp 10
                            if ($reward < 1) $reward = 1.0;
                        } elseif ($reward_mode === 'random') {
                            $rMin = (int)($reward_min * 100);
                            $rMax = (int)($reward_max * 100);
                            $reward = ($rMin < $rMax) ? (mt_rand($rMin, $rMax) / 100) : $reward_min;
                            $reward = round($reward / 10) * 10;
                            if ($reward < 1) $reward = $reward_min;
                        } elseif ($reward_mode === 'fixed') {
                            $reward = $reward_fixed;
                        } else {
                            $reward = (float)($item['reward_amount'] ?? $item['reward'] ?? $reward_fixed);
                            if ($reward < 1) $reward = $reward_fixed;
                        }

                        if ($sort_order_mode === 'auto') {
                            $maxSort++;
                            $sortOrder = $maxSort;
                        } else {
                            $sortOrder = (int)($item['sort_order'] ?? 0);
                        }

                        // 3. Tentukan Fake Likes Awal Berdasarkan Mode
                        if ($like_mode === 'random') {
                            $fake_likes = ($like_min < $like_max) ? mt_rand($like_min, $like_max) : $like_min;
                        } elseif ($like_mode === 'fixed') {
                            $fake_likes = $like_fixed;
                        } else {
                            $fake_likes = 0;
                        }
                        $total_likes = $fake_likes;

                        // 4. Tentukan Fake Views Awal Berdasarkan Mode
                        if ($view_mode === 'random') {
                            $fake_watches = ($view_min < $view_max) ? mt_rand($view_min, $view_max) : $view_min;
                        } elseif ($view_mode === 'fixed') {
                            $fake_watches = $view_fixed;
                        } else {
                            $fake_watches = 0;
                        }
                        $total_watches = $fake_watches;

                        $insertStmt->execute([$title, $ytId, $reward, $duration, $is_active, $sortOrder, $fake_likes, $total_likes, $fake_watches, $total_watches]);
                        $existingIds[$ytId] = true;
                        $imported++;
                    }

                    $pdo->commit();

                    if ($imported > 0) {
                        $flash = "Berhasil mengimpor {$imported} video baru!" . ($skipped > 0 ? " ({$skipped} video dilewati karena duplikat/invalid)." : "");
                        $flashType = 'success';
                    } else {
                        $flash = "Tidak ada video baru yang diimpor. " . ($skipped > 0 ? "Semua ({$skipped}) video sudah ada di database atau tidak valid." : "");
                        $flashType = 'warning';
                    }
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $flash = 'Gagal menyimpan ke database: ' . $e->getMessage();
                    $flashType = 'error';
                }
            }
        }
    }

    if ($action === 'save_sort') {
        $sort = $_POST['sort_mode'] ?? 'default';
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('video_sort_mode',?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$sort, $sort]);
        $flash = 'Mode sortir video berhasil disimpan.';
    }
}

$videos = $pdo->query(
    "SELECT v.*,
            (SELECT COUNT(*) FROM video_likes vl WHERE vl.video_id = v.id) AS real_likes,
            (SELECT COUNT(*) FROM watch_history wh WHERE wh.video_id = v.id) AS real_watches
     FROM videos v
     ORDER BY sort_order ASC, id DESC"
)->fetchAll();
$currentSort = setting($pdo, 'video_sort_mode', 'default');

$pageTitle  = 'Manajemen Video';
$activePage = 'videos';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h5 class="mb-0 fw-bold">🎬 Manajemen Video</h5>
    <small class="text-secondary"><?= count($videos) ?> video tersimpan di katalog</small>
  </div>
  <div class="d-flex flex-wrap align-items-center gap-2">
    <?php if (!empty($videos)): ?>
    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal">
      🗑️ Hapus Semua Video
    </button>
    <?php endif; ?>
    <button class="btn btn-sm text-dark fw-bold" style="background:#fbbf24;border:none;box-shadow:0 3px 12px rgba(251,191,36,0.3)" data-bs-toggle="modal" data-bs-target="#importModal">
      📥 Impor JSON (YT Scrapper)
    </button>
    <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addModal">
      + Tambah Manual
    </button>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="c-card mb-4">
  <div class="c-card-body py-3">
    <form method="POST" class="d-flex align-items-center gap-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_sort">
      <div style="font-size:13px;font-weight:600;white-space:nowrap">Urutkan Video User:</div>
      <select name="sort_mode" class="c-form-control" style="width:auto;min-width:200px" onchange="this.form.submit()">
        <option value="default" <?= $currentSort==='default'?'selected':'' ?>>Custom (berdasarkan Urutan & ID)</option>
        <option value="newest" <?= $currentSort==='newest'?'selected':'' ?>>Terbaru (ID Terbesar)</option>
        <option value="oldest" <?= $currentSort==='oldest'?'selected':'' ?>>Terlama (ID Terkecil)</option>
        <option value="reward_desc" <?= $currentSort==='reward_desc'?'selected':'' ?>>Reward Terbesar (Rp)</option>
        <option value="reward_asc" <?= $currentSort==='reward_asc'?'selected':'' ?>>Reward Terkecil (Rp)</option>
        <option value="duration_asc" <?= $currentSort==='duration_asc'?'selected':'' ?>>Durasi Tersingkat</option>
        <option value="random" <?= $currentSort==='random'?'selected':'' ?>>Acak (Random)</option>
      </select>
    </form>
  </div>
</div>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>Urutan</th><th>Thumbnail</th><th>Judul</th><th>Reward</th><th>Durasi</th><th>Tayangan / Views</th><th>Likes</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($videos as $v): ?>
      <tr>
        <td>
          <span class="badge bg-secondary" style="font-size:11px"><?= $v['sort_order'] ?></span>
        </td>
        <td><img src="https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/default.jpg" style="width:80px;height:45px;object-fit:cover;border-radius:6px"></td>
        <td style="max-width:200px">
          <div style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($v['title']) ?></div>
          <div style="font-size:11px;color:#666"><?= $v['youtube_id'] ?></div>
        </td>
        <td style="color:#4CAF82;font-weight:700"><?= format_rp((float)$v['reward_amount']) ?></td>
        <td style="color:#888"><?= $v['watch_duration'] ?>s</td>
        <td>
          <div style="font-weight:700;color:#fff;font-size:12.5px;display:flex;align-items:center;gap:4px">
            <i class="ph-bold ph-eye" style="color:#818cf8"></i> <?= number_format((int)$v['total_watches']) ?>
          </div>
          <div style="font-size:10.5px;color:#94a3b8;margin-top:3px;display:flex;gap:4px;flex-wrap:wrap">
            <span class="badge" style="background:rgba(99,102,241,0.15);color:#818cf8;font-weight:700" title="Tontonan asli dari user nyata">
              👤 <?= number_format((int)$v['real_watches']) ?> real
            </span>
            <span class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8;font-weight:700" title="Tayangan fake bawaan">
              🤖 <?= number_format((int)$v['fake_watches']) ?> fake
            </span>
          </div>
        </td>
        <td>
          <div style="font-weight:700;color:#38bdf8;font-size:12.5px;display:flex;align-items:center;gap:4px">
            <i class="ph-bold ph-thumbs-up" style="color:#38bdf8"></i> <?= number_format((int)$v['total_likes']) ?>
          </div>
          <div style="font-size:10.5px;color:#94a3b8;margin-top:3px;display:flex;gap:4px;flex-wrap:wrap">
            <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;font-weight:700" title="Like murni dari pengguna aktif">
              👤 <?= number_format((int)$v['real_likes']) ?> real
            </span>
            <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-weight:700" title="Like fake bawaan">
              🤖 <?= number_format((int)$v['fake_likes']) ?> fake
            </span>
          </div>
        </td>
        <td>
          <form method="POST" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $v['id'] ?>">
            <input type="hidden" name="val" value="<?= $v['is_active']?0:1 ?>">
            <button type="submit" class="badge border-0 <?= $v['is_active']?'b-success':'b-danger' ?>" style="cursor:pointer;border-radius:6px;padding:4px 8px">
              <?= $v['is_active']?'Aktif':'Nonaktif' ?>
            </button>
          </form>
        </td>
        <td>
          <button class="btn btn-sm b-neutral" style="border-radius:8px;font-size:11px;border:none"
            onclick='editVideo(<?= json_encode($v) ?>)'>✏️ Edit</button>
          <form method="POST" class="d-inline" onsubmit="return confirm('Hapus video ini?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>">
            <button class="btn btn-sm b-danger" style="border-radius:8px;font-size:11px;border:none">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($videos)): ?><div style="padding:40px;text-align:center;color:#555">Belum ada video. Tambahkan sekarang!</div><?php endif; ?>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah Video</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <?php include __DIR__ . '/partials/video_form.php'; ?>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Video</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" id="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit Video</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="edit-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update Video</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function editVideo(v) {
  const realLikes   = v.real_likes !== undefined ? Number(v.real_likes) : 0;
  const fakeLikes   = v.fake_likes !== undefined ? Number(v.fake_likes) : Number(v.total_likes || 0);
  const totalLikes  = realLikes + fakeLikes;

  const realWatches  = v.real_watches !== undefined ? Number(v.real_watches) : 0;
  const fakeWatches  = v.fake_watches !== undefined ? Number(v.fake_watches) : Number(v.total_watches || 0);
  const totalWatches = realWatches + fakeWatches;

  document.getElementById('edit-id').value = v.id;
  document.getElementById('edit-body').innerHTML = `
    <div class="c-form-group mb-3"><label class="c-label">Judul Video</label>
      <input type="text" name="title" class="c-form-control" value="${escH(v.title)}" required></div>
    <div class="c-form-group mb-3"><label class="c-label">YouTube URL / ID</label>
      <input type="text" name="youtube_url" class="c-form-control" value="${escH(v.youtube_id)}" required>
      <div style="font-size:11px;color:#666;margin-top:4px">Masukkan URL atau ID YouTube</div></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Reward (Rp)</label>
        <input type="number" name="reward_amount" class="c-form-control" value="${v.reward_amount}" min="1" step="any" required></div>
      <div class="col-6"><label class="c-label">Durasi Min (detik)</label>
        <input type="number" name="watch_duration" class="c-form-control" value="${v.watch_duration}" min="5" required></div>
    </div>
    
    <!-- Edit Fake & Real Views -->
    <div class="p-3 mb-3 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08)">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <label class="c-label mb-0 fw-bold" style="font-size:12px;color:#818cf8">👁 Manajemen Tayangan (Views)</label>
        <span class="badge" style="background:rgba(99,102,241,0.15);color:#818cf8;font-size:11px">
          Total Tampil: ${totalWatches.toLocaleString('id-ID')}
        </span>
      </div>
      <div class="row g-2 align-items-center">
        <div class="col-6">
          <label class="c-label" style="font-size:11px">Fake Views (Bisa Diedit)</label>
          <input type="number" name="fake_watches" class="c-form-control form-control-sm" value="${fakeWatches}" min="0">
        </div>
        <div class="col-6">
          <label class="c-label" style="font-size:11px">Real Views (User Asli)</label>
          <div class="form-control form-control-sm text-light fw-bold" style="background:rgba(99,102,241,0.1);border-color:rgba(99,102,241,0.3)">
            👤 ${realWatches.toLocaleString('id-ID')}x Ditonton
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Fake & Real Likes -->
    <div class="p-3 mb-3 rounded" style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08)">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <label class="c-label mb-0 fw-bold" style="font-size:12px;color:#fbbf24">👍 Manajemen Like Video</label>
        <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:11px">
          Total Tampil: ${totalLikes.toLocaleString('id-ID')}
        </span>
      </div>
      <div class="row g-2 align-items-center">
        <div class="col-6">
          <label class="c-label" style="font-size:11px">Fake Likes (Bisa Diedit)</label>
          <input type="number" name="fake_likes" class="c-form-control form-control-sm" value="${fakeLikes}" min="0">
        </div>
        <div class="col-6">
          <label class="c-label" style="font-size:11px">Real Likes (User Asli)</label>
          <div class="form-control form-control-sm text-success fw-bold" style="background:rgba(16,185,129,0.1);border-color:rgba(16,185,129,0.3)">
            👤 ${realLikes.toLocaleString('id-ID')} Like Asli
          </div>
        </div>
      </div>
    </div>

    <div class="row g-2">
      <div class="col-6"><label class="c-label">Urutan</label>
        <input type="number" name="sort_order" class="c-form-control" value="${v.sort_order}"></div>
      <div class="col-6 d-flex align-items-end pb-1">
        <div class="form-check ms-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="ea" ${v.is_active==1?'checked':''}>
          <label class="form-check-label text-secondary" for="ea" style="font-size:13px">Aktif</label>
        </div>
      </div>
    </div>`;
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<!-- Import JSON Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="background:#161922;border:1px solid #2d3149;box-shadow:0 15px 40px rgba(0,0,0,0.6)">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_json">
        
        <div class="modal-header border-0 pb-0 d-flex justify-content-between align-items-center">
          <div>
            <h5 class="modal-title fw-bold text-warning d-flex align-items-center gap-2">
              <span>📥</span> Impor Video via JSON (MancingCuan Scrapper)
            </h5>
            <div style="font-size:12px;color:#94a3b8;margin-top:2px">
              Salin data JSON dari ekstensi <strong>MancingCuan YT Scrapper</strong> di browser, lalu tempelkan langsung di bawah ini.
            </div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body pt-3">
          <!-- Textarea JSON -->
          <div class="c-form-group mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <label class="c-label mb-0 fw-bold">Data JSON Video <span class="text-danger">*</span></label>
              <span id="json_count_badge" class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8;font-size:11px">
                Menunggu input JSON...
              </span>
            </div>
            <textarea name="json_data" id="json_data_input" class="c-form-control font-monospace" rows="6" 
              placeholder='Tempelkan (Ctrl+V) JSON hasil copy dari ekstensi di sini... Contoh: [{"title":"...","youtube_id":"..."},...]' 
              style="font-size:12px;line-height:1.4;background:#0d1017;border-color:#2a2e42;color:#e2e8f0;"></textarea>
            
            <div class="d-flex justify-content-between align-items-center mt-2">
              <div style="font-size:11px;color:#64748b">
                Format didukung: <code>[{"youtube_id":"...","title":"..."},...]</code>
              </div>
              <label class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;cursor:pointer">
                📁 Atau Upload File .json
                <input type="file" id="json_file_input" name="json_file" accept=".json,application/json" style="display:none">
              </label>
            </div>
          </div>

          <!-- Variable Settings Grid -->
          <div class="p-3 mb-3 rounded" style="background:rgba(20,24,36,0.8);border:1px solid rgba(251,191,36,0.2)">
            <div class="fw-bold text-warning mb-2 d-flex align-items-center gap-2" style="font-size:13px">
              <span>⚙️</span> Pengaturan Variabel Otomatis (Benefit & Durasi)
            </div>

            <div class="row g-3">
              <!-- Reward Setting -->
              <div class="col-md-6">
                <label class="c-label fw-bold" style="font-size:12px">Benefit / Reward Pengguna (Rp)</label>
                <div class="d-flex flex-wrap gap-2 mb-2">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="reward_mode" id="rm_rate" value="rate_duration" checked onchange="toggleRewardInputs()">
                    <label class="form-check-label text-warning fw-bold" for="rm_rate" style="font-size:12px">⚡ Rasio Durasi</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="reward_mode" id="rm_random" value="random" onchange="toggleRewardInputs()">
                    <label class="form-check-label text-secondary" for="rm_random" style="font-size:12px">Acak Range</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="reward_mode" id="rm_fixed" value="fixed" onchange="toggleRewardInputs()">
                    <label class="form-check-label text-secondary" for="rm_fixed" style="font-size:12px">Tetap (Fixed)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="reward_mode" id="rm_json" value="json" onchange="toggleRewardInputs()">
                    <label class="form-check-label text-secondary" for="rm_json" style="font-size:12px">Dari JSON</label>
                  </div>
                </div>

                <!-- Input Rasio Durasi (contoh per 1 menit = Rp 2.000) -->
                <div id="reward_rate_wrap" style="display:block;background:rgba(251,191,36,0.06);border:1px solid rgba(251,191,36,0.25);border-radius:10px;padding:10px;">
                  <div class="row g-2 mb-2">
                    <div class="col-6">
                      <div style="font-size:11px;color:#fbbf24;font-weight:700">Benefit Reward (Rp)</div>
                      <input type="number" name="rate_amount" id="rate_amount" class="c-form-control form-control-sm" value="2000" min="1" step="any" oninput="updateRateFormulaHint()">
                    </div>
                    <div class="col-6">
                      <div style="font-size:11px;color:#fbbf24;font-weight:700">Per Berapa Detik</div>
                      <div class="input-group input-group-sm">
                        <input type="number" name="rate_seconds" id="rate_seconds" class="c-form-control form-control-sm" value="60" min="1" oninput="updateRateFormulaHint()">
                        <span class="input-group-text" style="background:#202534;border-color:#2a2e42;color:#94a3b8;font-size:11px">dtk</span>
                      </div>
                    </div>
                  </div>
                  <div class="row g-2">
                    <div class="col-6">
                      <div style="font-size:10.5px;color:#888">Batas Min (Floor Rp)</div>
                      <input type="number" name="rate_min_floor" class="c-form-control form-control-sm" value="100" min="1" oninput="updateRateFormulaHint()">
                    </div>
                    <div class="col-6">
                      <div style="font-size:10.5px;color:#888">Batas Max (0 = Bebas)</div>
                      <input type="number" name="rate_max_cap" class="c-form-control form-control-sm" value="10000" min="0" oninput="updateRateFormulaHint()">
                    </div>
                  </div>
                  <div id="rate_formula_hint" style="font-size:11px;color:#38bdf8;margin-top:6px;line-height:1.35">
                    💡 <strong>Rasio Aktif:</strong> Rp 2.000 per 1 menit (60 detik). Video 30s = Rp 1.000 | 60s = Rp 2.000 | 2 menit = Rp 4.000.
                  </div>
                </div>

                <div id="reward_range_wrap" class="row g-2" style="display:none">
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Min Reward (Rp)</div>
                    <input type="number" name="reward_min" class="c-form-control form-control-sm" value="50" min="1" step="any" oninput="triggerJsonPreviewUpdate()">
                  </div>
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Max Reward (Rp)</div>
                    <input type="number" name="reward_max" class="c-form-control form-control-sm" value="200" min="1" step="any" oninput="triggerJsonPreviewUpdate()">
                  </div>
                </div>

                <div id="reward_fixed_wrap" style="display:none">
                  <div style="font-size:11px;color:#888">Nominal Reward Tetap (Rp)</div>
                  <input type="number" name="reward_fixed" class="c-form-control form-control-sm" value="100" min="1" step="any" oninput="triggerJsonPreviewUpdate()">
                </div>
              </div>

              <!-- Duration Setting -->
              <div class="col-md-6">
                <label class="c-label fw-bold" style="font-size:12px">Durasi Tonton Minimal (Detik)</label>
                <div class="d-flex gap-2 mb-2">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration_mode" id="dm_random" value="random" checked onchange="toggleDurationInputs()">
                    <label class="form-check-label text-secondary" for="dm_random" style="font-size:12px">Acak Range</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration_mode" id="dm_fixed" value="fixed" onchange="toggleDurationInputs()">
                    <label class="form-check-label text-secondary" for="dm_fixed" style="font-size:12px">Tetap (Fixed)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="duration_mode" id="dm_json" value="json" onchange="toggleDurationInputs()">
                    <label class="form-check-label text-secondary" for="dm_json" style="font-size:12px">Dari JSON</label>
                  </div>
                </div>

                <div id="duration_range_wrap" class="row g-2">
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Min Durasi (detik)</div>
                    <input type="number" name="duration_min" class="c-form-control form-control-sm" value="15" min="5">
                  </div>
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Max Durasi (detik)</div>
                    <input type="number" name="duration_max" class="c-form-control form-control-sm" value="60" min="5">
                  </div>
                </div>

                <div id="duration_fixed_wrap" style="display:none">
                  <div style="font-size:11px;color:#888">Durasi Tetap (detik)</div>
                  <input type="number" name="duration_fixed" class="c-form-control form-control-sm" value="30" min="5">
                </div>
              </div>

              <!-- Fake Likes Setting -->
              <div class="col-12 mt-3 pt-2" style="border-top:1px dashed rgba(255,255,255,0.08)">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="c-label fw-bold mb-0" style="font-size:12px;color:#38bdf8">👍 Pengaturan Like Fake Awal (Initial/Bot Likes)</label>
                  <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:10.5px">Otomatis Tiap Video</span>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-2">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="like_mode" id="lm_random" value="random" checked onchange="toggleLikeInputs()">
                    <label class="form-check-label text-info fw-bold" for="lm_random" style="font-size:12px">🎲 Acak Range (Min - Max)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="like_mode" id="lm_fixed" value="fixed" onchange="toggleLikeInputs()">
                    <label class="form-check-label text-secondary" for="lm_fixed" style="font-size:12px">📌 Tetap (Fixed)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="like_mode" id="lm_none" value="none" onchange="toggleLikeInputs()">
                    <label class="form-check-label text-secondary" for="lm_none" style="font-size:12px">❌ Tanpa Like (0)</label>
                  </div>
                </div>

                <div id="like_range_wrap" class="row g-2">
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Min Like Fake</div>
                    <input type="number" name="like_min" id="like_min" class="c-form-control form-control-sm" value="50" min="0" oninput="triggerJsonPreviewUpdate()">
                  </div>
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Max Like Fake</div>
                    <input type="number" name="like_max" id="like_max" class="c-form-control form-control-sm" value="350" min="0" oninput="triggerJsonPreviewUpdate()">
                  </div>
                  <div class="col-12" style="font-size:10.5px;color:#64748b">
                    Setiap video yang diimpor akan mendapatkan like awal acak dalam rentang ini. Real like dari user akan otomatis bertambah di atas nilai ini.
                  </div>
                </div>

                <div id="like_fixed_wrap" style="display:none">
                  <div style="font-size:11px;color:#888">Jumlah Like Tetap per Video</div>
                  <input type="number" name="like_fixed" id="like_fixed" class="c-form-control form-control-sm" value="100" min="0" oninput="triggerJsonPreviewUpdate()">
                </div>
              </div>

              <!-- Fake Views Setting -->
              <div class="col-12 mt-3 pt-2" style="border-top:1px dashed rgba(255,255,255,0.08)">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="c-label fw-bold mb-0" style="font-size:12px;color:#818cf8">👁 Pengaturan View / Tayangan Fake Awal</label>
                  <span class="badge" style="background:rgba(99,102,241,0.15);color:#818cf8;font-size:10.5px">Otomatis Tiap Video</span>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-2">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="view_mode" id="vm_random" value="random" checked onchange="toggleViewInputs()">
                    <label class="form-check-label text-primary fw-bold" for="vm_random" style="font-size:12px">🎲 Acak Range (Min - Max)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="view_mode" id="vm_fixed" value="fixed" onchange="toggleViewInputs()">
                    <label class="form-check-label text-secondary" for="vm_fixed" style="font-size:12px">📌 Tetap (Fixed)</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="view_mode" id="vm_none" value="none" onchange="toggleViewInputs()">
                    <label class="form-check-label text-secondary" for="vm_none" style="font-size:12px">❌ Tanpa View (0)</label>
                  </div>
                </div>

                <div id="view_range_wrap" class="row g-2">
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Min View Fake</div>
                    <input type="number" name="view_min" id="view_min" class="c-form-control form-control-sm" value="150" min="0" oninput="triggerJsonPreviewUpdate()">
                  </div>
                  <div class="col-6">
                    <div style="font-size:11px;color:#888">Max View Fake</div>
                    <input type="number" name="view_max" id="view_max" class="c-form-control form-control-sm" value="1200" min="0" oninput="triggerJsonPreviewUpdate()">
                  </div>
                  <div class="col-12" style="font-size:10.5px;color:#64748b">
                    Setiap video yang diimpor akan mendapatkan jumlah tontonan awal acak dalam rentang ini. Real tontonan dari user akan otomatis ditambahkan ke angka ini.
                  </div>
                </div>

                <div id="view_fixed_wrap" style="display:none">
                  <div style="font-size:11px;color:#888">Jumlah View Tetap per Video</div>
                  <input type="number" name="view_fixed" id="view_fixed" class="c-form-control form-control-sm" value="500" min="0" oninput="triggerJsonPreviewUpdate()">
                </div>
              </div>
            </div>

            <!-- Extra Options -->
            <hr style="border-color:rgba(255,255,255,0.08);margin:12px 0 10px">
            <div class="row g-2">
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="skip_duplicate" id="skip_duplicate" value="1" checked>
                  <label class="form-check-label text-light" for="skip_duplicate" style="font-size:12px">
                    Cegah Duplikat (Lewati jika ID video sudah ada di database)
                  </label>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="is_active" id="import_is_active" value="1" checked>
                  <label class="form-check-label text-light" for="import_is_active" style="font-size:12px">
                    Langsung Aktifkan Semua Video yang Diimpor
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Live Sample Preview -->
          <div id="json_preview_box" style="display:none;background:#0d1017;border:1px solid #232738;border-radius:10px;padding:10px;">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div style="font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase">Contoh Data Terdeteksi (3 Teratas)</div>
              <span id="preview_total_count" class="badge bg-primary" style="font-size:10px"></span>
            </div>
            <div id="preview_items_list" style="display:flex;flex-direction:column;gap:6px"></div>
          </div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" id="btn_submit_import" class="btn btn-sm text-dark fw-bold" style="background:#fbbf24;box-shadow:0 3px 12px rgba(251,191,36,0.3)" disabled>
            🚀 Mulai Impor Video
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete All Videos Modal -->
<div class="modal fade" id="deleteAllModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="background:#1a1d27;border:1.5px solid #ef4444;box-shadow:0 15px 40px rgba(239,68,68,0.25)">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_all">

        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold text-danger d-flex align-items-center gap-2">
            <span>⚠️</span> Hapus Semua Video
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="alert alert-danger py-2 mb-3" style="font-size:13px;border-radius:10px">
            <strong>PERINGATAN:</strong> Anda akan menghapus permanen seluruh <strong><?= count($videos) ?> video</strong> yang ada di database. Aksi ini tidak dapat dibatalkan!
          </div>

          <div class="c-form-group mb-3">
            <label class="c-label text-warning mb-1" style="font-size:12.5px;font-weight:700">
              Ketik kata <span style="background:rgba(239,68,68,0.2);color:#ef4444;padding:2px 6px;border-radius:4px">HAPUS</span> untuk konfirmasi:
            </label>
            <input type="text" id="confirm_delete_input" name="confirm_delete" class="c-form-control text-uppercase" placeholder="Ketik HAPUS di sini..." autocomplete="off" required>
          </div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" id="btn_confirm_delete" class="btn btn-sm btn-danger fw-bold" disabled>
            🗑️ Ya, Hapus Semua Video Permanen
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// JSON Import & Variable Toggles
function toggleRewardInputs() {
  const mode = document.querySelector('input[name="reward_mode"]:checked')?.value || 'rate_duration';
  document.getElementById('reward_rate_wrap').style.display = (mode === 'rate_duration') ? 'block' : 'none';
  document.getElementById('reward_range_wrap').style.display = (mode === 'random') ? 'flex' : 'none';
  document.getElementById('reward_fixed_wrap').style.display = (mode === 'fixed') ? 'block' : 'none';
  if (mode === 'rate_duration') updateRateFormulaHint();
  triggerJsonPreviewUpdate();
}

function updateRateFormulaHint() {
  const amt = parseFloat(document.getElementById('rate_amount')?.value) || 2000;
  const sec = parseInt(document.getElementById('rate_seconds')?.value, 10) || 60;
  const minFloor = parseFloat(document.querySelector('input[name="rate_min_floor"]')?.value) || 100;
  const perSec = (amt / sec).toFixed(1);
  const minText = (sec === 60) ? '1 menit' : (sec + ' dtk');
  const ex30 = Math.max(minFloor, Math.round((30 / sec) * amt / 10) * 10);
  const ex60 = Math.max(minFloor, Math.round((60 / sec) * amt / 10) * 10);
  const ex120 = Math.max(minFloor, Math.round((120 / sec) * amt / 10) * 10);
  const hintEl = document.getElementById('rate_formula_hint');
  if (hintEl) {
    hintEl.innerHTML = `💡 <strong>Rasio Aktif:</strong> Rp ${amt.toLocaleString('id-ID')} per ${minText} (±Rp ${perSec}/detik).<br>Contoh Hasil: Video 30s = <strong>Rp ${ex30.toLocaleString('id-ID')}</strong> | 60s = <strong>Rp ${ex60.toLocaleString('id-ID')}</strong> | 2 menit = <strong>Rp ${ex120.toLocaleString('id-ID')}</strong>`;
  }
  triggerJsonPreviewUpdate();
}

function toggleDurationInputs() {
  const mode = document.querySelector('input[name="duration_mode"]:checked')?.value || 'random';
  document.getElementById('duration_range_wrap').style.display = (mode === 'random') ? 'flex' : 'none';
  document.getElementById('duration_fixed_wrap').style.display = (mode === 'fixed') ? 'block' : 'none';
  triggerJsonPreviewUpdate();
}

function toggleLikeInputs() {
  const mode = document.querySelector('input[name="like_mode"]:checked')?.value || 'random';
  const rWrap = document.getElementById('like_range_wrap');
  const fWrap = document.getElementById('like_fixed_wrap');
  if (rWrap) rWrap.style.display = (mode === 'random') ? 'flex' : 'none';
  if (fWrap) fWrap.style.display = (mode === 'fixed') ? 'block' : 'none';
  triggerJsonPreviewUpdate();
}

function toggleViewInputs() {
  const mode = document.querySelector('input[name="view_mode"]:checked')?.value || 'random';
  const rWrap = document.getElementById('view_range_wrap');
  const fWrap = document.getElementById('view_fixed_wrap');
  if (rWrap) rWrap.style.display = (mode === 'random') ? 'flex' : 'none';
  if (fWrap) fWrap.style.display = (mode === 'fixed') ? 'block' : 'none';
  triggerJsonPreviewUpdate();
}

const jsonInput = document.getElementById('json_data_input');
const jsonFileInput = document.getElementById('json_file_input');
const countBadge = document.getElementById('json_count_badge');
const submitBtn = document.getElementById('btn_submit_import');
const previewBox = document.getElementById('json_preview_box');
const previewList = document.getElementById('preview_items_list');
const previewTotal = document.getElementById('preview_total_count');

function triggerJsonPreviewUpdate() {
  if (jsonInput && jsonInput.value) {
    validateAndPreviewJson(jsonInput.value);
  }
}

function validateAndPreviewJson(str) {
  if (!str || !str.trim()) {
    countBadge.className = 'badge';
    countBadge.style.background = 'rgba(255,255,255,0.06)';
    countBadge.style.color = '#94a3b8';
    countBadge.textContent = 'Menunggu input JSON...';
    submitBtn.disabled = true;
    previewBox.style.display = 'none';
    return;
  }

  try {
    let parsed = JSON.parse(str);
    if (!Array.isArray(parsed)) {
      if (parsed.videos && Array.isArray(parsed.videos)) parsed = parsed.videos;
      else if (parsed.data && Array.isArray(parsed.data)) parsed = parsed.data;
      else throw new Error("JSON harus berupa array");
    }

    const validItems = parsed.filter(item => {
      if (!item || typeof item !== 'object') return false;
      const yt = item.youtube_id || item.youtubeId || item.id || item.url;
      return !!yt;
    });

    if (validItems.length > 0) {
      countBadge.className = 'badge bg-success';
      countBadge.style.color = '#fff';
      countBadge.textContent = `✅ ${validItems.length} video valid terdeteksi`;
      submitBtn.disabled = false;

      // Render 3 sample preview
      previewBox.style.display = 'block';
      previewTotal.textContent = `Total: ${validItems.length} item`;
      previewList.innerHTML = '';
      
      const rMode = document.querySelector('input[name="reward_mode"]:checked')?.value || 'rate_duration';
      const dMode = document.querySelector('input[name="duration_mode"]:checked')?.value || 'random';

      validItems.slice(0, 3).forEach(v => {
        const id = v.youtube_id || v.youtubeId || v.id || '-';
        const title = v.title || 'Tanpa Judul';

        // Calculate sample duration
        let dur = v.watch_duration || v.duration || 30;
        if (dMode === 'fixed') {
          dur = parseInt(document.querySelector('input[name="duration_fixed"]')?.value, 10) || 30;
        } else if (dMode === 'random') {
          const dmin = parseInt(document.querySelector('input[name="duration_min"]')?.value, 10) || 15;
          const dmax = parseInt(document.querySelector('input[name="duration_max"]')?.value, 10) || 60;
          dur = Math.round((dmin + dmax) / 2);
        }

        // Calculate sample reward
        let estReward = 100;
        if (rMode === 'rate_duration') {
          const amt = parseFloat(document.getElementById('rate_amount')?.value) || 2000;
          const sec = parseInt(document.getElementById('rate_seconds')?.value, 10) || 60;
          const minFloor = parseFloat(document.querySelector('input[name="rate_min_floor"]')?.value) || 100;
          const maxCap = parseFloat(document.querySelector('input[name="rate_max_cap"]')?.value) || 0;
          let calc = Math.round((dur / sec) * amt / 10) * 10;
          if (calc < minFloor) calc = minFloor;
          if (maxCap > 0 && calc > maxCap) calc = maxCap;
          estReward = `Rp ${calc.toLocaleString('id-ID')}`;
        } else if (rMode === 'fixed') {
          const fixVal = parseFloat(document.querySelector('input[name="reward_fixed"]')?.value) || 100;
          estReward = `Rp ${fixVal.toLocaleString('id-ID')}`;
        } else if (rMode === 'json') {
          const jVal = parseFloat(v.reward_amount || v.reward || 100);
          estReward = `Rp ${jVal.toLocaleString('id-ID')}`;
        } else {
          const rmin = parseFloat(document.querySelector('input[name="reward_min"]')?.value) || 50;
          const rmax = parseFloat(document.querySelector('input[name="reward_max"]')?.value) || 200;
          estReward = `Rp ${rmin} - ${rmax}`;
        }

        // Calculate sample likes & views
        const lMode = document.querySelector('input[name="like_mode"]:checked')?.value || 'random';
        let estLikes = '0';
        if (lMode === 'random') {
          const lmin = parseInt(document.getElementById('like_min')?.value, 10) || 50;
          const lmax = parseInt(document.getElementById('like_max')?.value, 10) || 350;
          estLikes = `👍 ~${Math.round((lmin + lmax) / 2)}`;
        } else if (lMode === 'fixed') {
          const lfix = parseInt(document.getElementById('like_fixed')?.value, 10) || 100;
          estLikes = `👍 ${lfix}`;
        } else {
          estLikes = `👍 0`;
        }

        const vMode = document.querySelector('input[name="view_mode"]:checked')?.value || 'random';
        let estViews = '0';
        if (vMode === 'random') {
          const vmin = parseInt(document.getElementById('view_min')?.value, 10) || 150;
          const vmax = parseInt(document.getElementById('view_max')?.value, 10) || 1200;
          estViews = `👁 ~${Math.round((vmin + vmax) / 2)}`;
        } else if (vMode === 'fixed') {
          const vfix = parseInt(document.getElementById('view_fixed')?.value, 10) || 500;
          estViews = `👁 ${vfix}`;
        } else {
          estViews = `👁 0`;
        }

        const itemRow = document.createElement('div');
        itemRow.style.display = 'flex';
        itemRow.style.alignItems = 'center';
        itemRow.style.gap = '8px';
        itemRow.style.fontSize = '11px';
        itemRow.style.padding = '6px 8px';
        itemRow.style.borderRadius = '6px';
        itemRow.style.background = '#151926';

        itemRow.innerHTML = `
          <img src="https://img.youtube.com/vi/${id}/default.jpg" style="width:40px;height:24px;object-fit:cover;border-radius:4px" onerror="this.style.display='none'">
          <div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#e2e8f0;font-weight:600">
            ${escH(title)}
          </div>
          <span class="badge" style="background:rgba(99,102,241,0.15);color:#818cf8;font-size:10px">${estViews}</span>
          <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:10px">${estLikes}</span>
          <span class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8;font-size:10px">${dur}s</span>
          <span class="badge" style="background:rgba(245,158,11,0.2);color:#fbbf24;font-size:10px;font-weight:700">${estReward}</span>
        `;
        previewList.appendChild(itemRow);
      });
    } else {
      countBadge.className = 'badge bg-warning text-dark';
      countBadge.textContent = '⚠️ Tidak ditemukan item video yang valid';
      submitBtn.disabled = true;
      previewBox.style.display = 'none';
    }
  } catch (err) {
    countBadge.className = 'badge bg-danger';
    countBadge.style.color = '#fff';
    countBadge.textContent = '❌ Format JSON belum valid';
    submitBtn.disabled = true;
    previewBox.style.display = 'none';
  }
}

if (jsonInput) {
  jsonInput.addEventListener('input', (e) => {
    validateAndPreviewJson(e.target.value);
  });
}

if (jsonFileInput) {
  jsonFileInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
      const reader = new FileReader();
      reader.onload = (event) => {
        jsonInput.value = event.target.result;
        validateAndPreviewJson(event.target.result);
      };
      reader.readAsText(file);
    }
  });
}

// Delete All Safety Confirmation
const confirmDelInput = document.getElementById('confirm_delete_input');
const btnConfirmDel = document.getElementById('btn_confirm_delete');
if (confirmDelInput && btnConfirmDel) {
  confirmDelInput.addEventListener('input', (e) => {
    btnConfirmDel.disabled = (e.target.value.trim().toUpperCase() !== 'HAPUS');
  });
}

// Initialize on page ready
document.addEventListener('DOMContentLoaded', () => {
  toggleRewardInputs();
  toggleDurationInputs();
  toggleLikeInputs();
  toggleViewInputs();
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
