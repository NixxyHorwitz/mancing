<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan Resmi';
$activePage = 'panduan';

$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');
$ref_pct     = (float) setting($pdo, 'referral_commission_percent', '5');
$ref_hold    = (int)   setting($pdo, 'referral_hold_days', '3');
$ci_min      = (float) setting($pdo, 'checkin_reward_min', '500');
$ci_max      = max($ci_min, (float) setting($pdo, 'checkin_reward_max', '2000'));
$thr_reward  = (float) setting($pdo, 'threads_campaign_reward', '25000');
$thr_reward2 = (float) setting($pdo, 'threads_campaign_reward_step2', '50000');

$wd_free_dana = setting($pdo, 'wd_free_only_dana', '1') === '1';
$wd_free_1x   = setting($pdo, 'wd_free_limit_1x', '1') === '1';
$wd_free_1day = setting($pdo, 'wd_free_require_1day', '1') === '1';
$wd_lock_s    = setting($pdo, 'wd_lock_start', '');
$wd_lock_e    = setting($pdo, 'wd_lock_end', '');

$panduan_intro    = setting($pdo, 'panduan_intro', 'Semua cara panen cuan di MancingCuan: nonton video, mancing, misi, sampai tarik saldo.');
$panduan_faq      = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text', 'Mulai Nonton Video');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url', '/videos');

try {
    $memberships = $pdo->query("SELECT name, price, watch_limit, duration_days, min_wd, max_wd FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
} catch (\Throwable) { $memberships = []; }

// Ringkasan misi aktif per kategori
$mission_sum = [];
try {
    foreach ($pdo->query("SELECT category, COUNT(*) n, SUM(reward_amount) total FROM missions WHERE is_active = 1 GROUP BY category") as $r) {
        $mission_sum[$r['category']] = ['n' => (int)$r['n'], 'total' => (float)$r['total']];
    }
} catch (\Throwable) {}

// Umpan, kunci ikan & joran
try { $bait_packs = $pdo->query("SELECT name, qty, price FROM fishing_bait_packs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(); } catch (\Throwable) { $bait_packs = []; }
try { $rod_count = (int)$pdo->query("SELECT COUNT(*) FROM fishing_rod_skins WHERE is_active = 1")->fetchColumn(); } catch (\Throwable) { $rod_count = 0; }
$fe          = function_exists('home_cms_config') ? (home_cms_config($pdo)['fishing_engine'] ?? []) : [];
$lock_price  = max(0, (float)($fe['lock_price'] ?? 2000));
$lock_free   = max(0, (int)($fe['lock_free_trials'] ?? 2));
$bait_from   = $bait_packs ? min(array_map(fn($b) => (float)$b['price'] / max(1, (int)$b['qty']), $bait_packs)) : 0;

try {
    $wallets = $pdo->query("SELECT name FROM payment_channels WHERE is_active = 1 ORDER BY type DESC, sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable) { $wallets = []; }

$free_name = '';
foreach ($memberships as $m) if ((float)$m['price'] <= 0) { $free_name = (string)$m['name']; break; }

$rp_short = static function (float $v): string {
    if ($v >= 1000000) return 'Rp' . rtrim(rtrim(number_format($v / 1000000, 1, ',', ''), '0'), ',') . 'jt';
    if ($v >= 1000) return 'Rp' . rtrim(rtrim(number_format($v / 1000, 1, ',', ''), '0'), ',') . 'rb';
    return 'Rp' . number_format($v, 0, ',', '.');
};

require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ PANDUAN — Peta Harta Samudra · living coral reef clay ══ */
.pd { width: 100%; max-width: 440px; margin: 0 auto; padding: 6px 12px 28px; display: flex; flex-direction: column; gap: 12px; }
.pd-top { display: flex; align-items: center; gap: 8px; }
.pd-back { width: 34px; height: 34px; border-radius: 11px; border: 1.5px solid #fff; background: #fff; display: inline-flex; align-items: center; justify-content: center; color: var(--reef-ink); font-size: 16px; text-decoration: none; box-shadow: 0 3px 8px rgba(30, 27, 46, .06); flex-shrink: 0; }
.pd-top h1 { flex: 1; margin: 0; font-size: 15px; font-weight: 900; color: var(--reef-ink); }
.pd-chipx { display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 900; color: #7e22ce; background: #f3e8ff; border: 1.5px solid #fff; }

.ico { width: 30px; height: 30px; border-radius: 10px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; color: #fff;
  box-shadow: 0 3px 6px var(--sh, rgba(0, 0, 0, .12)), inset 1.5px 1.5px 3px rgba(255, 255, 255, .55), inset -1.5px -1.5px 3px rgba(0, 0, 0, .15); }
.i-coral  { background: linear-gradient(145deg, #fda4af, #f43f5e); --sh: rgba(244, 63, 94, .32); }
.i-lagoon { background: linear-gradient(145deg, #a5f3fc, #0891b2); --sh: rgba(8, 145, 178, .3); }
.i-kelp   { background: linear-gradient(145deg, #6ee7b7, #059669); --sh: rgba(5, 150, 105, .3); }
.i-violet { background: linear-gradient(145deg, #c084fc, #7c3aed); --sh: rgba(124, 58, 237, .32); }
.i-sun    { background: linear-gradient(145deg, #fde68a, #f59e0b); --sh: rgba(245, 158, 11, .32); }
.i-sand   { background: linear-gradient(145deg, #fed7aa, #ea580c); --sh: rgba(234, 88, 12, .3); }

/* Hero */
.pd-hero { border: 2px solid #fff; border-radius: 24px; padding: 6px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.pd-hero-in { position: relative; overflow: hidden; border-radius: 19px; padding: 14px; color: #fff;
  background: radial-gradient(circle at 90% 115%, rgba(254, 240, 138, .5) 0, transparent 45%), radial-gradient(circle at 0 0, rgba(192, 132, 252, .55) 0, transparent 50%),
    linear-gradient(135deg, #0e7490 0%, #7c3aed 45%, #db2777 80%, #fb923c 100%);
  box-shadow: inset 2px 2px 6px rgba(255, 255, 255, .3), inset -3px -4px 10px rgba(30, 10, 60, .25); }
.pd-hero-in::before { content: ''; position: absolute; top: 5px; left: 14px; width: 40%; height: 9px; border-radius: 999px; background: linear-gradient(90deg, rgba(255, 255, 255, .3), transparent); }
.pd-hero small { font-size: 9.5px; font-weight: 900; letter-spacing: .7px; text-transform: uppercase; opacity: .85; }
.pd-hero h2 { margin: 2px 0 4px; font-size: 20px; font-weight: 900; letter-spacing: -.5px; line-height: 1.1; text-shadow: 0 2px 8px rgba(30, 10, 60, .3); max-width: 72%; }
.pd-hero p { margin: 0; font-size: 11px; font-weight: 700; line-height: 1.45; opacity: .92; max-width: 74%; }
.pd-hero-art { position: absolute; right: -6px; bottom: -6px; width: 112px; height: 112px; opacity: .95; }
.pd-tour { position: relative; z-index: 2; margin-top: 11px; display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px 7px 7px; border-radius: 13px; border: 0; cursor: pointer;
  background: #fff; color: #7c3aed; font: 900 11.5px/1 'Nunito', sans-serif; box-shadow: 0 4px 10px rgba(30, 10, 60, .25); }
.pd-tour .ico { width: 24px; height: 24px; border-radius: 8px; font-size: 12px; }
.pd-tour em { font-style: normal; font-size: 9px; padding: 2px 6px; border-radius: 6px; background: #f3e8ff; }
.pd-tour em.done { background: #d1fae5; color: #047857; }

/* Jalur 3 langkah */
.pd-path { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; align-items: center; gap: 4px; }
.pd-path a { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 9px 4px; border-radius: 16px; border: 2px solid #fff; background: #fff; text-decoration: none;
  box-shadow: 0 4px 10px rgba(30, 27, 46, .05), inset -2px -2px 4px rgba(251, 146, 60, .1); }
.pd-path b { font-size: 10.5px; font-weight: 900; color: var(--reef-ink); text-align: center; line-height: 1.15; }
.pd-path > i { color: #94a3b8; font-size: 13px; }

/* Navigasi bab */
.pd-nav { display: flex; gap: 6px; overflow-x: auto; padding: 4px 2px; margin: -2px -2px; scrollbar-width: none; }
.pd-nav::-webkit-scrollbar { display: none; }
.pd-nav a { flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; padding: 6px 11px; border-radius: 999px; background: rgba(255, 255, 255, .92); border: 1.5px solid #fff;
  font-size: 11px; font-weight: 900; color: var(--reef-muted); text-decoration: none; box-shadow: 0 2px 6px rgba(30, 27, 46, .06); backdrop-filter: blur(6px); }
.pd-nav a.on { background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff; }

/* Bab */
.pd-sec { scroll-margin-top: 70px; }
.pd-h { display: flex; align-items: center; gap: 8px; margin: 2px 2px 8px; }
.pd-h h3 { flex: 1; margin: 0; font-size: 13.5px; font-weight: 900; color: var(--reef-ink); letter-spacing: -.2px; }
.pd-h span { font-size: 10px; font-weight: 800; color: var(--reef-muted); }
.pd-card { border: 2px solid #fff; border-radius: 20px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); padding: 11px; }

/* Bento sumber cuan */
.pd-bento { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pd-tile { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 10px; border-radius: 17px; border: 2px solid #fff; background: #fff; text-decoration: none; color: inherit;
  box-shadow: 0 6px 14px rgba(30, 27, 46, .05), inset -2px -2px 5px rgba(251, 146, 60, .1); transition: transform .14s cubic-bezier(.34, 1.56, .64, 1); }
.pd-tile:active { transform: scale(.97); }
.pd-tile.wide { grid-column: 1 / -1; flex-direction: row; align-items: center; }
.pd-tile b { font-size: 12px; font-weight: 900; color: var(--reef-ink); line-height: 1.2; }
.pd-tile p { margin: 0; font-size: 10px; font-weight: 700; color: var(--reef-muted); line-height: 1.35; }
.pd-tile .amt { position: absolute; top: 9px; right: 9px; font-size: 9px; font-weight: 900; padding: 2px 7px; border-radius: 999px; background: #ecfdf5; color: #047857; }
.pd-tile.wide .tx { flex: 1; min-width: 0; }
.pd-tile.wide .amt { position: static; flex-shrink: 0; }

/* Alur mancing */
.pd-flow { position: relative; display: flex; flex-direction: column; gap: 9px; padding-left: 4px; }
.pd-flow::before { content: ''; position: absolute; left: 18px; top: 14px; bottom: 14px; width: 2px; background: repeating-linear-gradient(180deg, #a5f3fc 0 5px, transparent 5px 9px); }
.pd-st { position: relative; display: flex; gap: 10px; align-items: flex-start; }
.pd-st .ico { position: relative; z-index: 1; }
.pd-st b { display: block; font-size: 12px; font-weight: 900; color: var(--reef-ink); }
.pd-st p { margin: 1px 0 0; font-size: 10.5px; font-weight: 700; color: var(--reef-muted); line-height: 1.4; }
.pd-st p strong { color: var(--reef-ink); }
.pd-pills { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.pd-pills span { font-size: 9.5px; font-weight: 900; padding: 2px 7px; border-radius: 7px; background: #fff; border: 1.5px solid #fff; box-shadow: 0 1px 3px rgba(30, 27, 46, .08); }

.pd-rar { display: grid; grid-template-columns: repeat(6, 1fr); gap: 5px; margin-top: 10px; }
.pd-rar div { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 5px 2px; border-radius: 12px; background: #fff; box-shadow: 0 2px 5px rgba(30, 27, 46, .06); }
.pd-rar img { width: 32px; height: 32px; object-fit: contain; }
.pd-rar span { font-size: 8.5px; font-weight: 900; text-align: center; line-height: 1.1; }

/* Dua saldo */
.pd-wallets { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pd-wal { border-radius: 17px; padding: 10px; color: #fff; position: relative; overflow: hidden; box-shadow: inset 2px 2px 5px rgba(255, 255, 255, .3), inset -3px -3px 8px rgba(0, 0, 0, .15), 0 8px 16px var(--sh); }
.pd-wal.wd { background: linear-gradient(145deg, #34d399, #047857); --sh: rgba(4, 120, 87, .25); }
.pd-wal.dep { background: linear-gradient(145deg, #38bdf8, #4f46e5); --sh: rgba(79, 70, 229, .25); }
.pd-wal i.big { position: absolute; right: -6px; bottom: -8px; font-size: 54px; opacity: .18; }
.pd-wal b { display: block; font-size: 12.5px; font-weight: 900; }
.pd-wal small { display: block; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: .5px; opacity: .85; margin: 6px 0 2px; }
.pd-wal ul { margin: 0; padding: 0; list-style: none; font-size: 10px; font-weight: 800; line-height: 1.45; }
.pd-wal li::before { content: '• '; opacity: .7; }

/* Tabel pangkat */
.pd-rank { display: flex; flex-direction: column; gap: 6px; }
.pd-rk { display: grid; grid-template-columns: 1fr auto; gap: 2px 8px; padding: 8px 10px; border-radius: 14px; background: #fff; box-shadow: 0 2px 6px rgba(30, 27, 46, .05); }
.pd-rk b { font-size: 12px; font-weight: 900; color: var(--reef-ink); }
.pd-rk .pr { font-size: 11px; font-weight: 900; color: #e11d48; text-align: right; }
.pd-rk .pr.free { color: #059669; }
.pd-rk .meta { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 4px; }
.pd-rk .meta span { font-size: 9.5px; font-weight: 800; color: var(--reef-muted); background: #fff7ed; padding: 2px 7px; border-radius: 7px; display: inline-flex; align-items: center; gap: 3px; }

/* Aturan */
.pd-rules { display: flex; flex-direction: column; gap: 7px; }
.pd-rule { display: flex; gap: 8px; align-items: flex-start; font-size: 11px; font-weight: 700; color: var(--reef-muted); line-height: 1.4; }
.pd-rule .ico { width: 24px; height: 24px; border-radius: 8px; font-size: 12px; }
.pd-rule strong { color: var(--reef-ink); }

.pd-btn { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 10px; padding: 10px; border-radius: 13px; border: 2px solid #fff; font-size: 11.5px; font-weight: 900; text-decoration: none; }
.pd-btn.lag { background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff; box-shadow: 0 3px 0 #0e7490; }
.pd-btn.sun { background: linear-gradient(135deg, #fef08a, #f59e0b); color: #78350f; box-shadow: 0 3px 0 #b45309; }

/* FAQ */
.pd-faq { display: flex; flex-direction: column; gap: 6px; }
.pd-q { border-radius: 14px; background: #fff; box-shadow: 0 2px 6px rgba(30, 27, 46, .05); overflow: hidden; }
.pd-q summary { list-style: none; cursor: pointer; padding: 10px 11px; display: flex; align-items: center; gap: 8px; font-size: 11.5px; font-weight: 900; color: var(--reef-ink); }
.pd-q summary::-webkit-details-marker { display: none; }
.pd-q summary i { margin-left: auto; color: #0891b2; transition: transform .2s; }
.pd-q[open] summary i { transform: rotate(180deg); }
.pd-q div { padding: 0 11px 10px; font-size: 11px; font-weight: 700; color: var(--reef-muted); line-height: 1.5; }

.pd-cta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pd-cta a { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 8px; border-radius: 15px; border: 2px solid #fff; font-size: 12px; font-weight: 900; text-decoration: none; color: #fff; }
.pd-cta .a1 { background: linear-gradient(135deg, #fb7185, #e11d48); box-shadow: 0 4px 0 #be123c, 0 8px 16px rgba(225, 29, 72, .25); }
.pd-cta .a2 { background: linear-gradient(135deg, #22d3ee, #0891b2); box-shadow: 0 4px 0 #0e7490, 0 8px 16px rgba(8, 145, 178, .25); }
</style>

<div class="pd">

  <div class="pd-top">
    <a href="/home" class="pd-back" title="Kembali"><i class="ph-bold ph-caret-left"></i></a>
    <h1>Panduan</h1>
    <span class="pd-chipx"><i class="ph-fill ph-book-open-text"></i>Resmi</span>
  </div>

  <!-- HERO -->
  <section class="pd-hero">
    <div class="pd-hero-in">
      <small>Peta Harta Samudra</small>
      <h2>Cara panen cuan di MancingCuan</h2>
      <p><?= htmlspecialchars($panduan_intro) ?></p>
      <button type="button" class="pd-tour" onclick="startPanduanTour('watch')">
        <span class="ico i-violet"><i class="ph-fill ph-compass"></i></span>Tur interaktif <em id="badge-tour-watch">Mulai</em>
      </button>
      <svg class="pd-hero-art" viewBox="0 0 112 112" aria-hidden="true">
        <circle cx="56" cy="56" r="44" fill="#fff" opacity=".12"/>
        <circle cx="56" cy="56" r="30" fill="#fff" opacity=".12"/>
        <g transform="translate(56 56)">
          <path d="M0 -34 L7 -7 L0 0 L-7 -7 Z" fill="#fef08a"/><path d="M0 34 L7 7 L0 0 L-7 7 Z" fill="#fff" opacity=".8"/>
          <path d="M-34 0 L-7 -7 L0 0 L-7 7 Z" fill="#fff" opacity=".8"/><path d="M34 0 L7 -7 L0 0 L7 7 Z" fill="#fff" opacity=".8"/>
          <circle r="5" fill="#fb923c" stroke="#fff" stroke-width="2"/>
        </g>
        <circle cx="20" cy="22" r="3" fill="#fff" opacity=".7"/><circle cx="92" cy="30" r="2" fill="#fff" opacity=".6"/>
      </svg>
    </div>
  </section>

  <!-- 3 LANGKAH -->
  <div class="pd-path">
    <a href="/videos"><span class="ico i-coral"><i class="ph-fill ph-play"></i></span><b>Nonton &amp; Mancing</b></a>
    <i class="ph-bold ph-caret-right"></i>
    <a href="/missions"><span class="ico i-sun"><i class="ph-fill ph-target"></i></span><b>Kejar Misi</b></a>
    <i class="ph-bold ph-caret-right"></i>
    <a href="/withdraw"><span class="ico i-kelp"><i class="ph-fill ph-hand-coins"></i></span><b>Tarik Saldo</b></a>
  </div>

  <nav class="pd-nav" id="pd-nav">
    <a href="#cuan" class="on">Sumber Cuan</a>
    <a href="#mancing">Mancing</a>
    <a href="#saldo">Saldo</a>
    <a href="#pangkat">Pangkat</a>
    <a href="#tarik">Penarikan</a>
    <a href="#faq">Tanya Jawab</a>
  </nav>

  <!-- 1. SUMBER CUAN -->
  <section class="pd-sec" id="cuan">
    <div class="pd-h"><span class="ico i-coral"><i class="ph-fill ph-coins"></i></span><h3>Sumber Cuan</h3><span>semua masuk Saldo Tarik</span></div>
    <div class="pd-bento">
      <a href="/videos" class="pd-tile"><span class="ico i-coral"><i class="ph-fill ph-film-strip"></i></span><b>Nonton Video</b><p>Tonton sampai hitungan selesai, reward langsung masuk.</p></a>
      <a href="/minigame" class="pd-tile"><span class="ico i-lagoon"><i class="ph-fill ph-fish-simple"></i></span><b>Mancing</b><p>Tangkap ikan, jual di Pasar Ikan jadi saldo.</p></a>
      <a href="/missions" class="pd-tile"><?php if (!empty($mission_sum)): ?><span class="amt"><?= count($mission_sum) ? array_sum(array_column($mission_sum, 'n')) : 0 ?> misi</span><?php endif; ?>
        <span class="ico i-sun"><i class="ph-fill ph-target"></i></span><b>Misi</b><p>Harian, mingguan &amp; sekali seumur akun.</p></a>
      <a href="/checkin" class="pd-tile"><span class="amt"><?= $rp_short($ci_min) ?>–<?= $rp_short($ci_max) ?></span>
        <span class="ico i-kelp"><i class="ph-fill ph-calendar-check"></i></span><b>Check-in</b><p>Klaim hadiah acak sekali sehari.</p></a>
      <a href="/referral" class="pd-tile"><span class="amt"><?= $rp_short($ref_bonus) ?>/kawan</span>
        <span class="ico i-violet"><i class="ph-fill ph-users-three"></i></span><b>Ajak Kawan</b><p>+<?= rtrim(rtrim(number_format($ref_pct, 1, ',', ''), '0'), ',') ?>% dari isi kas mereka.</p></a>
      <a href="/redeem" class="pd-tile"><span class="ico i-sand"><i class="ph-fill ph-ticket"></i></span><b>Kode Redeem</b><p>Tukar kode dari event &amp; pengumuman.</p></a>
      <a href="/threads" class="pd-tile wide"><span class="ico i-violet"><i class="ph-fill ph-megaphone"></i></span>
        <div class="tx"><b>Event Threads</b><p>Promosikan MancingCuan di Threads, kirim bukti, tunggu verifikasi.</p></div>
        <span class="amt"><?= $rp_short($thr_reward) ?>–<?= $rp_short($thr_reward2) ?></span></a>
    </div>
  </section>

  <!-- 2. MANCING -->
  <section class="pd-sec" id="mancing">
    <div class="pd-h"><span class="ico i-lagoon"><i class="ph-fill ph-fish"></i></span><h3>Cara Mancing</h3><span>dari umpan sampai cuan</span></div>
    <div class="pd-card">
      <div class="pd-flow">
        <div class="pd-st"><span class="ico i-sand"><i class="ph-fill ph-bug"></i></span><div><b>1. Siapkan umpan</b>
          <p>Satu umpan = satu lemparan. Beli paket di toko umpan pakai <strong>Saldo Beli</strong><?= $bait_from > 0 ? ' (mulai ' . format_rp(round($bait_from)) . '/umpan)' : '' ?>.</p>
          <?php if ($bait_packs): ?><div class="pd-pills"><?php foreach ($bait_packs as $b): ?><span><?= htmlspecialchars($b['name']) ?> · <?= (int)$b['qty'] ?>x · <?= $rp_short((float)$b['price']) ?></span><?php endforeach; ?></div><?php endif; ?></div></div>
        <div class="pd-st"><span class="ico i-lagoon"><i class="ph-fill ph-arrow-bend-up-right"></i></span><div><b>2. Lempar, lalu HOOK!</b>
          <p>Tahan tombol untuk isi tenaga, lepas untuk melempar. Abaikan getaran kecil; saat muncul <strong>STRIKE!!</strong> tekan <strong>HOOK</strong> dalam 1,5 detik.</p></div></div>
        <div class="pd-st"><span class="ico i-coral"><i class="ph-fill ph-arrows-clockwise"></i></span><div><b>3. Gulung sampai meter penuh</b>
          <p>Tahan <strong>Gulung</strong>. Saat Tegangan Senar masuk zona merah, lepas sebentar. Kalau senar sampai penuh, ikan kabur menjauh. Ikan langka melawan lebih kuat.</p></div></div>
        <div class="pd-st"><span class="ico i-violet"><i class="ph-fill ph-lock-key"></i></span><div><b>4. Susah? Kunci atau lepaskan</b>
          <p><strong>Kunci Ikan</strong> membuat ikan tidak bisa kabur (<?= $lock_price > 0 ? format_rp($lock_price) . ' dari Saldo Beli' : 'gratis' ?><?= $lock_free > 0 ? ', ' . $lock_free . 'x pertama gratis' : '' ?>). Atau <strong>Lepaskan</strong> kalau menyerah, umpan tidak kembali.</p></div></div>
        <div class="pd-st"><span class="ico i-kelp"><i class="ph-fill ph-storefront"></i></span><div><b>5. Jual di Pasar Ikan</b>
          <p>Hasil tangkapan masuk Ember. Jual kapan saja, uangnya masuk <strong>Saldo Tarik</strong>. Harga pasar berubah tiap hari, pantau trennya.</p></div></div>
      </div>

      <div class="pd-rar">
        <div><img src="/assets/img/fish/biasa.png" alt=""><span style="color:#0284c7">Biasa</span></div>
        <div><img src="/assets/img/fish/bagus.png" alt=""><span style="color:#d97706">Bagus</span></div>
        <div><img src="/assets/img/fish/langka.png" alt=""><span style="color:#e11d48">Langka</span></div>
        <div><img src="/assets/img/fish/super.png" alt=""><span style="color:#7c3aed">Super</span></div>
        <div><img src="/assets/img/fish/jackpot.png" alt=""><span style="color:#059669">Peti Harta</span></div>
        <div><img src="/assets/img/fish/junk-boot.png" alt=""><span style="color:#78716c">Sampah</span></div>
      </div>
      <p style="margin:8px 2px 0;font-size:10.5px;font-weight:700;color:var(--reef-muted);line-height:1.4">Makin langka makin mahal. Kadang yang nyangkut sampah laut (zonk).
        <?php if ($rod_count > 1): ?>Ada <strong style="color:var(--reef-ink)"><?= $rod_count ?> joran</strong> yang terbuka dari jumlah tangkapan, Peti Harta, atau pangkat. Joran lebih bagus = gulung lebih kuat &amp; gigitan lebih cepat.<?php endif; ?></p>
      <a href="/minigame" class="pd-btn lag"><i class="ph-fill ph-fish-simple"></i>Mulai Mancing</a>
    </div>
  </section>

  <!-- 3. SALDO -->
  <section class="pd-sec" id="saldo">
    <div class="pd-h"><span class="ico i-kelp"><i class="ph-fill ph-wallet"></i></span><h3>Dua Jenis Saldo</h3></div>
    <div class="pd-wallets">
      <div class="pd-wal wd"><i class="ph-fill ph-hand-coins big"></i><b>Saldo Tarik</b>
        <small>Dari</small><ul><li>Video &amp; jual ikan</li><li>Misi &amp; check-in</li><li>Referral &amp; event</li></ul>
        <small>Untuk</small><ul><li>Dicairkan ke rekening</li></ul></div>
      <div class="pd-wal dep"><i class="ph-fill ph-vault big"></i><b>Saldo Beli</b>
        <small>Dari</small><ul><li>Isi Kas (deposit)</li></ul>
        <small>Untuk</small><ul><li>Naik pangkat</li><li>Beli umpan</li><li>Kunci ikan</li></ul></div>
    </div>
  </section>

  <!-- 4. PANGKAT -->
  <?php if ($memberships): ?>
  <section class="pd-sec" id="pangkat">
    <div class="pd-h"><span class="ico i-violet"><i class="ph-fill ph-crown"></i></span><h3>Pangkat</h3><span>lebih tinggi = lebih banyak cuan</span></div>
    <div class="pd-card">
      <div class="pd-rank">
        <?php foreach ($memberships as $m): $free = (float)$m['price'] <= 0; ?>
          <div class="pd-rk">
            <b><?= htmlspecialchars($m['name']) ?></b>
            <span class="pr <?= $free ? 'free' : '' ?>"><?= $free ? 'Gratis' : format_rp((float)$m['price']) ?></span>
            <div class="meta">
              <span><i class="ph-fill ph-film-strip"></i><?= (int)$m['watch_limit'] ?> video/hari</span>
              <span><i class="ph-fill ph-hand-coins"></i>WD <?= $rp_short((float)$m['min_wd']) ?><?= (float)$m['max_wd'] > 0 ? '–' . $rp_short((float)$m['max_wd']) : '+' ?></span>
              <?php if (!$free): ?><span><i class="ph-fill ph-clock"></i><?= (int)$m['duration_days'] ?> hari</span><?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <a href="/upgrade" class="pd-btn sun"><i class="ph-fill ph-crown"></i>Lihat Benefit &amp; Naik Pangkat</a>
    </div>
  </section>
  <?php endif; ?>

  <!-- 5. PENARIKAN -->
  <section class="pd-sec" id="tarik">
    <div class="pd-h"><span class="ico i-sun"><i class="ph-fill ph-bank"></i></span><h3>Aturan Penarikan</h3></div>
    <div class="pd-card pd-rules">
      <div class="pd-rule"><span class="ico i-kelp"><i class="ph-bold ph-scales"></i></span><span>Batas minimal &amp; maksimal sekali tarik <strong>mengikuti pangkatmu</strong> (lihat tabel di atas).</span></div>
      <div class="pd-rule"><span class="ico i-lagoon"><i class="ph-bold ph-hourglass-medium"></i></span><span>Hanya <strong>1 penarikan diproses</strong> dalam satu waktu. Tunggu selesai sebelum tarik lagi.</span></div>
      <?php if ($wd_lock_s && $wd_lock_e): ?>
        <div class="pd-rule"><span class="ico i-coral"><i class="ph-bold ph-moon"></i></span><span>Penarikan <strong>tutup pukul <?= htmlspecialchars(date('H:i', strtotime($wd_lock_s))) ?>–<?= htmlspecialchars(date('H:i', strtotime($wd_lock_e))) ?></strong>.</span></div>
      <?php endif; ?>
      <?php if ($free_name && ($wd_free_dana || $wd_free_1x || $wd_free_1day)): ?>
        <div class="pd-rule"><span class="ico i-sand"><i class="ph-bold ph-info"></i></span><span>Pangkat <strong><?= htmlspecialchars($free_name) ?></strong>:
          <?= implode(', ', array_filter([$wd_free_dana ? 'hanya ke DANA' : '', $wd_free_1x ? 'maksimal 1x penarikan' : '', $wd_free_1day ? 'akun minimal 1 hari' : ''])) ?>.</span></div>
      <?php endif; ?>
      <div class="pd-rule"><span class="ico i-violet"><i class="ph-bold ph-identification-card"></i></span><span>Rekening harus <strong>atas nama sendiri</strong>.<?= $wallets ? ' Tersedia: ' . htmlspecialchars(implode(', ', $wallets)) . '.' : '' ?></span></div>
      <a href="/withdraw" class="pd-btn lag" style="margin-top:4px"><i class="ph-fill ph-hand-coins"></i>Tarik Saldo</a>
    </div>
  </section>

  <!-- 6. FAQ -->
  <section class="pd-sec" id="faq">
    <div class="pd-h"><span class="ico i-coral"><i class="ph-fill ph-chat-circle-dots"></i></span><h3>Tanya Jawab</h3></div>
    <div class="pd-faq">
      <details class="pd-q" open><summary>Kenapa saldo tidak bisa ditarik?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Cek: saldo sudah mencapai minimal pangkatmu, tidak ada penarikan lain yang diproses, rekening sudah diisi, dan sedang di luar jam tutup.</div></details>
      <details class="pd-q"><summary>Kuota video habis, lalu apa?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Kuota video reset tiap hari. Sambil menunggu, kamu tetap bisa mancing, kejar misi, dan check-in. Kuota lebih banyak tersedia di pangkat lebih tinggi.</div></details>
      <details class="pd-q"><summary>Umpan habis, bisa mancing gratis?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Setiap lemparan butuh 1 umpan. Umpan dibeli dengan Saldo Beli lewat Isi Kas, lalu hasil jual ikannya masuk Saldo Tarik.</div></details>
      <details class="pd-q"><summary>Kapan bonus referral cair?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Bonus <?= format_rp($ref_bonus) ?> per kawan yang daftar<?= $ref_hold > 0 ? ' ditahan ' . $ref_hold . ' hari dulu' : '' ?>, plus <?= rtrim(rtrim(number_format($ref_pct, 1, ',', ''), '0'), ',') ?>% dari setiap isi kas mereka. Akun palsu atau daftar sendiri terdeteksi otomatis dan bonusnya tidak diberikan.</div></details>
      <details class="pd-q"><summary>Bagaimana ganti rekening?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Buka <a href="/edit-rekening" style="color:#0891b2;font-weight:900">Edit Rekening</a>. Pengajuan dicek admin dulu. Fitur ini tersedia untuk pangkat tertentu dan butuh Saldo Beli mengendap.</div></details>
      <details class="pd-q"><summary>Butuh bantuan?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Ngobrol dengan pemain lain di <a href="/global-chat" style="color:#0891b2;font-weight:900">Global Chat</a>, atau hubungi CS lewat <a href="/livechat" style="color:#0891b2;font-weight:900">Live Chat</a>.</div></details>
      <?php if (!empty($panduan_faq)): ?>
        <details class="pd-q"><summary>Info tambahan<i class="ph-bold ph-caret-down"></i></summary><div><?= nl2br(htmlspecialchars($panduan_faq)) ?></div></details>
      <?php endif; ?>
    </div>
  </section>

  <div class="pd-cta">
    <a href="/minigame" class="a2"><i class="ph-fill ph-fish-simple"></i>Mancing</a>
    <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="a1"><i class="ph-fill ph-play-circle"></i><?= htmlspecialchars($panduan_cta_text) ?></a>
  </div>

</div>

<script>
// Status tur interaktif
(function () {
  const b = document.getElementById('badge-tour-watch');
  let done = false;
  try { done = localStorage.getItem('mancing_tour_done_watch') === '1'; } catch (e) {}
  if (b && done) { b.textContent = '✓ Selesai'; b.classList.add('done'); }
})();

function startPanduanTour(track) {
  if (typeof window.startGlobalTour === 'function') {
    window.startGlobalTour('watch', 0);
  } else {
    try {
      localStorage.setItem('mancing_tour_active', JSON.stringify({ track: 'watch', step: 0, isRunning: true, updatedAt: Date.now() }));
    } catch (e) {}
    window.location.href = '/home';
  }
}

// Navigasi bab: gulir halus + sorot bab aktif
(function () {
  const links = [...document.querySelectorAll('#pd-nav a')];
  links.forEach(a => a.addEventListener('click', e => {
    e.preventDefault(); e.stopPropagation();
    const t = document.querySelector(a.getAttribute('href'));
    if (t) window.scrollTo({ top: t.getBoundingClientRect().top + window.scrollY - 70, behavior: 'smooth' });
  }));
  const nav = document.getElementById('pd-nav');
  const io = new IntersectionObserver(entries => {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      links.forEach(a => a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id));
      const on = links.find(a => a.classList.contains('on'));
      if (on) nav.scrollTo({ left: on.offsetLeft - 12, behavior: 'smooth' });
    });
  }, { rootMargin: '-40% 0px -55% 0px' });
  document.querySelectorAll('.pd-sec').forEach(s => io.observe(s));
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
