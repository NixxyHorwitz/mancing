<?php
declare(strict_types=1);
// DEBUG: tampilkan semua error PHP ke log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';
$user         = require_auth($pdo);
$_favicon     = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_seo_title   = setting($pdo, 'seo_title', 'MancingCuan');
$_lc_enabled  = setting($pdo, 'livechat_enabled', '1') === '1';
$_ai_enabled  = setting($pdo, 'chat_ai_enabled', '1') === '1';
$_adm_enabled = setting($pdo, 'chat_admin_enabled', '1') === '1';
$_att_enabled = setting($pdo, 'lc_attachment_enabled', '1') === '1';
$_debug_on    = setting($pdo, 'lc_debug_panel', '0') === '1';
if (!$_ai_enabled && !$_adm_enabled) $_lc_enabled = false;

error_log('[LiveChat] page loaded, user=' . ($user['username'] ?? 'null')
    . ' lc_enabled=' . (int)$_lc_enabled
    . ' ai=' . (int)$_ai_enabled
    . ' adm=' . (int)$_adm_enabled
    . ' debug=' . (int)$_debug_on);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<meta name="theme-color" content="#78350f">
<title>Live Chat — <?= htmlspecialchars($_seo_title) ?></title>
<?php
$_abs_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
if ($_abs_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
/* ── LiveChat: position:fixed layout (keyboard-safe, app.css-proof) ── */
* { box-sizing: border-box; }
body { margin: 0; padding: 0; overflow: hidden; background: #fef8ee; font-family: 'Nunito', sans-serif; }

/* Topbar: fixed to top */
.chat-topbar {
  position: fixed !important;
  top: 0; left: 0; right: 0;
  height: 60px;
  z-index: 100;
  background: linear-gradient(180deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  border-bottom: 3.5px solid #78350f;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 16px;
  flex-shrink: 0;
  color: #fff;
  box-shadow: 0 4px 14px rgba(120,53,15,0.25);
  overflow: hidden;
}
.chat-topbar::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 24px 24px;
  background-position: 0 0, 12px 12px;
  opacity: 0.18; pointer-events: none;
}

.chat-back-btn {
  width: 36px; height: 36px;
  border: 2px solid #78350f;
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 2.5px 0 #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: #78350f;
  text-decoration: none;
  transition: transform .1s, box-shadow .1s;
  position: relative; z-index: 2;
}
.chat-back-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

.chat-topbar__info { flex: 1; min-width: 0; position: relative; z-index: 2; }
.chat-topbar__title { font-size: 16px; font-weight: 900; display: flex; align-items: center; gap: 6px; letter-spacing: -0.3px; text-shadow: 0 1px 2px rgba(120,53,15,0.4); color: #fff; }
.chat-topbar__sub   { font-size: 11px; color: #fef3c7; font-weight: 800; display: block; margin-top: -2px; }

.chat-status-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 900;
  padding: 4px 10px;
  border: 2px solid #78350f;
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 5px;
  box-shadow: 0 2px 0 #78350f;
  text-transform: uppercase;
  position: relative; z-index: 2;
}
.chat-status-badge.online { background: linear-gradient(135deg, #34d399, #10b981); color: #fff; border-color: #064e3b; box-shadow: 0 2px 0 #064e3b; }
.chat-status-badge.busy   { background: linear-gradient(135deg, #f87171, #ef4444); color: #fff; border-color: #991b1b; box-shadow: 0 2px 0 #991b1b; }
.chat-status-badge::before {
  content: '';
  width: 6px; height: 6px;
  border-radius: 50%;
  background: #fff;
  display: inline-block;
  box-shadow: 0 0 4px rgba(255,255,255,0.8);
}

/* Chat root: fills everything below topbar */
#chat-root {
  position: fixed;
  top: 60px;
  left: 0; right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: #fef8ee;
}
</style>
</head>
<body>

<?php if (!$_lc_enabled): ?>
<!-- Live Chat Disabled -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#fef8ee;padding:24px;">
  <div style="text-align:center;max-width:340px;background:#ffffff;border:3px solid #78350f;border-radius:24px;box-shadow:0 6px 0 #78350f;padding:32px 24px;">
    <div style="width:72px;height:72px;margin:0 auto 16px;background:linear-gradient(135deg,#fef08a,#fde047);border:2.5px solid #78350f;box-shadow:0 4px 0 #78350f;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:36px;color:#78350f;">
      <i class="ph-fill ph-clock-countdown"></i>
    </div>
    <h2 style="font-weight:900;font-size:20px;margin-bottom:8px;color:#78350f;">Live Chat Sedang Ditutup</h2>
    <p style="color:#92400e;font-size:13px;font-weight:700;margin-bottom:24px;line-height:1.5;"><?= nl2br(htmlspecialchars(setting($pdo, 'lc_offline_msg', 'Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional.'))) ?></p>
    <a href="/home" style="background:linear-gradient(135deg, #f59e0b, #d97706);border:2.5px solid #78350f;box-shadow:0 4px 0 #78350f;border-radius:14px;font-weight:900;padding:12px 24px;font-size:13px;text-decoration:none;color:#fff;display:inline-flex;align-items:center;gap:6px;transition:transform 0.1s;"><i class="ph-bold ph-house"></i> Kembali ke Beranda</a>
  </div>
</div>
<?php else: ?>
<!-- Live Chat Active -->
<!-- ── Custom Topbar ── -->
<header class="chat-topbar" id="chat-topbar">
  <a href="/home" class="chat-back-btn" title="Kembali ke Beranda">
    <i class="ph-bold ph-caret-left" style="font-size:20px;"></i>
  </a>
  <div class="chat-topbar__info">
    <span class="chat-topbar__title"><i class="ph-fill ph-chat-circle-dots" style="color:#fde047;"></i> Live Support 🍯</span>
    <span class="chat-topbar__sub" id="topbar-username"><?= htmlspecialchars($user['username']) ?></span>
  </div>
  <div class="chat-topbar__actions">
    <div class="chat-status-badge online" id="chat-status-badge">Online</div>
  </div>
</header>

<?php if ($_debug_on): ?>
<!-- ── DEBUG PANEL ── -->
<div id="lc-debug-wrapper" style="
  position:fixed; bottom:0; left:0; right:0; z-index:9999;
  background:rgba(0,0,10,0.92); color:#e0e0e0;
  font-size:10px; font-family:monospace; line-height:1.5;
  max-height:38vh; display:flex; flex-direction:column;
  border-top:2px solid #f59e0b;
">
  <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#111;flex-shrink:0;border-bottom:1px solid #333">
    <strong style="color:#f59e0b">🐛 LiveChat Debug</strong>
    <button onclick="this.closest('#lc-debug-wrapper').style.display='none'"
      style="background:none;border:none;color:#aaa;cursor:pointer;font-size:12px">✕ tutup</button>
  </div>
  <div id="lc-debug" style="overflow-y:auto;flex:1;padding:6px 8px"></div>
</div>
<?php endif; ?>

<style>
/* ═══════════════════════════════════════
   LIVECHAT — MANCINGCUAN THEME
   ═══════════════════════════════════════ */
.chat-page {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;
  padding: 0;
  overflow: hidden;
  min-height: 0;
}

/* ── Mode switch bar ─────────────────── */
.chat-modebar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: #fffbeb;
  border-bottom: 2px solid #fde68a;
  flex-shrink: 0;
}
.chat-modebar__label {
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  flex-shrink: 0;
}
.mode-pill {
  display: flex;
  background: #fef3c7;
  border: 2px solid #78350f;
  border-radius: 14px;
  box-shadow: 0 2px 0 #78350f;
  overflow: hidden;
  flex: 1;
  padding: 2px;
}
.mode-btn {
  flex: 1;
  border: none;
  background: transparent;
  font-size: 12px;
  font-weight: 800;
  padding: 7px 6px;
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  color: #92400e;
  border-radius: 10px;
  font-family: 'Nunito', sans-serif;
}
.mode-btn.active {
  color: #fff;
  font-weight: 900;
  box-shadow: 0 2px 4px rgba(120,53,15,0.2);
}
.mode-btn.active.mode-ai  { background: linear-gradient(135deg, #8b5cf6, #6d28d9); text-shadow: 0 1px 1px rgba(0,0,0,0.2); }
.mode-btn.active.mode-adm { background: linear-gradient(135deg, #f59e0b, #d97706); text-shadow: 0 1px 1px rgba(0,0,0,0.2); }

/* ── Mode info banner ────────────────── */
.mode-info-banner {
  margin: 0 16px;
  padding: 9px 13px;
  border-radius: 14px;
  border: 2px solid;
  font-size: 11.5px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
  animation: fadeIn 0.3s ease;
}
@keyframes fadeIn { from { opacity:0; transform:translateY(-5px); } to { opacity:1; transform:translateY(0); } }
.mode-info-banner.ai-mode  { background: #ede9fe; border-color: #c4b5fd; color: #5b21b6; }
.mode-info-banner.adm-mode { background: #fef3c7; border-color: #f59e0b; color: #78350f; }

/* ── Messages area ───────────────────── */
.chat-messages {
  flex: 1 1 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #fef8ee;
  background-image: radial-gradient(rgba(120,53,15,0.04) 12%, transparent 13%), radial-gradient(rgba(120,53,15,0.04) 12%, transparent 13%);
  background-size: 32px 32px;
  background-position: 0 0, 16px 16px;
  scroll-behavior: smooth;
  min-height: 0;
  -webkit-overflow-scrolling: touch;
}
.chat-messages::-webkit-scrollbar { width: 6px; }
.chat-messages::-webkit-scrollbar-thumb { background: #fde68a; border-radius: 6px; }

/* ── Bubble ──────────────────────────── */
.bubble-wrap {
  display: flex;
  flex-direction: column;
  max-width: 85%;
  animation: bubblePop .3s cubic-bezier(.175,.885,.32,1.275);
}
@keyframes bubblePop {
  0% { opacity: 0; transform: scale(0.8) translateY(10px); }
  100% { opacity: 1; transform: scale(1) translateY(0); }
}
.bubble-wrap.user  { align-self: flex-end; align-items: flex-end; }
.bubble-wrap.other { align-self: flex-start; align-items: flex-start; }
.bubble-wrap.system{ align-self: center; align-items: center; max-width: 90%; }

.bubble {
  padding: 12px 16px;
  font-size: 13.5px;
  font-weight: 700;
  line-height: 1.5;
  word-break: break-word;
  position: relative;
}

.bubble-wrap.user .bubble {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  border: 2.5px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  border-radius: 20px 20px 4px 20px;
  color: #78350f;
}
.bubble-wrap.ai .bubble {
  background: #ffffff;
  border: 2.5px solid #6366f1;
  box-shadow: 0 3px 0 #4f46e5;
  border-radius: 20px 20px 20px 4px;
  color: #1e1b4b;
}
.bubble-wrap.admin .bubble {
  background: #ffffff;
  border: 2.5px solid #059669;
  box-shadow: 0 3px 0 #047857;
  border-radius: 20px 20px 20px 4px;
  color: #064e3b;
}
.bubble-wrap.system .bubble {
  background: #fffbeb;
  color: #92400e;
  font-size: 11px;
  border: 1.5px dashed #d97706;
  box-shadow: none;
  padding: 8px 16px;
  border-radius: 20px;
  font-weight: 800;
}

.bubble-meta {
  font-size: 10px;
  color: #92400e;
  font-weight: 800;
  margin-top: 5px;
  padding: 0 6px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.bubble-wrap.user .bubble-meta { flex-direction: row-reverse; }

.bubble-sender-tag {
  font-size: 10px;
  font-weight: 900;
  padding: 3px 10px;
  border-radius: 12px;
  border: 2px solid #fff;
  margin-bottom: 6px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #fff;
  text-shadow: 0 1px 1px rgba(0,0,0,0.3);
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.sender-ai    { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
.sender-admin { background: linear-gradient(135deg, #10b981, #059669); }
.sender-user  { background: linear-gradient(135deg, #f59e0b, #d97706); }

/* ── Typing indicator ────────────────── */
.typing-bubble {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 12px 18px;
  background: #ffffff;
  border: 2.5px solid #6366f1;
  border-radius: 20px 20px 20px 4px;
  box-shadow: 0 3px 0 #4f46e5;
  width: fit-content;
}
.typing-dot {
  width: 8px; height: 8px;
  background: #6366f1;
  border-radius: 50%;
  animation: typingBounce 1.4s infinite ease-in-out both;
}
.typing-dot:nth-child(1) { animation-delay: -0.32s; }
.typing-dot:nth-child(2) { animation-delay: -0.16s; }
@keyframes typingBounce {
  0%, 80%, 100% { transform: scale(0); }
  40% { transform: scale(1); }
}

/* ── Input area ──────────────────────── */
.chat-inputbar {
  padding: 12px 16px;
  padding-bottom: calc(12px + env(safe-area-inset-bottom));
  background: #ffffff;
  border-top: 2.5px solid #fde68a;
  display: flex;
  gap: 10px;
  align-items: flex-end;
  flex-shrink: 0;
  z-index: 10;
  box-shadow: 0 -4px 16px rgba(120,53,15,0.06);
}
.chat-textarea {
  flex: 1;
  min-height: 48px;
  max-height: 120px;
  resize: none;
  border: 2px solid #fde68a;
  border-radius: 16px;
  padding: 12px 16px;
  font-size: 16px; /* Prevent iOS zoom */
  font-family: inherit;
  font-weight: 700;
  color: #78350f;
  background: #fffbeb;
  outline: none;
  transition: all .2s;
  overflow-y: auto;
  line-height: 1.4;
}
.chat-textarea:focus { border-color: #f59e0b; background: #ffffff; box-shadow: 0 0 0 3px #fef3c7; }
.chat-textarea::placeholder { color: #b45309; opacity: 0.65; font-weight: 600; }

.chat-attach-btn {
  width: 48px; height: 48px;
  flex-shrink: 0;
  background: #fef3c7;
  border: 2px solid #78350f;
  border-radius: 14px;
  box-shadow: 0 2.5px 0 #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .1s, box-shadow .1s;
  color: #78350f;
  font-size: 20px;
}
.chat-attach-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

.chat-send-btn {
  width: 48px; height: 48px;
  flex-shrink: 0;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2.5px solid #78350f;
  border-radius: 14px;
  box-shadow: 0 3.5px 0 #78350f;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .1s, box-shadow .1s;
  color: #fff;
}
.chat-send-btn:hover { transform: translateY(-1px); box-shadow: 0 4.5px 0 #78350f; }
.chat-send-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.chat-send-btn:disabled { background: #f1f5f9; border-color: #cbd5e1; box-shadow: 0 2px 0 #94a3b8; color: #94a3b8; cursor: not-allowed; transform: none; }

/* ── Session start overlay ───────────── */
.chat-start-overlay {
  position: absolute;
  inset: 0;
  background: rgba(120, 53, 15, 0.45);
  backdrop-filter: blur(5px);
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.chat-start-card {
  width: 100%;
  max-width: 360px;
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 24px;
  box-shadow: 0 6px 0 #78350f, 0 16px 32px rgba(120,53,15,0.2);
  padding: 28px 22px;
  text-align: center;
  animation: bubblePop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.chat-start-icon {
  width: 76px; height: 76px;
  margin: 0 auto 16px;
  background: linear-gradient(135deg, #fef08a, #fde047);
  border: 2.5px solid #78350f;
  box-shadow: 0 4px 0 #78350f;
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 38px;
}
.chat-start-title { font-size: 21px; font-weight: 900; margin-bottom: 6px; color: #78350f; }
.chat-start-sub   { font-size: 13px; color: #92400e; font-weight: 700; margin-bottom: 22px; line-height: 1.4; }

/* ── Closed overlay ──────────────────── */
.chat-closed-bar {
  padding: 14px;
  background: #fef2f2;
  border-top: 2.5px solid #fca5a5;
  color: #991b1b;
  text-align: center;
  font-size: 13px;
  font-weight: 800;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}
</style>

<div id="chat-root">

  <!-- Start Overlay -->
  <div class="chat-start-overlay" id="chat-start-overlay">
    <div class="chat-start-card">
      <div class="chat-start-icon">🍯</div>
      <div class="chat-start-title">Live Support</div>
      <div class="chat-start-sub">Pilih mode chat dan mulai percakapan sekarang.</div>

      <!-- Mode selector in start screen -->
      <div style="margin-bottom:24px;">
        <p style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:10px;text-transform:uppercase;letter-spacing:0.5px;">Pilih Mode Chat</p>
        <div class="mode-pill" style="box-shadow: 0 3px 0 #78350f; border-width: 2px; padding: 3px; background: #fef3c7;">
          <?php if ($_ai_enabled): ?>
          <button class="mode-btn mode-ai active" id="start-mode-ai" onclick="selectStartMode('ai')" style="font-size:13px; padding:10px;">
            🤖 Asisten AI
          </button>
          <?php endif; ?>
          <?php if ($_adm_enabled): ?>
          <button class="mode-btn mode-adm" id="start-mode-admin" onclick="selectStartMode('admin')" style="font-size:13px; padding:10px;">
            👨‍💼 Admin
          </button>
          <?php endif; ?>
        </div>
        <p id="start-mode-desc" style="font-size:12px;color:#92400e;margin-top:12px;font-weight:700;">
          <?php if ($_ai_enabled): ?>
          AI akan menjawab pertanyaan Anda secara otomatis & instan.
          <?php else: ?>
          Admin akan membalas pesan Anda langsung.
          <?php endif; ?>
        </p>
      </div>

      <button id="btn-start-chat" onclick="startChat()" style="width:100%; background:linear-gradient(135deg, #10b981, #059669); border: 2.5px solid #064e3b; box-shadow: 0 4px 0 #064e3b; color: #fff; font-size: 15px; font-weight: 900; padding: 13px; border-radius: 16px; cursor: pointer; transition: transform 0.1s; display:flex; align-items:center; justify-content:center; gap:8px;">
        <i class="ph-bold ph-paper-plane-right" style="font-size:20px;"></i> Mulai Chat
      </button>
    </div>
  </div>

  <!-- Queue Overlay (Saat Kapasitas Sesi Penuh) -->
  <div class="chat-start-overlay" id="chat-queue-overlay" style="display:none;">
    <div class="chat-start-card" style="text-align:center;">
      <div class="chat-start-icon" style="font-size:44px; margin-bottom:12px;">⏳</div>
      <div class="chat-start-title" style="font-size:20px; font-weight:900; color:#78350f;">Sesi Chat Sedang Penuh</div>
      <div class="chat-start-sub" style="font-size:13px; color:#92400e; margin-bottom:16px;">Mohon tunggu sebentar, Anda berada dalam antrean customer support.</div>

      <!-- Queue Box -->
      <div style="background:#fffbeb; border:2.5px dashed #f59e0b; border-radius:18px; padding:18px 14px; margin-bottom:18px;">
        <div style="font-size:11px; font-weight:900; color:#78350f; text-transform:uppercase; letter-spacing:0.5px;">Nomor Antrean Anda</div>
        <div style="font-size:38px; font-weight:900; color:#d97706; margin:4px 0; text-shadow:0 2px 4px rgba(217,119,6,0.15);">#<span id="queue-pos-num">1</span></div>
        <div style="font-size:12px; font-weight:800; color:#92400e;">
          <span id="queue-ahead-num">0</span> orang di depan Anda
        </div>
      </div>

      <div style="display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:800; color:#78350f; margin-bottom:20px; background:#fef3c7; padding:10px 14px; border-radius:14px; border:1.5px solid #fde68a;">
        <i class="ph-bold ph-spinner ph-spin" style="font-size:16px; color:#d97706;"></i>
        <span>Menghubungkan otomatis saat giliran Anda...</span>
      </div>

      <button id="btn-cancel-queue" onclick="cancelQueue()" style="width:100%; background:linear-gradient(135deg, #ef4444, #dc2626); border: 2.5px solid #991b1b; box-shadow: 0 4px 0 #991b1b; color: #fff; font-size: 14px; font-weight: 900; padding: 12px; border-radius: 16px; cursor: pointer; transition: transform 0.1s; display:flex; align-items:center; justify-content:center; gap:8px;">
        <i class="ph-bold ph-x-circle" style="font-size:18px;"></i> Batal Antre
      </button>
    </div>
  </div>

  <!-- Chat UI -->
  <div class="chat-page" id="chat-ui" style="display:none;">

    <!-- Mode Bar -->
    <div class="chat-modebar">
      <span class="chat-modebar__label"><i class="ph-bold ph-swap"></i> Mode:</span>
      <div class="mode-pill" id="mode-pill-wrap">
        <?php if ($_ai_enabled): ?>
        <button class="mode-btn mode-ai" id="modebtn-ai" onclick="switchMode('ai')">
          🤖 AI
        </button>
        <?php endif; ?>
        <?php if ($_adm_enabled): ?>
        <button class="mode-btn mode-adm" id="modebtn-admin" onclick="switchMode('admin')">
          👨‍💼 Admin
        </button>
        <?php endif; ?>
      </div>
      <div class="chat-status-badge online" id="chat-status-badge">Online</div>
    </div>

    <!-- Mode info banner -->
    <div class="mode-info-banner ai-mode" id="mode-info-banner" style="margin-top:12px;">
      <i class="ph-fill ph-info"></i>
      <span id="mode-info-text">Mode AI aktif — Dijawab otomatis oleh AI.</span>
    </div>

    <!-- Messages -->
    <div class="chat-messages" id="chat-messages"></div>

    <!-- Typing indicator -->
    <div id="typing-wrap" style="padding:0 16px 12px;display:none;">
      <div class="typing-bubble">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>
    </div>

    <!-- Input bar -->
    <div class="chat-inputbar" id="chat-inputbar">
      <?php if ($_att_enabled): ?>
      <button class="chat-attach-btn" onclick="document.getElementById('chat-attachment-input').click()" title="Lampirkan File"><i class="ph-bold ph-paperclip"></i></button>
      <input type="file" id="chat-attachment-input" style="display:none;" accept="image/jpeg,image/png,image/gif,application/pdf,.zip,.rar" onchange="previewAttachment(this)">
      <?php endif; ?>
      
      <div style="flex:1; display:flex; flex-direction:column; gap:6px; position:relative;">
        <div id="attachment-preview" style="display:none; font-size:11px; background:#fffbeb; border-radius:10px; padding:6px 10px; border:2px solid #fde68a; font-weight:800; color:#78350f;">
            <i class="ph-bold ph-file"></i> <span id="att-preview-name"></span>
            <span style="color:#ef4444; cursor:pointer; float:right; padding: 0 4px;" onclick="clearAttachment()"><i class="ph-bold ph-x"></i> Hapus</span>
        </div>
        <textarea class="chat-textarea" id="chat-input"
          placeholder="Ketik pesan..." rows="1"
          onkeydown="handleKey(event)"
          oninput="autoResize(this)"
        ></textarea>
      </div>
      
      <button class="chat-send-btn" id="chat-send-btn" onclick="sendMessage()" title="Kirim Pesan">
        <i class="ph-bold ph-paper-plane-right" style="font-size:22px;"></i>
      </button>
    </div>

    <!-- Closed bar -->
    <div class="chat-closed-bar" id="chat-closed-bar" style="display:none;">
      <i class="ph-fill ph-lock-key"></i> Sesi ditutup.
      <button onclick="resetChat()" style="background:#ef4444;color:#fff;border:2px solid #991b1b;border-radius:10px;padding:6px 16px;font-weight:900;font-size:12px;cursor:pointer;box-shadow:0 2.5px 0 #991b1b;">Chat Baru</button>
    </div>
  </div>

</div>

<script>
/* ═══════════════════════════════════════
   LIVECHAT CLIENT
═══════════════════════════════════════ */
let sessionKey   = null;
let queueToken   = null;
let queueTimer   = null;

// Initial mode priority
const LC_AI_ENABLED  = <?= $_ai_enabled ? 'true' : 'false' ?>;
const LC_ADM_ENABLED = <?= $_adm_enabled ? 'true' : 'false' ?>;
let startMode = LC_AI_ENABLED ? 'ai' : (LC_ADM_ENABLED ? 'admin' : 'ai');
let currentMode = startMode;

let lastMsgId    = 0;
let pollTimer    = null;
let sessionStatus = 'open';
let isPolling    = false;

// Initialize starting button states if both are available
if (LC_AI_ENABLED && LC_ADM_ENABLED) {
    document.getElementById('start-mode-ai').classList.add('active');
    document.getElementById('start-mode-admin').classList.remove('active');
}

// ── DEBUG PANEL ──────────────────────────────────────────
const _debugEl = document.getElementById('lc-debug');
function dbg(msg, data) {
  const ts = new Date().toLocaleTimeString('id-ID');
  const str = data ? JSON.stringify(data) : '';
  console.log('[LiveChat]', msg, data ?? '');
  if (_debugEl) {
    const line = document.createElement('div');
    line.style.cssText = 'border-bottom:1px solid #333;padding:2px 0;word-break:break-all';
    line.innerHTML = `<span style="color:#aaa">${ts}</span> <span style="color:#4fc3f7">${msg}</span>` + (str ? ` <span style="color:#fff9c4">${str}</span>` : '');
    _debugEl.appendChild(line);
    _debugEl.scrollTop = _debugEl.scrollHeight;
  }
}
window.onerror = (msg, src, line, col, err) => {
  dbg('❌ JS Error: ' + msg, { src, line });
  return false;
};
window.addEventListener('unhandledrejection', e => dbg('❌ Promise rejected', String(e.reason)));

// ── Mode selector on start screen ────────────────────────
function selectStartMode(mode) {
  startMode = mode;
  const btnAi = document.getElementById('start-mode-ai');
  const btnAdm = document.getElementById('start-mode-admin');
  
  if (btnAi) btnAi.classList.toggle('active', mode === 'ai');
  if (btnAdm) btnAdm.classList.toggle('active', mode === 'admin');
  
  const desc = document.getElementById('start-mode-desc');
  if (mode === 'ai') {
    desc.textContent = 'AI akan menjawab pertanyaan Anda secara otomatis & instan.';
  } else {
    desc.textContent = 'Admin akan membalas pesan Anda langsung.';
  }
}

// ── Start session ─────────────────────────────────────────
async function startChat() {
  const btn = document.getElementById('btn-start-chat');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin" style="font-size:20px;"></i> Menghubungkan...';

  try {
    const fd = new FormData();
    fd.append('mode', startMode);
    if (queueToken) fd.append('queue_token', queueToken);

    const res = await fetch('/chat_action?action=start', { method: 'POST', body: fd, credentials: 'include' });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Gagal memulai sesi.');

    // Jika antrean penuh / masuk queue
    if (data.queued) {
      btn.disabled = false;
      btn.innerHTML = '<i class="ph-bold ph-paper-plane-right" style="font-size:20px;"></i> Mulai Chat';

      queueToken = data.queue_token;
      showQueueUI(data.position, Math.max(0, data.position - 1));
      startQueuePolling();
      return;
    }

    // Berhasil masuk room chat
    hideQueueUI();
    sessionKey    = data.session_key;
    currentMode   = data.mode;
    sessionStatus = data.status;

    document.getElementById('chat-start-overlay').style.display = 'none';
    document.getElementById('chat-ui').style.display = 'flex';

    const msgs = document.getElementById('chat-messages');
    msgs.innerHTML = '';
    (data.messages || []).forEach(m => appendBubble(m.sender, m.message, m.created_at, false, m.attachment, m.id));

    updateModeUI(currentMode);
    if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);

    scrollBottom();
    startPolling();
    document.getElementById('chat-input').focus();

  } catch(e) {
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-paper-plane-right" style="font-size:20px;"></i> Mulai Chat';
    if (typeof nToast === 'function') nToast('❌ ' + e.message, 'error');
  }
}

// ── Queue UI & Polling ────────────────────────────────────
function showQueueUI(pos, ahead) {
  document.getElementById('chat-start-overlay').style.display = 'none';
  document.getElementById('chat-ui').style.display = 'none';
  const qOverlay = document.getElementById('chat-queue-overlay');
  if (qOverlay) {
    qOverlay.style.display = 'flex';
    document.getElementById('queue-pos-num').textContent = pos || 1;
    document.getElementById('queue-ahead-num').textContent = ahead || 0;
  }
}

function hideQueueUI() {
  const qOverlay = document.getElementById('chat-queue-overlay');
  if (qOverlay) qOverlay.style.display = 'none';
  if (queueTimer) { clearInterval(queueTimer); queueTimer = null; }
}

function startQueuePolling() {
  if (queueTimer) clearInterval(queueTimer);
  queueTimer = setInterval(pollQueueStatus, 3000);
}

async function pollQueueStatus() {
  if (!queueToken) return;
  try {
    const res = await fetch(`/chat_action?action=check_queue&queue_token=${encodeURIComponent(queueToken)}`, { credentials: 'include' });
    const data = await res.json();
    if (!data.ok) return;

    if (data.status === 'ready' && data.session_key) {
      // Slot tersedia! Transisi langsung ke room chat
      hideQueueUI();
      sessionKey    = data.session_key;
      currentMode   = data.mode;
      sessionStatus = 'open';

      document.getElementById('chat-start-overlay').style.display = 'none';
      document.getElementById('chat-ui').style.display = 'flex';

      const msgs = document.getElementById('chat-messages');
      msgs.innerHTML = '';
      (data.messages || []).forEach(m => appendBubble(m.sender, m.message, m.created_at, false, m.attachment, m.id));

      updateModeUI(currentMode);
      if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);

      scrollBottom();
      startPolling();
      document.getElementById('chat-input').focus();
    } else if (data.status === 'waiting') {
      const pos = data.position || 1;
      document.getElementById('queue-pos-num').textContent = pos;
      document.getElementById('queue-ahead-num').textContent = Math.max(0, pos - 1);
    } else if (data.status === 'cancelled') {
      hideQueueUI();
      document.getElementById('chat-start-overlay').style.display = 'flex';
      queueToken = null;
      if (typeof nToast === 'function') nToast('Antrean telah berakhir atau dibatalkan.', 'warn');
    }
  } catch (e) {
    dbg('Queue poll error', e.message);
  }
}

async function cancelQueue() {
  if (queueTimer) { clearInterval(queueTimer); queueTimer = null; }
  if (queueToken) {
    const fd = new FormData();
    fd.append('queue_token', queueToken);
    fetch('/chat_action?action=cancel_queue', { method: 'POST', body: fd, credentials: 'include' }).catch(() => {});
    queueToken = null;
  }
  hideQueueUI();
  document.getElementById('chat-start-overlay').style.display = 'flex';
  const btn = document.getElementById('btn-start-chat');
  btn.disabled = false;
  btn.innerHTML = '<i class="ph-bold ph-paper-plane-right" style="font-size:20px;"></i> Mulai Chat';
}

// ── Append bubble ─────────────────────────────────────────
let _msgIdCounter = 0;
function appendBubble(sender, text, time, animate = true, attachment = null, serverId = null) {
  const msgs = document.getElementById('chat-messages');
  if (serverId && document.querySelector(`.bubble-wrap[data-server-id="${serverId}"]`)) return null;

  const wrap = document.createElement('div');
  const id   = ++_msgIdCounter;
  wrap.className   = `bubble-wrap ${sender === 'user' ? 'user' : sender === 'system' ? 'system' : sender}`;
  wrap.dataset.msgId = id;
  if (serverId) wrap.dataset.serverId = serverId;
  if (!animate) wrap.style.animation = 'none';

  const senderLabels = { ai: '🤖 AI', admin: '👨‍💼 Admin', user: '🙋 Kamu', system: '' };
  const senderClass  = { ai: 'sender-ai', admin: 'sender-admin', user: 'sender-user', system: '' };

  let labelName = senderLabels[sender] || sender;
  let actualText = text;

  if (sender === 'admin') {
      const match = text.match(/^\[(.*?)\]\s*(.*)$/is);
      if (match) {
          labelName = `👨‍💼 ${match[1]}`;
          actualText = match[2];
      }
  }

  const timeStr = time ? new Date(time).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'}) : '';
  const label   = sender !== 'system' && sender !== 'user' ? `<div class="bubble-sender-tag ${senderClass[sender]||''}">${escHtml(labelName)}</div>` : '';
  const bodyHtml = (sender === 'ai' || sender === 'admin') ? renderMarkdown(actualText) : (sender === 'system' ? escHtml(actualText) : escHtml(actualText) + '');

  let attHtml = '';
  if (attachment) {
      const ext = attachment.split('.').pop().toLowerCase();
      if (['jpg','jpeg','png','gif'].includes(ext)) {
          attHtml = `<div style="margin-bottom:8px;"><a href="/${attachment}" target="_blank"><img src="/${attachment}" style="max-width:100%; border-radius:12px; border:2px solid rgba(0,0,0,0.1);"></a></div>`;
      } else {
          attHtml = `<div style="margin-bottom:8px;"><a href="/${attachment}" target="_blank" style="display:inline-flex; align-items:center; gap:6px; padding:8px 12px; background:rgba(0,0,0,.05); border-radius:10px; text-decoration:none; color:inherit; border:2px dashed rgba(0,0,0,.2); font-size:12px; font-weight:800;"><i class="ph-bold ph-download-simple"></i> Download Lampiran</a></div>`;
      }
  }

  wrap.innerHTML = `
    ${label}
    <div class="bubble">${attHtml}${bodyHtml}</div>
    ${sender !== 'system' ? `<div class="bubble-meta">${timeStr}</div>` : ''}
  `;
  msgs.appendChild(wrap);
  scrollBottom();
  return wrap;
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Markdown renderer ─────────────────────────────────────
function renderMarkdown(text) {
  let s = escHtml(text);
  s = s.replace(/^###\s+(.+)$/gm, '<strong style="font-size:14px;display:block;margin-top:6px;">$1</strong>');
  s = s.replace(/^##\s+(.+)$/gm,  '<strong style="font-size:15px;display:block;margin-top:6px;">$1</strong>');
  s = s.replace(/^#\s+(.+)$/gm,   '<strong style="font-size:16px;display:block;margin-top:6px;">$1</strong>');
  s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/__(.+?)__/g,     '<strong>$1</strong>');
  s = s.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');
  s = s.replace(/_([^_\n]+?)_/g,   '<em>$1</em>');
  s = s.replace(/`([^`]+?)`/g, '<code style="background:rgba(0,0,0,.08);padding:2px 6px;border-radius:6px;font-size:12px;font-family:monospace;color:#1e293b;">$1</code>');
  s = s.replace(/^(\d+)\.\s+(.+)$/gm, '<div style="display:flex;gap:8px;margin:4px 0;"><span style="font-weight:900;min-width:16px;">$1.</span><span>$2</span></div>');
  s = s.replace(/^[-*]\s+(.+)$/gm, '<div style="display:flex;gap:8px;margin:4px 0;"><span style="font-weight:900;color:inherit;">•</span><span>$1</span></div>');
  s = s.replace(/\n/g, '<br>');
  return s;
}

function scrollBottom() {
  const msgs = document.getElementById('chat-messages');
  msgs.scrollTop = msgs.scrollHeight;
}

// ── Send message ──────────────────────────────────────────
function previewAttachment(input) {
  const file = input.files[0];
  if (file) {
      if (file.size > 5 * 1024 * 1024) {
          if (typeof nToast === 'function') nToast('Maksimal ukuran file adalah 5MB.', 'warn');
          clearAttachment();
          return;
      }
      document.getElementById('attachment-preview').style.display = 'block';
      document.getElementById('att-preview-name').textContent = file.name;
  }
}
function clearAttachment() {
  const input = document.getElementById('chat-attachment-input');
  if (input) input.value = '';
  document.getElementById('attachment-preview').style.display = 'none';
}

async function sendMessage() {
  if (sessionStatus === 'closed') return;
  const input = document.getElementById('chat-input');
  const attInput = document.getElementById('chat-attachment-input');
  const text  = input.value.trim();
  const file = attInput && attInput.files[0] ? attInput.files[0] : null;

  if (!text && !file) return;

  const sendBtn = document.getElementById('chat-send-btn');
  input.value = '';
  input.style.height = '';
  sendBtn.disabled = true;

  if (file) {
      document.getElementById('attachment-preview').style.display = 'none';
      if (attInput) attInput.value = '';
  }

  let bubbleText = text;
  if (file && !text) bubbleText = '📎 Mengirim lampiran...';

  const tmpBubble = appendBubble('user', bubbleText, new Date().toISOString());
  if (currentMode === 'ai') showTyping(true);

  try {
    const fd = new FormData();
    fd.append('message', text);
    if (file) fd.append('attachment', file);
    fd.append('session_key', sessionKey);
    const res  = await fetch('/chat_action?action=send', { method:'POST', body:fd, credentials:'include' });
    const data = await res.json();
    showTyping(false);
    if (!data.ok) throw new Error(data.error || 'Gagal mengirim.');
    
    tmpBubble.remove();
    if (data.user_message) {
        appendBubble('user', data.user_message.message, data.user_message.created_at, false, data.user_message.attachment, data.user_message.id);
    }

    if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);
    if (data.reply) {
      appendBubble(data.reply.sender, data.reply.message, data.reply.created_at, true, data.reply.attachment, data.reply.id);
    }
  } catch(e) {
    tmpBubble.remove();
    showTyping(false);
    appendBubble('system', '⚠️ ' + e.message, new Date().toISOString());
  }

  sendBtn.disabled = false;
  input.focus();
}

function showTyping(show) {
  document.getElementById('typing-wrap').style.display = show ? 'block' : 'none';
  scrollBottom();
}

// ── Switch mode ───────────────────────────────────────────
async function switchMode(mode) {
  if (mode === currentMode) return;
  try {
    const fd = new FormData();
    fd.append('mode', mode);
    fd.append('session_key', sessionKey);
    const res  = await fetch('/chat_action?action=switch_mode', { method:'POST', body:fd, credentials:'include' });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    currentMode = data.mode;
    updateModeUI(currentMode);
    if (data.switch_msg_id) lastMsgId = parseInt(data.switch_msg_id);
    if (data.switch_message) appendModeDivider(data.mode, data.switch_message);
  } catch(e) {
    if (typeof nToast === 'function') nToast('Gagal beralih mode: ' + e.message, 'error');
  }
}

function appendModeDivider(mode, label) {
  const msgs  = document.getElementById('chat-messages');
  const el    = document.createElement('div');
  const color = mode === 'ai' ? '#8b5cf6' : '#10b981';
  el.style.cssText = 'display:flex;align-items:center;gap:12px;margin:16px 0;';
  el.innerHTML = `
    <div style="flex:1;height:2.5px;border-radius:2px;background:${color}; opacity:0.5;"></div>
    <span style="font-size:10px;font-weight:900;background:#fff;color:${color};border:2px solid ${color};padding:4px 12px;border-radius:20px;white-space:nowrap;box-shadow:0 2px 4px rgba(0,0,0,0.05);">${escHtml(label)}</span>
    <div style="flex:1;height:2.5px;border-radius:2px;background:${color}; opacity:0.5;"></div>
  `;
  msgs.appendChild(el);
  scrollBottom();
}

function updateModeUI(mode) {
  const aiBtn  = document.getElementById('modebtn-ai');
  const admBtn = document.getElementById('modebtn-admin');
  if (aiBtn)  aiBtn.classList.toggle('active', mode === 'ai');
  if (admBtn) admBtn.classList.toggle('active', mode === 'admin');

  const banner = document.getElementById('mode-info-banner');
  const bannerText = document.getElementById('mode-info-text');
  if (!banner || !bannerText) return;
  if (mode === 'ai') {
    banner.className = 'mode-info-banner ai-mode';
    bannerText.innerHTML = 'Mode AI aktif — Dijawab otomatis oleh AI.';
  } else {
    banner.className = 'mode-info-banner adm-mode';
    bannerText.innerHTML = 'Mode Admin aktif — Admin akan merespons.';
  }
}

// ── Polling ───────────────────────────────────────────────
function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(pollMessages, 2000);
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    clearInterval(pollTimer);
  } else if (sessionKey && sessionStatus !== 'closed') {
    pollMessages();
    startPolling();
  }
});

async function pollMessages() {
  if (!sessionKey || sessionStatus === 'closed' || isPolling) return;
  isPolling = true;
  try {
    const res  = await fetch(`/chat_action?action=poll&session_key=${sessionKey}&after_id=${lastMsgId}`, { credentials:'include' });
    const data = await res.json();
    if (!data.ok) { isPolling = false; return; }

    (data.messages || []).forEach(m => {
      if (parseInt(m.id) > lastMsgId) {
        lastMsgId = parseInt(m.id);
        appendBubble(m.sender, m.message, m.created_at, true, m.attachment, m.id);
      }
    });

    if (data.status === 'closed' && sessionStatus !== 'closed') {
      sessionStatus = 'closed';
      onSessionClosed();
    }
    if (data.mode && data.mode !== currentMode) {
      currentMode = data.mode;
      updateModeUI(currentMode);
    }
  } catch (e) {
    dbg('Poll fetch error', e.message);
  }
  isPolling = false;
}

function onSessionClosed() {
  clearInterval(pollTimer);
  document.getElementById('chat-inputbar').style.display = 'none';
  document.getElementById('chat-closed-bar').style.display = 'flex';
  const badge = document.getElementById('chat-status-badge');
  badge.className = 'chat-status-badge busy';
  badge.innerHTML = 'Ditutup';
}

// ── Reset session ─────────────────────────────────────────
async function resetChat() {
  await fetch('/chat_action?action=close', { method:'POST', credentials:'include' });
  document.cookie = 'chat_session=; max-age=0; path=/';
  location.reload();
}

// ── Auto-resize textarea ──────────────────────────────────
function autoResize(el) {
  el.style.height = '';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

// ── Init ──────────────────────────────────────────────────
(function() {
  const hasCookie = document.cookie.split(';').some(c => c.trim().startsWith('chat_session='));
  if (hasCookie) {
    startChat();
  }

  const chatRoot = document.getElementById('chat-root');
  function onVpChange() {
    if (!chatRoot) return;
    const vvH   = window.visualViewport?.height ?? window.innerHeight;
    const vvTop = window.visualViewport?.offsetTop ?? 0;
    const kbHeight = window.innerHeight - vvH - vvTop;
    chatRoot.style.bottom = Math.max(0, kbHeight) + 'px';
    scrollBottom();
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', onVpChange);
    window.visualViewport.addEventListener('scroll', onVpChange);
  }
  window.addEventListener('resize', onVpChange);
  onVpChange();

  const chatInput = document.getElementById('chat-input');
  if (chatInput) {
    chatInput.addEventListener('focus', () => setTimeout(() => { onVpChange(); scrollBottom(); }, 350));
    chatInput.addEventListener('blur',  () => setTimeout(onVpChange, 350));
  }
})();
</script>

<style>
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
<script src="/assets/js/toast.js"></script>
<?php endif; ?>
</body>
</html>
