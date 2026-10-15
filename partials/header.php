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
/* ══════════════════════════════════════════════════════════
   VIBRANT LIVING CORAL REEF — GLOBAL TOKENS & BACKGROUND
   ══════════════════════════════════════════════════════════ */
:root {
  /* Coral Pink & Anemone Rose */
  --reef-coral-500: #f43f5e; --reef-coral-400: #fb7185; --reef-coral-200: #fda4af;
  /* Tropical Sun Gold & Amber Pearl */
  --reef-sun-500: #f59e0b; --reef-sun-400: #fbbf24; --reef-sun-200: #fef08a;
  /* Seaweed & Emerald Lagoon */
  --reef-kelp-500: #10b981; --reef-kelp-600: #059669; --reef-kelp-400: #34d399;
  /* Deep Sea Violet & Coral Purple */
  --reef-glow-500: #8b5cf6; --reef-glow-450: #a855f7; --reef-glow-300: #c084fc;
  /* Warm Shell Sand & Soft Peach */
  --reef-sand-50: #fff7ed; --reef-sand-100: #ffedd5;
  --reef-lagoon-400: #22d3ee; --reef-lagoon-600: #0891b2;
  --reef-ink: #1e1b2e;
  --reef-muted: #6b6478;
  /* Border polos putih (clay), warna cukup di isi elemen */
  --reef-border: linear-gradient(#ffffff, #ffffff);
  --reef-card: linear-gradient(160deg, #ffffff 0%, #fff7ed 58%, #ffedd5 100%);
  /* Tactile cushion: soft double drop + glossy top sheen + warm inner bottom */
  --reef-clay-shadow:
    0 14px 30px rgba(244, 63, 94, 0.10),
    0 6px 14px rgba(8, 145, 178, 0.10),
    inset 3px 3px 6px rgba(255, 255, 255, 0.95),
    inset -4px -4px 9px rgba(251, 146, 60, 0.16);
}

*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }
body {
  font-family: 'Nunito', 'Inter', -apple-system, sans-serif !important;
  background-color: #ecfeff !important;
  background-image:
    radial-gradient(circle at 10% 6%, rgba(253, 164, 175, 0.22) 0, transparent 36%),
    radial-gradient(circle at 94% 20%, rgba(192, 132, 252, 0.14) 0, transparent 38%),
    radial-gradient(circle at 16% 74%, rgba(52, 211, 153, 0.12) 0, transparent 40%),
    radial-gradient(circle at 88% 94%, rgba(251, 191, 36, 0.14) 0, transparent 38%),
    linear-gradient(180deg, #ecfeff 0%, #f0f9ff 34%, #fff7ed 100%) !important;
  background-size: auto !important;
  background-attachment: fixed !important;
  background-position: 0 0 !important;
  padding-bottom: 84px !important;
  -webkit-font-smoothing: antialiased;
  color: var(--reef-ink);
}
/* Glowing plankton dust (GPU transform only, seamless 192px loop) */
body::before {
  content: '';
  position: fixed;
  inset: 0 0 -192px 0;
  z-index: 0;
  pointer-events: none;
  background-image:
    radial-gradient(rgba(251, 113, 133, 0.45) 1.3px, transparent 1.8px),
    radial-gradient(rgba(34, 211, 238, 0.40) 1px, transparent 1.6px),
    radial-gradient(rgba(192, 132, 252, 0.40) 1.5px, transparent 2px),
    radial-gradient(rgba(251, 191, 36, 0.40) 1.1px, transparent 1.6px);
  background-size: 48px 48px, 32px 32px, 64px 64px, 96px 96px;
  background-position: 0 0, 13px 17px, 29px 7px, 51px 41px;
  opacity: 0.55;
  animation: planktonDrift 38s linear infinite;
  will-change: transform;
}
@keyframes planktonDrift {
  from { transform: translateY(0); }
  to   { transform: translateY(-192px); }
}
i[class^="ph-"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}
.app-shell { width: 100%; max-width: 440px; margin: 0 auto; position: relative; }

/* ══ TOP SHEET APP BAR — satu panel menempel di atas, sudut bawah membulat ══ */
.topbar-wrap {
  position: sticky; top: 0; z-index: 1000;
  width: 100%;
  padding: 0;
}
.topbar-floating-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: 8px;
  height: 52px;
  padding: 0 10px 0 12px;
  background: rgba(255, 255, 255, 0.94);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-radius: 0 0 20px 20px;
  box-shadow:
    0 6px 18px rgba(30, 27, 46, 0.07),
    inset 0 -3px 6px rgba(251, 146, 60, 0.08);
}

/* Brand: logo situs + nama dua warna + nama halaman */
.island-brand-pill {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
  flex: 1 1 auto;
  text-decoration: none;
  transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.brand-logo {
  position: relative;
  width: 38px; height: 38px;
  flex-shrink: 0;
  border-radius: 13px;
  padding: 2px;
  background: linear-gradient(145deg, #ffffff, #ffedd5);
  box-shadow:
    0 4px 10px rgba(8, 145, 178, 0.22),
    0 2px 4px rgba(244, 63, 94, 0.12),
    inset 1.5px 1.5px 3px #ffffff,
    inset -2px -2px 4px rgba(251, 146, 60, 0.22);
}
.brand-logo img {
  display: block; width: 100%; height: 100%;
  object-fit: contain; border-radius: 11px;
  animation: brandBob 4.5s ease-in-out infinite;
}
.brand-logo .brand-fin-icon { width: 100%; height: 100%; border-radius: 11px; }
@keyframes brandBob { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-1.5px) rotate(-3deg); } }
.brand-copy { display: flex; flex-direction: column; min-width: 0; line-height: 1.05; }
.brand-name {
  font-size: 16px; font-weight: 900; letter-spacing: -0.4px;
  color: var(--reef-lagoon-600);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.brand-name b {
  font-weight: 900;
  background: linear-gradient(135deg, var(--reef-sun-500), var(--reef-coral-500));
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.brand-page {
  margin-top: 2px;
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 800; color: var(--reef-muted);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.brand-page::before {
  content: ''; width: 5px; height: 5px; flex-shrink: 0; border-radius: 50%;
  background: var(--reef-coral-400); box-shadow: 0 0 0 2px rgba(251, 113, 133, 0.2);
}
.island-brand-pill:active { transform: scale(0.97); }
.brand-fin-icon {
  width: 28px; height: 28px;
  background: linear-gradient(145deg, #fdba74 0%, #fb923c 45%, #f43f5e 100%);
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  color: #ffffff;
  font-size: 15px;
  box-shadow:
    0 3px 8px rgba(244, 63, 94, 0.28),
    inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.7),
    inset -2px -2px 3px rgba(190, 18, 60, 0.28);
}
.brand-text {
  font-size: 14px;
  font-weight: 800;
  color: var(--reef-ink);
  letter-spacing: -0.4px;
  display: flex;
  align-items: baseline;
}
.brand-dot { color: var(--reef-sun-500); font-weight: 900; }
.brand-sub { color: var(--reef-coral-500); font-weight: 900; }

/* Kanan: chip saldo + tombol notifikasi bulat */
.island-cluster-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.cluster-balance-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 4px 0 10px;
  border-radius: 999px;
  background: #f0fdf4;
  box-shadow: inset 1.5px 1.5px 3px #ffffff, inset -1.5px -1.5px 3px rgba(16, 185, 129, 0.12);
  cursor: pointer;
  user-select: none;
  transition: transform 0.12s;
}
.cluster-balance-btn:active { transform: scale(0.96); }
.pulse-emerald-dot {
  width: 6px; height: 6px;
  border-radius: 50%;
  background: var(--reef-kelp-500);
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
  animation: pulseAura 2.2s infinite ease-in-out;
}
@keyframes pulseAura {
  0%, 100% { box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25); }
  50% { box-shadow: 0 0 0 4.5px rgba(16, 185, 129, 0.12); }
}
.cluster-bal-txt {
  font-size: 11.5px;
  font-weight: 900;
  color: var(--reef-kelp-600);
  letter-spacing: -0.2px;
}
.cluster-user-avatar {
  width: 24px; height: 24px;
  border-radius: 50%;
  background: linear-gradient(145deg, #c084fc, #8b5cf6);
  color: #ffffff;
  font-size: 10px;
  font-weight: 900;
  display: flex; align-items: center; justify-content: center;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.5);
}
.cluster-divider { display: none; }
.cluster-bell-btn {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: #f97316;
  font-size: 16px;
  text-decoration: none;
  position: relative;
  background: #fff7ed;
  box-shadow: inset 1.5px 1.5px 3px #fff, inset -1.5px -1.5px 3px rgba(251, 146, 60, 0.18);
  transition: transform 0.12s;
}
.cluster-bell-btn:active { transform: scale(0.92); }
.cluster-badge-count {
  position: absolute;
  top: -2px; right: -2px;
  background: var(--reef-coral-500);
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
.island-cluster-pill.guest { gap: 2px; }
.island-guest-link {
  color: var(--reef-coral-500); font-size: 11px; font-weight: 900; text-decoration: none; padding: 4px 8px;
}
.island-guest-btn {
  background: linear-gradient(145deg, #fb7185, #f43f5e); color: #fff; font-size: 11px; font-weight: 900; text-decoration: none;
  padding: 6px 12px; border-radius: 999px;
  box-shadow: 0 3px 8px rgba(244, 63, 94, 0.28), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5);
}


/* ── WALLET POPOVER DRAWER ── */
.tb-wallet-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(30, 27, 46, 0.35);
  backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  z-index: 9998;
}
.tb-wallet-backdrop.open { display: block; }

.tb-wallet-popover {
  display: none;
  position: fixed;
  top: 58px; left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 24px); max-width: 400px;
  border: 2px solid #ffffff;
  background: var(--reef-card);
  border-radius: 20px;
  box-shadow: 0 20px 48px rgba(244, 63, 94, 0.14), 0 6px 16px rgba(8, 145, 178, 0.10), inset 2px 2px 5px #fff;
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
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 6px;
}
.twp-close {
  background: #ffffff;
  color: var(--reef-muted);
  border: none;
  border-radius: 50%;
  width: 26px; height: 26px;
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
  background: #ffffff;
  border: none;
  border-radius: 14px;
  padding: 8px 11px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  box-shadow: inset 2px 2px 4px #fff, inset -2px -2px 4px rgba(251, 146, 60, 0.08);
}
.twp-card-left { display: flex; align-items: center; gap: 9px; }
.twp-card-icon {
  width: 32px; height: 32px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
}
.twp-card-icon.wd { background: #ffe4e6; color: #e11d48; }
.twp-card-icon.dep { background: #d1fae5; color: #059669; }
.twp-card-icon.baits { background: #fef3c7; color: #d97706; }
.twp-card-lbl {
  font-size: 8px;
  font-weight: 800;
  color: var(--reef-muted);
  text-transform: uppercase;
}
.twp-card-val { font-size: 13px; font-weight: 900; color: var(--reef-ink); }
.twp-card-btn {
  padding: 5px 11px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 900;
  text-decoration: none;
  color: #fff;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.5);
}
.twp-card-btn.wd { background: linear-gradient(145deg, #fb7185, #f43f5e); }
.twp-card-btn.dep { background: linear-gradient(145deg, #34d399, #059669); }
.twp-card-btn.baits { background: linear-gradient(145deg, #fbbf24, #d97706); }

@media (prefers-reduced-motion: reduce) {
  body::before, .pulse-emerald-dot, .brand-logo img { animation: none !important; }
}
</style>
</head>
<body>
<div class="app-shell">
  <!-- ══ DUAL FLOATING HORIZON ISLANDS ══ -->
  <div class="topbar-wrap">
    <div class="topbar-floating-row">
      <!-- Left Island: Geometric Nautical Brand -->
      <?php
        // Nama situs dua warna: "MancingCuan" -> "Mancing" + "Cuan"
        $_brand_a = $_site_name; $_brand_b = '';
        if (preg_match('/^(.+?)([A-Z][a-z0-9]+)$/u', trim($_site_name), $_bm)) { $_brand_a = $_bm[1]; $_brand_b = $_bm[2]; }
        $_brand_page = trim((string)($pageTitle ?? ''));
      ?>
      <a href="/home" class="island-brand-pill" aria-label="<?= htmlspecialchars($_site_name) ?> — Beranda">
        <span class="brand-logo">
          <?php if ($_site_logo): ?>
          <img src="<?= htmlspecialchars($_site_logo) ?>" alt="" width="34" height="34" decoding="async"
               onerror="this.outerHTML='<span class=&quot;brand-fin-icon&quot;><i class=&quot;ph-fill ph-fish-simple&quot;></i></span>'">
          <?php else: ?>
          <span class="brand-fin-icon"><i class="ph-fill ph-fish-simple"></i></span>
          <?php endif; ?>
        </span>
        <span class="brand-copy">
          <span class="brand-name"><?= htmlspecialchars($_brand_a) ?><?php if ($_brand_b !== ''): ?><b><?= htmlspecialchars($_brand_b) ?></b><?php endif; ?></span>
          <?php if ($_brand_page !== ''): ?><span class="brand-page"><?= htmlspecialchars($_brand_page) ?></span><?php endif; ?>
        </span>
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
