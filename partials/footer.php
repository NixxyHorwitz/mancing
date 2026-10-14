  </main>

    <style>
  /* ══════════════════════════════════════════════════════
     MINIMALIST FLOATING HORIZON DOCK
     ══════════════════════════════════════════════════════ */
  .bottom-nav {
    position: fixed !important;
    bottom: 10px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: calc(100% - 20px) !important;
    max-width: 420px !important;
    background: linear-gradient(155deg, #ffffff 0%, #f0f9ff 100%) !important;
    backdrop-filter: blur(20px) !important;
    -webkit-backdrop-filter: blur(20px) !important;
    border: 2px solid #bae6fd !important;
    border-radius: 22px !important;
    box-shadow: 
      0 12px 28px rgba(2, 132, 199, 0.14),
      0 2px 6px rgba(0, 0, 0, 0.02),
      inset 2px 2px 4px #ffffff,
      inset -2px -2px 4px rgba(2, 132, 199, 0.06) !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr 1.2fr 1fr 1fr !important;
    align-items: center !important;
    height: 54px !important;
    padding: 0 6px !important;
    z-index: 9999 !important;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
  }

  body { padding-bottom: 80px !important; }

  /* DOCK NAV ITEMS */
  .nav-item {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    text-decoration: none !important;
    color: #64748b !important;
    font-size: 9.5px !important;
    font-weight: 800 !important;
    font-family: 'Nunito', sans-serif !important;
    gap: 2px !important;
    height: 42px !important;
    border-radius: 14px !important;
    transition: color 0.15s, transform 0.12s !important;
    position: relative;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
  }
  .nav-item:active {
    transform: scale(0.92) !important;
  }
  .nav-item i {
    font-size: 19px !important;
    color: #64748b !important;
    transition: color 0.15s, transform 0.15s !important;
  }

  /* ACTIVE STATE */
  .nav-item.active {
    color: #0284c7 !important;
    font-weight: 900 !important;
  }
  .nav-item.active i {
    color: #0284c7 !important;
    transform: scale(1.08) !important;
  }
  .nav-item.active::after {
    content: '';
    position: absolute;
    bottom: 2px;
    width: 14px;
    height: 3px;
    background: #0284c7;
    border-radius: 999px;
  }

  /* CENTER PLAY BUTTON */
  .nav-item--play {
    position: relative !important;
    overflow: visible !important;
    height: auto !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    transform: none !important;
  }
  .nav-play-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    margin-top: -16px;
  }
  .nav-play-btn {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #0ea5e9, #0284c7) !important;
    border-radius: 16px !important;
    border: 2px solid #ffffff !important;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    z-index: 5;
    box-shadow: 0 6px 18px rgba(2, 132, 199, 0.35) !important;
    transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s !important;
  }
  .nav-item--play:active .nav-play-btn {
    transform: scale(0.92) !important;
  }
  .nav-item--play.active .nav-play-btn {
    background: linear-gradient(135deg, #38bdf8, #0284c7) !important;
    box-shadow: 0 8px 20px rgba(2, 132, 199, 0.45) !important;
  }
  .nav-play-btn i {
    font-size: 20px !important;
    color: #ffffff !important;
    margin-left: 2px;
  }

  .nav-play-label {
    font-size: 9px;
    font-weight: 800;
    color: #0369a1;
    font-family: 'Nunito', sans-serif;
    margin-top: 2px;
    letter-spacing: -0.2px;
  }
  .nav-item--play.active .nav-play-label {
    color: #0284c7;
    font-weight: 900;
  }

  .float-contact-wrap {
    bottom: 74px !important;
  }
  </style>

  <nav class="bottom-nav">
    <a href="/home" class="nav-item <?= ($activePage??'')==='home'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='home'?'ph-fill':'ph-bold' ?> ph-house-simple"></i>
      Dermaga
    </a>
    <a href="/upgrade" class="nav-item <?= ($activePage??'')==='upgrade'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='upgrade'?'ph-fill':'ph-bold' ?> ph-crown-simple"></i>
      Pangkat
    </a>

    <!-- Center: NONTON SAMUDRA BUTTON -->
    <a href="/videos" class="nav-item nav-item--play <?= ($activePage??'')==='videos'?'active':'' ?>">
      <div class="nav-play-wrap">
        <div class="nav-play-btn">
          <i class="ph-fill ph-play"></i>
        </div>
        <span class="nav-play-label">Nonton</span>
      </div>
    </a>

    <a href="/referral" class="nav-item <?= ($activePage??'')==='referral'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='referral'?'ph-fill':'ph-bold' ?> ph-users-three"></i>
      Kru Laut
    </a>
    <a href="/profile" class="nav-item <?= ($activePage??'')==='profile'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='profile'?'ph-fill':'ph-bold' ?> ph-user-circle"></i>
      Kabin
    </a>
  </nav>
</div>

<?php
$_floating_on = setting($pdo, 'floating_enabled', '1') === '1';
$_float_btns  = [];
if ($_floating_on) {
    try {
        $__q = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        $_float_btns = $__q ? $__q->fetchAll() : [];
    } catch (\Throwable) {}
}
if ($_floating_on && !empty($_float_btns)):
$_fsvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>
<style>
.float-contact-wrap {
  position: fixed; bottom: 86px; right: 14px;
  z-index: 500; display: flex; flex-direction: column;
  align-items: flex-end; gap: 12px;
}
.float-btn {
  width: 52px; height: 52px; border-radius: 22px;
  border: 3px solid rgba(255, 255, 255, 0.95);
  box-shadow: 
    0 12px 24px rgba(0, 0, 0, 0.16),
    0 3px 8px rgba(0, 0, 0, 0.06),
    inset 3px 3px 6px rgba(255, 255, 255, 0.75),
    inset -4px -4px 8px rgba(0, 0, 0, 0.2);
  display: flex; align-items: center; justify-content: center;
  text-decoration: none; transition: transform 0.15s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.15s;
  overflow: hidden; position: relative;
}
.float-btn:active {
  transform: translateY(2px) scale(0.94);
  box-shadow: 
    0 4px 10px rgba(0, 0, 0, 0.12),
    inset 3px 3px 6px rgba(0, 0, 0, 0.25),
    inset -2px -2px 4px rgba(255, 255, 255, 0.5);
}
.float-btn img { width: 100%; height: 100%; object-fit: cover; }
.float-btn__label {
  position: absolute; right: 64px; top: 50%; transform: translateY(-50%);
  background: #1e2230; color: #fff; font-size: 11px; font-weight: 800;
  white-space: nowrap; padding: 6px 14px; border-radius: 999px;
  border: 1.5px solid rgba(255, 255, 255, 0.2);
  box-shadow: 0 10px 20px rgba(0,0,0,0.25), inset 2px 2px 4px rgba(255,255,255,0.15);
  opacity: 0; pointer-events: none; transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1);
  margin-right: -10px; font-family: 'Nunito', sans-serif;
}
.float-btn:hover .float-btn__label { opacity: 1; margin-right: 0; }
</style>
<div class="float-contact-wrap" id="float-contacts">
  <?php foreach ($_float_btns as $_fb): ?>
  <a href="<?= htmlspecialchars($_fb['url']) ?>" target="_blank" rel="noopener"
     class="float-btn" style="background-color:<?= htmlspecialchars($_fb['bg_color']) ?>"
     title="<?= htmlspecialchars($_fb['label']) ?>">
    <?php if ($_fb['icon_type'] === 'custom'): ?>
      <img src="<?= htmlspecialchars($_fb['icon_value']) ?>" alt="<?= htmlspecialchars($_fb['label']) ?>">
    <?php else: ?>
      <span style="color:#fff;display:flex;align-items:center;justify-content:center;position:relative;z-index:2;"><?= $_fsvg[$_fb['icon_value']] ?? '<i class="ph-fill ph-headset" style="font-size:26px"></i>' ?></span>
    <?php endif; ?>
    <span class="float-btn__label"><?= htmlspecialchars($_fb['label']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     UNIVERSAL SPOTLIGHT TOUR ENGINE (CROSS-PAGE & SCROLL-SYNC)
     ══════════════════════════════════════════════════════════ -->
<style>
/* Global Spotlight Backdrop (allows pointer events to pass so user can freely scroll) */
#app-tour-overlay {
  position: fixed;
  inset: 0;
  z-index: 99990;
  pointer-events: none;
  display: none;
  background: transparent;
}

/* Spotlight Cutout Box with Real-time Sync & Dynamic Border Radius */
#app-tour-spotlight {
  position: fixed;
  z-index: 99992;
  border-radius: 18px;
  border: 3px solid #fbbf24;
  box-shadow: 0 0 0 9999px rgba(10, 15, 29, 0.82), 0 0 25px rgba(251, 191, 36, 0.75);
  pointer-events: none;
  box-sizing: border-box;
  opacity: 0;
  transform: scale(0.98);
  transition: opacity 0.25s ease, transform 0.25s ease;
}
#app-tour-spotlight.active {
  opacity: 1;
  transform: scale(1);
}

/* Spotlight pulsing beacon indicator */
#app-tour-spotlight::after {
  content: '';
  position: absolute;
  inset: -6px;
  border-radius: inherit;
  border: 2px solid rgba(251, 191, 36, 0.65);
  animation: beaconPulse 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
  pointer-events: none;
}
@keyframes beaconPulse {
  0% { transform: scale(0.98); opacity: 0.9; }
  100% { transform: scale(1.05); opacity: 0; }
}

/* Floating Tour Popover Card - Clay Style */
#app-tour-popover {
  position: fixed;
  z-index: 99999;
  background: #ffffff;
  border: 2.5px solid rgba(255, 255, 255, 0.95);
  border-radius: 28px;
  box-shadow: 
    0 24px 50px rgba(120, 53, 15, 0.22),
    0 6px 16px rgba(0, 0, 0, 0.08),
    inset 4px 4px 10px rgba(255, 255, 255, 1),
    inset -5px -5px 12px rgba(180, 83, 9, 0.08);
  padding: 18px 18px 15px;
  max-width: 370px;
  width: calc(100vw - 28px);
  box-sizing: border-box;
  display: none;
  pointer-events: auto;
  font-family: 'Nunito', sans-serif;
  opacity: 0;
  transform: translateY(6px);
  transition: opacity 0.22s ease, transform 0.22s ease;
}
#app-tour-popover.active {
  opacity: 1;
  transform: translateY(0);
}

.tour-pop-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.tour-pop-track-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10px;
  font-weight: 900;
  padding: 4px 10px;
  border-radius: 999px;
  background: #fef3c7;
  color: #b45309;
  border: 1.5px solid rgba(255, 255, 255, 0.8);
  box-shadow: 0 2px 6px rgba(180, 83, 9, 0.1), inset 1px 1px 3px rgba(255, 255, 255, 0.8);
  letter-spacing: 0.2px;
}
.tour-pop-step-count {
  font-size: 11px;
  font-weight: 800;
  color: #94a3b8;
}
.tour-pop-close {
  background: #f1f5f9;
  border: none;
  font-size: 15px;
  color: #64748b;
  cursor: pointer;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  box-shadow: inset 1px 1px 3px rgba(255,255,255,1), 0 2px 6px rgba(0,0,0,0.06);
  transition: all 0.15s;
}
.tour-pop-close:hover {
  color: #ef4444;
  background: #fee2e2;
}
.tour-pop-title {
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
  line-height: 1.3;
}
.tour-pop-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: #475569;
  line-height: 1.45;
  margin-bottom: 9px;
}
.tour-pop-tip {
  background: #fffbeb;
  border: 1.5px solid #fef08a;
  border-radius: 16px;
  padding: 8px 12px;
  font-size: 10.5px;
  font-weight: 800;
  color: #92400e;
  margin-bottom: 12px;
  display: flex;
  align-items: flex-start;
  gap: 6px;
  line-height: 1.35;
  box-shadow: inset 2px 2px 5px rgba(255,255,255,0.8), inset -2px -2px 5px rgba(245,158,11,0.08);
}
.tour-pop-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-top: 10px;
  border-top: 1.5px dashed #f1f5f9;
}
.tour-pop-dots {
  display: flex;
  align-items: center;
  gap: 5px;
}
.tour-pop-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #cbd5e1;
  transition: all 0.2s ease;
}
.tour-pop-dot.active {
  width: 18px;
  border-radius: 999px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  box-shadow: 0 2px 4px rgba(245, 158, 11, 0.4);
}
.tour-pop-nav-btns {
  display: flex;
  align-items: center;
  gap: 8px;
}
.btn-tour-nav-prev {
  background: #f8fafc;
  border: 1.5px solid rgba(255, 255, 255, 0.9);
  color: #475569;
  font-size: 11px;
  font-weight: 800;
  padding: 7px 13px;
  border-radius: 14px;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(0,0,0,0.06), inset 2px 2px 4px rgba(255,255,255,1), inset -2px -2px 4px rgba(0,0,0,0.04);
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.btn-tour-nav-prev:disabled {
  opacity: 0.35;
  cursor: not-allowed;
  pointer-events: none;
}
.btn-tour-nav-prev:active {
  transform: scale(0.95);
}
.btn-tour-nav-next {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.5px solid rgba(255, 255, 255, 0.8);
  color: #ffffff;
  font-size: 11.5px;
  font-weight: 900;
  padding: 7px 16px;
  border-radius: 14px;
  cursor: pointer;
  box-shadow: 0 8px 16px rgba(245, 158, 11, 0.38), inset 3px 3px 6px rgba(255, 255, 255, 0.7), inset -3px -3px 6px rgba(180, 83, 9, 0.3);
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.15s ease;
}
.btn-tour-nav-next.finish {
  background: linear-gradient(135deg, #10b981, #059669);
  box-shadow: 0 8px 16px rgba(16, 185, 129, 0.38), inset 3px 3px 6px rgba(255, 255, 255, 0.7), inset -3px -3px 6px rgba(6, 78, 59, 0.3);
}
.btn-tour-nav-next:active {
  transform: scale(0.95);
  box-shadow: 0 3px 8px rgba(245, 158, 11, 0.25);
}
</style>

<!-- Fullscreen Spotlight Tour Overlay & Popover Container -->
<div id="app-tour-overlay">
  <div id="app-tour-spotlight"></div>
  <div id="app-tour-popover">
    <div class="tour-pop-header">
      <div class="tour-pop-track-tag">
        <span id="tour-pop-track-text">🎬 Panduan MancingCuan</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="tour-pop-step-count" id="tour-pop-step-text">1/7</span>
        <button type="button" class="tour-pop-close" onclick="window.closeGlobalTour()" title="Lewati / Tutup Tour">
          <i class="ph-bold ph-x"></i>
        </button>
      </div>
    </div>
    <div class="tour-pop-title" id="tour-pop-title-text"></div>
    <div class="tour-pop-desc" id="tour-pop-desc-text"></div>
    <div class="tour-pop-tip" id="tour-pop-tip-box" style="display:none;">
      <span id="tour-pop-tip-text"></span>
    </div>
    <div class="tour-pop-footer">
      <div class="tour-pop-dots" id="tour-pop-dots"></div>
      <div class="tour-pop-nav-btns">
        <button type="button" class="btn-tour-nav-prev" id="btn-tour-prev" onclick="window.prevGlobalTourStep()">
          <i class="ph-bold ph-arrow-left"></i> Mundur
        </button>
        <button type="button" class="btn-tour-nav-next" id="btn-tour-next" onclick="window.nextGlobalTourStep()">
          Maju <i class="ph-bold ph-arrow-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<script src="/assets/js/toast.js"></script>
<?php
$show_wd_notif = true;
if (function_exists('is_wd_locked') && isset($pdo)) {
    if (is_wd_locked($pdo)) $show_wd_notif = false;
}
$recent_wd_list = [];
if ($show_wd_notif) {
    try {
        $wd_notif_stmt = $pdo->query("SELECT u.username, w.amount FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY w.id DESC LIMIT 15");
        $recent_wd_list = $wd_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $th) { $recent_wd_list = []; }
}
?>
<?php if ($show_wd_notif): ?>
<script>
(function() {
  let wdNotifs = <?= json_encode($recent_wd_list ?: []) ?>;
  const fakeNames = ["Andi","Budi","Cici","Dedi","Eka","Fajar","Gita","Hadi","Indra","Joko","Rina","Siti","Ayu","Dian","Fitri","Maya","Nina","Putra","Rizky","Sari","Tri","Wahyu","Yudi","Agus","Bambang","Rudi","Hendra","Iwan","Yanto","Arif","Hasan","Rizki","Nanda","Ahmad","Irfan"];
  function generateFakeWD() {
    const name = fakeNames[Math.floor(Math.random() * fakeNames.length)];
    const amount = (Math.floor(Math.random() * 24) + 2) * 10000;
    return { username: name, amount: amount };
  }
  while (wdNotifs.length < 25) wdNotifs.push(generateFakeWD());
  wdNotifs.sort(() => Math.random() - 0.5);
  if (wdNotifs && wdNotifs.length > 0) {
    function showRandomWD() {
      if (wdNotifs.length === 0) return;
      const wd = wdNotifs.pop();
      const amtStr = 'Rp ' + parseFloat(wd.amount).toLocaleString('id-ID');
      let uname = wd.username;
      uname = uname.length > 3 ? uname.substring(0,3)+'***' : uname+'***';
      if (typeof window.nToast === 'function') window.nToast(`💸 <b>${uname}</b> baru saja menarik <b>${amtStr}</b>`, 'success', 4000);
      if (wdNotifs.length > 0) setTimeout(showRandomWD, Math.floor(Math.random()*(40000-15000+1))+15000);
    }
  }
})();
</script>
<?php endif; ?>

<!-- GLOBAL SPOTLIGHT TOUR SCRIPT ENGINE -->
<script>
(function() {
  // ── 1. DEFINISI LANGKAH TOUR LENGKAP LINTAS HALAMAN (CROSS-PAGE) ──
  const GLOBAL_TOUR_CONFIG = {
    watch: [
      {
        url: '/home',
        selector: '#tour-video-mission, .cuan-video-mission',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-film-strip" style="color:#f97316;"></i> Kuota Misi Video Harian',
        desc: 'Setiap hari kamu mendapatkan kuota video berbayar. Progress bar ini menampilkan berapa video yang sudah kamu selesaikan dan sisa kuota hari ini.',
        tip: '💡 Kuota direset secara otomatis setiap malam pukul 00:00 WIB.',
        btnNext: 'Lihat Tombol Nonton ➔',
        pad: 8
      },
      {
        url: '/home',
        selector: '#tour-btn-watch, [href="/videos"].btn-cuan-watch-now',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-play-circle" style="color:#f59e0b;"></i> Tombol Cepat Mulai Nonton',
        desc: 'Klik tombol cepat ini untuk langsung memutar video cuan dan menghasilkan saldo rupiah dari video berdurasi singkat!',
        tip: '💡 Cukup tonton video sampai hitungan mundur selesai dan saldo rupiah otomatis bertambah ke akunmu.',
        btnNext: 'Buka Galeri Video ➔',
        pad: 6
      },
      {
        url: '/videos',
        selector: '.vcard, .vhub-hero, .video-hub-page',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-film-reel" style="color:#ea580c;"></i> Galeri Video Pilihan',
        desc: 'Pilih aneka video menarik yang ingin kamu tonton. Setiap video dilengkapi keterangan nominal reward uang rupiah yang transparan!',
        tip: '💡 Tonton seluruh kuota video setiap hari untuk mengumpulkan saldo maksimal.',
        btnNext: 'Lanjut ke Sarang Harian ➔',
        pad: 6
      },
      {
        url: '/checkin',
        selector: '.honeycomb-hive-stage, .hex-row, .ci-streak-strip, .ci-wrap',
        trackName: '🎬 Panduan MancingCuan',
        title: '<i class="ph-fill ph-gift" style="color:#f59e0b;"></i> Hadiah Check-in Harian',
        desc: 'Klaim hadiah gratis setiap hari! Cukup 1 klik check-in untuk mendapatkan kejutan uang tunai gratis tanpa syarat.',
        tip: '💡 Check-in rutin setiap hari untuk menjaga streak bonus uang yang semakin melimpah!',
        btnNext: 'Lanjut ke Misi Cuan ➔',
        pad: 8
      },
      {
        url: '/missions',
        selector: '.ms-tabs, .ms-card',
        trackName: '🎬 Panduan MancingCuan',
        title: '<i class="ph-fill ph-target" style="color:#8b5cf6;"></i> Misi & Tantangan Harian',
        desc: 'Dapatkan saldo tambahan berlipat dengan menyelesaikan berbagai misi harian, mingguan, dan pencapaian spesial!',
        tip: '💡 Tekan tombol Klaim begitu progress misi sudah tercapai 100%.',
        btnNext: 'Lanjut ke Tarik Saldo ➔',
        pad: 6
      },
      {
        url: '/withdraw',
        selector: '.wd-top-banner, .wd-balance-card, .wd-form',
        trackName: '🎬 Panduan MancingCuan',
        title: '<i class="ph-fill ph-arrow-up-right" style="color:#059669;"></i> Tarik Saldo ke Rekening',
        desc: 'Klaim uang tunaimu! Tarik saldo langsung ke rekening DANA, GoPay, OVO, ShopeePay, atau Bank transfer dalam hitungan menit.',
        tip: '💡 Penarikan diproses setiap hari dengan proses pencairan cepat dan aman.',
        btnNext: 'Kembali ke Beranda ➔',
        pad: 6
      },
      {
        url: '/home',
        selector: '#tour-balance-box, .cuan-card-balance-box',
        trackName: '🎬 Panduan MancingCuan',
        title: '<i class="ph-fill ph-sparkle" style="color:#fbbf24;"></i> Siap Mendulang Rupiah!',
        desc: 'Luar biasa! Kamu sudah menyelesaikan seluruh panduan cuan nonton video. Mulai tonton video pertamamu sekarang dan nikmati cuan yang mengalir!',
        tip: '💡 Tonton video secara rutin setiap hari untuk hasil yang maksimal! 🚀💰',
        btnNext: 'Selesai 🎉',
        pad: 6
      }
    ]
  };

  // ── 2. STATE MANAGER ──
  let isTourRunning = false;
  let currentTrack = 'watch';
  let currentStepIndex = 0;
  let activeElement = null;
  let activeStepConfig = null;
  let rafSyncId = null;

  function normalizePath(p) {
    if (!p) return '/';
    let clean = p.split('?')[0].split('#')[0].replace(/\/+$/, '');
    return clean || '/';
  }

  function getSavedTourState() {
    try {
      const raw = localStorage.getItem('mancing_tour_active');
      return raw ? JSON.parse(raw) : null;
    } catch(e) { return null; }
  }

  function setSavedTourState(track, step) {
    try {
      localStorage.setItem('mancing_tour_active', JSON.stringify({
        track: track,
        step: step,
        isRunning: true,
        updatedAt: Date.now()
      }));
    } catch(e) {}
  }

  function clearSavedTourState() {
    try {
      localStorage.removeItem('mancing_tour_active');
    } catch(e) {}
  }

  // ── 3. REAL-TIME SCROLL & RESIZE SYNCHRONIZER ──
  // Menyesuaikan spotlight dan popover secara instan saat layar atau menu di dalam di-scroll
  function syncSpotlightGeometry() {
    if (!isTourRunning || !activeElement || !activeStepConfig) return;
    if (rafSyncId) cancelAnimationFrame(rafSyncId);
    rafSyncId = requestAnimationFrame(() => {
      applySpotlightCoordinates(activeElement, activeStepConfig, false);
    });
  }

  // Tangkap scroll di window maupun di internal scrollable container dengan capture: true
  window.addEventListener('scroll', syncSpotlightGeometry, { passive: true, capture: true });
  window.addEventListener('resize', syncSpotlightGeometry, { passive: true });

  // ── 4. RENDER SPOTLIGHT PADA ELEMENT TARGET ──
  function applySpotlightCoordinates(el, step, isSmooth = false) {
    const spotlight = document.getElementById('app-tour-spotlight');
    const popover = document.getElementById('app-tour-popover');
    if (!spotlight || !popover) return;

    const rect = el.getBoundingClientRect();
    const pad = step.pad || 8;

    const top = Math.max(0, rect.top - pad);
    const left = Math.max(4, rect.left - pad);
    const width = Math.min(window.innerWidth - 8, rect.width + (pad * 2));
    const height = rect.height + (pad * 2);

    // Saat user scrolling, disable CSS transition agar spotlight tidak lag atau tertinggal
    spotlight.style.transition = isSmooth ? 'all 0.28s cubic-bezier(0.25, 1, 0.5, 1)' : 'none';
    popover.style.transition = isSmooth ? 'opacity 0.2s ease, transform 0.2s ease' : 'none';

    spotlight.style.top = top + 'px';
    spotlight.style.left = left + 'px';
    spotlight.style.width = width + 'px';
    spotlight.style.height = height + 'px';

    // Sesuaikan border-radius spotlight dengan bentuk asli element (bento, pill, circle, box)
    try {
      const comp = window.getComputedStyle(el);
      const radius = comp.borderRadius;
      if (radius && radius !== '0px') {
        spotlight.style.borderRadius = radius;
      } else {
        spotlight.style.borderRadius = '16px';
      }
    } catch(e) {
      spotlight.style.borderRadius = '16px';
    }

    // Posisikan popover card secara ergonomis
    positionPopoverCard(top, left, width, height, popover);
  }

  function positionPopoverCard(targetTop, targetLeft, targetWidth, targetHeight, popover) {
    const vh = window.innerHeight;
    const vw = window.innerWidth;
    const popHeight = popover.offsetHeight || 190;
    const spaceBelow = vh - (targetTop + targetHeight);
    const spaceAbove = targetTop;

    if (vw <= 480) {
      popover.style.left = '12px';
      popover.style.right = '12px';
      popover.style.width = 'calc(100vw - 24px)';
      popover.style.maxWidth = '370px';
      popover.style.margin = '0 auto';

      if (spaceBelow >= popHeight + 14) {
        popover.style.top = (targetTop + targetHeight + 10) + 'px';
        popover.style.bottom = 'auto';
      } else if (spaceAbove >= popHeight + 14) {
        popover.style.bottom = (vh - targetTop + 10) + 'px';
        popover.style.top = 'auto';
      } else {
        popover.style.bottom = '12px';
        popover.style.top = 'auto';
      }
    } else {
      popover.style.width = '360px';
      popover.style.right = 'auto';
      let centerLeft = targetLeft + (targetWidth / 2) - 180;
      centerLeft = Math.max(14, Math.min(vw - 374, centerLeft));
      popover.style.left = centerLeft + 'px';

      if (spaceBelow >= popHeight + 14) {
        popover.style.top = (targetTop + targetHeight + 12) + 'px';
        popover.style.bottom = 'auto';
      } else {
        popover.style.bottom = Math.max(12, vh - targetTop + 12) + 'px';
        popover.style.top = 'auto';
      }
    }
  }

  // ── 5. ASYNC ELEMENT FINDER DENGAN RETRY ──
  function locateElement(selector, maxTries = 12, delay = 120) {
    return new Promise((resolve) => {
      let tries = 0;
      function check() {
        const parts = selector.split(',').map(s => s.trim());
        for (let sel of parts) {
          try {
            const el = document.querySelector(sel);
            if (el) return resolve(el);
          } catch(e) {}
        }
        tries++;
        if (tries >= maxTries) return resolve(null);
        setTimeout(check, delay);
      }
      check();
    });
  }

  // ── 6. RENDER LANGKAH AKTIF ──
  async function renderActiveStep() {
    const steps = GLOBAL_TOUR_CONFIG[currentTrack];
    if (!steps || !steps[currentStepIndex]) {
      window.finishGlobalTour();
      return;
    }

    const step = steps[currentStepIndex];
    activeStepConfig = step;

    // Cek apakah langkah ini membutuhkan navigasi ke halaman lain
    const currentPath = normalizePath(window.location.pathname);
    const targetPath = normalizePath(step.url);

    if (currentPath !== targetPath) {
      // Simpan state tour dan redirect ke halaman yang sesuai
      setSavedTourState(currentTrack, currentStepIndex);
      window.location.href = step.url;
      return;
    }

    // Jika sudah di halaman yang benar, simpan state
    setSavedTourState(currentTrack, currentStepIndex);

    // Tampilkan container overlay
    const overlay = document.getElementById('app-tour-overlay');
    const spotlight = document.getElementById('app-tour-spotlight');
    const popover = document.getElementById('app-tour-popover');
    if (overlay) overlay.style.display = 'block';

    // Cari element di halaman
    const el = await locateElement(step.selector);
    activeElement = el || document.body;

    // Scroll element target ke tengah pandangan secara halus
    if (el && el !== document.body) {
      try {
        el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
      } catch(e) {}
    }

    // Isi konten teks popover
    document.getElementById('tour-pop-track-text').textContent = step.trackName;
    document.getElementById('tour-pop-step-text').textContent = `${currentStepIndex + 1}/${steps.length}`;
    document.getElementById('tour-pop-title-text').innerHTML = step.title;
    document.getElementById('tour-pop-desc-text').textContent = step.desc;

    const tipBox = document.getElementById('tour-pop-tip-box');
    if (step.tip) {
      tipBox.style.display = 'flex';
      document.getElementById('tour-pop-tip-text').textContent = step.tip;
    } else {
      tipBox.style.display = 'none';
    }

    // Render step indicator dots
    const dotsContainer = document.getElementById('tour-pop-dots');
    dotsContainer.innerHTML = '';
    for (let i = 0; i < steps.length; i++) {
      const d = document.createElement('span');
      d.className = 'tour-pop-dot' + (i === currentStepIndex ? ' active' : '');
      dotsContainer.appendChild(d);
    }

    // Tombol Mundur
    const prevBtn = document.getElementById('btn-tour-prev');
    prevBtn.disabled = (currentStepIndex === 0);

    // Tombol Maju
    const nextBtn = document.getElementById('btn-tour-next');
    if (currentStepIndex === steps.length - 1) {
      nextBtn.className = 'btn-tour-nav-next finish';
      nextBtn.innerHTML = 'Selesai 🎉';
    } else {
      nextBtn.className = 'btn-tour-nav-next';
      nextBtn.innerHTML = (step.btnNext || 'Maju ➔');
    }

    popover.style.display = 'block';

    // Berikan sedikit jeda untuk kalkulasi posisi setelah layout/scroll selesai
    setTimeout(() => {
      applySpotlightCoordinates(activeElement, step, true);
      if (spotlight) spotlight.classList.add('active');
      if (popover) popover.classList.add('active');
    }, 220);
  }

  // ── 7. KONTROL TOUR PUBLIK (WINDOW EXPOSURE) ──
  window.startGlobalTour = function(track = 'watch', stepIndex = 0) {
    currentTrack = 'watch';
    currentStepIndex = stepIndex;
    isTourRunning = true;

    const steps = GLOBAL_TOUR_CONFIG[currentTrack] || GLOBAL_TOUR_CONFIG.watch;
    const targetPath = normalizePath(steps[currentStepIndex].url);
    const currentPath = normalizePath(window.location.pathname);

    if (currentPath !== targetPath) {
      setSavedTourState(currentTrack, currentStepIndex);
      window.location.href = steps[currentStepIndex].url;
      return;
    }

    renderActiveStep();
  };

  window.nextGlobalTourStep = function() {
    const steps = GLOBAL_TOUR_CONFIG[currentTrack] || GLOBAL_TOUR_CONFIG.watch;
    if (!steps) return;
    if (currentStepIndex < steps.length - 1) {
      currentStepIndex++;
      const nextStep = steps[currentStepIndex];
      const currentPath = normalizePath(window.location.pathname);
      const nextPath = normalizePath(nextStep.url);

      if (currentPath !== nextPath) {
        setSavedTourState(currentTrack, currentStepIndex);
        window.location.href = nextStep.url;
      } else {
        renderActiveStep();
      }
    } else {
      window.finishGlobalTour();
    }
  };

  window.prevGlobalTourStep = function() {
    if (currentStepIndex > 0) {
      currentStepIndex--;
      const prevStep = (GLOBAL_TOUR_CONFIG[currentTrack] || GLOBAL_TOUR_CONFIG.watch)[currentStepIndex];
      const currentPath = normalizePath(window.location.pathname);
      const prevPath = normalizePath(prevStep.url);

      if (currentPath !== prevPath) {
        setSavedTourState(currentTrack, currentStepIndex);
        window.location.href = prevStep.url;
      } else {
        renderActiveStep();
      }
    }
  };

  window.closeGlobalTour = function() {
    isTourRunning = false;
    clearSavedTourState();

    const overlay = document.getElementById('app-tour-overlay');
    const spotlight = document.getElementById('app-tour-spotlight');
    const popover = document.getElementById('app-tour-popover');

    if (spotlight) spotlight.classList.remove('active');
    if (popover) popover.classList.remove('active');
    setTimeout(() => {
      if (overlay) overlay.style.display = 'none';
      if (popover) popover.style.display = 'none';
    }, 200);
  };

  window.finishGlobalTour = function() {
    window.closeGlobalTour();
    try {
      localStorage.setItem('mancing_tour_done', '1');
      const b = document.getElementById('tour-invite-card');
      if (b) b.style.display = 'none';
    } catch(e) {}

    if (typeof window.nToast === 'function') {
      window.nToast('🎉 Selamat! Kamu telah menguasai panduan MancingCuan dan siap mendulang rupiah!', 'success', 5000);
    }
  };

  // Keyboard Navigation: ArrowRight (Maju), ArrowLeft (Mundur), Escape (Tutup)
  window.addEventListener('keydown', (e) => {
    if (!isTourRunning) return;
    if (e.key === 'ArrowRight') {
      window.nextGlobalTourStep();
    } else if (e.key === 'ArrowLeft') {
      window.prevGlobalTourStep();
    } else if (e.key === 'Escape') {
      window.closeGlobalTour();
    }
  });

  // ── 8. AUTO-RESUME TOUR SAAT PINDAH HALAMAN ──
  document.addEventListener('DOMContentLoaded', () => {
    const saved = getSavedTourState();
    if (saved && saved.isRunning) {
      currentTrack = 'watch';
      currentStepIndex = parseInt(saved.step, 10) || 0;
      isTourRunning = true;

      // Tunggu layout siap render
      setTimeout(() => {
        renderActiveStep();
      }, 300);
    }
  });
})();
</script>
</body>
</html>
