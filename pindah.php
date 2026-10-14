<?php
declare(strict_types=1);

// Static variable for the new server destination URL (tidak dicetak langsung di DOM)
$new_server_url = 'https://mancingcuan-baru.com'; // Ganti dengan URL server tujuan migrasi Anda

// Cek jika request adalah AJAX untuk mengambil URL
if (isset($_GET['action']) && $_GET['action'] === 'get_url') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'url' => $new_server_url
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pindah ke Server Baru</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
  /* Neo-brutalist style variables fallback (jika app.css tidak termuat) */
  :root {
    --bg-fallback: #F4F1EA;
    --ink-fallback: #1e1e24;
    --border-fallback: 3px solid #1e1e24;
    --shadow-fallback: 4px 4px 0 #1e1e24;
    --shadow-lg-fallback: 8px 8px 0 #1e1e24;
    --yellow-fallback: #ffd000;
    --brand-fallback: #ff5e00;
    --salmon-fallback: #ff8b94;
    --mint-fallback: #a8e6cf;
    --lavender-fallback: #dcedc1;
    --peach-fallback: #ffd3b6;
  }

  .move-server-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    background: var(--bg, var(--bg-fallback));
    text-align: center;
    gap: 0;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  }

  .mv-card {
    width: 100%;
    max-width: 420px;
    background: var(--white, #fff);
    border: var(--border, var(--border-fallback));
    border-radius: 24px;
    box-shadow: var(--shadow-lg, var(--shadow-lg-fallback));
    padding: 36px 28px 32px;
    position: relative;
    overflow: hidden;
  }

  /* Big decorative "NEW" background text */
  .mv-bg-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -52%);
    font-size: 140px;
    font-weight: 900;
    color: rgba(0,0,0,.03);
    line-height: 1;
    pointer-events: none;
    user-select: none;
    white-space: nowrap;
    z-index: 0;
  }

  .mv-inner { position: relative; z-index: 1; }

  .mv-emoji-box {
    width: 80px;
    height: 80px;
    background: var(--yellow, var(--yellow-fallback));
    border: var(--border, var(--border-fallback));
    box-shadow: var(--shadow, var(--shadow-fallback));
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    margin: 0 auto 20px;
    animation: mvWiggle 3s ease-in-out infinite;
  }
  @keyframes mvWiggle {
    0%,100% { transform: rotate(-4deg); }
    50%      { transform: rotate(4deg); }
  }

  .mv-title {
    font-size: 22px;
    font-weight: 900;
    color: var(--ink, var(--ink-fallback));
    margin-bottom: 12px;
    line-height: 1.3;
  }

  .mv-desc {
    font-size: 13.5px;
    color: #666;
    font-weight: 600;
    line-height: 1.6;
    margin-bottom: 28px;
  }

  .mv-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .btn-mv {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px 20px;
    border: var(--border, var(--border-fallback));
    border-radius: 12px;
    font-size: 15px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    box-sizing: border-box;
  }

  .btn-mv--primary {
    background: var(--brand, var(--brand-fallback));
    color: var(--white, #fff);
    box-shadow: var(--shadow, var(--shadow-fallback));
  }
  .btn-mv--primary:hover {
    transform: translate(-2px, -2px);
    box-shadow: 6px 6px 0 var(--ink, var(--ink-fallback));
  }
  .btn-mv--primary:active {
    transform: translate(2px, 2px);
    box-shadow: 2px 2px 0 var(--ink, var(--ink-fallback));
  }

  .btn-mv--ghost {
    background: transparent;
    color: var(--ink, var(--ink-fallback));
  }
  .btn-mv--ghost:hover {
    background: rgba(0,0,0,0.03);
  }

  .mv-sticker {
    display: inline-block;
    background: var(--salmon, var(--salmon-fallback));
    border: var(--border, var(--border-fallback));
    box-shadow: 3px 3px 0 var(--ink, var(--ink-fallback));
    border-radius: 10px;
    font-size: 11px;
    font-weight: 900;
    padding: 5px 12px;
    transform: rotate(-2deg);
    position: absolute;
    top: -12px;
    right: 20px;
    color: var(--ink, var(--ink-fallback));
    white-space: nowrap;
  }

  /* Decorative corner shapes */
  .mv-corner {
    position: absolute;
    width: 40px;
    height: 40px;
    border: var(--border, var(--border-fallback));
    border-radius: 8px;
  }
  .mv-corner--tl { top: -8px; left: -8px; background: var(--mint, var(--mint-fallback)); transform: rotate(12deg); }
  .mv-corner--br { bottom: -8px; right: -8px; background: var(--lavender, var(--lavender-fallback)); transform: rotate(-8deg); }

  @media (min-width: 520px) {
    body { background: #E8E4DA; }
  }

  /* Loading State */
  .btn-mv.is-loading {
    pointer-events: none;
    opacity: 0.8;
  }
  .btn-mv.is-loading .spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2.5px solid rgba(255,255,255,0.3);
    border-radius: 50%;
    border-top-color: #fff;
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>
</head>
<body>
<div class="move-server-page">

  <div class="mv-card">
    <div class="mv-bg-text">NEW</div>
    <div class="mv-corner mv-corner--tl"></div>
    <div class="mv-corner mv-corner--br"></div>
    <span class="mv-sticker">🚀 Migrasi!</span>

    <div class="mv-inner">
      <div class="mv-emoji-box">🌐</div>
      <div class="mv-title">Pindah ke Server Baru</div>
      <div class="mv-desc">
        Untuk kenyamanan dan akses yang lebih ngebut, MancingCuan kini sudah berpindah ke rumah baru yang jauh lebih stabil dan kencang. Yuk gabung ke server baru sekarang!
      </div>

      <div class="mv-actions">
        <button id="btn-pindah" class="btn-mv btn-mv--primary">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
          Pindah Sekarang
        </button>
        <a href="javascript:history.back()" class="btn-mv btn-mv--ghost">
          Kembali
        </a>
      </div>
    </div>
  </div>

  <!-- Decorative tags below card -->
  <div style="display:flex;gap:8px;margin-top:20px;flex-wrap:wrap;justify-content:center;">
    <?php
    $tags = ['⚡ Lebih Cepat', '🔒 Lebih Aman', '🔥 Fitur Baru', '🎈 Server Baru'];
    $colors = ['var(--yellow, var(--yellow-fallback))','var(--mint, var(--mint-fallback))','var(--lavender, var(--lavender-fallback))','var(--peach, var(--peach-fallback))'];
    foreach ($tags as $i => $tag):
    ?>
    <span style="
      background:<?= $colors[$i] ?>;
      border:var(--border, var(--border-fallback));
      box-shadow:2px 2px 0 var(--ink, var(--ink-fallback));
      border-radius:8px;
      font-size:11px;font-weight:800;
      padding:4px 12px;
      transform:rotate(<?= ($i%2===0?'-':''). (1+$i) ?>deg);
      display:inline-block;
      color: var(--ink, var(--ink-fallback));
    "><?= $tag ?></span>
    <?php endforeach; ?>
  </div>

</div>

<script>
document.getElementById('btn-pindah').addEventListener('click', function(e) {
  e.preventDefault();
  const btn = this;
  const originalText = btn.innerHTML;
  
  // Ubah status ke loading
  btn.classList.add('is-loading');
  btn.innerHTML = '<span class="spinner"></span> Menghubungkan ke server baru...';
  
  // Mengambil URL dari variable PHP statis secara aman via AJAX
  fetch('pindah.php?action=get_url')
    .then(response => {
      if (!response.ok) {
        throw new Error('Gagal menghubungi server.');
      }
      return response.json();
    })
    .then(data => {
      if (data.ok && data.url) {
        // Berikan delay micro-animation sejenak, lalu redirect
        setTimeout(() => {
          window.location.href = data.url;
        }, 800);
      } else {
        throw new Error('Alamat server tidak valid.');
      }
    })
    .catch(error => {
      console.error(error);
      if (typeof nToast === 'function') nToast('Waduh! Gagal mengambil alamat server baru. Silakan coba beberapa saat lagi.', 'error');
      btn.classList.remove('is-loading');
      btn.innerHTML = originalText;
    });
});
</script>
</body>
</html>
