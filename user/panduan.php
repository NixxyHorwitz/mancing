<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan Resmi — MancingCuan';
$activePage = 'panduan';

$free_limit  = (int)   setting($pdo, 'free_watch_limit', '7');
$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');

$panduan_intro    = setting($pdo, 'panduan_intro', 'Panduan lengkap mendulang uang rupiah dari nonton video, tantangan minigame harian, dan bonus referral di MancingCuan.');
$panduan_faq      = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text', 'Mulai Nonton Video Cuan');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url', '/videos');

try {
    $memberships = $pdo->query("SELECT name, price, watch_limit, duration_days, description FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
} catch (\Throwable) { $memberships = []; }

require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   PANDUAN RESMI MANCINGCUAN
   ══════════════════════════════════════════════════════════ */
body {
  background: #fef8ee !important;
  color: #1e293b;
  font-family: 'Nunito', sans-serif;
  padding-bottom: 120px !important;
}

.panduan-page {
  max-width: 480px;
  margin: 0 auto;
}

/* ── HERO BANNER ── */
.pan-hero {
  background: linear-gradient(135deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  padding: 16px 14px 28px;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 20px rgba(120,53,15,0.25);
  position: relative;
  overflow: hidden;
}
.pan-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.15) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.15) 15%, transparent 16%);
  background-size: 22px 22px;
  background-position: 0 0, 11px 11px;
  opacity: 0.18;
  pointer-events: none;
}
.pan-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  position: relative;
  z-index: 2;
}
.pan-back-btn {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  width: 38px;
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #78350f;
  font-size: 18px;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s;
}
.pan-back-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.pan-title-badge {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 900;
  color: #78350f;
  box-shadow: 0 3px 0 #78350f;
  display: flex;
  align-items: center;
  gap: 6px;
}

.pan-hero-body {
  position: relative;
  z-index: 2;
  text-align: center;
  padding: 4px 6px;
}
.pan-hero-title {
  font-size: 21px;
  font-weight: 900;
  color: #ffffff;
  text-shadow: 0 2px 4px rgba(120,53,15,0.6);
  line-height: 1.25;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.pan-hero-sub {
  font-size: 11.5px;
  font-weight: 700;
  color: #fef3c7;
  line-height: 1.45;
  max-width: 380px;
  margin: 0 auto;
}

/* ── BODY CONTAINER ── */
.pan-content {
  padding: 16px 14px;
}

/* ── INTERACTIVE TOUR LAUNCH BOX (PROMINENT AT TOP) ── */
.pan-tour-box {
  background: linear-gradient(145deg, #ffffff 0%, #fffbeb 50%, #fef3c7 100%);
  border: 3px solid #78350f;
  border-radius: 22px;
  box-shadow: 0 6px 0 #78350f, 0 10px 24px rgba(120,53,15,0.15);
  padding: 15px 14px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.pan-tour-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.pan-tour-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #78350f;
  color: #fef08a;
  font-size: 10px;
  font-weight: 900;
  padding: 3px 10px;
  border-radius: 12px;
  letter-spacing: 0.5px;
  text-transform: uppercase;
}
.pan-tour-tag i {
  color: #fbbf24;
  animation: compassSpin 4s ease-in-out infinite;
}
@keyframes compassSpin {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(20deg); }
  75% { transform: rotate(-20deg); }
}
.pan-tour-status-pill {
  font-size: 10px;
  font-weight: 800;
  color: #92400e;
  background: rgba(245,158,11,0.15);
  border: 1px solid #fde68a;
  border-radius: 10px;
  padding: 2px 8px;
}
.pan-tour-title {
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
  line-height: 1.3;
}
.pan-tour-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 12px;
  line-height: 1.45;
}

.pan-tour-cards {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
@media (min-width: 440px) {
  .pan-tour-cards {
    grid-template-columns: 1fr 1fr;
  }
}
.pan-tour-btn {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 12px 10px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 4px 0 #78350f;
  text-align: left;
  transition: all 0.15s ease;
  position: relative;
  overflow: hidden;
}
.pan-tour-btn:active {
  transform: translateY(2px);
  box-shadow: 0 2px 0 #78350f;
}
.pan-tour-btn--bee {
  background: linear-gradient(145deg, #ffffff 0%, #fffbeb 100%);
}
.pan-tour-btn--watch {
  background: linear-gradient(145deg, #ffffff 0%, #fff7ed 100%);
}
.pan-tour-btn-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
}
.pan-tour-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #ffffff;
  box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}
.pan-tour-btn--bee .pan-tour-icon {
  background: linear-gradient(135deg, #f59e0b, #d97706);
}
.pan-tour-btn--watch .pan-tour-icon {
  background: linear-gradient(135deg, #f97316, #ea580c);
}
.pan-tour-badge {
  font-size: 9px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 8px;
}
.pan-tour-badge.done {
  background: #dcfce7;
  color: #15803d;
  border: 1px solid #86efac;
}
.pan-tour-badge.new {
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fde68a;
}
.pan-tour-btn-title {
  font-size: 12.5px;
  font-weight: 900;
  color: #78350f;
  line-height: 1.25;
}
.pan-tour-btn-desc {
  font-size: 10px;
  font-weight: 700;
  color: #64748b;
  line-height: 1.35;
}
.pan-tour-btn-cta {
  font-size: 10.5px;
  font-weight: 900;
  color: #d97706;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin-top: 2px;
}

/* ── SECTIONS & CARDS ── */
.pan-card {
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 5px 0 #78350f;
  margin-bottom: 18px;
  overflow: hidden;
}
.pan-card-head {
  padding: 12px 14px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-bottom: 2.5px solid #78350f;
}
.pan-card-head--amber {
  background: linear-gradient(135deg, #fef08a 0%, #fde047 50%, #f59e0b 100%);
  color: #78350f;
}
.pan-card-head--green {
  background: linear-gradient(135deg, #dcfce7 0%, #a7f3d0 50%, #34d399 100%);
  color: #064e3b;
}
.pan-card-head--blue {
  background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 50%, #38bdf8 100%);
  color: #075985;
}
.pan-card-head--purple {
  background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 50%, #c084fc 100%);
  color: #581c87;
}
.pan-card-icon {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: #ffffff;
  border: 2px solid currentColor;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  box-shadow: 0 2px 0 currentColor;
  flex-shrink: 0;
}
.pan-card-title {
  font-size: 13.5px;
  font-weight: 900;
  line-height: 1.25;
}
.pan-card-body {
  padding: 14px;
}

/* ── STEP ITEMS ── */
.pan-step-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.pan-step-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.pan-step-num {
  width: 28px;
  height: 28px;
  border-radius: 10px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2px solid #78350f;
  color: #ffffff;
  font-size: 12.5px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 0 #78350f;
  flex-shrink: 0;
}
.pan-step-content {
  flex: 1;
}
.pan-step-title {
  font-size: 12.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 2px;
}
.pan-step-desc {
  font-size: 11px;
  font-weight: 700;
  color: #475569;
  line-height: 1.45;
}

/* ── FEATURE HIGHLIGHT PILLS ── */
.pan-feature-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 10px;
}
.pan-feature-box {
  background: #fffbeb;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px;
  box-shadow: 0 2.5px 0 #78350f;
}
.pan-feature-box i {
  font-size: 22px;
  color: #d97706;
  margin-bottom: 4px;
  display: inline-block;
}
.pan-feature-title {
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 2px;
}
.pan-feature-desc {
  font-size: 9.5px;
  font-weight: 700;
  color: #64748b;
  line-height: 1.35;
}

/* ── SALDO EXPLANATION ── */
.pan-saldo-row {
  display: flex;
  gap: 10px;
  margin-bottom: 10px;
  background: #f8fafc;
  border: 2px solid #e2e8f0;
  border-radius: 14px;
  padding: 10px 12px;
  align-items: center;
}
.pan-saldo-row.wd {
  border-color: #059669;
  background: #f0fdf4;
}
.pan-saldo-row.dep {
  border-color: #0284c7;
  background: #f0f9ff;
}
.pan-saldo-icon {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: #fff;
  flex-shrink: 0;
}
.pan-saldo-row.wd .pan-saldo-icon {
  background: #059669;
}
.pan-saldo-row.dep .pan-saldo-icon {
  background: #0284c7;
}
.pan-saldo-title {
  font-size: 12px;
  font-weight: 900;
  margin-bottom: 2px;
}
.pan-saldo-row.wd .pan-saldo-title { color: #065f46; }
.pan-saldo-row.dep .pan-saldo-title { color: #0369a1; }
.pan-saldo-desc {
  font-size: 10.5px;
  font-weight: 700;
  color: #475569;
  line-height: 1.35;
}

/* ── MEMBERSHIP GRID ── */
.pan-mem-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
}
.pan-mem-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px;
  box-shadow: 0 3px 0 #78350f;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.pan-mem-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 2px;
}
.pan-mem-name {
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.pan-mem-price {
  font-size: 9.5px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  color: #fff;
  background: #d97706;
}
.pan-mem-price.free {
  background: #059669;
}
.pan-mem-stat {
  font-size: 9.5px;
  font-weight: 800;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* ── ACCORDION FAQ ── */
.pan-faq-item {
  border: 2px solid #78350f;
  border-radius: 12px;
  margin-bottom: 8px;
  background: #ffffff;
  overflow: hidden;
  box-shadow: 0 2.5px 0 #78350f;
  transition: all 0.2s ease;
}
.pan-faq-item:last-child {
  margin-bottom: 0;
}
.pan-faq-question {
  padding: 11px 12px;
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  justify-content: space-between;
  align-items: center;
  cursor: pointer;
  user-select: none;
}
.pan-faq-question i {
  font-size: 13px;
  color: #d97706;
  transition: transform 0.2s;
}
.pan-faq-item.open .pan-faq-question i {
  transform: rotate(180deg);
}
.pan-faq-item.open .pan-faq-question {
  background: #fffbeb;
  border-bottom: 2px dashed #fef08a;
}
.pan-faq-answer {
  padding: 0 12px;
  font-size: 11px;
  font-weight: 700;
  color: #475569;
  line-height: 1.45;
  max-height: 0;
  opacity: 0;
  transition: all 0.25s ease;
}
.pan-faq-item.open .pan-faq-answer {
  padding: 11px 12px;
  max-height: 400px;
  opacity: 1;
}

/* ── BOTTOM CALL TO ACTION ── */
.pan-action-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 14px;
}
.btn-pan-action {
  border-radius: 16px;
  border: 2.5px solid #78350f;
  padding: 12px 10px;
  font-size: 12px;
  font-weight: 900;
  text-decoration: none;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  box-shadow: 0 4px 0 #78350f;
  transition: transform 0.1s;
}
.btn-pan-action:active {
  transform: translateY(2px);
  box-shadow: 0 2px 0 #78350f;
}
.btn-pan-action--farm {
  background: linear-gradient(135deg, #fde047, #f59e0b);
  color: #78350f;
}
.btn-pan-action--video {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #ffffff;
  border-color: #064e3b;
  box-shadow: 0 4px 0 #064e3b;
}
</style>

<div class="panduan-page">
  <!-- HERO BANNER -->
  <div class="pan-hero">
    <div class="pan-nav">
      <a href="/home" class="pan-back-btn" title="Kembali ke Beranda">
        <i class="ph-bold ph-arrow-left"></i>
      </a>
      <div class="pan-title-badge">
        <i class="ph-fill ph-book-open" style="color:#d97706;"></i>
        <span>PANDUAN RESMI</span>
      </div>
      <div style="width:38px;"></div>
    </div>

    <div class="pan-hero-body">
      <div class="pan-hero-title">
        <span>Pusat Edukasi MancingCuan</span>
        <i class="ph-fill ph-book-open-text" style="font-size:24px;color:#d97706;"></i>
      </div>
      <div class="pan-hero-sub"><?= htmlspecialchars($panduan_intro) ?></div>
    </div>
  </div>

  <div class="pan-content">

    <!-- ══════════════════════════════════════════════════════════
         KOTAK TOUR INTERAKTIF PANDUAN LANGSUNG (SPOTLIGHT LAUNCHER)
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-tour-box">
      <div class="pan-tour-header">
        <div class="pan-tour-tag">
          <i class="ph-fill ph-compass"></i>
          <span>PRAKTIK INTERAKTIF</span>
        </div>
        <div class="pan-tour-status-pill" id="tour-overall-status">Panduan Interaktif</div>
      </div>

      <div class="pan-tour-title">Belajar Lebih Cepat dengan Panduan Interaktif! 🚀</div>
      <div class="pan-tour-desc">
        Ikuti tur langsung di aplikasi untuk memahami alur nonton video reward, klaim bonus harian, dan pencairan saldo:
      </div>

      <div class="pan-tour-cards">
        <!-- Tour Cuan Nonton -->
        <button type="button" class="pan-tour-btn pan-tour-btn--watch" onclick="startPanduanTour('watch')" style="grid-column: 1 / -1;">
          <div class="pan-tour-btn-top">
            <div class="pan-tour-icon"><i class="ph-fill ph-film-strip"></i></div>
            <span class="pan-tour-badge new" id="badge-tour-watch">Mulai Tour</span>
          </div>
          <div class="pan-tour-btn-title">Tour Cuan Nonton Video &amp; Aktivitas</div>
          <div class="pan-tour-btn-desc">Pelajari misi video berbayar, hadiah check-in harian, minigame, dan cara penarikan saldo.</div>
          <div class="pan-tour-btn-cta">Mulai Panduan <i class="ph-bold ph-arrow-right"></i></div>
        </button>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         1. 5 PILAR UTAMA MENGHASILKAN RUPIAH
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-card">
      <div class="pan-card-head pan-card-head--amber">
        <div class="pan-card-icon"><i class="ph-fill ph-sparkle"></i></div>
        <div>
          <div class="pan-card-title">5 Pilar Menghasilkan Uang di MancingCuan</div>
          <div style="font-size:10px;font-weight:700;opacity:0.85;">Pahami seluruh cara mendulang rupiah di aplikasi</div>
        </div>
      </div>

      <div class="pan-card-body">
        <div class="pan-step-list">
          <div class="pan-step-item">
            <div class="pan-step-num">1</div>
            <div class="pan-step-content">
              <div class="pan-step-title">Misi Nonton Video Berbayar</div>
              <div class="pan-step-desc">Tonton video berdurasi singkat hingga hitungan mundur selesai. Reward uang Rupiah otomatis masuk seketika ke Saldo Penarikan (WD) tanpa ribet!</div>
            </div>
          </div>

          <div class="pan-step-item">
            <div class="pan-step-num">2</div>
            <div class="pan-step-content">
              <div class="pan-step-title">Tantangan Minigame Cuan Harian</div>
              <div class="pan-step-desc">Mainkan game seru dan tantangan harian untuk mengumpulkan reward instan yang langsung dapat ditarik ke rekening.</div>
            </div>
          </div>

          <div class="pan-step-item">
            <div class="pan-step-num">3</div>
            <div class="pan-step-content">
              <div class="pan-step-title">Hadiah Check-in Harian</div>
              <div class="pan-step-desc">Klaim bonus uang tunai gratis setiap hari dengan membuka kotak keberuntungan. Pertahankan streak berturut-turut untuk hadiah ekstra!</div>
            </div>
          </div>

          <div class="pan-step-item">
            <div class="pan-step-num">4</div>
            <div class="pan-step-content">
              <div class="pan-step-title">Misi &amp; Kampanye Promosi Threads</div>
              <div class="pan-step-desc">Selesaikan target misi harian dan promosikan MancingCuan di Threads untuk mengklaim tambahan reward bonus saldo hingga jutaan rupiah.</div>
            </div>
          </div>

          <div class="pan-step-item">
            <div class="pan-step-num">5</div>
            <div class="pan-step-content">
              <div class="pan-step-title">Komisi Undang Teman (Referral 3 Tingkat)</div>
              <div class="pan-step-desc">Bagikan kode referralmu. Dapatkan reward langsung sebesar <strong><?= format_rp($ref_bonus) ?></strong> per teman ditambah komisi berjenjang 3 level dari transaksi mereka!</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         2. PANDUAN MINIGAME & TANTANGAN HARIAN
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-card">
      <div class="pan-card-head pan-card-head--green">
        <div class="pan-card-icon"><i class="ph-fill ph-game-controller"></i></div>
        <div>
          <div class="pan-card-title">Mekanisme Minigame &amp; Tantangan</div>
          <div style="font-size:10px;font-weight:700;opacity:0.85;">Cara bermain dan klaim hadiah instan</div>
        </div>
      </div>

      <div class="pan-card-body">
        <div class="pan-feature-grid">
          <div class="pan-feature-box">
            <i class="ph-fill ph-play-circle"></i>
            <div class="pan-feature-title">Mainkan Kapan Saja</div>
            <div class="pan-feature-desc">Akses minigame langsung dari menu utama tanpa perlu download aplikasi tambahan.</div>
          </div>

          <div class="pan-feature-box">
            <i class="ph-fill ph-trophy"></i>
            <div class="pan-feature-title">Raih Skor Tertinggi</div>
            <div class="pan-feature-desc">Capai target skor harian untuk membuka bonus ekstra dan multiplier reward.</div>
          </div>

          <div class="pan-feature-box">
            <i class="ph-fill ph-hand-heart"></i>
            <div class="pan-feature-title">Klaim Instan</div>
            <div class="pan-feature-desc">Uang tunai yang dimenangkan langsung ditambahkan otomatis ke Saldo Tarik kamu.</div>
          </div>

          <div class="pan-feature-box">
            <i class="ph-fill ph-sparkle"></i>
            <div class="pan-feature-title">Tantangan Harian</div>
            <div class="pan-feature-desc">Tantangan baru diperbarui setiap pagi pukul 00:00 WIB untuk kesempatan cuan tiada henti.</div>
          </div>
        </div>

        <a href="/minigame" style="display:flex;align-items:center;justify-content:center;gap:6px;background:#f0fdf4;border:2px solid #059669;border-radius:12px;padding:9px;color:#065f46;font-size:11.5px;font-weight:900;text-decoration:none;margin-top:12px;">
          <i class="ph-fill ph-game-controller"></i> Buka Minigame Cuan Sekarang
        </a>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         3. MEMAHAMI JENIS SALDO (WD VS BELI)
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-card">
      <div class="pan-card-head pan-card-head--blue">
        <div class="pan-card-icon"><i class="ph-fill ph-wallet"></i></div>
        <div>
          <div class="pan-card-title">Mengenal Jenis Saldo & Penarikan</div>
          <div style="font-size:10px;font-weight:700;opacity:0.85;">Perbedaan Saldo Tarik (WD) dan Saldo Beli</div>
        </div>
      </div>

      <div class="pan-card-body">
        <div class="pan-saldo-row wd">
          <div class="pan-saldo-icon"><i class="ph-fill ph-hand-coins"></i></div>
          <div>
            <div class="pan-saldo-title">Saldo Tarik (WD Cash)</div>
            <div class="pan-saldo-desc">Diperoleh dari hasil menonton video, minigame, misi tantangan, check-in harian, dan bonus referral. Saldo ini <strong>dapat dicairkan ke rekening Bank atau E-Wallet 24 jam</strong>.</div>
          </div>
        </div>

        <div class="pan-saldo-row dep">
          <div class="pan-saldo-icon"><i class="ph-fill ph-credit-card"></i></div>
          <div>
            <div class="pan-saldo-title">Saldo Beli (Deposit Investasi)</div>
            <div class="pan-saldo-desc">Diisi melalui menu Top Up / Deposit. Khusus digunakan untuk <strong>upgrade paket membership VIP atau paket investasi cuan</strong>.</div>
          </div>
        </div>

        <div style="background:#fffbeb;border:1.5px solid #fef08a;border-radius:12px;padding:8px 10px;font-size:10.5px;font-weight:800;color:#92400e;display:flex;align-items:center;gap:6px;margin-top:10px;">
          <i class="ph-fill ph-info" style="font-size:16px;color:#d97706;flex-shrink:0;"></i>
          <span>Minimal penarikan saldo adalah <strong><?= format_rp($min_wd) ?></strong> tanpa biaya admin tersembunyi.</span>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         4. PAKET MEMBERSHIP & KEUNTUNGAN
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-card">
      <div class="pan-card-head pan-card-head--purple">
        <div class="pan-card-icon"><i class="ph-fill ph-crown"></i></div>
        <div>
          <div class="pan-card-title">Tingkatan Paket Membership</div>
          <div style="font-size:10px;font-weight:700;opacity:0.85;">Tingkatkan kuota video harian untuk cuan lebih besar</div>
        </div>
      </div>

      <div class="pan-card-body">
        <?php if (!empty($memberships)): ?>
          <div class="pan-mem-grid">
            <?php foreach ($memberships as $mem):
              $isFree = ((float)($mem['price'] ?? 0) === 0.0);
            ?>
              <div class="pan-mem-card">
                <div class="pan-mem-head">
                  <span class="pan-mem-name"><?= htmlspecialchars($mem['name']) ?></span>
                  <span class="pan-mem-price <?= $isFree ? 'free' : '' ?>">
                    <?= $isFree ? 'GRATIS' : format_rp((float)$mem['price']) ?>
                  </span>
                </div>
                <div class="pan-mem-stat">
                  <i class="ph-fill ph-film-strip" style="color:#d97706;"></i>
                  <span><?= $mem['watch_limit'] ?> video / hari</span>
                </div>
                <?php if (!$isFree): ?>
                  <div class="pan-mem-stat">
                    <i class="ph-fill ph-clock" style="color:#2563eb;"></i>
                    <span>Durasi: <?= $mem['duration_days'] ?> hari</span>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <a href="/upgrade" style="display:flex;align-items:center;justify-content:center;gap:6px;background:linear-gradient(135deg,#f59e0b,#d97706);border:2px solid #78350f;border-radius:12px;padding:9px;color:#ffffff;font-size:11.5px;font-weight:900;text-decoration:none;box-shadow:0 2.5px 0 #78350f;margin-top:12px;">
          <i class="ph-bold ph-lightning"></i> Lihat Rincian Paket & Upgrade
        </a>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         5. PERTANYAAN UMUM (FAQ ACCORDION)
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-card">
      <div class="pan-card-head pan-card-head--amber">
        <div class="pan-card-icon"><i class="ph-fill ph-question"></i></div>
        <div>
          <div class="pan-card-title">Tanya Jawab Seputar MancingCuan</div>
          <div style="font-size:10px;font-weight:700;opacity:0.85;">Pertanyaan yang sering ditanyakan pengguna</div>
        </div>
      </div>

      <div class="pan-card-body">
        <div class="pan-faq-item open">
          <div class="pan-faq-question">
            <span>Bagaimana uang hasil nonton video bertambah?</span>
            <i class="ph-bold ph-caret-down"></i>
          </div>
          <div class="pan-faq-answer">
            Setiap video memiliki batas durasi tonton. Cukup tonton video sampai hitungan detik selesai dan reward rupiah otomatis masuk seketika ke Saldo Siap Tarik (WD).
          </div>
        </div>

        <div class="pan-faq-item">
          <div class="pan-faq-question">
            <span>Kapan saja saya bisa menonton video dan menarik uang?</span>
            <i class="ph-bold ph-caret-down"></i>
          </div>
          <div class="pan-faq-answer">
            Misi video dan penarikan saldo dapat diakses 24 jam setiap harinya tanpa hari libur.
          </div>
        </div>

        <div class="pan-faq-item">
          <div class="pan-faq-question">
            <span>Apakah saldo hasil nonton dan minigame langsung bisa ditarik?</span>
            <i class="ph-bold ph-caret-down"></i>
          </div>
          <div class="pan-faq-answer">
            Ya! Setiap reward otomatis masuk ke Saldo Siap Tarik (WD) dan dapat ditarik langsung ke rekening bank atau e-wallet begitu mencapai batas minimum penarikan.
          </div>
        </div>

        <div class="pan-faq-item">
          <div class="pan-faq-question">
            <span>Penarikan saldo bisa ke rekening apa saja?</span>
            <i class="ph-bold ph-caret-down"></i>
          </div>
          <div class="pan-faq-answer">
            Bisa ditarik ke semua E-Wallet (DANA, GoPay, OVO, ShopeePay) serta seluruh Rekening Bank di Indonesia (BCA, Mandiri, BRI, BNI, Jago, SeaBank, dll).
          </div>
        </div>

        <div class="pan-faq-item">
          <div class="pan-faq-question">
            <span>Berapa minimal withdraw dan berapa lama cairnya?</span>
            <i class="ph-bold ph-caret-down"></i>
          </div>
          <div class="pan-faq-answer">
            Minimal withdraw adalah <?= format_rp($min_wd) ?>. Permintaan penarikan diproses secara cepat 24 jam setiap harinya.
          </div>
        </div>

        <?php if (!empty($panduan_faq)): ?>
          <div class="pan-faq-item">
            <div class="pan-faq-question">
              <span>Informasi Tambahan Sistem</span>
              <i class="ph-bold ph-caret-down"></i>
            </div>
            <div class="pan-faq-answer">
              <?= nl2br(htmlspecialchars($panduan_faq)) ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         QUICK ACTION BUTTONS
         ══════════════════════════════════════════════════════════ -->
    <div class="pan-action-row">
      <a href="/minigame" class="btn-pan-action btn-pan-action--farm">
        <i class="ph-fill ph-game-controller"></i> Buka Minigame
      </a>
      <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="btn-pan-action btn-pan-action--video">
        <i class="ph-fill ph-play-circle"></i> <?= htmlspecialchars($panduan_cta_text) ?>
      </a>
    </div>

  </div>
</div>

<script>
// Accordion Toggle Logic
document.querySelectorAll('.pan-faq-question').forEach(header => {
  header.addEventListener('click', () => {
    const item = header.closest('.pan-faq-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.pan-faq-item').forEach(i => i.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
  });
});

// Update Tour Badges on Guide Page
function updatePanduanTourStatus() {
  const doneWatch = localStorage.getItem('mancing_tour_done_watch') === '1';
  const badgeWatch = document.getElementById('badge-tour-watch');
  const statusOverall = document.getElementById('tour-overall-status');

  if (badgeWatch) {
    if (doneWatch) {
      badgeWatch.className = 'pan-tour-badge done';
      badgeWatch.innerHTML = '✓ Selesai';
    } else {
      badgeWatch.className = 'pan-tour-badge new';
      badgeWatch.innerHTML = 'Mulai Tour';
    }
  }

  if (statusOverall) {
    statusOverall.textContent = doneWatch ? '1/1 Selesai 🎉' : 'Panduan Interaktif';
  }
}

// Function to start tour from panduan
function startPanduanTour(track) {
  if (typeof window.startGlobalTour === 'function') {
    window.startGlobalTour('watch', 0);
  } else {
    try {
      localStorage.setItem('mancing_tour_active', JSON.stringify({
        track: 'watch',
        step: 0,
        isRunning: true,
        updatedAt: Date.now()
      }));
    } catch(e) {}
    window.location.href = '/home';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  updatePanduanTourStatus();
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
