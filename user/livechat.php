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
<meta name="theme-color" content="#0891b2">
<title>Live Chat — <?= htmlspecialchars($_seo_title) ?></title>
<?php
$_abs_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
$_logo    = site_logo($pdo);
if ($_abs_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
/* ══════════════════════════════════════════════════════════
   LIVE CHAT — Living Coral Reef, ringkas & minimalis
   Layout position:fixed (aman keyboard HP, kebal app.css)
   ══════════════════════════════════════════════════════════ */
:root {
  --ink: #1e1b2e; --muted: #6b6478; --lagoon: #0891b2; --coral: #e11d48; --violet: #7c3aed; --kelp: #059669;
  --card: linear-gradient(160deg, #ffffff 0%, #fff7ed 70%, #ffedd5 100%);
  --clay: 0 10px 22px rgba(8, 145, 178, 0.10), 0 4px 10px rgba(244, 63, 94, 0.06), inset 2px 2px 5px #fff, inset -3px -3px 7px rgba(251, 146, 60, 0.12);
}
* { box-sizing: border-box; }
html, body { height: 100%; }
body { margin: 0; padding: 0; overflow: hidden; color: var(--ink); font-family: 'Nunito', -apple-system, sans-serif;
  background: radial-gradient(circle at 12% 8%, rgba(253, 164, 175, 0.20), transparent 38%), radial-gradient(circle at 90% 30%, rgba(192, 132, 252, 0.12), transparent 40%), linear-gradient(180deg, #ecfeff 0%, #f0f9ff 40%, #fff7ed 100%); }

/* Topbar */
.chat-topbar { position: fixed !important; top: 0; left: 0; right: 0; height: 56px; z-index: 100; display: flex; align-items: center; gap: 10px; padding: 0 12px;
  background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-radius: 0 0 18px 18px;
  box-shadow: 0 6px 18px rgba(30, 27, 46, 0.07), inset 0 -3px 6px rgba(251, 146, 60, 0.08); }
.chat-back-btn { width: 34px; height: 34px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 12px; text-decoration: none; color: var(--ink); background: #fff; box-shadow: 0 3px 8px rgba(30, 27, 46, 0.08), inset 1.5px 1.5px 3px #fff; }
.chat-back-btn i { font-size: 18px; }
.chat-ava { position: relative; width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; padding: 2px; background: linear-gradient(145deg, #fff, #ffedd5); box-shadow: 0 3px 8px rgba(8, 145, 178, 0.2); }
.chat-ava img { width: 100%; height: 100%; object-fit: contain; border-radius: 10px; }
.chat-ava::after { content: ''; position: absolute; right: -2px; bottom: -2px; width: 11px; height: 11px; border-radius: 50%; background: #10b981; border: 2px solid #fff; }
.chat-topbar__info { flex: 1; min-width: 0; line-height: 1.15; }
.chat-topbar__title { display: block; font-size: 14px; font-weight: 900; }
.chat-topbar__sub { display: block; font-size: 10.5px; font-weight: 800; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-status-badge { flex-shrink: 0; display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 9.5px; font-weight: 900; letter-spacing: 0.4px; text-transform: uppercase; }
.chat-status-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.chat-status-badge.online { color: #047857; background: #d1fae5; }
.chat-status-badge.busy { color: #be123c; background: #ffe4e6; }

/* Root */
#chat-root { position: fixed; top: 56px; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; overflow: hidden; }
.chat-page { display: flex; flex-direction: column; width: 100%; max-width: 560px; height: 100%; margin: 0 auto; overflow: hidden; min-height: 0; }

/* Mode bar (ringkas) */
.chat-modebar { display: flex; align-items: center; gap: 8px; padding: 8px 12px 6px; flex-shrink: 0; }
.mode-pill { display: flex; gap: 3px; padding: 3px; border: 2px solid #fff; border-radius: 999px; background: rgba(255, 255, 255, 0.7); box-shadow: inset 1px 1px 3px rgba(8, 145, 178, 0.12); }
.mode-btn { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 5px 12px; border: none; border-radius: 999px; background: transparent; cursor: pointer; font-family: inherit; font-size: 11.5px; font-weight: 900; color: var(--muted); transition: all 0.15s; }
.mode-btn i { font-size: 14px; }
.mode-btn.active { color: #fff; box-shadow: 0 4px 10px rgba(30, 27, 46, 0.15), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.4); }
.mode-btn.active.mode-ai { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.mode-btn.active.mode-adm { background: linear-gradient(145deg, #6ee7b7, #059669); }
.mode-info-banner { flex: 1; min-width: 0; display: flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mode-info-banner span { overflow: hidden; text-overflow: ellipsis; }
.mode-info-banner.ai-mode { color: var(--violet); }
.mode-info-banner.adm-mode { color: var(--kelp); }

/* Pesan */
.chat-messages { flex: 1 1 0; min-height: 0; overflow-y: auto; overflow-x: hidden; padding: 6px 12px 12px; display: flex; flex-direction: column; gap: 8px; scroll-behavior: smooth; -webkit-overflow-scrolling: touch; scrollbar-width: thin; scrollbar-color: #a5f3fc transparent; }
.bubble-wrap { display: flex; flex-direction: column; max-width: 82%; animation: bubblePop 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes bubblePop { from { opacity: 0; transform: translateY(6px) scale(0.96); } to { opacity: 1; transform: none; } }
.bubble-wrap.user { align-self: flex-end; align-items: flex-end; }
.bubble-wrap.other, .bubble-wrap.ai, .bubble-wrap.admin { align-self: flex-start; align-items: flex-start; }
.bubble-wrap.system { align-self: center; align-items: center; max-width: 92%; }
.bubble { position: relative; padding: 8px 12px; font-size: 13px; font-weight: 700; line-height: 1.45; word-break: break-word; }
.bubble-wrap.user .bubble { color: #fff; border-radius: 16px 16px 4px 16px; background: linear-gradient(145deg, #22d3ee, #0891b2); box-shadow: 0 5px 12px rgba(8, 145, 178, 0.22), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.35); }
.bubble-wrap.ai .bubble, .bubble-wrap.admin .bubble { color: var(--ink); border: 2px solid #fff; border-radius: 16px 16px 16px 4px; background: var(--card); box-shadow: var(--clay); }
.bubble-wrap.ai .bubble { border-left: 3px solid #c084fc; }
.bubble-wrap.admin .bubble { border-left: 3px solid #34d399; }
.bubble-wrap.system .bubble { padding: 4px 11px; border-radius: 999px; font-size: 10.5px; font-weight: 800; color: var(--muted); background: rgba(255, 255, 255, 0.75); }
.bubble-meta { display: flex; align-items: center; gap: 4px; margin-top: 2px; padding: 0 4px; font-size: 9.5px; font-weight: 800; color: var(--muted); }
.bubble-wrap.user .bubble-meta { flex-direction: row-reverse; }
.bubble-sender-tag { display: inline-flex; align-items: center; gap: 3px; margin: 0 0 3px 4px; font-size: 9.5px; font-weight: 900; }
.sender-ai { color: var(--violet); } .sender-admin { color: var(--kelp); } .sender-user { color: var(--lagoon); }
.bubble a { color: inherit; }

/* Mengetik */
.typing-bubble { display: flex; align-items: center; gap: 4px; width: fit-content; padding: 9px 13px; border: 2px solid #fff; border-radius: 16px 16px 16px 4px; background: var(--card); box-shadow: var(--clay); }
.typing-dot { width: 6px; height: 6px; border-radius: 50%; background: #a78bfa; animation: typingBounce 1.4s infinite ease-in-out both; }
.typing-dot:nth-child(1) { animation-delay: -0.32s; } .typing-dot:nth-child(2) { animation-delay: -0.16s; }
@keyframes typingBounce { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }

/* Input */
.chat-inputbar { display: flex; align-items: flex-end; gap: 6px; flex-shrink: 0; z-index: 10; margin: 0 10px; padding: 6px; padding-bottom: 6px; margin-bottom: calc(8px + env(safe-area-inset-bottom));
  border: 2px solid #fff; border-radius: 22px; background: rgba(255, 255, 255, 0.92); box-shadow: 0 8px 20px rgba(30, 27, 46, 0.08), inset 2px 2px 4px #fff; }
.chat-textarea { flex: 1; min-height: 40px; max-height: 120px; resize: none; border: none; outline: none; padding: 10px 6px; background: transparent; font-family: inherit; font-size: 16px; font-weight: 700; color: var(--ink); line-height: 1.35; overflow-y: auto; }
.chat-textarea::placeholder { color: #94a3b8; font-weight: 700; }
.chat-attach-btn, .chat-send-btn { width: 40px; height: 40px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border: none; border-radius: 14px; cursor: pointer; transition: transform 0.12s; }
.chat-attach-btn { color: var(--muted); background: #f1f5f9; font-size: 19px; }
.chat-send-btn { color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 5px 12px rgba(225, 29, 72, 0.3), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.35); }
.chat-attach-btn:active, .chat-send-btn:active { transform: scale(0.92); }
.chat-send-btn:disabled { background: #e2e8f0; box-shadow: none; color: #94a3b8; cursor: not-allowed; }
.att-chip { display: none; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 10px; background: #ecfeff; font-size: 11px; font-weight: 800; color: #0e7490; }
.att-chip span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.att-chip button { border: none; background: none; cursor: pointer; color: var(--coral); font-size: 14px; padding: 0; }

/* Overlay mulai & antrean */
.chat-start-overlay { position: absolute; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 18px; background: rgba(236, 254, 255, 0.55); backdrop-filter: blur(4px); }
.chat-start-card { width: 100%; max-width: 330px; padding: 18px 16px 16px; text-align: center; border: 2px solid #fff; border-radius: 24px; background: var(--card); box-shadow: 0 20px 40px rgba(30, 27, 46, 0.14), inset 3px 3px 6px #fff; animation: bubblePop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1); }
.chat-start-icon { width: 56px; height: 56px; margin: 0 auto 8px; border-radius: 18px; padding: 3px; background: linear-gradient(145deg, #fff, #ffedd5); box-shadow: 0 6px 14px rgba(8, 145, 178, 0.22); display: flex; align-items: center; justify-content: center; font-size: 28px; color: var(--lagoon); }
.chat-start-icon img { width: 100%; height: 100%; object-fit: contain; border-radius: 14px; }
.chat-start-title { font-size: 17px; font-weight: 900; }
.chat-start-sub { margin: 2px 0 12px; font-size: 11.5px; font-weight: 700; color: var(--muted); }
.start-modes { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: 6px; }
.start-modes .mode-btn { flex-direction: column; gap: 2px; padding: 10px 6px; border: 2px solid #fff; border-radius: 16px; background: rgba(255, 255, 255, 0.85); color: var(--ink); box-shadow: inset 1px 1px 3px rgba(8, 145, 178, 0.1); }
.start-modes .mode-btn i { font-size: 22px; }
.start-modes .mode-btn small { font-size: 9.5px; font-weight: 800; opacity: 0.8; }
.start-modes .mode-btn.mode-ai i { color: var(--violet); } .start-modes .mode-btn.mode-adm i { color: var(--kelp); }
.start-modes .mode-btn.active i { color: #fff; }
.start-desc { margin: 8px 0 12px; font-size: 10.5px; font-weight: 700; color: var(--muted); min-height: 14px; }
.btn-main { width: 100%; height: 46px; display: flex; align-items: center; justify-content: center; gap: 7px; border: 2px solid #fff; border-radius: 16px; cursor: pointer; font-family: inherit; font-size: 14px; font-weight: 900; color: #fff;
  background: linear-gradient(145deg, #22d3ee, #0891b2); box-shadow: 0 8px 18px rgba(8, 145, 178, 0.3), inset 2px 2px 4px rgba(255, 255, 255, 0.4); }
.btn-main i { font-size: 18px; }
.btn-main.danger { background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 8px 18px rgba(225, 29, 72, 0.28), inset 2px 2px 4px rgba(255, 255, 255, 0.4); }
.btn-main:disabled { opacity: 0.7; cursor: wait; }
.queue-box { margin: 4px 0 10px; padding: 10px; border-radius: 16px; background: rgba(255, 255, 255, 0.85); box-shadow: inset 1px 1px 3px rgba(251, 146, 60, 0.15); }
.queue-box small { display: block; font-size: 9.5px; font-weight: 900; letter-spacing: 0.5px; text-transform: uppercase; color: var(--muted); }
.queue-box b { display: block; font-size: 30px; font-weight: 900; line-height: 1.1; color: var(--lagoon); }
.queue-box span { font-size: 11px; font-weight: 800; color: var(--muted); }
.queue-wait { display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 10px; font-size: 10.5px; font-weight: 800; color: var(--muted); }

/* Sesi ditutup */
.chat-closed-bar { display: flex; align-items: center; justify-content: center; gap: 10px; flex-shrink: 0; margin: 0 10px calc(8px + env(safe-area-inset-bottom)); padding: 8px 10px; border: 2px solid #fff; border-radius: 18px; background: #fff1f2; font-size: 12px; font-weight: 800; color: #9f1239; }
.chat-closed-bar button { padding: 6px 13px; border: 2px solid #fff; border-radius: 999px; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 900; color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); }

/* Live chat nonaktif */
.lc-off { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
.lc-off-card { max-width: 320px; padding: 22px 18px; text-align: center; border: 2px solid #fff; border-radius: 24px; background: var(--card); box-shadow: 0 20px 40px rgba(30, 27, 46, 0.12), inset 3px 3px 6px #fff; }
.lc-off-card .ic { width: 56px; height: 56px; margin: 0 auto 10px; border-radius: 18px; display: flex; align-items: center; justify-content: center; font-size: 28px; color: #fff; background: linear-gradient(145deg, #fcd34d, #f59e0b); box-shadow: 0 6px 14px rgba(245, 158, 11, 0.3); }
.lc-off-card h2 { margin: 0 0 4px; font-size: 16px; font-weight: 900; }
.lc-off-card p { margin: 0 0 14px; font-size: 11.5px; font-weight: 700; color: var(--muted); line-height: 1.5; }
.lc-off-card a { text-decoration: none; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .bubble-wrap, .chat-start-card { animation: none !important; } }
</style>
</head>
<body>

<?php if (!$_lc_enabled): ?>
<div class="lc-off">
  <div class="lc-off-card">
    <div class="ic"><i class="ph-fill ph-clock-countdown"></i></div>
    <h2>Live Chat Sedang Tutup</h2>
    <p><?= nl2br(htmlspecialchars(setting($pdo, 'lc_offline_msg', 'Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional.'))) ?></p>
    <a href="/home" class="btn-main"><i class="ph-bold ph-house"></i> Kembali ke Beranda</a>
  </div>
</div>
<?php else: ?>
<header class="chat-topbar" id="chat-topbar">
  <a href="/home" class="chat-back-btn" title="Kembali ke Beranda"><i class="ph-bold ph-caret-left"></i></a>
  <span class="chat-ava"><?php if ($_logo): ?><img src="<?= htmlspecialchars($_logo) ?>" alt=""><?php endif; ?></span>
  <div class="chat-topbar__info">
    <span class="chat-topbar__title">Live Support</span>
    <span class="chat-topbar__sub" id="topbar-username"><?= htmlspecialchars($user['username']) ?></span>
  </div>
  <div class="chat-status-badge online" id="chat-status-badge">Online</div>
</header>

<?php if ($_debug_on): ?>
<!-- ── DEBUG PANEL ── -->
<div id="lc-debug-wrapper" style="position:fixed;bottom:0;left:0;right:0;z-index:9999;background:rgba(0,0,10,0.92);color:#e0e0e0;font-size:10px;font-family:monospace;line-height:1.5;max-height:38vh;display:flex;flex-direction:column;border-top:2px solid #22d3ee;">
  <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#111;flex-shrink:0;border-bottom:1px solid #333">
    <strong style="color:#22d3ee">🐛 LiveChat Debug</strong>
    <button onclick="this.closest('#lc-debug-wrapper').style.display='none'" style="background:none;border:none;color:#aaa;cursor:pointer;font-size:12px">✕ tutup</button>
  </div>
  <div id="lc-debug" style="overflow-y:auto;flex:1;padding:6px 8px"></div>
</div>
<?php endif; ?>

<div id="chat-root">

  <!-- Mulai -->
  <div class="chat-start-overlay" id="chat-start-overlay">
    <div class="chat-start-card">
      <div class="chat-start-icon"><?php if ($_logo): ?><img src="<?= htmlspecialchars($_logo) ?>" alt=""><?php else: ?><i class="ph-fill ph-chat-circle-dots"></i><?php endif; ?></div>
      <div class="chat-start-title">Butuh bantuan?</div>
      <div class="chat-start-sub">Pilih mau ngobrol dengan siapa.</div>
      <div class="start-modes">
        <?php if ($_ai_enabled): ?>
        <button type="button" class="mode-btn mode-ai active" id="start-mode-ai" onclick="selectStartMode('ai')"><i class="ph-fill ph-robot"></i>Asisten AI<small>Instan</small></button>
        <?php endif; ?>
        <?php if ($_adm_enabled): ?>
        <button type="button" class="mode-btn mode-adm" id="start-mode-admin" onclick="selectStartMode('admin')"><i class="ph-fill ph-headset"></i>Admin<small>Dibalas tim</small></button>
        <?php endif; ?>
      </div>
      <p class="start-desc" id="start-mode-desc"><?= $_ai_enabled ? 'AI akan menjawab pertanyaan Anda secara otomatis & instan.' : 'Admin akan membalas pesan Anda langsung.' ?></p>
      <button type="button" id="btn-start-chat" class="btn-main" onclick="startChat()"><i class="ph-bold ph-paper-plane-right"></i> Mulai Chat</button>
    </div>
  </div>

  <!-- Antrean (kapasitas penuh) -->
  <div class="chat-start-overlay" id="chat-queue-overlay" style="display:none;">
    <div class="chat-start-card">
      <div class="chat-start-icon"><i class="ph-fill ph-hourglass-medium"></i></div>
      <div class="chat-start-title">Antrean penuh</div>
      <div class="chat-start-sub">Tunggu sebentar, kamu sedang antre.</div>
      <div class="queue-box">
        <small>Nomor antrean</small>
        <b>#<span id="queue-pos-num">1</span></b>
        <span><span id="queue-ahead-num">0</span> orang di depanmu</span>
      </div>
      <div class="queue-wait"><i class="ph-bold ph-spinner ph-spin"></i> Tersambung otomatis saat giliranmu</div>
      <button type="button" id="btn-cancel-queue" class="btn-main danger" onclick="cancelQueue()"><i class="ph-bold ph-x-circle"></i> Batal Antre</button>
    </div>
  </div>

  <!-- Chat -->
  <div class="chat-page" id="chat-ui" style="display:none;">
    <div class="chat-modebar">
      <div class="mode-pill" id="mode-pill-wrap">
        <?php if ($_ai_enabled): ?>
        <button type="button" class="mode-btn mode-ai" id="modebtn-ai" onclick="switchMode('ai')"><i class="ph-fill ph-robot"></i> AI</button>
        <?php endif; ?>
        <?php if ($_adm_enabled): ?>
        <button type="button" class="mode-btn mode-adm" id="modebtn-admin" onclick="switchMode('admin')"><i class="ph-fill ph-headset"></i> Admin</button>
        <?php endif; ?>
      </div>
      <div class="mode-info-banner ai-mode" id="mode-info-banner"><i class="ph-fill ph-info"></i><span id="mode-info-text">Mode AI aktif — Dijawab otomatis oleh AI.</span></div>
    </div>

    <div class="chat-messages" id="chat-messages"></div>

    <div id="typing-wrap" style="padding:0 12px 8px;display:none;">
      <div class="typing-bubble"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>
    </div>

    <div class="chat-inputbar" id="chat-inputbar">
      <?php if ($_att_enabled): ?>
      <button type="button" class="chat-attach-btn" onclick="document.getElementById('chat-attachment-input').click()" title="Lampirkan File"><i class="ph-bold ph-paperclip"></i></button>
      <input type="file" id="chat-attachment-input" style="display:none;" accept="image/jpeg,image/png,image/gif,application/pdf,.zip,.rar" onchange="previewAttachment(this)">
      <?php endif; ?>
      <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:4px;">
        <div id="attachment-preview" class="att-chip" style="display:none;">
          <i class="ph-bold ph-file"></i><span id="att-preview-name"></span>
          <button type="button" onclick="clearAttachment()" title="Hapus lampiran"><i class="ph-bold ph-x-circle"></i></button>
        </div>
        <textarea class="chat-textarea" id="chat-input" placeholder="Ketik pesan..." rows="1" onkeydown="handleKey(event)" oninput="autoResize(this)"></textarea>
      </div>
      <button type="button" class="chat-send-btn" id="chat-send-btn" onclick="sendMessage()" title="Kirim Pesan"><i class="ph-fill ph-paper-plane-right" style="font-size:19px;"></i></button>
    </div>

    <div class="chat-closed-bar" id="chat-closed-bar" style="display:none;">
      <i class="ph-fill ph-lock-key"></i> Sesi ditutup.
      <button type="button" onclick="resetChat()">Chat Baru</button>
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
      document.getElementById('attachment-preview').style.display = 'flex';
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

<script src="/assets/js/toast.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/toast.js') ?>"></script>
<?php endif; ?>
</body>
</html>
