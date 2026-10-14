<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!$_is_head_admin && !staff_can('home_cms') && !staff_can('settings')) {
    http_response_code(403);
    die('Akses ditolak: Anda tidak memiliki izin mengelola CMS Beranda.');
}
csrf_enforce();

global $pdo;

$flash     = $_SESSION['cms_flash']      ?? '';
$flashType = $_SESSION['cms_flash_type'] ?? '';
unset($_SESSION['cms_flash'], $_SESSION['cms_flash_type']);

// Handle Save POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_cms') {
        $curConfig = home_cms_config($pdo);

        // 1. HERO
        $curConfig['hero']['show_user_bar']      = isset($_POST['hero_show_user_bar']);
        $curConfig['hero']['show_mascot']        = isset($_POST['hero_show_mascot']);
        $curConfig['hero']['mascot_text']        = trim((string)($_POST['hero_mascot_text'] ?? $curConfig['hero']['mascot_text']));
        $curConfig['hero']['show_balance_card']  = isset($_POST['hero_show_balance_card']);
        $curConfig['hero']['show_today_stats']   = isset($_POST['hero_show_today_stats']);
        $curConfig['hero']['show_ref_strip']     = isset($_POST['hero_show_ref_strip']);
        $curConfig['hero']['show_watch_mission'] = isset($_POST['hero_show_watch_mission']);
        $curConfig['hero']['show_upgrade_btn']   = isset($_POST['hero_show_upgrade_btn']);
        $curConfig['hero']['show_watch_cta']     = isset($_POST['hero_show_watch_cta']);
        $curConfig['hero']['watch_cta_text']     = trim((string)($_POST['hero_watch_cta_text'] ?? $curConfig['hero']['watch_cta_text']));
        $curConfig['hero']['show_quick_actions'] = isset($_POST['hero_show_quick_actions']);

        // 2. TOUR BANNER
        $curConfig['tour']['show_banner'] = isset($_POST['tour_show_banner']);
        $curConfig['tour']['title']       = trim((string)($_POST['tour_title'] ?? $curConfig['tour']['title']));
        $curConfig['tour']['desc']        = trim((string)($_POST['tour_desc'] ?? $curConfig['tour']['desc']));

        // 3. BENTO TILES
        $curConfig['bento']['show_section'] = isset($_POST['bento_show_section']);
        $curConfig['bento']['title']        = trim((string)($_POST['bento_title'] ?? $curConfig['bento']['title']));
        
        $tilesData = $_POST['bento_tiles'] ?? [];
        if (is_array($tilesData)) {
            $updatedTiles = [];
            foreach ($tilesData as $tid => $tinfo) {
                $updatedTiles[] = [
                    'id'      => clean_input((string)($tinfo['id'] ?? $tid)),
                    'enabled' => isset($tinfo['enabled']),
                    'label'   => clean_input((string)($tinfo['label'] ?? '')),
                    'url'     => clean_input((string)($tinfo['url'] ?? '')),
                    'icon'    => clean_input((string)($tinfo['icon'] ?? 'ph-circle')),
                    'bg'      => trim((string)($tinfo['bg'] ?? 'linear-gradient(135deg,#f59e0b,#d97706)')),
                ];
            }
            if (!empty($updatedTiles)) {
                $curConfig['bento']['tiles'] = $updatedTiles;
            }
        }

        // 4. SURVEY BANNER
        $curConfig['survey_banner']['enabled'] = isset($_POST['survey_banner_enabled']);
        $curConfig['survey_banner']['title']   = trim((string)($_POST['survey_banner_title'] ?? ''));
        $curConfig['survey_banner']['badge']   = trim((string)($_POST['survey_banner_badge'] ?? ''));
        $curConfig['survey_banner']['desc']    = trim((string)($_POST['survey_banner_desc'] ?? ''));
        $curConfig['survey_banner']['cta']     = trim((string)($_POST['survey_banner_cta'] ?? ''));

        // 5. PROMO BANNER
        $curConfig['promo_banner']['enabled'] = isset($_POST['promo_banner_enabled']);
        $curConfig['promo_banner']['title']   = trim((string)($_POST['promo_banner_title'] ?? ''));
        $curConfig['promo_banner']['badge']   = trim((string)($_POST['promo_banner_badge'] ?? ''));
        $curConfig['promo_banner']['desc']    = trim((string)($_POST['promo_banner_desc'] ?? ''));
        $curConfig['promo_banner']['cta']     = trim((string)($_POST['promo_banner_cta'] ?? ''));

        // 6. CONTINUE WATCHING
        $curConfig['continue_watching']['enabled'] = isset($_POST['continue_watching_enabled']);
        $curConfig['continue_watching']['title']   = trim((string)($_POST['continue_watching_title'] ?? 'Lanjutkan Menonton'));
        $curConfig['continue_watching']['limit']   = max(1, min(10, (int)($_POST['continue_watching_limit'] ?? 3)));

        // 7. THREADS CARD
        $curConfig['threads_card']['enabled']     = isset($_POST['threads_card_enabled']);
        $curConfig['threads_card']['badge_event'] = trim((string)($_POST['threads_card_badge_event'] ?? ''));
        $curConfig['threads_card']['badge_cuan']  = trim((string)($_POST['threads_card_badge_cuan'] ?? ''));
        $curConfig['threads_card']['title']       = trim((string)($_POST['threads_card_title'] ?? ''));
        $curConfig['threads_card']['desc']        = trim((string)($_POST['threads_card_desc'] ?? ''));

        // 8. FEATURED VIDEOS
        $curConfig['featured_videos']['enabled'] = isset($_POST['featured_videos_enabled']);
        $curConfig['featured_videos']['title']   = trim((string)($_POST['featured_videos_title'] ?? 'Video Pilihan Berhadiah'));
        $curConfig['featured_videos']['desc']    = trim((string)($_POST['featured_videos_desc'] ?? ''));
        $curConfig['featured_videos']['limit']   = max(1, min(20, (int)($_POST['featured_videos_limit'] ?? 4)));

        // 9. FISHING SPOTLIGHT CARD
        $curConfig['fishing_spotlight']['enabled'] = isset($_POST['fishing_spotlight_enabled']);
        $curConfig['fishing_spotlight']['badge']   = trim((string)($_POST['fishing_spotlight_badge'] ?? '🎣 GAME SPOTLIGHT'));
        $curConfig['fishing_spotlight']['title']   = trim((string)($_POST['fishing_spotlight_title'] ?? 'Kolam Pemancingan Cuan 🐟'));
        $curConfig['fishing_spotlight']['desc']    = trim((string)($_POST['fishing_spotlight_desc'] ?? ''));
        $curConfig['fishing_spotlight']['cta']     = trim((string)($_POST['fishing_spotlight_cta'] ?? 'Mulai Mancing Sekarang'));
        $curConfig['fishing_spotlight']['url']     = trim((string)($_POST['fishing_spotlight_url'] ?? '/minigame'));

        // 10. TIPS BOX
        $curConfig['tips']['enabled'] = isset($_POST['tips_enabled']);
        $curConfig['tips']['title']   = trim((string)($_POST['tips_title'] ?? 'Tips Cuan Harian'));
        $curConfig['tips']['text']    = trim((string)($_POST['tips_text'] ?? ''));

        // 11. INBOX NOTIFIKASI
        $curConfig['notif_inbox']['enabled'] = isset($_POST['notif_inbox_enabled']);

        // 12. FLOATING PROMO
        $curConfig['floating_promo']['enabled'] = isset($_POST['floating_promo_enabled']);
        $curConfig['floating_promo']['title']   = trim((string)($_POST['floating_promo_title'] ?? 'Bonus Promosi'));
        $curConfig['floating_promo']['sub']     = trim((string)($_POST['floating_promo_sub'] ?? 'Raih Rp 75K'));
        $curConfig['floating_promo']['badge']   = trim((string)($_POST['floating_promo_badge'] ?? 'Hot'));

        // 13. FISHING ENGINE SETTINGS
        $curConfig['fishing_engine']['enabled']          = isset($_POST['fishing_engine_enabled']);
        $curConfig['fishing_engine']['daily_free_baits'] = max(0, (int)($_POST['fishing_daily_free_baits'] ?? 3));
        $curConfig['fishing_engine']['bait_per_video']   = max(0, (int)($_POST['fishing_bait_per_video'] ?? 1));
        $curConfig['fishing_engine']['reward_min']       = max(10, (float)($_POST['fishing_reward_min'] ?? 100));
        $curConfig['fishing_engine']['reward_max']       = max(50, (float)($_POST['fishing_reward_max'] ?? 5000));
        $curConfig['fishing_engine']['bait_pack_price']  = max(500, (float)($_POST['fishing_bait_pack_price'] ?? 5000));
        $curConfig['fishing_engine']['bait_pack_qty']    = max(1, (int)($_POST['fishing_bait_pack_qty'] ?? 5));

        home_cms_config_set($pdo, $curConfig);

        $_SESSION['cms_flash'] = '✅ Pengaturan Tata Letak Beranda & Sistem Mancing berhasil disimpan!';
        $_SESSION['cms_flash_type'] = 'success';
        redirect('/console/home_cms.php');
    }

    if ($action === 'reset_default') {
        setting_set($pdo, 'home_cms_config', '');
        $_SESSION['cms_flash'] = '🔄 Konfigurasi Tata Letak Beranda berhasil di-reset ke nilai bawaan!';
        $_SESSION['cms_flash_type'] = 'info';
        redirect('/console/home_cms.php');
    }
}

$cms = home_cms_config($pdo);
$pageTitle = 'CMS Tata Letak Beranda';
$activePage = 'home_cms';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h1 class="h3 text-white mb-1 fw-bold d-flex align-items-center gap-2">
      <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-warning"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
      CMS &amp; Tata Letak Beranda
    </h1>
    <p class="text-muted small mb-0">Atur elemen, sembunyikan/munculkan fitur, ubah teks promosi, dan kelola sistem game mancing secara visual.</p>
  </div>
  <div class="d-flex gap-2">
    <a href="/" target="_blank" class="btn btn-sm btn-outline-info d-flex align-items-center gap-2">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
      Pratinjau Beranda User
    </a>
    <form method="POST" onsubmit="return confirm('Yakin ingin mereset seluruh tata letak beranda ke konfigurasi awal?');" style="display:inline;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_default">
      <button type="submit" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        Reset Default
      </button>
    </form>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType === 'success' ? 'success' : 'info' ?> alert-dismissible fade show" role="alert">
  <?= htmlspecialchars($flash) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<style>
.cms-nav-tabs {
  display: flex; gap: 8px; border-bottom: 2px solid #1f2438;
  margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px;
}
.cms-tab-btn {
  background: #121626; border: 1.5px solid #232942; border-radius: 12px;
  color: #94a3b8; font-weight: 700; font-size: 13px; padding: 10px 18px;
  display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
  transition: all 0.2s ease; white-space: nowrap;
}
.cms-tab-btn:hover { background: #1a2035; color: #f8fafc; border-color: rgba(245,158,11,0.5); }
.cms-tab-btn.active {
  background: linear-gradient(135deg, rgba(245,158,11,0.2), rgba(217,119,6,0.1));
  border-color: #f59e0b; color: #f59e0b; box-shadow: 0 4px 12px rgba(245,158,11,0.15);
}
.cms-tab-pane { display: none; }
.cms-tab-pane.active { display: block; }

.cms-card {
  background: #121626; border: 1.5px solid #1e243d; border-radius: 16px;
  padding: 20px; margin-bottom: 20px; box-shadow: 0 8px 24px rgba(0,0,0,0.25);
}
.cms-card-title {
  font-size: 15px; font-weight: 800; color: #f8fafc; margin-bottom: 4px;
  display: flex; align-items: center; gap: 8px;
}
.cms-card-sub { font-size: 12px; color: #64748b; margin-bottom: 18px; }

.toggle-switch-card {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px; background: rgba(255,255,255,0.02);
  border: 1px solid #1f2438; border-radius: 12px; margin-bottom: 10px;
  transition: border-color 0.2s ease;
}
.toggle-switch-card:hover { border-color: rgba(245,158,11,0.3); }
.toggle-switch-info { flex: 1; min-width: 0; padding-right: 14px; }
.toggle-switch-title { font-size: 13px; font-weight: 700; color: #e2e8f0; margin-bottom: 2px; }
.toggle-switch-desc { font-size: 11px; color: #64748b; margin-bottom: 0; }

.form-check-input.cms-toggle {
  width: 44px; height: 24px; cursor: pointer; background-color: #1e243d;
  border-color: #334155;
}
.form-check-input.cms-toggle:checked {
  background-color: #10b981; border-color: #10b981;
}

.tile-edit-card {
  background: #0d111d; border: 1.5px solid #1e243d; border-radius: 14px;
  padding: 14px; margin-bottom: 12px;
}
</style>

<form method="POST" action="home_cms.php">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save_cms">

  <!-- Nav Tabs -->
  <div class="cms-nav-tabs">
    <button type="button" class="cms-tab-btn active" onclick="switchCmsTab('tab-hero')">
      👤 Hero &amp; Profil User
    </button>
    <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-bento')">
      🔲 Menu Bento Grid
    </button>
    <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-banners')">
      📢 Banner &amp; Event
    </button>
    <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-content')">
      🎬 Video &amp; Tips
    </button>
    <button type="button" class="cms-tab-btn" onclick="switchCmsTab('tab-fishing')">
      🎣 Sistem Engine Mancing
    </button>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 1: HERO & PROFIL USER
       ══════════════════════════════════════════════ -->
  <div id="tab-hero" class="cms-tab-pane active">
    <div class="cms-card">
      <div class="cms-card-title">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-warning"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
        Bagian Atas (Header &amp; Kartu Flex Cuan)
      </div>
      <div class="cms-card-sub">Kontrol visibilitas avatar, ucapan maskot, kartu saldo, dan tombol tontonan di bagian teratas beranda.</div>

      <div class="row">
        <div class="col-md-6">
          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Bar Profil &amp; Avatar Pengguna</div>
              <div class="toggle-switch-desc">Menampilkan avatar, username, badge tier VIP, dan tombol Profil.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_user_bar" <?= $cms['hero']['show_user_bar'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Balon Ucapan Maskot</div>
              <div class="toggle-switch-desc">Balon percakapan maskot nelayan cuan dengan sapaan interaktif.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_mascot" <?= $cms['hero']['show_mascot'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="mb-3 ps-2">
            <label class="form-label text-light small fw-bold">Teks Ucapan Maskot</label>
            <textarea name="hero_mascot_text" class="form-control form-control-sm bg-dark text-light border-secondary" rows="2"><?= htmlspecialchars($cms['hero']['mascot_text']) ?></textarea>
            <div class="form-text text-muted small">Gunakan kode <code>{username}</code> untuk menyisipkan nama pengguna otomatis.</div>
          </div>

          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Kartu Saldo Utama (VIP Flex Card)</div>
              <div class="toggle-switch-desc">Kartu bergaya claymorphism yang menampilkan saldo siap tarik (WD).</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_balance_card" <?= $cms['hero']['show_balance_card'] ? 'checked' : '' ?>>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Pill Statistik Cuan Hari Ini &amp; Total</div>
              <div class="toggle-switch-desc">Menampilkan nominal cuan hari ini dan total yang pernah dihasilkan.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_today_stats" <?= $cms['hero']['show_today_stats'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Strip Salin Kode Referral Cepat</div>
              <div class="toggle-switch-desc">Tombol 1-klik salin kode referral untuk memudahkan pengguna berbagi.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_ref_strip" <?= $cms['hero']['show_ref_strip'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Progress Misi Tontonan Video Hari Ini</div>
              <div class="toggle-switch-desc">Progress bar kuota video hari ini (misal 5/10 video selesai).</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_watch_mission" <?= $cms['hero']['show_watch_mission'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Tombol Utama: Mulai Tonton Video</div>
              <div class="toggle-switch-desc">Tombol oranye besar Call-To-Action yang mengarahkan ke galeri video.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_watch_cta" <?= $cms['hero']['show_watch_cta'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="mb-3 ps-2">
            <label class="form-label text-light small fw-bold">Teks Tombol Tonton</label>
            <input type="text" name="hero_watch_cta_text" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['hero']['watch_cta_text']) ?>">
          </div>

          <div class="toggle-switch-card">
            <div class="toggle-switch-info">
              <div class="toggle-switch-title">Tombol Aksi Cepat (Tarik, Top Up, Riwayat)</div>
              <div class="toggle-switch-desc">Deretan 3 tombol aksi langsung di bawah kartu saldo.</div>
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="hero_show_quick_actions" <?= $cms['hero']['show_quick_actions'] ? 'checked' : '' ?>>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 2: MENU BENTO GRID (TOMBOL CEPAT)
       ══════════════════════════════════════════════ -->
  <div id="tab-bento" class="cms-tab-pane">
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <div class="cms-card-title">
            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-warning"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Bento Quick Action Grid
          </div>
          <div class="cms-card-sub mb-0">Atur tombol-tombol ikonik di beranda. Anda bisa menyembunyikan, mengganti label nama, ikon, tautan, dan warna gradasi.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <label class="small text-muted fw-bold">Tampilkan Grid:</label>
          <div class="form-check form-switch m-0">
            <input class="form-check-input cms-toggle" type="checkbox" name="bento_show_section" <?= $cms['bento']['show_section'] ? 'checked' : '' ?>>
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label text-light small fw-bold">Judul Bagian Menu</label>
        <input type="text" name="bento_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['bento']['title']) ?>" style="max-width:320px;">
      </div>

      <div class="row">
        <?php foreach ($cms['bento']['tiles'] as $idx => $t): ?>
          <div class="col-md-6 col-lg-3">
            <div class="tile-edit-card">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="badge bg-secondary small"><?= htmlspecialchars($t['id']) ?></span>
                <div class="form-check form-switch m-0">
                  <input class="form-check-input cms-toggle" type="checkbox" name="bento_tiles[<?= $idx ?>][enabled]" <?= !empty($t['enabled']) ? 'checked' : '' ?> title="Aktifkan / Sembunyikan Tombol Ini">
                </div>
              </div>
              <input type="hidden" name="bento_tiles[<?= $idx ?>][id]" value="<?= htmlspecialchars($t['id']) ?>">
              
              <div class="mb-2">
                <label class="small text-muted" style="font-size:11px;">Label Tombol:</label>
                <input type="text" name="bento_tiles[<?= $idx ?>][label]" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($t['label']) ?>">
              </div>
              <div class="mb-2">
                <label class="small text-muted" style="font-size:11px;">Tautan URL:</label>
                <input type="text" name="bento_tiles[<?= $idx ?>][url]" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($t['url']) ?>">
              </div>
              <div class="mb-2">
                <label class="small text-muted" style="font-size:11px;">Phosphor Icon:</label>
                <input type="text" name="bento_tiles[<?= $idx ?>][icon]" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($t['icon']) ?>">
              </div>
              <div>
                <label class="small text-muted" style="font-size:11px;">Warna Gradasi (CSS):</label>
                <input type="text" name="bento_tiles[<?= $idx ?>][bg]" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($t['bg']) ?>" style="font-size:10.5px;">
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 3: BANNER & PROMOSI
       ══════════════════════════════════════════════ -->
  <div id="tab-banners" class="cms-tab-pane">
    <!-- Banner Survei -->
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="cms-card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-success"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          Banner Survei Pengguna Berhadiah
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input cms-toggle" type="checkbox" name="survey_banner_enabled" <?= $cms['survey_banner']['enabled'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="cms-card-sub">Banner hijau ajakan mengisi survei singkat untuk mendapatkan saldo penarikan gratis.</div>
      
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label text-light small fw-bold">Judul Banner</label>
          <input type="text" name="survey_banner_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['survey_banner']['title']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Badge Hadiah</label>
          <input type="text" name="survey_banner_badge" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['survey_banner']['badge']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Teks Tombol</label>
          <input type="text" name="survey_banner_cta" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['survey_banner']['cta']) ?>">
        </div>
        <div class="col-12">
          <label class="form-label text-light small fw-bold">Deskripsi Ajakan</label>
          <input type="text" name="survey_banner_desc" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['survey_banner']['desc']) ?>">
        </div>
      </div>
    </div>

    <!-- Banner Ajakan Promo Hub -->
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="cms-card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-warning"><path d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
          Banner Promo Hub (Ajak Teman &amp; Viral Threads)
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input cms-toggle" type="checkbox" name="promo_banner_enabled" <?= $cms['promo_banner']['enabled'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="cms-card-sub">Banner interaktif pemicu modal promosi viral dengan komisi saldo.</div>
      
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label text-light small fw-bold">Judul Banner</label>
          <input type="text" name="promo_banner_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['promo_banner']['title']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Badge Hadiah</label>
          <input type="text" name="promo_banner_badge" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['promo_banner']['badge']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Teks Tombol</label>
          <input type="text" name="promo_banner_cta" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['promo_banner']['cta']) ?>">
        </div>
        <div class="col-12">
          <label class="form-label text-light small fw-bold">Deskripsi Ajakan</label>
          <input type="text" name="promo_banner_desc" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['promo_banner']['desc']) ?>">
        </div>
      </div>
    </div>

    <!-- Card Promosi Threads -->
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="cms-card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-light"><circle cx="12" cy="12" r="10"/><path d="M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          Card Promosi Event Threads
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input cms-toggle" type="checkbox" name="threads_card_enabled" <?= $cms['threads_card']['enabled'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="cms-card-sub">Kartu promosi event Threads dengan langkah postingan dan gaji pasti promotor.</div>

      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label text-light small fw-bold">Judul Card</label>
          <input type="text" name="threads_card_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['threads_card']['title']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Badge Event</label>
          <input type="text" name="threads_card_badge_event" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['threads_card']['badge_event']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Badge Cuan</label>
          <input type="text" name="threads_card_badge_cuan" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['threads_card']['badge_cuan']) ?>">
        </div>
        <div class="col-12">
          <label class="form-label text-light small fw-bold">Deskripsi Event</label>
          <textarea name="threads_card_desc" class="form-control form-control-sm bg-dark text-light border-secondary" rows="2"><?= htmlspecialchars($cms['threads_card']['desc']) ?></textarea>
        </div>
      </div>
    </div>

    <!-- Banner Tour Pengguna Baru -->
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="cms-card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-info"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
          Banner Tour Pengguna Baru
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input cms-toggle" type="checkbox" name="tour_show_banner" <?= $cms['tour']['show_banner'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="cms-card-sub">Banner panduan interaktif awal untuk pengguna baru yang pertama kali mendaftar.</div>

      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label text-light small fw-bold">Judul Banner Tour</label>
          <input type="text" name="tour_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['tour']['title']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label text-light small fw-bold">Deskripsi Tour</label>
          <input type="text" name="tour_desc" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['tour']['desc']) ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 4: KONTEN VIDEO & TIPS
       ══════════════════════════════════════════════ -->
  <div id="tab-content" class="cms-tab-pane">
    <!-- Riwayat Lanjutkan Menonton -->
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="cms-card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-warning"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Section: Lanjutkan Menonton
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input cms-toggle" type="checkbox" name="continue_watching_enabled" <?= $cms['continue_watching']['enabled'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="cms-card-sub">Menampilkan video yang belum selesai ditonton oleh user agar bisa dilanjutkan kapan saja.</div>

      <div class="row g-2">
        <div class="col-md-8">
          <label class="form-label text-light small fw-bold">Judul Section</label>
          <input type="text" name="continue_watching_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['continue_watching']['title']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label text-light small fw-bold">Maksimal Video Ditampilkan</label>
          <input type="number" name="continue_watching_limit" min="1" max="10" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= (int)$cms['continue_watching']['limit'] ?>">
        </div>
      </div>
    </div>

    <!-- Tugas Video Pilihan Hari Ini -->
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="cms-card-title">
          <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-danger"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
          Section: Video Pilihan Berhadiah (Featured Videos)
        </div>
        <div class="form-check form-switch m-0">
          <input class="form-check-input cms-toggle" type="checkbox" name="featured_videos_enabled" <?= $cms['featured_videos']['enabled'] ? 'checked' : '' ?>>
        </div>
      </div>
      <div class="cms-card-sub">Grid video tugas pilihan hari ini yang siap ditonton user untuk mendapatkan saldo.</div>

      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label text-light small fw-bold">Judul Section</label>
          <input type="text" name="featured_videos_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['featured_videos']['title']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label text-light small fw-bold">Jumlah Video Muncul</label>
          <input type="number" name="featured_videos_limit" min="1" max="20" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= (int)$cms['featured_videos']['limit'] ?>">
        </div>
        <div class="col-12">
          <label class="form-label text-light small fw-bold">Deskripsi Subtitle</label>
          <input type="text" name="featured_videos_desc" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['featured_videos']['desc']) ?>">
        </div>
      </div>
    </div>

    <!-- Tips Cuan, Notif Inbox & Floating Button -->
    <div class="row">
      <div class="col-md-6">
        <div class="cms-card">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="cms-card-title">
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-warning"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
              Kotak Tips Cuan Harian
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="tips_enabled" <?= $cms['tips']['enabled'] ? 'checked' : '' ?>>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label text-light small fw-bold">Judul Tips</label>
            <input type="text" name="tips_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['tips']['title']) ?>">
          </div>
          <div>
            <label class="form-label text-light small fw-bold">Isi Teks Tips</label>
            <textarea name="tips_text" class="form-control form-control-sm bg-dark text-light border-secondary" rows="2"><?= htmlspecialchars($cms['tips']['text']) ?></textarea>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="cms-card">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="cms-card-title">
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-info"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
              Kotak Notifikasi Inbox
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="notif_inbox_enabled" <?= $cms['notif_inbox']['enabled'] ? 'checked' : '' ?>>
            </div>
          </div>
          <div class="cms-card-sub mb-3">Tampilkan 2 notifikasi pengumuman admin yang belum dibaca langsung di beranda.</div>

          <hr class="border-secondary my-3">

          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="cms-card-title">
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="text-danger"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
              Tombol Melayang (Floating Promo)
            </div>
            <div class="form-check form-switch m-0">
              <input class="form-check-input cms-toggle" type="checkbox" name="floating_promo_enabled" <?= $cms['floating_promo']['enabled'] ? 'checked' : '' ?>>
            </div>
          </div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label text-light small fw-bold">Judul</label>
              <input type="text" name="floating_promo_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['floating_promo']['title']) ?>">
            </div>
            <div class="col-5">
              <label class="form-label text-light small fw-bold">Subtitle</label>
              <input type="text" name="floating_promo_sub" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['floating_promo']['sub']) ?>">
            </div>
            <div class="col-3">
              <label class="form-label text-light small fw-bold">Badge</label>
              <input type="text" name="floating_promo_badge" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['floating_promo']['badge']) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════
       TAB 5: SISTEM & ENGINE MANCING CUAN
       ══════════════════════════════════════════════ -->
  <div id="tab-fishing" class="cms-tab-pane">
    <div class="cms-card">
      <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <div class="cms-card-title text-info">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12 6.477 2 12 2z"/><path d="M12 6v6l4 2"/></svg>
            ⚙️ Sistem Engine Game Mancing Cuan
          </div>
          <div class="cms-card-sub mb-0">Pengaturan logika mesin game memancing: kuota umpan, drop rate ikan, nominal reward saldo, dan penjualan paket umpan.</div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <label class="small text-light fw-bold">Status Fitur Game:</label>
          <div class="form-check form-switch m-0">
            <input class="form-check-input cms-toggle" type="checkbox" name="fishing_engine_enabled" <?= !empty($cms['fishing_engine']['enabled']) ? 'checked' : '' ?>>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- Spotlight Card di Beranda -->
        <div class="col-md-6">
          <div class="p-3 rounded-3" style="background:#090d16;border:1px solid #1e243d;">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div class="fw-bold text-warning small">🎯 Spotlight Card Mancing di Beranda</div>
              <div class="form-check form-switch m-0">
                <input class="form-check-input cms-toggle" type="checkbox" name="fishing_spotlight_enabled" <?= $cms['fishing_spotlight']['enabled'] ? 'checked' : '' ?>>
              </div>
            </div>
            <div class="mb-2">
              <label class="small text-muted">Badge Banner:</label>
              <input type="text" name="fishing_spotlight_badge" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['fishing_spotlight']['badge']) ?>">
            </div>
            <div class="mb-2">
              <label class="small text-muted">Judul Banner:</label>
              <input type="text" name="fishing_spotlight_title" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['fishing_spotlight']['title']) ?>">
            </div>
            <div class="mb-2">
              <label class="small text-muted">Deskripsi Ajakan Mancing:</label>
              <textarea name="fishing_spotlight_desc" class="form-control form-control-sm bg-dark text-light border-secondary" rows="2"><?= htmlspecialchars($cms['fishing_spotlight']['desc']) ?></textarea>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="small text-muted">Label Tombol:</label>
                <input type="text" name="fishing_spotlight_cta" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['fishing_spotlight']['cta']) ?>">
              </div>
              <div class="col-6">
                <label class="small text-muted">Tautan URL:</label>
                <input type="text" name="fishing_spotlight_url" class="form-control form-control-sm bg-dark text-light border-secondary" value="<?= htmlspecialchars($cms['fishing_spotlight']['url']) ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Aturan Umpan & Reward Mancing -->
        <div class="col-md-6">
          <div class="p-3 rounded-3" style="background:#090d16;border:1px solid #1e243d;">
            <div class="fw-bold text-info small mb-3">🪱 Aturan Umpan &amp; Pembagian Cuan</div>
            
            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="small text-muted">Umpan Gratis Harian:</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="fishing_daily_free_baits" min="0" max="50" class="form-control bg-dark text-light border-secondary" value="<?= (int)($cms['fishing_engine']['daily_free_baits'] ?? 3) ?>">
                  <span class="input-group-text bg-secondary text-light">Umpan</span>
                </div>
                <div class="form-text text-muted" style="font-size:10px;">Diberikan gratis tiap hari saat login/checkin.</div>
              </div>
              <div class="col-6">
                <label class="small text-muted">Bonus Umpan per Video:</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="fishing_bait_per_video" min="0" max="10" class="form-control bg-dark text-light border-secondary" value="<?= (int)($cms['fishing_engine']['bait_per_video'] ?? 1) ?>">
                  <span class="input-group-text bg-secondary text-light">Umpan</span>
                </div>
                <div class="form-text text-muted" style="font-size:10px;">Diberikan otomatis selesai tonton video.</div>
              </div>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="small text-muted">Reward Ikan Terendah:</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text bg-secondary text-light">Rp</span>
                  <input type="number" name="fishing_reward_min" min="10" max="50000" class="form-control bg-dark text-light border-secondary" value="<?= (float)($cms['fishing_engine']['reward_min'] ?? 100) ?>">
                </div>
              </div>
              <div class="col-6">
                <label class="small text-muted">Reward Ikan Tertinggi:</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text bg-secondary text-light">Rp</span>
                  <input type="number" name="fishing_reward_max" min="50" max="500000" class="form-control bg-dark text-light border-secondary" value="<?= (float)($cms['fishing_engine']['reward_max'] ?? 5000) ?>">
                </div>
              </div>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <label class="small text-muted">Harga Paket Umpan (Depo):</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text bg-secondary text-light">Rp</span>
                  <input type="number" name="fishing_bait_pack_price" min="500" max="500000" class="form-control bg-dark text-light border-secondary" value="<?= (float)($cms['fishing_engine']['bait_pack_price'] ?? 5000) ?>">
                </div>
                <div class="form-text text-muted" style="font-size:10px;">Dibeli user pakai Saldo Beli (Deposit).</div>
              </div>
              <div class="col-6">
                <label class="small text-muted">Isi Umpan per Paket:</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="fishing_bait_pack_qty" min="1" max="100" class="form-control bg-dark text-light border-secondary" value="<?= (int)($cms['fishing_engine']['bait_pack_qty'] ?? 5) ?>">
                  <span class="input-group-text bg-secondary text-light">Umpan</span>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sticky Save Bar -->
  <div class="p-3 bg-dark border border-secondary rounded-4 d-flex align-items-center justify-content-between flex-wrap gap-2 sticky-bottom shadow-lg mt-4" style="z-index:1030;">
    <div class="text-muted small">
      💡 Perubahan akan langsung aktif secara instan pada tampilan beranda pengguna.
    </div>
    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-warning fw-bold px-4 d-flex align-items-center gap-2 shadow">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
        Simpan Seluruh Pengaturan CMS
      </button>
    </div>
  </div>
</form>

<script>
function switchCmsTab(tabId) {
  document.querySelectorAll('.cms-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.cms-tab-pane').forEach(p => p.classList.remove('active'));
  
  event.currentTarget.classList.add('active');
  const target = document.getElementById(tabId);
  if (target) target.classList.add('active');
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
