<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Inisialisasi token CSRF session
$csrf_token = csrf_token();

// ── Ambil notifikasi untuk user ini ──────────────────────────────────────────
$notifications = [];
try {
    $stmt = $pdo->prepare(
        "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
         FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
         WHERE (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
           AND (n.expires_at IS NULL OR n.expires_at > NOW())
         ORDER BY n.created_at DESC
         LIMIT 50"
    );
    $stmt->execute([$user['id'], (string)$user['id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback: hanya ambil notif 'all' jika JSON_CONTAINS tidak didukung
    try {
        $stmt = $pdo->prepare(
            "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
             FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE n.target_type='all'
               AND (n.expires_at IS NULL OR n.expires_at > NOW())
             ORDER BY n.created_at DESC LIMIT 50"
        );
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $ex) {}
}

$unread_count = 0;
foreach ($notifications as $n) {
    if (empty($n['is_read'])) $unread_count++;
}

$pageTitle  = 'Notifikasi & Pengumuman';
$activePage = 'notifications';
require dirname(__DIR__) . '/partials/header.php';

// Konfigurasi tipe notifikasi (Phosphor Icons murni, ZERO Emojis!)
$type_cfg = [
    'info'     => [
        'icon'   => '<i class="ph-fill ph-info"></i>',
        'label'  => 'Info',
        'color'  => '#0369a1',
        'badge'  => '#e0f2fe',
        'border' => '#7dd3fc',
        'icon_bg'=> 'linear-gradient(135deg, #0284c7, #0369a1)'
    ],
    'success'  => [
        'icon'   => '<i class="ph-fill ph-check-circle"></i>',
        'label'  => 'Sukses',
        'color'  => '#047857',
        'badge'  => '#d1fae5',
        'border' => '#6ee7b7',
        'icon_bg'=> 'linear-gradient(135deg, #10b981, #059669)'
    ],
    'warning'  => [
        'icon'   => '<i class="ph-fill ph-warning"></i>',
        'label'  => 'Perhatian',
        'color'  => '#b45309',
        'badge'  => '#fef3c7',
        'border' => '#fcd34d',
        'icon_bg'=> 'linear-gradient(135deg, #f59e0b, #d97706)'
    ],
    'alert'    => [
        'icon'   => '<i class="ph-fill ph-warning-octagon"></i>',
        'label'  => 'Penting',
        'color'  => '#b91c1c',
        'badge'  => '#fee2e2',
        'border' => '#fca5a5',
        'icon_bg'=> 'linear-gradient(135deg, #ef4444, #dc2626)'
    ],
    'congrats' => [
        'icon'   => '<i class="ph-fill ph-sparkle"></i>',
        'label'  => 'Spesial',
        'color'  => '#7e22ce',
        'badge'  => '#f3e8ff',
        'border' => '#d8b4fe',
        'icon_bg'=> 'linear-gradient(135deg, #a855f7, #7e22ce)'
    ],
];
?>

<style>
/* ══════════════════════════════════════════════════════════
   NOTIFIKASI & PENGUMUMAN — AMBER HONEY THEME
   Zero Emojis • Segmented Tabs • Micro-interactions
   ══════════════════════════════════════════════════════════ */
body {
  background: #fef8ee !important;
  color: #1e293b;
  font-family: 'Nunito', sans-serif;
  margin: 0;
  padding: 0;
  overflow-x: hidden;
}

/* ── TOP BANNER ── */
.ntf-top-banner {
  background: linear-gradient(180deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  padding: 16px 14px 22px;
  border-bottom: 3.5px solid #78350f;
  position: relative;
  text-align: center;
  overflow: hidden;
}
.ntf-top-banner::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(#fbbf24 1px, transparent 1px);
  background-size: 16px 16px;
  opacity: 0.2;
  pointer-events: none;
}
.ntf-top-title {
  position: relative;
  font-size: 20px;
  font-weight: 900;
  color: #fff;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
  margin-bottom: 2px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.ntf-top-sub {
  position: relative;
  font-size: 11.5px;
  font-weight: 800;
  color: #fef3c7;
}

.ntf-top-action {
  position: relative;
  z-index: 2;
  margin-top: 10px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.2);
  border: 2px solid #fff;
  border-radius: 12px;
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 900;
  color: #fff;
  cursor: pointer;
  backdrop-filter: blur(4px);
  box-shadow: 0 2px 0 rgba(0,0,0,0.2);
  transition: transform 0.1s;
}
.ntf-top-action:active {
  transform: translateY(2px);
  box-shadow: none;
}

/* ── BODY WRAPPER ── */
.ntf-page-wrap {
  padding: 14px 14px 100px;
  position: relative;
  max-width: 480px;
  margin: 0 auto;
}

/* ── SEGMENTED FILTER TABS ── */
.ntf-tabs {
  display: flex;
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 4px;
  box-shadow: 0 4px 0 #78350f;
  margin-bottom: 16px;
  gap: 4px;
}
.ntf-tab-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 12px;
  border-radius: 11px;
  border: none;
  background: transparent;
  font-size: 12px;
  font-weight: 900;
  color: #78350f;
  cursor: pointer;
  transition: all 0.2s;
  font-family: 'Nunito', sans-serif;
}
.ntf-tab-btn.active {
  background: linear-gradient(180deg, #f59e0b, #d97706);
  color: #fff;
  box-shadow: 0 2px 0 #78350f;
  text-shadow: 0 1px 2px #78350f;
}
.ntf-tab-badge {
  font-size: 10px;
  padding: 1px 6px;
  border-radius: 8px;
  background: rgba(120, 53, 15, 0.12);
  color: #78350f;
  font-weight: 900;
}
.ntf-tab-btn.active .ntf-tab-badge {
  background: rgba(255, 255, 255, 0.3);
  color: #fff;
}

/* ── NOTIFICATION CARDS LIST ── */
.ntf-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ntf-item {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 12px 14px;
  box-shadow: 0 4px 0 #78350f;
  display: flex;
  gap: 12px;
  align-items: flex-start;
  transition: transform 0.15s, opacity 0.2s;
  position: relative;
}
.ntf-item:active {
  transform: scale(0.99);
}

/* Unread Specific Visual Accent */
.ntf-item.unread {
  background: #ffffff;
  border-color: #d97706;
  box-shadow: 0 4px 0 #78350f, 0 0 12px rgba(245, 158, 11, 0.15);
  border-left: 5px solid #d97706;
}

/* Read Specific Visual Accent */
.ntf-item.read {
  background: #fdfaf6;
  border-color: #cbd5e1;
  box-shadow: 0 3px 0 #cbd5e1;
  opacity: 0.82;
}

/* Type Icon Box */
.ntf-ico-box {
  width: 38px;
  height: 38px;
  border-radius: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #fff;
  border: 2px solid #78350f;
  box-shadow: 0 2px 0 #78350f;
  flex-shrink: 0;
}
.ntf-item.read .ntf-ico-box {
  border-color: #94a3b8;
  box-shadow: 0 2px 0 #94a3b8;
  background: #cbd5e1 !important;
  color: #64748b;
}

/* Content Area */
.ntf-content {
  flex: 1;
  min-width: 0;
}
.ntf-header-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 4px;
}
.ntf-badge-group {
  display: flex;
  align-items: center;
  gap: 6px;
}
.ntf-type-tag {
  font-size: 9.5px;
  font-weight: 900;
  padding: 2px 7px;
  border-radius: 6px;
  border: 1.5px solid;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.ntf-unread-indicator {
  width: 8px;
  height: 8px;
  background: #ef4444;
  border-radius: 50%;
  border: 1.5px solid #fff;
  box-shadow: 0 0 6px #ef4444;
  animation: pulse-dot 1.5s infinite;
}
@keyframes pulse-dot {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.3); opacity: 0.8; }
}

.ntf-time-stamp {
  font-size: 9.5px;
  font-weight: 800;
  color: #94a3b8;
  display: flex;
  align-items: center;
  gap: 3px;
}

.ntf-title {
  font-size: 13px;
  font-weight: 900;
  color: #0f172a;
  line-height: 1.35;
  margin-bottom: 4px;
}
.ntf-message {
  font-size: 11.5px;
  font-weight: 700;
  color: #475569;
  line-height: 1.45;
}

/* CTA Action Button */
.ntf-action-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-top: 8px;
  font-size: 11px;
  font-weight: 900;
  color: #fff;
  text-decoration: none;
  background: linear-gradient(180deg, #f59e0b, #d97706);
  border: 1.5px solid #78350f;
  border-radius: 10px;
  padding: 5px 12px;
  box-shadow: 0 2px 0 #78350f;
  text-shadow: 0 1px 2px #78350f;
  transition: transform 0.1s;
}
.ntf-action-link:active {
  transform: translateY(2px);
  box-shadow: none;
}

/* Single Mark As Read Button */
.btn-mark-single {
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  color: #64748b;
  border-radius: 10px;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  cursor: pointer;
  box-shadow: 0 2px 0 #cbd5e1;
  transition: all 0.1s;
  flex-shrink: 0;
}
.btn-mark-single:hover {
  background: #ecfdf5;
  color: #059669;
  border-color: #10b981;
}
.btn-mark-single:active {
  transform: translateY(2px);
  box-shadow: none;
}

/* ── EMPTY STATE ── */
.ntf-empty-box {
  text-align: center;
  padding: 36px 20px;
  background: #ffffff;
  border: 2.5px dashed #d97706;
  border-radius: 22px;
  box-shadow: 0 4px 0 #78350f;
  margin-top: 10px;
}
.ntf-empty-icon {
  width: 60px;
  height: 60px;
  border-radius: 20px;
  background: #fef3c7;
  border: 2px solid #d97706;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 30px;
  color: #d97706;
  margin: 0 auto 12px;
}
.ntf-empty-title {
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
}
.ntf-empty-sub {
  font-size: 11.5px;
  font-weight: 700;
  color: #94a3b8;
  line-height: 1.45;
  margin-bottom: 16px;
}
.btn-back-home {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 18px;
  border-radius: 14px;
  background: linear-gradient(180deg, #f59e0b, #d97706);
  border: 2px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  color: #fff;
  font-size: 12px;
  font-weight: 900;
  text-decoration: none;
  text-shadow: 0 1px 2px #78350f;
}
.btn-back-home:active {
  transform: translateY(2px);
  box-shadow: none;
}
</style>

<!-- TOP BANNER -->
<div class="ntf-top-banner">
  <div class="ntf-top-title">
    <i class="ph-bold ph-bell-ringing" style="color:#fde047;"></i>
    <span>Notifikasi & Info</span>
  </div>
  <div class="ntf-top-sub" id="banner-sub-text">
    <?php if ($unread_count > 0): ?>
      Kamu punya <strong id="unread-count-banner"><?= $unread_count ?></strong> pesan baru
    <?php else: ?>
      Semua pesan sudah dibaca
    <?php endif; ?>
  </div>

  <?php if ($unread_count > 0): ?>
    <button type="button" class="ntf-top-action" id="btn-mark-all" onclick="markAllRead()">
      <i class="ph-bold ph-checks"></i>
      <span>Tandai Semua Dibaca</span>
    </button>
  <?php endif; ?>
</div>

<div class="ntf-page-wrap">

  <!-- SEGMENTED FILTER TABS -->
  <div class="ntf-tabs">
    <button type="button" class="ntf-tab-btn active" id="tab-all" onclick="filterNotifs('all')">
      <i class="ph-bold ph-tray"></i>
      <span>Semua</span>
      <span class="ntf-tab-badge" id="badge-all-count"><?= count($notifications) ?></span>
    </button>

    <button type="button" class="ntf-tab-btn" id="tab-unread" onclick="filterNotifs('unread')">
      <i class="ph-bold ph-bell-simple-ringing"></i>
      <span>Belum Dibaca</span>
      <span class="ntf-tab-badge" id="badge-unread-count"><?= $unread_count ?></span>
    </button>
  </div>

  <!-- NOTIFICATIONS LIST -->
  <?php if (empty($notifications)): ?>
    <div class="ntf-empty-box">
      <div class="ntf-empty-icon">
        <i class="ph-fill ph-bell-slash"></i>
      </div>
      <div class="ntf-empty-title">Kotak Pesan Bersih</div>
      <div class="ntf-empty-sub">
        Belum ada informasi atau pengumuman terbaru untuk akunmu saat ini.
      </div>
      <a href="/home" class="btn-back-home">
        <i class="ph-bold ph-house"></i>
        <span>Kembali ke Beranda</span>
      </a>
    </div>
  <?php else: ?>
    <div class="ntf-list" id="notif-list-container">
      <?php foreach ($notifications as $n):
        $cfg = $type_cfg[$n['type']] ?? $type_cfg['info'];
        $icon = !empty($n['icon']) ? htmlspecialchars($n['icon']) : $cfg['icon'];
        $is_read = !empty($n['is_read']);
      ?>
        <div class="ntf-item <?= $is_read ? 'read' : 'unread' ?>"
             data-id="<?= (int)$n['id'] ?>"
             data-read="<?= $is_read ? '1' : '0' ?>">

          <!-- Left Icon Box -->
          <div class="ntf-ico-box" style="background: <?= $cfg['icon_bg'] ?>;">
            <?= $icon ?>
          </div>

          <!-- Body Content -->
          <div class="ntf-content">
            <div class="ntf-header-meta">
              <div class="ntf-badge-group">
                <?php if (!$is_read): ?>
                  <span class="ntf-unread-indicator" title="Belum Dibaca"></span>
                <?php endif; ?>
                <span class="ntf-type-tag" style="color:<?= $cfg['color'] ?>; background:<?= $cfg['badge'] ?>; border-color:<?= $cfg['border'] ?>;">
                  <?= htmlspecialchars($cfg['label']) ?>
                </span>
              </div>
              <span class="ntf-time-stamp">
                <i class="ph-bold ph-clock"></i> <?= date('d M, H:i', strtotime($n['created_at'])) ?>
              </span>
            </div>

            <div class="ntf-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="ntf-message"><?= nl2br(htmlspecialchars($n['message'])) ?></div>

            <?php if (!empty($n['action_url']) && !empty($n['action_text'])): ?>
              <div>
                <a href="<?= htmlspecialchars($n['action_url']) ?>" class="ntf-action-link">
                  <span><?= htmlspecialchars($n['action_text']) ?></span>
                  <i class="ph-bold ph-arrow-up-right"></i>
                </a>
              </div>
            <?php endif; ?>
          </div>

          <!-- Mark As Read Single Button -->
          <?php if (!$is_read): ?>
            <button type="button" class="btn-mark-single" onclick="markRead(<?= (int)$n['id'] ?>, this)" title="Tandai sudah dibaca">
              <i class="ph-bold ph-check"></i>
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Empty Filter Message (Hidden by default) -->
    <div id="filter-empty-message" class="ntf-empty-box" style="display:none;">
      <div class="ntf-empty-icon" style="background:#ecfdf5;border-color:#10b981;color:#059669;">
        <i class="ph-fill ph-checks"></i>
      </div>
      <div class="ntf-empty-title">Semua Pesan Sudah Dibaca!</div>
      <div class="ntf-empty-sub">
        Tidak ada pesan baru yang tertunda. Kamu selalu ter-update dengan kabar terbaru MancingCuan.
      </div>
      <button type="button" onclick="filterNotifs('all')" class="btn-back-home" style="cursor:pointer;">
        <i class="ph-bold ph-tray"></i>
        <span>Tampilkan Semua Pesan</span>
      </button>
    </div>
  <?php endif; ?>

</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>';
let currentFilter = 'all';

function filterNotifs(filterType) {
  currentFilter = filterType;
  const tabAll = document.getElementById('tab-all');
  const tabUnread = document.getElementById('tab-unread');
  const items = document.querySelectorAll('.ntf-item');
  const emptyMsg = document.getElementById('filter-empty-message');

  if (filterType === 'unread') {
    tabUnread.classList.add('active');
    tabAll.classList.remove('active');
  } else {
    tabAll.classList.add('active');
    tabUnread.classList.remove('active');
  }

  let visibleCount = 0;
  items.forEach(item => {
    const isRead = item.getAttribute('data-read') === '1';
    if (filterType === 'unread' && isRead) {
      item.style.display = 'none';
    } else {
      item.style.display = 'flex';
      visibleCount++;
    }
  });

  if (emptyMsg) {
    emptyMsg.style.display = (visibleCount === 0) ? 'block' : 'none';
  }
}

async function markRead(id, btn) {
  const item = btn.closest('.ntf-item');
  const fd = new FormData();
  fd.append('action', 'mark_read');
  fd.append('id', id);
  fd.append('_csrf', CSRF);
  fd.append('csrf_token', CSRF);

  try {
    const res = await fetch('/notif_action', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF },
      body: fd
    });
    const data = await res.json();
    if (data.ok) {
      item.classList.remove('unread');
      item.classList.add('read');
      item.setAttribute('data-read', '1');

      // Hilangkan unread dot
      const dot = item.querySelector('.ntf-unread-indicator');
      if (dot) dot.remove();

      // Hilangkan tombol check
      btn.remove();

      // Kurangi counter
      updateUnreadCounter(data.count ?? null);

      // Jika saat ini sedang di tab unread, sembunyikan item
      if (currentFilter === 'unread') {
        item.style.display = 'none';
        const remainingUnread = document.querySelectorAll('.ntf-item[data-read="0"]');
        if (remainingUnread.length === 0) {
          const emptyMsg = document.getElementById('filter-empty-message');
          if (emptyMsg) emptyMsg.style.display = 'block';
        }
      }
    }
  } catch (err) {
    console.error('Error markRead:', err);
  }
}

async function markAllRead() {
  const fd = new FormData();
  fd.append('action', 'mark_all');
  fd.append('_csrf', CSRF);
  fd.append('csrf_token', CSRF);

  try {
    const res = await fetch('/notif_action', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF },
      body: fd
    });
    const data = await res.json();
    if (data.ok) {
      // Ubah semua item menjadi read di UI
      document.querySelectorAll('.ntf-item').forEach(item => {
        item.classList.remove('unread');
        item.classList.add('read');
        item.setAttribute('data-read', '1');
        const dot = item.querySelector('.ntf-unread-indicator');
        if (dot) dot.remove();
        const btn = item.querySelector('.btn-mark-single');
        if (btn) btn.remove();
      });

      updateUnreadCounter(0);

      // Sembunyikan tombol Tandai Semua Dibaca
      const btnAll = document.getElementById('btn-mark-all');
      if (btnAll) btnAll.remove();

      if (currentFilter === 'unread') {
        filterNotifs('unread');
      }
    }
  } catch (err) {
    console.error('Error markAllRead:', err);
  }
}

function updateUnreadCounter(explicitCount) {
  let count = explicitCount;
  if (count === null || count === undefined) {
    count = document.querySelectorAll('.ntf-item[data-read="0"]').length;
  }

  const badgeUnread = document.getElementById('badge-unread-count');
  if (badgeUnread) badgeUnread.innerText = count;

  const countBanner = document.getElementById('unread-count-banner');
  if (countBanner) countBanner.innerText = count;

  const subText = document.getElementById('banner-sub-text');
  if (subText && count <= 0) {
    subText.innerHTML = 'Semua pesan sudah dibaca';
    const btnAll = document.getElementById('btn-mark-all');
    if (btnAll) btnAll.remove();
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>