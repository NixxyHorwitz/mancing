<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

$pageTitle  = 'Log Misi User';
$activePage = 'missions';

// ── Filters ───────────────────────────────────────────────────
$filter_cat  = $_GET['cat']    ?? '';   // daily | weekly | lifetime
$filter_slug = $_GET['slug']   ?? '';
$filter_user = trim($_GET['user'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 50;
$offset      = ($page - 1) * $per_page;

// ── Build query ───────────────────────────────────────────────
$where  = ['um.claimed_at IS NOT NULL'];
$params = [];

// ── Daftar misi dari database (tabel `missions`, sama dengan user/missions.php) ──
$CAT_LABEL = ['daily' => 'Harian', 'weekly' => 'Mingguan', 'lifetime' => 'Pencapaian'];
$REWARD_MAP = [];
$SLUGS_BY_CAT = ['daily' => [], 'weekly' => [], 'lifetime' => []];
foreach ($pdo->query("SELECT * FROM missions ORDER BY is_active DESC, FIELD(category, 'daily', 'weekly', 'lifetime'), sort_order, id") as $mr) {
    $REWARD_MAP[$mr['slug']] = [
        'label'  => $mr['title'] . ((int)$mr['is_active'] ? '' : ' (nonaktif)'),
        'cat'    => $CAT_LABEL[$mr['category']] ?? 'Lainnya',
        'reward' => (float)$mr['reward_amount'],
        'icon'   => 'ph-bold ' . ($mr['icon'] ?: 'ph-target'),
    ];
    $SLUGS_BY_CAT[$mr['category']][] = $mr['slug'];
}

if ($filter_cat) {
    $cat_slugs = $SLUGS_BY_CAT[$filter_cat] ?? [];
    if ($cat_slugs) {
        $in = implode(',', array_fill(0, count($cat_slugs), '?'));
        $where[] = "um.mission_slug IN ($in)";
        $params  = array_merge($params, $cat_slugs);
    }
}

if ($filter_slug) {
    $where[]  = 'um.mission_slug = ?';
    $params[] = $filter_slug;
}

if ($filter_user) {
    $where[]  = '(u.username LIKE ? OR u.id = ?)';
    $params[] = '%' . $filter_user . '%';
    $params[] = (int)$filter_user;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

// Count matching records
$cnt_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM user_missions um
     LEFT JOIN users u ON u.id = um.user_id
     $where_sql"
);
$cnt_stmt->execute($params);
$total_records = (int)$cnt_stmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));

// Fetch rows
$stmt = $pdo->prepare(
    "SELECT um.*, u.username, u.id as uid
     FROM user_missions um
     LEFT JOIN users u ON u.id = um.user_id
     $where_sql
     ORDER BY um.claimed_at DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Summary stats ─────────────────────────────────────────────
$total_reward_given = 0;
foreach ($rows as $r) {
    $total_reward_given += $REWARD_MAP[$r['mission_slug']]['reward'] ?? 0;
}

// All-time totals
try {
    $all_total_claims = (int)$pdo->query("SELECT COUNT(*) FROM user_missions WHERE claimed_at IS NOT NULL")->fetchColumn();
    $slug_top = $pdo->query("SELECT mission_slug, COUNT(*) as cnt FROM user_missions WHERE claimed_at IS NOT NULL GROUP BY mission_slug ORDER BY cnt DESC LIMIT 1")->fetch();
} catch (\Throwable) {
    $all_total_claims = 0;
    $slug_top = null;
}

require __DIR__ . '/partials/header.php';
?>

<style>
/* ── Missions Console Styling ── */
.m-badge-cat {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 700;
  padding: 4px 10px;
  border-radius: 20px;
  line-height: 1.2;
}
.m-badge-cat.harian {
  background: rgba(16, 185, 129, 0.12);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.25);
}
.m-badge-cat.mingguan {
  background: rgba(245, 158, 11, 0.12);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.25);
}
.m-badge-cat.pencapaian {
  background: rgba(139, 92, 246, 0.12);
  color: #a78bfa;
  border: 1px solid rgba(139, 92, 246, 0.25);
}
.m-badge-cat.other {
  background: rgba(148, 163, 184, 0.12);
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.25);
}

.m-user-chip {
  display: flex;
  align-items: center;
  gap: 10px;
}
.m-user-avatar {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(217,119,6,0.15));
  border: 1px solid rgba(245,158,11,0.3);
  color: #fbbf24;
  font-weight: 800;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.m-mission-chip {
  display: flex;
  align-items: center;
  gap: 10px;
}
.m-mission-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: #fbbf24;
  flex-shrink: 0;
}
.m-reward-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12.5px;
  font-weight: 800;
  color: #34d399;
  background: rgba(52, 211, 153, 0.1);
  border: 1px solid rgba(52, 211, 153, 0.2);
  padding: 4px 10px;
  border-radius: 8px;
}
.m-period-tag {
  font-size: 11px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.07);
  padding: 3px 8px;
  border-radius: 6px;
  color: #94a3b8;
}
.stat-glow-card {
  position: relative;
  overflow: hidden;
  border-radius: 16px;
  border: 1px solid var(--border-color);
  background: var(--card-bg);
  padding: 18px 20px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.25);
  transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.stat-glow-card:hover {
  transform: translateY(-2px);
  border-color: rgba(245,158,11,0.4);
  box-shadow: 0 8px 28px rgba(0,0,0,0.35);
}
.stat-glow-card::before {
  content: "";
  position: absolute;
  top: -30px;
  right: -30px;
  width: 90px;
  height: 90px;
  border-radius: 50%;
  filter: blur(35px);
  opacity: 0.15;
  pointer-events: none;
}
.stat-glow-card.glow-amber::before { background: #f59e0b; }
.stat-glow-card.glow-emerald::before { background: #10b981; }
.stat-glow-card.glow-purple::before { background: #8b5cf6; }
.stat-glow-card.glow-cyan::before { background: #06b6d4; }

.stat-icon-wrapper {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.filter-box-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 18px 20px;
  margin-bottom: 20px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}
</style>

<div class="c-content">

  <!-- Header Title Bar -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.3);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:18px;">
          <i class="ph-bold ph-target"></i>
        </div>
        <h4 class="mb-0 fw-bold text-white" style="letter-spacing:-0.3px;">Log Klaim Misi User</h4>
        <span class="badge bg-dark border border-secondary text-muted px-2 py-1" style="font-size:11px;">v2.0</span>
      </div>
      <p class="text-secondary mb-0" style="font-size:13px;">Pantau riwayat misi yang telah diselesaikan serta total reward saldo yang diklaim oleh pengguna.</p>
    </div>
    
    <div class="d-flex align-items-center gap-2">
      <a href="/console/missions.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" style="border-radius:10px;padding:8px 14px;font-weight:600;">
        <i class="ph-bold ph-arrow-counter-clockwise"></i> Refresh
      </a>
      <a href="/user/missions.php" target="_blank" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1" style="border-radius:10px;padding:8px 14px;font-weight:600;">
        <i class="ph-bold ph-arrow-square-out"></i> Preview Halaman User
      </a>
    </div>
  </div>

  <!-- Stats Row -->
  <div class="row g-3 mb-4">
    <!-- Stat 1: Total Klaim Sepanjang Masa -->
    <div class="col-xl-3 col-md-6">
      <div class="stat-glow-card glow-amber">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon-wrapper" style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);">
            <i class="ph-bold ph-seal-check"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">Total Klaim All-Time</div>
            <div class="text-white fw-bold" style="font-size:22px;line-height:1.2;margin-top:2px;">
              <?= number_format($all_total_claims) ?> <span style="font-size:12px;font-weight:500;color:#94a3b8;">klaim</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Stat 2: Misi Terpopuler -->
    <div class="col-xl-3 col-md-6">
      <div class="stat-glow-card glow-purple">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon-wrapper" style="background:rgba(139,92,246,0.15);color:#a78bfa;border:1px solid rgba(139,92,246,0.3);">
            <i class="ph-bold ph-flame"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">Misi Terpopuler</div>
            <div class="text-white fw-bold text-truncate" style="font-size:15px;line-height:1.3;margin-top:4px;" title="<?= htmlspecialchars($REWARD_MAP[$slug_top['mission_slug'] ?? '']['label'] ?? '-') ?>">
              <?= htmlspecialchars($REWARD_MAP[$slug_top['mission_slug'] ?? '']['label'] ?? '-') ?>
            </div>
            <div style="font-size:11px;color:#94a3b8;">
              <?= $slug_top ? number_format((int)$slug_top['cnt']) . 'x klaim' : '0 klaim' ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Stat 3: Reward Halaman Ini -->
    <div class="col-xl-3 col-md-6">
      <div class="stat-glow-card glow-emerald">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon-wrapper" style="background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3);">
            <i class="ph-bold ph-coins"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">Reward Halaman Ini</div>
            <div class="fw-bold" style="font-size:20px;line-height:1.2;margin-top:2px;color:#34d399;">
              Rp <?= number_format($total_reward_given, 0, ',', '.') ?>
            </div>
            <div style="font-size:11px;color:#94a3b8;">dari <?= count($rows) ?> baris data</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Stat 4: Filter Aktif / Total Matching -->
    <div class="col-xl-3 col-md-6">
      <div class="stat-glow-card glow-cyan">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon-wrapper" style="background:rgba(6,182,212,0.15);color:#06b6d4;border:1px solid rgba(6,182,212,0.3);">
            <i class="ph-bold ph-funnel"></i>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">Data Ditemukan</div>
            <div class="text-white fw-bold" style="font-size:22px;line-height:1.2;margin-top:2px;">
              <?= number_format($total_records) ?> <span style="font-size:12px;font-weight:500;color:#94a3b8;">klaim</span>
            </div>
            <div style="font-size:11px;color:#94a3b8;">Hal <?= $page ?> dari <?= $total_pages ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter Toolbar -->
  <div class="filter-box-card">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div class="d-flex align-items-center gap-2">
        <i class="ph-bold ph-sliders-horizontal text-warning" style="font-size:16px;"></i>
        <span class="fw-bold text-white" style="font-size:13.5px;">Filter Pencarian Klaim</span>
      </div>
      <?php if ($filter_cat || $filter_slug || $filter_user): ?>
      <span class="badge bg-warning text-dark px-2 py-1" style="font-size:10px;font-weight:800;">Filter Aktif</span>
      <?php endif; ?>
    </div>

    <form method="GET" class="row g-2 align-items-end">
      <!-- Filter Kategori -->
      <div class="col-lg-3 col-md-4 col-sm-6">
        <label class="c-label" style="font-size:11.5px;color:#94a3b8;font-weight:600;margin-bottom:6px;">
          <i class="ph-bold ph-tag me-1"></i> Kategori Misi
        </label>
        <select name="cat" class="c-form-control" style="background:#0b0d17;border-color:#232942;height:40px;">
          <option value="">Semua Kategori</option>
          <option value="daily"    <?= $filter_cat==='daily'   ?'selected':''?>>Harian (Daily)</option>
          <option value="weekly"   <?= $filter_cat==='weekly'  ?'selected':''?>>Mingguan (Weekly)</option>
          <option value="lifetime" <?= $filter_cat==='lifetime'?'selected':''?>>Pencapaian (Lifetime)</option>
        </select>
      </div>

      <!-- Filter Misi Spesifik -->
      <div class="col-lg-4 col-md-4 col-sm-6">
        <label class="c-label" style="font-size:11.5px;color:#94a3b8;font-weight:600;margin-bottom:6px;">
          <i class="ph-bold ph-crosshair me-1"></i> Misi Spesifik
        </label>
        <select name="slug" class="c-form-control" style="background:#0b0d17;border-color:#232942;height:40px;">
          <option value="">Semua Misi (Tanpa Filter)</option>
          <?php foreach ($REWARD_MAP as $slug => $info): ?>
          <option value="<?= $slug ?>" <?= $filter_slug===$slug?'selected':''?>>
            [<?= $info['cat'] ?>] <?= htmlspecialchars($info['label']) ?> (+Rp <?= number_format($info['reward'], 0, ',', '.') ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Filter User / Username / UID -->
      <div class="col-lg-3 col-md-4 col-sm-12">
        <label class="c-label" style="font-size:11.5px;color:#94a3b8;font-weight:600;margin-bottom:6px;">
          <i class="ph-bold ph-user me-1"></i> Pengguna (Username / ID)
        </label>
        <div style="position:relative;">
          <input type="text" name="user" class="c-form-control" style="background:#0b0d17;border-color:#232942;height:40px;padding-left:36px;" value="<?= htmlspecialchars($filter_user) ?>" placeholder="Contoh: user123 atau 45">
          <i class="ph-bold ph-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#64748b;font-size:15px;pointer-events:none;"></i>
        </div>
      </div>

      <!-- Submit & Reset Action Buttons -->
      <div class="col-lg-2 col-md-12 col-sm-12 d-flex gap-2">
        <button type="submit" class="btn w-100 d-flex align-items-center justify-content-center gap-2" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border-radius:10px;font-weight:700;height:40px;border:none;box-shadow:0 4px 12px rgba(245,158,11,0.25);">
          <i class="ph-bold ph-magnifying-glass"></i> Filter
        </button>
        <?php if ($filter_cat || $filter_slug || $filter_user): ?>
        <a href="/console/missions.php" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="border-radius:10px;height:40px;padding:0 14px;border-color:#2d3550;" title="Reset Filter">
          <i class="ph-bold ph-x"></i>
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Table Card -->
  <div class="c-card">
    <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <i class="ph-bold ph-clock-counter-clockwise text-warning"></i>
        <span class="c-card-title mb-0">Riwayat Klaim Misi Pengguna</span>
        <span class="badge bg-dark border border-secondary text-secondary ms-1" style="font-size:11px;font-weight:600;">
          <?= number_format($total_records) ?> catatan
        </span>
      </div>
      
      <div class="text-secondary" style="font-size:12px;">
        Halaman <strong><?= $page ?></strong> dari <strong><?= $total_pages ?></strong>
      </div>
    </div>

    <div class="c-card-body p-0">
      <div class="table-responsive">
        <table class="table c-table mb-0 align-middle">
          <thead>
            <tr>
              <th style="padding-left:20px;">Pengguna</th>
              <th>Misi Selesai</th>
              <th>Kategori</th>
              <th>Reward Saldo</th>
              <th style="text-align:center;">Progress</th>
              <th>Periode</th>
              <th style="padding-right:20px;text-align:right;">Waktu Klaim</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr>
              <td colspan="7" class="text-center py-5">
                <div style="width:60px;height:60px;margin:0 auto 12px;border-radius:16px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);display:flex;align-items:center;justify-content:center;font-size:28px;color:#64748b;">
                  <i class="ph-bold ph-clipboard-text"></i>
                </div>
                <div class="fw-bold text-white mb-1">Tidak Ada Data Klaim Misi</div>
                <div class="text-secondary" style="font-size:12px;">
                  <?= ($filter_cat || $filter_slug || $filter_user) ? 'Tidak ditemukan klaim misi yang sesuai dengan filter di atas.' : 'Belum ada misi yang diselesaikan dan diklaim oleh pengguna.' ?>
                </div>
                <?php if ($filter_cat || $filter_slug || $filter_user): ?>
                <div class="mt-3">
                  <a href="/console/missions.php" class="btn btn-sm btn-outline-warning" style="border-radius:8px;font-weight:600;">
                    Reset Semua Filter
                  </a>
                </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php else: foreach ($rows as $r):
              $info = $REWARD_MAP[$r['mission_slug']] ?? [
                  'label'  => $r['mission_slug'],
                  'cat'    => 'Lainnya',
                  'reward' => 0,
                  'icon'   => 'ph-bold ph-check-square'
              ];
              $catKey = strtolower($info['cat']);
              $catCls = match($catKey) {
                  'harian'     => 'harian',
                  'mingguan'   => 'mingguan',
                  'pencapaian' => 'pencapaian',
                  default      => 'other'
              };
              $userInitial = strtoupper(substr((string)($r['username'] ?: 'U'), 0, 1));
            ?>
            <tr>
              <!-- Kolom User -->
              <td style="padding-left:20px;">
                <div class="m-user-chip">
                  <div class="m-user-avatar"><?= $userInitial ?></div>
                  <div>
                    <?php if ($r['uid']): ?>
                    <a href="/console/user_detail.php?id=<?= $r['uid'] ?>" style="font-weight:700;color:#f8fafc;text-decoration:none;display:inline-flex;align-items:center;gap:4px;" class="user-link-hover">
                      <?= htmlspecialchars((string)$r['username']) ?>
                      <i class="ph-bold ph-arrow-up-right text-muted" style="font-size:11px;"></i>
                    </a>
                    <div style="font-size:11px;color:#64748b;">
                      UID: <span class="badge bg-dark border border-secondary text-secondary" style="font-size:10px;padding:1px 5px;">#<?= $r['uid'] ?></span>
                    </div>
                    <?php else: ?>
                    <span class="text-muted fw-bold"><?= htmlspecialchars((string)($r['username'] ?: 'Guest / Deleted')) ?></span>
                    <div style="font-size:11px;color:#64748b;">ID: #<?= $r['user_id'] ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <!-- Kolom Misi -->
              <td>
                <div class="m-mission-chip">
                  <div class="m-mission-icon">
                    <i class="<?= $info['icon'] ?>"></i>
                  </div>
                  <div>
                    <div style="font-weight:700;font-size:13px;color:#f1f5f9;"><?= htmlspecialchars($info['label']) ?></div>
                    <div style="font-size:11px;color:#64748b;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:1px;">
                      <?= htmlspecialchars($r['mission_slug']) ?>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Kolom Kategori -->
              <td>
                <span class="m-badge-cat <?= $catCls ?>">
                  <?php if ($catCls === 'harian'): ?>
                    <i class="ph-bold ph-calendar"></i>
                  <?php elseif ($catCls === 'mingguan'): ?>
                    <i class="ph-bold ph-lightning"></i>
                  <?php elseif ($catCls === 'pencapaian'): ?>
                    <i class="ph-bold ph-trophy"></i>
                  <?php else: ?>
                    <i class="ph-bold ph-circle"></i>
                  <?php endif; ?>
                  <?= htmlspecialchars($info['cat']) ?>
                </span>
              </td>

              <!-- Kolom Reward -->
              <td>
                <span class="m-reward-pill">
                  <i class="ph-bold ph-coins"></i>
                  +Rp <?= number_format($info['reward'], 0, ',', '.') ?>
                </span>
              </td>

              <!-- Kolom Progress -->
              <td style="text-align:center;">
                <div style="display:inline-flex;flex-direction:column;align-items:center;gap:3px;">
                  <span class="badge bg-dark border border-secondary text-white fw-bold px-2 py-1" style="font-size:11px;">
                    <?= (int)$r['progress'] ?>
                  </span>
                  <span style="font-size:10px;color:#10b981;font-weight:700;">
                    <i class="ph-bold ph-check"></i> 100%
                  </span>
                </div>
              </td>

              <!-- Kolom Periode -->
              <td>
                <?php if (!empty($r['period_key'])): ?>
                <span class="m-period-tag"><?= htmlspecialchars($r['period_key']) ?></span>
                <?php else: ?>
                <span style="font-size:12px;color:#475569;">—</span>
                <?php endif; ?>
              </td>

              <!-- Kolom Diklaim -->
              <td style="padding-right:20px;text-align:right;white-space:nowrap;">
                <?php if ($r['claimed_at']): ?>
                <div style="font-size:12.5px;font-weight:600;color:#e2e8f0;">
                  <?= date('d M Y', strtotime($r['claimed_at'])) ?>
                </div>
                <div style="font-size:11px;color:#64748b;">
                  <i class="ph-bold ph-clock me-1"></i><?= date('H:i:s', strtotime($r['claimed_at'])) ?> WIB
                </div>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Modern Pagination Footer -->
      <?php if ($total_pages > 1): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-top:1px solid var(--border-color);background:rgba(255,255,255,0.01);flex-wrap:wrap;gap:12px;">
        <div style="font-size:12.5px;color:#94a3b8;">
          Menampilkan baris <strong><?= $offset + 1 ?></strong> – <strong><?= min($total_records, $offset + $per_page) ?></strong> dari total <strong><?= number_format($total_records) ?></strong> klaim
        </div>

        <div class="d-flex align-items-center gap-2">
          <?php 
            $qPrev = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
            $qNext = http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)]));
          ?>
          
          <a href="?<?= $qPrev ?>" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" style="border-radius:8px;font-weight:600;padding:6px 12px;<?= $page <= 1 ? 'opacity:.4;pointer-events:none;' : '' ?>">
            <i class="ph-bold ph-caret-left"></i> Prev
          </a>

          <!-- Page numbers capsule -->
          <div class="d-none d-sm-flex align-items-center gap-1">
            <?php
              $startP = max(1, $page - 2);
              $endP   = min($total_pages, $page + 2);
              for ($p = $startP; $p <= $endP; $p++):
                $qp = http_build_query(array_merge($_GET, ['page' => $p]));
                $isCur = ($p === $page);
            ?>
            <a href="?<?= $qp ?>" class="btn btn-sm <?= $isCur ? 'btn-warning text-dark fw-bold' : 'btn-outline-secondary text-secondary' ?>" style="width:32px;height:32px;padding:0;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;">
              <?= $p ?>
            </a>
            <?php endfor; ?>
          </div>

          <a href="?<?= $qNext ?>" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" style="border-radius:8px;font-weight:600;padding:6px 12px;<?= $page >= $total_pages ? 'opacity:.4;pointer-events:none;' : '' ?>">
            Next <i class="ph-bold ph-caret-right"></i>
          </a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// Micro-interaction for user hover link
document.querySelectorAll('.user-link-hover').forEach(el => {
  el.addEventListener('mouseenter', () => el.style.color = 'var(--brand)');
  el.addEventListener('mouseleave', () => el.style.color = '#f8fafc');
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
