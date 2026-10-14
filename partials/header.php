<?php
/** partials/header.php — requires: $pageTitle, $activePage, $user */
$_site_name   = setting($pdo, 'site_name', 'MancingCuan');
$_seo_title   = setting($pdo, 'seo_title', 'MancingCuan');
$_seo_desc    = setting($pdo, 'seo_description', 'MancingCuan adalah platform penghasil saldo nyata terpercaya di Indonesia. Tonton video harian, selesaikan misi, dan cairkan saldo cuan instan.');
$_seo_kw      = setting($pdo, 'seo_keywords', 'mancingcuan, mancing cuan, nonton video dapat uang, aplikasi penghasil saldo dana');
$_seo_robots  = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og      = setting($pdo, 'seo_og_image', '/assets/seobanner.jpg');
$_seo_twcard  = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_author  = setting($pdo, 'seo_author', 'MancingCuan Official');
$_seo_og_title = setting($pdo, 'seo_og_title', '');
$_seo_og_desc  = setting($pdo, 'seo_og_description', '');
$_seo_og_type  = setting($pdo, 'seo_og_type', 'website');
$_favicon     = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_site_logo   = site_logo($pdo);

$og_disk = $_seo_og ? dirname(__DIR__) . '/' . ltrim(preg_replace('~\?.*$~', '', $_seo_og), '/') : '';
if (!$_seo_og || !file_exists($og_disk)) {
    $_seo_og = '/assets/seobanner.jpg';
}
$absolute_og = preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'));
$og_mime = str_ends_with(strtolower($absolute_og), '.png') ? 'image/png' : 'image/jpeg';
$fav_url = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '/assets/favicon.png';
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_title = $_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title);
$final_og_desc = $_seo_og_desc ?: $_seo_desc;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#d97706">
<meta name="application-name" content="<?= htmlspecialchars($_site_name) ?>">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($_site_name) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= htmlspecialchars(($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author):?><meta name="author"     content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
<meta property="og:locale" content="id_ID">
<meta property="og:site_name" content="<?= htmlspecialchars($_site_name) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<meta property="og:title" content="<?= htmlspecialchars($final_og_title) ?>">
<?php if ($final_og_desc): ?>
<meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="<?= $og_mime ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_site_name) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:site" content="@MancingCuan">
<meta name="twitter:creator" content="@MancingCuan">
<meta name="twitter:title" content="<?= htmlspecialchars($final_og_title) ?>">
<?php if ($final_og_desc): ?><meta name="twitter:description" content="<?= htmlspecialchars($final_og_desc) ?>"><?php endif; ?>
<?php if ($absolute_og): ?>
<meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta name="twitter:image:alt" content="<?= htmlspecialchars($_site_name) ?>">
<?php endif; ?>
<?php if ($fav_url): ?>
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png?v=<?= @filemtime(dirname(__DIR__) . '/assets/apple-touch-icon.png') ?: time() ?>">
<link rel="shortcut icon" href="/assets/favicon.ico">
<?php endif; ?>
<!-- Schema.org JSON-LD Structured Data for Google Rich Snippets -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "@id": "<?= htmlspecialchars(base_url('#website')) ?>",
      "url": "<?= htmlspecialchars(base_url()) ?>",
      "name": "<?= htmlspecialchars($_site_name) ?>",
      "description": "<?= htmlspecialchars($_seo_desc) ?>",
      "publisher": {
        "@id": "<?= htmlspecialchars(base_url('#organization')) ?>"
      },
      "inLanguage": "id-ID"
    },
    {
      "@type": "Organization",
      "@id": "<?= htmlspecialchars(base_url('#organization')) ?>",
      "name": "<?= htmlspecialchars($_site_name) ?>",
      "url": "<?= htmlspecialchars(base_url()) ?>",
      "logo": {
        "@type": "ImageObject",
        "url": "<?= htmlspecialchars(base_url('assets/logo.png')) ?>",
        "width": 512,
        "height": 512
      }
    }
  ]
}
</script>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<style>
/* ══ MINIMALIST HORIZON BACKGROUND & RESET ══ */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }
body {
  font-family: 'Nunito', 'Inter', -apple-system, sans-serif !important;
  background: #f8fafc !important;
  background-image: radial-gradient(rgba(14, 165, 233, 0.08) 1px, transparent 1px) !important;
  background-size: 20px 20px !important;
  background-position: 0 0 !important;
  padding-bottom: 84px !important;
  -webkit-font-smoothing: antialiased;
  color: #0f172a;
}
i[class^="ph-"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}
.app-shell { width: 100%; max-width: 440px; margin: 0 auto; position: relative; }

/* ══ DUAL FLOATING HORIZON ISLANDS NAVBAR ══ */
.topbar-wrap {
  position: sticky; top: 0; z-index: 1000;
  width: 100%;
  padding: 8px 12px 4px;
  pointer-events: none;
}
.topbar-floating-row {
  pointer-events: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: 8px;
}

/* Left Island: Brand Sculpted Pill */
.island-brand-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 44px;
  padding: 0 12px 0 8px;
  background: rgba(255, 255, 255, 0.94);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1.5px solid #e0f2fe;
  border-radius: 16px;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.05), 0 1px 3px rgba(0, 0, 0, 0.02);
  text-decoration: none;
  transition: transform 0.15s, border-color 0.15s;
}
.island-brand-pill:active {
  transform: scale(0.97);
}
.brand-fin-icon {
  width: 28px; height: 28px;
  background: linear-gradient(135deg, #e0f2fe, #bae6fd);
  border: 1px solid #7dd3fc;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  color: #0284c7;
  font-size: 15px;
  box-shadow: 0 2px 6px rgba(2, 132, 199, 0.15);
}
.brand-text {
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
  letter-spacing: -0.4px;
  display: flex;
  align-items: baseline;
}
.brand-dot {
  color: #38bdf8;
  font-weight: 900;
}
.brand-sub {
  color: #0284c7;
  font-weight: 900;
}

/* Right Island: Smart Balance & Alert Capsule */
.island-cluster-pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 44px;
  padding: 0 8px 0 10px;
  background: rgba(255, 255, 255, 0.94);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1.5px solid #e0f2fe;
  border-radius: 16px;
  box-shadow: 0 8px 20px rgba(2, 132, 199, 0.05), 0 1px 3px rgba(0, 0, 0, 0.02);
}
.cluster-balance-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  user-select: none;
  transition: opacity 0.12s;
}
.cluster-balance-btn:active {
  opacity: 0.75;
}
.pulse-emerald-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
  animation: pulseAura 2.2s infinite ease-in-out;
}
@keyframes pulseAura {
  0%, 100% { box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25); }
  50% { box-shadow: 0 0 0 4.5px rgba(16, 185, 129, 0.12); }
}
.cluster-bal-txt {
  font-size: 12px;
  font-weight: 900;
  color: #0369a1;
  letter-spacing: -0.2px;
}
.cluster-user-avatar {
  width: 22px; height: 22px;
  border-radius: 8px;
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #ffffff;
  font-size: 10px;
  font-weight: 900;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 4px rgba(2, 132, 199, 0.2);
}

.cluster-divider {
  width: 1px;
  height: 18px;
  background: #e2e8f0;
}

.cluster-bell-btn {
  width: 28px; height: 28px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  color: #64748b;
  font-size: 16px;
  text-decoration: none;
  position: relative;
  transition: color 0.15s;
}
.cluster-bell-btn:active {
  color: #0284c7;
}
.cluster-badge-count {
  position: absolute;
  top: -2px; right: -2px;
  background: #f43f5e;
  color: #fff;
  font-size: 8px;
  font-weight: 900;
  min-width: 14px; height: 14px;
  border-radius: 999px;
  padding: 0 3px;
  border: 1.5px solid #fff;
  display: none;
  align-items: center; justify-content: center;
}

/* Guest buttons */
.island-cluster-pill.guest {
  padding: 0 6px;
  gap: 4px;
}
.island-guest-link {
  color: #0284c7; font-size: 11px; font-weight: 900; text-decoration: none; padding: 4px 8px;
}
.island-guest-btn {
  background: #0284c7; color: #fff; font-size: 11px; font-weight: 900; text-decoration: none;
  padding: 5px 12px; border-radius: 12px; box-shadow: 0 2px 6px rgba(2, 132, 199, 0.25);
}

/* ── WALLET POPOVER DRAWER ── */
.tb-wallet-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.35);
  backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  z-index: 9998;
}
.tb-wallet-backdrop.open { display: block; }

.tb-wallet-popover {
  display: none;
  position: fixed;
  top: 60px; left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 24px); max-width: 400px;
  background: #ffffff;
  border: 1.5px solid #e0f2fe;
  border-radius: 24px;
  box-shadow: 0 20px 48px rgba(2, 132, 199, 0.16), 0 4px 12px rgba(0, 0, 0, 0.04);
  z-index: 9999;
  overflow: hidden;
  animation: islandPop .18s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.tb-wallet-popover.open { display: block; }
@keyframes islandPop {
  from { transform: translateX(-50%) translateY(-6px) scale(0.98); opacity: 0; }
  to { transform: translateX(-50%) translateY(0) scale(1); opacity: 1; }
}

.twp-header {
  padding: 14px 16px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.twp-title {
  font-size: 13.5px;
  font-weight: 900;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 6px;
}
.twp-close {
  background: #f1f5f9;
  color: #64748b;
  border: none;
  border-radius: 50%;
  width: 24px; height: 24px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  cursor: pointer;
}

.twp-body {
  padding: 0 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.twp-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 9px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.twp-card-left {
  display: flex;
  align-items: center;
  gap: 9px;
}
.twp-card-icon {
  width: 32px; height: 32px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.twp-card-icon.wd { background: #e0f2fe; color: #0284c7; }
.twp-card-icon.dep { background: #ecfdf5; color: #059669; }
.twp-card-icon.baits { background: #fef3c7; color: #d97706; }

.twp-card-lbl {
  font-size: 8px;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
}
.twp-card-val {
  font-size: 13px;
  font-weight: 900;
  color: #0f172a;
}
.twp-card-btn {
  padding: 5px 11px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 900;
  text-decoration: none;
}
.twp-card-btn.wd { background: #0284c7; color: #fff; }
.twp-card-btn.dep { background: #059669; color: #fff; }
.twp-card-btn.baits { background: #d97706; color: #fff; }
</style>
</head>
<body>
<div class="app-shell">
  <!-- ══ DUAL FLOATING HORIZON ISLANDS ══ -->
  <div class="topbar-wrap">
    <div class="topbar-floating-row">
      <!-- Left Island: Geometric Nautical Brand -->
      <a href="/home" class="island-brand-pill">
        <div class="brand-fin-icon">
          <i class="ph-fill ph-fish-simple"></i>
        </div>
        <span class="brand-text">mancing<span class="brand-dot">.</span><span class="brand-sub">cuan</span></span>
      </a>

      <!-- Right Island: Smart Balance & Alert Capsule -->
      <?php if (!empty($user)): ?>
      <?php
      if (!function_exists('fmt_short_tb')) {
        function fmt_short_tb(float $n): string {
          if ($n >= 1_000_000_000) return 'Rp ' . number_format($n/1_000_000_000, 1, '.', '') . 'M';
          if ($n >= 1_000_000)     return 'Rp ' . number_format($n/1_000_000, 1, '.', '') . 'jt';
          if ($n >= 100_000)       return 'Rp ' . number_format($n/1_000, 0, '.', '') . 'rb';
          return 'Rp ' . number_format($n, 0, ',', '.');
        }
      }
      ?>
      <div class="island-cluster-pill">
        <!-- Balance Tap Area -->
        <div class="cluster-balance-btn" id="tb-saldo-trigger" onclick="toggleWalletPopover()" title="Klik untuk rincian dompet">
          <span class="pulse-emerald-dot"></span>
          <span class="cluster-bal-txt"><?= fmt_short_tb((float)$user['balance_wd']) ?></span>
          <div class="cluster-user-avatar"><?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?></div>
        </div>
        
        <span class="cluster-divider"></span>

        <!-- Notification Bell -->
        <a href="/notifications" class="cluster-bell-btn" id="notif-bell-btn" title="Notifikasi">
          <i class="ph-bold ph-bell-simple"></i>
          <span id="notif-badge" class="cluster-badge-count"></span>
        </a>
      </div>
      <?php else: ?>
      <div class="island-cluster-pill guest">
        <a href="/login" class="island-guest-link">Masuk</a>
        <a href="/register" class="island-guest-btn">Daftar</a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($user)): ?>
    <!-- Wallet Popover Drawer Modal -->
    <div class="tb-wallet-backdrop" id="tb-wallet-backdrop" onclick="toggleWalletPopover(false)"></div>
    <div class="tb-wallet-popover" id="tb-wallet-popover">
      <div class="twp-header">
        <div class="twp-title">
          <i class="ph-bold ph-wallet"></i> Dompet Anda
        </div>
        <button type="button" class="twp-close" onclick="toggleWalletPopover(false)">&times;</button>
      </div>
      <div class="twp-body">
        <!-- Card 1: Saldo WD -->
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon wd"><i class="ph-bold ph-coins"></i></div>
            <div>
              <div class="twp-card-lbl">Saldo Siap Tarik</div>
              <div class="twp-card-val"><?= format_rp((float)$user['balance_wd']) ?></div>
            </div>
          </div>
          <a href="/withdraw" class="twp-card-btn wd">Tarik &rarr;</a>
        </div>

        <!-- Card 2: Saldo Depo -->
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon dep"><i class="ph-bold ph-wallet"></i></div>
            <div>
              <div class="twp-card-lbl">Saldo Kas Pembelian</div>
              <div class="twp-card-val"><?= format_rp((float)$user['balance_dep']) ?></div>
            </div>
          </div>
          <a href="/deposit" class="twp-card-btn dep">+ Top Up</a>
        </div>

        <!-- Card 3: Umpan Pancing -->
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon baits"><i class="ph-fill ph-fish"></i></div>
            <div>
              <div class="twp-card-lbl">Umpan Pancing</div>
              <div class="twp-card-val"><?= (int)($user['fishing_baits'] ?? 0) ?> Umpan</div>
            </div>
          </div>
          <a href="/minigame" class="twp-card-btn baits">Mancing &rarr;</a>
        </div>
      </div>
    </div>

    <script>
    function toggleWalletPopover(force) {
      const popover = document.getElementById('tb-wallet-popover');
      const backdrop = document.getElementById('tb-wallet-backdrop');
      if (!popover || !backdrop) return;

      const shouldOpen = typeof force === 'boolean' ? force : !popover.classList.contains('open');
      if (shouldOpen) {
        popover.classList.add('open');
        backdrop.classList.add('open');
      } else {
        popover.classList.remove('open');
        backdrop.classList.remove('open');
      }
    }
    </script>
    <?php endif; ?>
  </div>

  <main class="page-content">

<?php if (!empty($user)): ?>
<script>
(function() {
  function fetchNotifCount() {
    fetch('/notif_action?action=count')
      .then(r => r.json())
      .then(data => {
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (data.count > 0) {
          badge.textContent = data.count > 9 ? '9+' : data.count;
          badge.style.display = 'inline-flex';
        } else {
          badge.style.display = 'none';
        }
      })
      .catch(() => {});
  }
  fetchNotifCount();
  setInterval(fetchNotifCount, 60000);
})();
</script>
<?php endif; ?>
