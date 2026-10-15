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

// Konfigurasi tipe notifikasi bahari (Living Coral Reef Palette)
$type_cfg = [
    'info'     => [
        'icon'   => '<i class="ph-fill ph-info"></i>',
        'label'  => 'Info Samudra',
        'color'  => '#0891b2',
        'badge'  => '#cffafe',
        'border' => '#a5f3fc',
        'icon_bg'=> 'linear-gradient(135deg, #06b6d4, #0891b2)'
    ],
    'success'  => [
        'icon'   => '<i class="ph-fill ph-check-circle"></i>',
        'label'  => 'Hasil Cuan',
        'color'  => '#059669',
        'badge'  => '#d1fae5',
        'border' => '#a7f3d0',
        'icon_bg'=> 'linear-gradient(135deg, #10b981, #059669)'
    ],
    'warning'  => [
        'icon'   => '<i class="ph-fill ph-warning"></i>',
        'label'  => 'Perhatian',
        'color'  => '#d97706',
        'badge'  => '#fef3c7',
        'border' => '#fde68a',
        'icon_bg'=> 'linear-gradient(135deg, #f59e0b, #d97706)'
    ],
    'alert'    => [
        'icon'   => '<i class="ph-fill ph-warning-octagon"></i>',
        'label'  => 'Penting',
        'color'  => '#e11d48',
        'badge'  => '#ffe4e6',
        'border' => '#fecdd3',
        'icon_bg'=> 'linear-gradient(135deg, #fb7185, #f43f5e)'
    ],
    'congrats' => [
        'icon'   => '<i class="ph-fill ph-sparkle"></i>',
        'label'  => 'Hadiah Spesial',
        'color'  => '#7c3aed',
        'badge'  => '#ede9fe',
        'border' => '#ddd6fe',
        'icon_bg'=> 'linear-gradient(135deg, #a855f7, #7c3aed)'
    ],
];
?>

<style>
/* ══════════════════════════════════════════════════════════════
   OCEAN SIGNAL LOG — NOTIFIKASI & WARTA SAMUDRA
   Tema: Living Coral Reef Fantasy & 3D Tactile Claymorphism
   ══════════════════════════════════════════════════════════════ */

.ntf-reef-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 32px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base */
.ntf-clay {
  border: 2px solid #ffffff;
  border-radius: 20px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
  overflow: hidden;
}

/* Ambient Marine Floating Bubbles */
.ntf-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.95) 0%, rgba(103, 232, 249, 0.35) 60%, rgba(244, 63, 94, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.85);
  box-shadow: inset 1px 1px 2px #ffffff, 0 2px 6px rgba(8, 145, 178, 0.15);
  pointer-events: none;
  z-index: 0;
}
.ntf-bubble--1 {
  width: 16px;
  height: 16px;
  top: 10px;
  right: 18px;
  animation: ntfBubbleFloat 5.2s infinite ease-in-out alternate;
}
.ntf-bubble--2 {
  width: 24px;
  height: 24px;
  bottom: 12px;
  right: 14px;
  animation: ntfBubbleFloat 6.8s infinite ease-in-out alternate-reverse;
  opacity: 0.65;
}
@keyframes ntfBubbleFloat {
  0% { transform: translateY(0) scale(0.95); }
  50% { transform: translateY(-6px) scale(1.05); }
  100% { transform: translateY(2px) scale(0.98); }
}

/* ── 1. COMPACT TOP NAVIGATOR ── */
.ntf-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.ntf-back-btn {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  border: 1.5px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 17px;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.95);
  transition: transform 0.12s ease;
}
.ntf-back-btn:active { transform: scale(0.92); }

.ntf-nav-title {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04);
}

.ntf-unread-pill {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  border: 1.5px solid #ffffff;
  color: #78350f;
  font-weight: 900;
  font-size: 11px;
  padding: 6px 11px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 2px 8px rgba(245, 158, 11, 0.22), inset 1px 1px 2px #ffffff;
}

/* ── 2. OCEAN SIGNAL TOWER (HEADER BANNER CARD) ── */
.ntf-signal-card {
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.ntf-signal-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.ntf-signal-avatar {
  width: 40px;
  height: 40px;
  border-radius: 13px;
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 100%);
  border: 1.5px solid #ffffff;
  box-shadow: 0 3px 10px rgba(244, 63, 94, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 20px;
  flex-shrink: 0;
}
.ntf-signal-info {
  line-height: 1.25;
}
.ntf-signal-title {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
}
.ntf-signal-sub {
  font-size: 10.5px;
  font-weight: 700;
  color: var(--reef-muted);
}

.btn-mark-all-read {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  padding: 6px 11px;
  font-size: 10.5px;
  font-weight: 800;
  color: var(--reef-coral-500);
  display: inline-flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.05);
  transition: transform 0.12s ease;
  user-select: none;
  flex-shrink: 0;
}
.btn-mark-all-read:active { transform: scale(0.95); }

/* ── 3. SEGMENTED FILTER TABS ── */
.ntf-tabs-deck {
  display: flex;
  background: rgba(255, 255, 255, 0.94);
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 4px;
  gap: 4px;
  box-shadow: 0 3px 10px rgba(30, 27, 46, 0.04);
}
.ntf-tab-item {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 10px;
  border-radius: 12px;
  border: none;
  background: transparent;
  font-size: 11.5px;
  font-weight: 800;
  color: var(--reef-muted);
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: inherit;
  user-select: none;
}
.ntf-tab-item.active {
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 100%);
  color: #ffffff;
  box-shadow: 0 3px 8px rgba(244, 63, 94, 0.28);
  font-weight: 900;
}
.ntf-tab-badge {
  font-size: 9.5px;
  padding: 1.5px 6px;
  border-radius: 8px;
  background: #f1f5f9;
  color: var(--reef-ink);
  font-weight: 800;
}
.ntf-tab-item.active .ntf-tab-badge {
  background: rgba(255, 255, 255, 0.3);
  color: #ffffff;
}

/* ── 4. NOTIFICATION CARDS LIST ── */
.ntf-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ntf-item {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 16px;
  padding: 11px 12px;
  box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04);
  display: flex;
  gap: 10px;
  align-items: flex-start;
  transition: transform 0.12s ease, opacity 0.2s ease;
  position: relative;
  overflow: hidden;
}
.ntf-item:active {
  transform: scale(0.99);
}

/* Unread Accent (Living Coral Pulse) */
.ntf-item.unread {
  background: linear-gradient(135deg, #ffffff 0%, #fff7ed 100%);
  border-left: 4px solid var(--reef-coral-500);
  box-shadow: 0 4px 14px rgba(244, 63, 94, 0.12);
}

/* Read Accent (Clean Ocean Softness) */
.ntf-item.read {
  background: rgba(255, 255, 255, 0.85);
  opacity: 0.86;
}

/* Icon Box */
.ntf-ico-box {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: #ffffff;
  border: 1.5px solid #ffffff;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
  flex-shrink: 0;
}
.ntf-item.read .ntf-ico-box {
  background: #e2e8f0 !important;
  color: #64748b !important;
  box-shadow: none;
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
  margin-bottom: 3px;
}
.ntf-badge-group {
  display: flex;
  align-items: center;
  gap: 5px;
}
.ntf-type-tag {
  font-size: 9px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  border: 1px solid;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.ntf-unread-dot {
  width: 7px;
  height: 7px;
  background: var(--reef-coral-500);
  border-radius: 50%;
  border: 1px solid #ffffff;
  box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.7);
  animation: ntfPulseDot 1.6s infinite;
  flex-shrink: 0;
}
@keyframes ntfPulseDot {
  0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.7); }
  70% { transform: scale(1.15); box-shadow: 0 0 0 5px rgba(244, 63, 94, 0); }
  100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(244, 63, 94, 0); }
}

.ntf-time-stamp {
  font-size: 9.5px;
  font-weight: 700;
  color: var(--reef-muted);
  display: flex;
  align-items: center;
  gap: 3px;
}

.ntf-title {
  font-size: 12.5px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.35;
  margin-bottom: 3px;
}
.ntf-message {
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.45;
}

/* Action Link */
.ntf-action-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 6px;
  font-size: 10.5px;
  font-weight: 900;
  color: #ffffff;
  text-decoration: none;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 1px solid #ffffff;
  border-radius: 8px;
  padding: 4px 9px;
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.25);
  transition: transform 0.1s ease;
}
.ntf-action-link:active { transform: scale(0.95); }

/* Single Mark As Read Button */
.btn-mark-single {
  background: #ffffff;
  border: 1.5px solid #ffffff;
  color: var(--reef-muted);
  border-radius: 10px;
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.05);
  transition: all 0.12s ease;
  flex-shrink: 0;
}
.btn-mark-single:hover {
  background: #ecfdf5;
  color: #059669;
}
.btn-mark-single:active { transform: scale(0.92); }

/* ── 5. EMPTY STATE (CLAYMORPHIC) ── */
.ntf-empty-card {
  text-align: center;
  padding: 30px 18px;
  margin-top: 4px;
}
.ntf-empty-icon {
  width: 56px;
  height: 56px;
  border-radius: 18px;
  background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
  border: 2px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: #065f46;
  margin: 0 auto 10px;
  box-shadow: 0 4px 14px rgba(16, 185, 129, 0.18);
}
.ntf-empty-title {
  font-size: 14.5px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 3px;
}
.ntf-empty-sub {
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.4;
  margin-bottom: 14px;
}
.btn-marine-home {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 9px 15px;
  border-radius: 12px;
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 100%);
  border: 1.5px solid #ffffff;
  box-shadow: 0 4px 12px rgba(244, 63, 94, 0.25);
  color: #ffffff;
  font-size: 11.5px;
  font-weight: 900;
  text-decoration: none;
  transition: transform 0.12s ease;
}
.btn-marine-home:active { transform: scale(0.96); }
</style>

<div class="ntf-reef-stage">

  <!-- ── 1. COMPACT TOP NAVIGATOR ── -->
  <div class="ntf-top-nav">
    <a href="/home" class="ntf-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="ntf-nav-title">
      <i class="ph-fill ph-bell-simple-ringing" style="color:var(--reef-coral-500);"></i>
      <span>Warta Samudra</span>
    </div>
    <div class="ntf-unread-pill">
      <i class="ph-bold ph-envelope-simple-open"></i>
      <span id="unread-pill-txt"><?= $unread_count ?> Baru</span>
    </div>
  </div>

  <!-- ── 2. OCEAN SIGNAL TOWER (HEADER BANNER) ── -->
  <div class="ntf-clay ntf-signal-card">
    <div class="ntf-bubble ntf-bubble--1"></div>
    <div class="ntf-bubble ntf-bubble--2"></div>

    <div class="ntf-signal-left">
      <div class="ntf-signal-avatar">
        <i class="ph-fill ph-broadcast"></i>
      </div>
      <div class="ntf-signal-info">
        <div class="ntf-signal-title">Pusat Sinyal & Pengumuman</div>
        <div class="ntf-signal-sub" id="banner-sub-text">
          <?php if ($unread_count > 0): ?>
            Ada <strong id="unread-count-banner"><?= $unread_count ?></strong> pesan yang belum dibaca
          <?php else: ?>
            Seluruh pesan telah dibaca
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($unread_count > 0): ?>
      <button type="button" class="btn-mark-all-read" id="btn-mark-all" onclick="markAllRead()" title="Tandai semua sudah dibaca">
        <i class="ph-bold ph-checks"></i>
        <span>Baca Semua</span>
      </button>
    <?php endif; ?>
  </div>

  <!-- ── 3. SEGMENTED FILTER TABS ── -->
  <div class="ntf-tabs-deck">
    <button type="button" class="ntf-tab-item active" id="tab-all" onclick="filterNotifs('all')">
      <i class="ph-bold ph-tray"></i>
      <span>Semua Warta</span>
      <span class="ntf-tab-badge" id="badge-all-count"><?= count($notifications) ?></span>
    </button>

    <button type="button" class="ntf-tab-item" id="tab-unread" onclick="filterNotifs('unread')">
      <i class="ph-bold ph-bell-simple-ringing"></i>
      <span>Belum Dibaca</span>
      <span class="ntf-tab-badge" id="badge-unread-count"><?= $unread_count ?></span>
    </button>
  </div>

  <!-- ── 4. NOTIFICATION CARDS LIST ── -->
  <?php if (empty($notifications)): ?>
    <div class="ntf-clay ntf-empty-card">
      <div class="ntf-empty-icon">
        <i class="ph-fill ph-bell-slash"></i>
      </div>
      <div class="ntf-empty-title">Kotak Warta Bersih</div>
      <div class="ntf-empty-sub">
        Belum ada pengumuman atau warta samudra baru untuk akunmu saat ini.
      </div>
      <a href="/home" class="btn-marine-home">
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
                  <span class="ntf-unread-dot" title="Belum Dibaca"></span>
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
    <div id="filter-empty-message" class="ntf-clay ntf-empty-card" style="display:none;">
      <div class="ntf-empty-icon" style="background:linear-gradient(135deg,#d1fae5,#a7f3d0);color:#065f46;">
        <i class="ph-fill ph-checks"></i>
      </div>
      <div class="ntf-empty-title">Semua Pesan Sudah Dibaca!</div>
      <div class="ntf-empty-sub">
        Tidak ada pesan baru yang tertunda. Kamu selalu ter-update dengan kabar terbaru MancingCuan.
      </div>
      <button type="button" onclick="filterNotifs('all')" class="btn-marine-home" style="cursor:pointer;border:none;">
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
      const dot = item.querySelector('.ntf-unread-dot');
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
        const dot = item.querySelector('.ntf-unread-dot');
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

  const pillTxt = document.getElementById('unread-pill-txt');
  if (pillTxt) pillTxt.innerText = count + ' Baru';

  const subText = document.getElementById('banner-sub-text');
  if (subText && count <= 0) {
    subText.innerHTML = 'Seluruh pesan telah dibaca';
    const btnAll = document.getElementById('btn-mark-all');
    if (btnAll) btnAll.remove();
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
