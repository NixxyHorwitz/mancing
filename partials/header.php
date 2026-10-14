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
/* ══ GLOBAL RESET & OCEANIC BACKGROUND ══ */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }
body {
  font-family: 'Nunito', 'Inter', sans-serif !important;
  background: #f0f7fc !important;
  background-image:
    radial-gradient(#bae6fd 1.5px, transparent 1.5px),
    radial-gradient(#bae6fd 1.5px, transparent 1.5px) !important;
  background-size: 26px 26px !important;
  background-position: 0 0, 13px 13px !important;
  padding-bottom: 86px !important;
  -webkit-font-smoothing: antialiased;
  color: #0f172a;
}
i[class^="ph-"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}
.app-shell { width: 100%; max-width: 480px; margin: 0 auto; }

/* ══ FLOATING OCEANIC CLAY CAPSULE TOPBAR ══ */
.topbar-wrap {
  position: sticky; top: 0; z-index: 1000;
  width: 100%;
  padding: 8px 10px 4px;
  background: linear-gradient(180deg, rgba(240, 247, 252, 0.96) 0%, rgba(240, 247, 252, 0.8) 75%, transparent 100%);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
.topbar {
  width: 100%;
  height: 54px;
  background: linear-gradient(135deg, #0369a1 0%, #0284c7 45%, #0ea5e9 100%);
  border: 2px solid #38bdf8;
  border-radius: 22px;
  box-shadow: 
    0 10px 24px rgba(2, 132, 199, 0.28),
    0 2px 6px rgba(0, 0, 0, 0.05),
    inset 0 2px 4px rgba(255, 255, 255, 0.55),
    inset 0 -2px 4px rgba(3, 105, 161, 0.35);
  display: flex;
  align-items: center;
}

.topbar__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 10px;
  height: 100%;
  width: 100%;
  gap: 8px;
}

/* ── Brand & Marine Mascot ── */
.tb-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  flex-shrink: 0;
}
.tb-mascot-box {
  width: 34px; height: 34px;
  background: #ffffff;
  border: 1.5px solid #bae6fd;
  border-radius: 12px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.2),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.12);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
}
.tb-mascot-img {
  width: 24px; height: 24px;
  object-fit: contain;
  animation: tbLogoFloat 2.6s ease-in-out infinite;
}
@keyframes tbLogoFloat {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-2px) rotate(4deg); }
}

.tb-brand-info {
  display: flex;
  flex-direction: column;
  line-height: 1.05;
}
.tb-brand-name {
  font-size: 15px;
  font-weight: 900;
  color: #ffffff;
  text-shadow: 0 1.5px 3px rgba(3, 105, 161, 0.8);
  letter-spacing: -0.3px;
}
.tb-brand-name em {
  font-style: normal;
  color: #fde047;
  text-shadow: 0 1.5px 3px rgba(3, 105, 161, 0.8);
}
.tb-brand-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 7.5px;
  font-weight: 900;
  color: #0369a1;
  background: #e0f2fe;
  padding: 1.5px 6px;
  border-radius: 6px;
  border: 1px solid #7dd3fc;
  width: fit-content;
  margin-top: 1.5px;
  box-shadow: inset 1px 1px 2px #fff;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

/* ── Actions HUD ── */
.tb-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

/* ── Saldo Capsule (Marine Pearl Clay Pill) ── */
.tb-saldo-capsule {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #ffffff;
  border: 1.5px solid #bae6fd;
  border-radius: 999px;
  padding: 3px 9px 3px 4px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.16),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.08);
  cursor: pointer;
  user-select: none;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.12s;
}
.tb-saldo-capsule:active {
  transform: translateY(2px) scale(0.96);
  box-shadow: 
    0 1px 4px rgba(2, 132, 199, 0.14),
    inset 1px 1px 2px rgba(2, 132, 199, 0.15);
}
.tb-saldo-icon {
  width: 23px; height: 23px;
  background: linear-gradient(135deg, #fde047, #f59e0b);
  border: 1.2px solid #ffffff;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  color: #78350f;
  flex-shrink: 0;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.8), 0 2px 4px rgba(245, 158, 11, 0.35);
}
.tb-saldo-texts {
  display: flex;
  flex-direction: column;
  line-height: 1.05;
}
.tb-saldo-lbl {
  font-size: 7px;
  font-weight: 900;
  color: #0369a1;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}
.tb-saldo-val {
  font-size: 11px;
  font-weight: 900;
  color: #0c4a6e;
  white-space: nowrap;
}
.tb-saldo-plus {
  width: 15px; height: 15px;
  background: #e0f2fe;
  border: 1px solid #7dd3fc;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 8.5px;
  color: #0284c7;
  font-weight: 900;
  box-shadow: inset 1px 1px 2px #fff;
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.tb-saldo-capsule.active .tb-saldo-plus {
  transform: rotate(180deg);
}

/* ── Marine Buttons (Bell & Avatar) ── */
.tb-btn-icon {
  width: 32px; height: 32px;
  background: #ffffff;
  border: 1.5px solid #bae6fd;
  border-radius: 11px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.16),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.08);
  display: flex; align-items: center; justify-content: center;
  color: #0284c7;
  text-decoration: none;
  position: relative;
  font-weight: 900;
  font-size: 15px;
  flex-shrink: 0;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.tb-btn-icon:active {
  transform: translateY(2px) scale(0.94);
  box-shadow: 
    0 1px 3px rgba(2, 132, 199, 0.14),
    inset 1px 1px 2px rgba(2, 132, 199, 0.15);
}
.tb-avatar {
  background: linear-gradient(135deg, #e0f2fe, #bae6fd);
  color: #0369a1;
  font-size: 13px;
  border: 1.5px solid #7dd3fc;
}
.tb-badge-count {
  position: absolute;
  top: -4px; right: -4px;
  background: linear-gradient(135deg, #f43f5e, #e11d48);
  color: #fff;
  font-size: 8.5px;
  font-weight: 900;
  min-width: 16px; height: 16px;
  border-radius: 999px;
  padding: 0 3px;
  border: 1.5px solid #fff;
  box-shadow: 0 2px 5px rgba(225, 29, 72, 0.4);
  display: none;
  align-items: center; justify-content: center;
  line-height: 1;
}

/* Guest buttons */
.tb-login-btn {
  background: #ffffff;
  border: 1.5px solid #bae6fd;
  border-radius: 999px;
  padding: 5px 11px;
  color: #0284c7;
  font-weight: 900;
  font-size: 11px;
  text-decoration: none;
  box-shadow: 0 3px 6px rgba(2, 132, 199, 0.15), inset 1px 1px 2px #fff;
}
.tb-reg-btn {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.5px solid #fde68a;
  border-radius: 999px;
  padding: 5px 11px;
  color: #ffffff;
  font-weight: 900;
  font-size: 11px;
  text-decoration: none;
  box-shadow: 0 3px 6px rgba(217, 119, 6, 0.3), inset 1px 1px 2px rgba(255, 255, 255, 0.7);
}

@media (max-width: 360px) {
  .tb-brand-tag { display: none; }
  .tb-saldo-lbl { display: none; }
  .tb-saldo-capsule { padding: 3px 5px; }
  .tb-btn-icon { width: 30px; height: 30px; font-size: 14px; }
  .tb-brand-name { font-size: 14px; }
  .tb-mascot-box { width: 30px; height: 30px; }
  .tb-mascot-img { width: 21px; height: 21px; }
}

/* ── WALLET POPOVER DRAWER (UNDERWATER VAULT CLAY) ── */
.tb-wallet-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(12, 74, 110, 0.45);
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  z-index: 9998;
  animation: tbFadeIn .2s ease;
}
.tb-wallet-backdrop.open { display: block; }

.tb-wallet-popover {
  display: none;
  position: fixed;
  top: 66px; left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 20px); max-width: 420px;
  background: #ffffff;
  border: 2px solid #bae6fd;
  border-radius: 24px;
  box-shadow: 
    0 18px 40px rgba(2, 132, 199, 0.25),
    0 4px 10px rgba(0, 0, 0, 0.04),
    inset 3px 3px 7px #ffffff,
    inset -3px -3px 7px rgba(2, 132, 199, 0.06);
  z-index: 9999;
  overflow: hidden;
  animation: tbPopIn .22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.tb-wallet-popover.open { display: block; }

@keyframes tbPopIn {
  from { transform: translateX(-50%) translateY(-8px) scale(0.97); opacity: 0; }
  to { transform: translateX(-50%) translateY(0) scale(1); opacity: 1; }
}
@keyframes tbFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.twp-header {
  background: linear-gradient(135deg, #0369a1, #0284c7);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 2px solid #38bdf8;
  color: #fff;
  box-shadow: inset 0 1px 2px rgba(255, 255, 255, 0.4);
}
.twp-title {
  font-size: 13.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  gap: 6px;
  text-shadow: 0 1px 2px rgba(3, 105, 161, 0.6);
}
.twp-close {
  background: #ffffff;
  color: #0369a1;
  border: 1.5px solid #bae6fd;
  border-radius: 8px;
  width: 24px; height: 24px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(2, 132, 199, 0.15), inset 1px 1px 2px #fff;
  transition: transform 0.1s;
}
.twp-close:active { transform: scale(0.9); }

.twp-body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.twp-card {
  background: #f8fafc;
  border: 1.5px solid #e2e8f0;
  border-radius: 18px;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  box-shadow: 
    0 4px 10px rgba(2, 132, 199, 0.05),
    inset 2px 2px 4px #ffffff,
    inset -2px -2px 4px rgba(2, 132, 199, 0.04);
}
.twp-card-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.twp-card-icon {
  width: 36px; height: 36px;
  border-radius: 12px;
  border: 1.2px solid #bae6fd;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(2, 132, 199, 0.12), inset 1px 1px 2px #ffffff;
}
.twp-card-icon.wd { background: #e0f2fe; color: #0284c7; border-color: #7dd3fc; }
.twp-card-icon.dep { background: #fef3c7; color: #d97706; border-color: #fde68a; }
.twp-card-icon.baits { background: #ccfbf1; color: #0f766e; border-color: #99f6e4; }

.twp-card-info {
  display: flex;
  flex-direction: column;
}
.twp-card-lbl {
  font-size: 8.5px;
  font-weight: 800;
  color: #64748b;
  text-transform: uppercase;
  line-height: 1.1;
}
.twp-card-val {
  font-size: 13.5px;
  font-weight: 900;
  color: #0f172a;
  line-height: 1.2;
}

.twp-card-btn {
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 900;
  text-decoration: none;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1), inset 1px 1px 2px rgba(255,255,255,0.7);
  transition: transform 0.12s;
  display: inline-flex; align-items: center; gap: 4px;
}
.twp-card-btn:active { transform: translateY(1px) scale(0.95); }
.twp-card-btn.wd { background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; border: 1px solid #38bdf8; }
.twp-card-btn.dep { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border: 1px solid #fde68a; }
.twp-card-btn.baits { background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: 1px solid #6ee7b7; }
</style>
</head>
<body>
<div class="app-shell">
  <!-- ══ FLOATING OCEANIC TOPBAR ══ -->
  <div class="topbar-wrap">
    <header class="topbar">
      <div class="topbar__inner">
        <!-- Left: Logo & Marine Mascot -->
        <a href="/home" class="tb-brand">
          <div class="tb-mascot-box">
            <img src="<?= htmlspecialchars($_site_logo) ?>" alt="<?= htmlspecialchars($_site_name) ?>" class="tb-mascot-img">
          </div>
          <div class="tb-brand-info">
            <div class="tb-brand-name">Mancing<em>Cuan</em></div>
            <div class="tb-brand-tag"><i class="ph-bold ph-anchor"></i> Samudra Cuan</div>
          </div>
        </a>

        <!-- Right: Ocean HUD / Actions -->
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
        <div class="tb-actions">
          <!-- Saldo Capsule HUD -->
          <div class="tb-saldo-capsule" id="tb-saldo-trigger" onclick="toggleWalletPopover()" title="Klik untuk rincian dompet laut">
            <div class="tb-saldo-icon">
              <i class="ph-fill ph-coins"></i>
            </div>
            <div class="tb-saldo-texts">
              <span class="tb-saldo-lbl">SALDO WD</span>
              <span class="tb-saldo-val"><?= fmt_short_tb((float)$user['balance_wd']) ?></span>
            </div>
            <div class="tb-saldo-plus">
              <i class="ph-bold ph-caret-down"></i>
            </div>
          </div>

          <!-- Notifikasi Bahari -->
          <a href="/notifications" class="tb-btn-icon" id="notif-bell-btn" title="Pengumuman & Notifikasi">
            <i class="ph-bold ph-bell"></i>
            <span id="notif-badge" class="tb-badge-count"></span>
          </a>

          <!-- Avatar Pelaut -->
          <a href="/profile" class="tb-btn-icon tb-avatar" title="Kabin Akun">
            <?= strtoupper(substr($user['username'], 0, 1)) ?>
          </a>
        </div>
        <?php else: ?>
        <div class="tb-actions">
          <a href="/login" class="tb-login-btn">Masuk</a>
          <a href="/register" class="tb-reg-btn">Daftar</a>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($user)): ?>
      <!-- Wallet Popover Modal (Treasure Vault) -->
      <div class="tb-wallet-backdrop" id="tb-wallet-backdrop" onclick="toggleWalletPopover(false)"></div>
      <div class="tb-wallet-popover" id="tb-wallet-popover">
        <div class="twp-header">
          <div class="twp-title">
            <i class="ph-bold ph-wallet"></i> Peti Dompet Samudra
          </div>
          <button type="button" class="twp-close" onclick="toggleWalletPopover(false)">&times;</button>
        </div>
        <div class="twp-body">
          <!-- Card 1: Saldo WD -->
          <div class="twp-card">
            <div class="twp-card-left">
              <div class="twp-card-icon wd"><i class="ph-bold ph-coins"></i></div>
              <div class="twp-card-info">
                <span class="twp-card-lbl">Saldo Siap Tarik (WD)</span>
                <span class="twp-card-val"><?= format_rp((float)$user['balance_wd']) ?></span>
              </div>
            </div>
            <a href="/withdraw" class="twp-card-btn wd">Tarik &rarr;</a>
          </div>

          <!-- Card 2: Saldo Depo -->
          <div class="twp-card">
            <div class="twp-card-left">
              <div class="twp-card-icon dep"><i class="ph-bold ph-wallet"></i></div>
              <div class="twp-card-info">
                <span class="twp-card-lbl">Saldo Kas Pembelian (Depo)</span>
                <span class="twp-card-val"><?= format_rp((float)$user['balance_dep']) ?></span>
              </div>
            </div>
            <a href="/deposit" class="twp-card-btn dep">+ Isi Kas</a>
          </div>

          <!-- Card 3: Umpan Pancing -->
          <div class="twp-card">
            <div class="twp-card-left">
              <div class="twp-card-icon baits"><i class="ph-fill ph-fish"></i></div>
              <div class="twp-card-info">
                <span class="twp-card-lbl">Umpan Pancing Anda</span>
                <span class="twp-card-val"><?= (int)($user['fishing_baits'] ?? 0) ?> Umpan</span>
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
        const trigger = document.getElementById('tb-saldo-trigger');
        if (!popover || !backdrop) return;

        const shouldOpen = typeof force === 'boolean' ? force : !popover.classList.contains('open');
        if (shouldOpen) {
          popover.classList.add('open');
          backdrop.classList.add('open');
          if (trigger) trigger.classList.add('active');
        } else {
          popover.classList.remove('open');
          backdrop.classList.remove('open');
          if (trigger) trigger.classList.remove('active');
        }
      }
      </script>
      <?php endif; ?>
    </header>
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
