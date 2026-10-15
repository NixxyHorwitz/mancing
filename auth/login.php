<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limit
$ip_key   = 'login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_att'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait  = ceil(($lock_until - time()) / 60);
        $error = "Akun terkunci sementara. Coba lagi dalam {$wait} menit.";
        goto end_login;
    }

    $login = trim($_POST['login'] ?? '');
    $pwd   = $_POST['password'] ?? '';
    $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    $s     = $pdo->prepare("SELECT * FROM users WHERE {$field}=? AND is_active=1");
    $s->execute([$login]);
    $user  = $s->fetch();

    if ($user && password_verify($pwd, $user['password_hash'])) {
        unset($_SESSION[$ip_key . '_att'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        set_auth_cookie((int)$user['id']);
        try {
            $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([(int)$user['id']]);
        } catch (\Throwable) {}
        redirect('/home');
    }

    $new_att = $attempts + 1;
    $_SESSION[$ip_key . '_att'] = $new_att;
    if ($new_att >= 5) {
        $_SESSION[$ip_key . '_lock'] = time() + 600;
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 10 menit.';
    } else {
        $left  = 5 - $new_att;
        $error = "Username/email atau kata sandi salah. Sisa percobaan: {$left}";
    }
}
end_login:

// Load SEO settings
$_seo_title  = setting($pdo, 'seo_title', 'MancingCuan — Nonton Video & Minigame Penghasil Saldo Cuan');
$_seo_desc   = setting($pdo, 'seo_description', 'Platform nonton video dan minigame penghasil saldo cuan resmi.');
$_favicon    = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_site_name  = setting($pdo, 'site_name', 'MancingCuan');
$_site_logo  = site_logo($pdo);
$_seo_kw     = setting($pdo, 'seo_keywords', 'mancingcuan, mancing cuan, nonton video dapat uang, aplikasi penghasil saldo dana');
$_seo_author = setting($pdo, 'seo_author', 'MancingCuan Official');
$_seo_twcard = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_og_img = setting($pdo, 'seo_og_image', '/assets/seobanner.jpg');

$_og_disk = dirname(__DIR__) . '/' . ltrim(preg_replace('~\?.*$~', '', $_seo_og_img), '/');
if (!$_seo_og_img || !file_exists($_og_disk)) {
    $_seo_og_img = '/assets/seobanner.jpg';
}
$absolute_og = preg_match('~^https?://~', $_seo_og_img) ? $_seo_og_img : base_url(ltrim($_seo_og_img, '/'));
$fav_url     = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : base_url(ltrim($_favicon, '/'))) : base_url('assets/favicon.png');
$og_mime     = str_ends_with(strtolower($absolute_og), '.png') ? 'image/png' : 'image/jpeg';
$current_url = base_url('login');
$_page_title = 'Masuk Akun — ' . $_seo_title;
$_final_og_title = setting($pdo, 'seo_og_title', 'MancingCuan — Nonton Video & Main Game Panen Saldo Nyata Tiap Hari!');
$_final_og_desc  = setting($pdo, 'seo_og_description', $_seo_desc);
?>
<!DOCTYPE html>
<html lang="id" prefix="og: https://ogp.me/ns#">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#a5f3fc">
<title><?= htmlspecialchars($_page_title) ?></title>
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
<meta name="description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<?php if ($_seo_kw): ?><meta name="keywords" content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author): ?><meta name="author" content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="index, follow">

<!-- Open Graph / Facebook / WhatsApp / Telegram Meta Tags -->
<meta property="og:locale" content="id_ID">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars($_site_name) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_final_og_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="<?= $og_mime ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_final_og_title) ?>">

<!-- Twitter Card Meta Tags -->
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:site" content="@MancingCuan">
<meta name="twitter:creator" content="@MancingCuan">
<meta name="twitter:url" content="<?= htmlspecialchars($current_url) ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($_final_og_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta name="twitter:image:alt" content="<?= htmlspecialchars($_final_og_title) ?>">

<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($fav_url) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png">
<link rel="shortcut icon" href="/assets/favicon.ico">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<!-- Phosphor Icons (CDN) -->
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>

<style>
/* ══ LOGIN — Living Coral Reef · compact clay ══ */
:root {
  --ink: #1e1b2e; --muted: #6b6478;
  --lagoon: #22d3ee; --lagoon-d: #0891b2; --lagoon-dd: #0e7490;
  --coral: #fb7185; --kelp: #10b981; --sun: #fbbf24;
}
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
html, body { min-height: 100%; }
body {
  font-family: 'Nunito', -apple-system, sans-serif; color: var(--ink);
  min-height: 100vh; min-height: 100dvh; overflow-x: hidden; position: relative;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 64px 18px 120px;
  background: linear-gradient(180deg, #a5f3fc 0%, #cffafe 22%, #ecfeff 52%, #fff7ed 82%, #ffedd5 100%);
}

/* ── Hiasan latar (semua pointer-events:none) ── */
.sea { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
.sea-rays { position: absolute; top: -10%; left: 50%; width: 140%; height: 75%; transform: translateX(-50%);
  background: repeating-linear-gradient(100deg, rgba(255,255,255,0) 0 46px, rgba(255,255,255,.38) 46px 70px, rgba(255,255,255,0) 70px 128px);
  -webkit-mask-image: linear-gradient(180deg, #000 0%, transparent 100%); mask-image: linear-gradient(180deg, #000 0%, transparent 100%);
  animation: rays 9s ease-in-out infinite alternate; opacity: .7; }
@keyframes rays { to { transform: translateX(-46%) skewX(-4deg); opacity: .45; } }

.sea-surface { position: absolute; top: 0; left: 0; width: 200%; height: 46px; animation: surf 14s linear infinite; }
@keyframes surf { to { transform: translateX(-50%); } }

.bub { position: absolute; bottom: -24px; border-radius: 50%;
  background: radial-gradient(circle at 32% 30%, #fff 0 18%, rgba(255,255,255,.35) 40%, rgba(34,211,238,.18) 100%);
  border: 1px solid rgba(255,255,255,.9); animation: rise linear infinite; }
@keyframes rise { 0% { transform: translate(0, 0); opacity: 0; } 10% { opacity: .9; } 50% { transform: translate(10px, -50vh); } 100% { transform: translate(-6px, -105vh); opacity: 0; } }

.fish { position: absolute; width: 38px; height: 38px; animation: swim linear infinite; filter: drop-shadow(0 6px 8px rgba(8,145,178,.2)); }
.fish img { width: 100%; height: 100%; object-fit: contain; animation: wiggle 1.6s ease-in-out infinite; }
@keyframes swim { from { transform: translateX(-60px); } to { transform: translateX(calc(100vw + 60px)); } }
@keyframes wiggle { 50% { transform: translateY(-4px) rotate(-4deg); } }
.fish.rev { animation-name: swimRev; }
.fish.rev img { transform: scaleX(-1); animation-name: wiggleRev; }
@keyframes swimRev { from { transform: translateX(calc(100vw + 60px)); } to { transform: translateX(-60px); } }
@keyframes wiggleRev { 0%, 100% { transform: scaleX(-1); } 50% { transform: scaleX(-1) translateY(-4px) rotate(-4deg); } }

.sand { position: absolute; left: 0; bottom: 0; width: 100%; height: 44px; }
.reef { position: absolute; bottom: 10px; width: 150px; height: 115px; }
.reef-l { left: max(-10px, calc(50% - 330px)); }
.reef-r { right: max(-10px, calc(50% - 330px)); }
.kelp { transform-origin: bottom center; transform-box: fill-box; animation: sway 4.5s ease-in-out infinite alternate; }
.kelp.k2 { animation-duration: 5.5s; animation-delay: -2s; }
.kelp.k3 { animation-duration: 3.8s; animation-delay: -1s; }
@keyframes sway { from { transform: rotate(-5deg); } to { transform: rotate(5deg); } }

/* ── Kartu ── */
.card { position: relative; z-index: 2; width: 100%; max-width: 340px; padding: 46px 20px 18px;
  border: 2px solid #fff; border-radius: 26px; background: linear-gradient(160deg, rgba(255,255,255,.96) 0%, rgba(255,247,237,.95) 62%, rgba(255,237,213,.95) 100%);
  box-shadow: 0 18px 38px rgba(8,145,178,.16), 0 6px 14px rgba(244,63,94,.08), inset 3px 3px 6px #fff, inset -4px -4px 9px rgba(251,146,60,.14);
  backdrop-filter: blur(6px); }

.logo { position: absolute; top: -38px; left: 50%; transform: translateX(-50%); width: 76px; height: 76px; border-radius: 24px;
  background: linear-gradient(145deg, #fff, #ecfeff); border: 3px solid #fff; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 10px 22px rgba(8,145,178,.25), inset 2px 2px 5px #fff, inset -3px -3px 7px rgba(34,211,238,.18); animation: bob 3.4s ease-in-out infinite; }
.logo img { width: 54px; height: 54px; object-fit: contain; }
.logo::before, .logo::after { content: ''; position: absolute; border-radius: 50%; border: 1.5px solid #fff; background: rgba(207,250,254,.8); }
.logo::before { width: 12px; height: 12px; right: -14px; top: 6px; }
.logo::after { width: 7px; height: 7px; right: -22px; top: -6px; }
@keyframes bob { 50% { transform: translate(-50%, -5px); } }

.hd { text-align: center; margin-bottom: 16px; }
.hd h1 { font-size: 20px; font-weight: 900; letter-spacing: -.4px; line-height: 1.15; }
.hd h1 span { background: linear-gradient(90deg, var(--lagoon-d), var(--kelp)); -webkit-background-clip: text; background-clip: text; color: transparent; }
.hd p { margin-top: 3px; font-size: 11.5px; font-weight: 800; color: var(--muted); }

.err { display: flex; align-items: flex-start; gap: 7px; margin-bottom: 12px; padding: 8px 11px; border-radius: 13px;
  background: #fff1f2; border: 1.5px solid #fecdd3; color: #9f1239; font-size: 11.5px; font-weight: 800; line-height: 1.35; animation: shake .35s; }
.err i { font-size: 15px; flex-shrink: 0; }
@keyframes shake { 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }

.fld { margin-bottom: 10px; }
.fld label { display: block; margin: 0 0 4px 4px; font-size: 10.5px; font-weight: 900; color: var(--muted); }
.box { display: flex; align-items: center; gap: 9px; height: 46px; padding: 0 12px; border-radius: 15px; background: #fff; border: 2px solid #fff;
  box-shadow: inset 2px 2px 5px rgba(30,27,46,.06), 0 2px 6px rgba(8,145,178,.07); transition: border-color .15s, box-shadow .15s; }
.box:focus-within { border-color: var(--lagoon); box-shadow: 0 0 0 4px rgba(34,211,238,.18); }
.box > i { font-size: 18px; color: var(--lagoon-d); flex-shrink: 0; }
.box input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: 800 14px/1 'Nunito', sans-serif; color: var(--ink); }
.box input::placeholder { color: #a8a3b3; font-weight: 700; font-size: 12.5px; }
.eye { border: 0; background: none; padding: 4px; color: #a8a3b3; font-size: 18px; display: flex; cursor: pointer; }
.eye:hover { color: var(--lagoon-d); }

.go { width: 100%; height: 48px; margin-top: 6px; border: 2px solid #fff; border-radius: 16px; cursor: pointer;
  background: linear-gradient(135deg, var(--lagoon) 0%, var(--lagoon-d) 100%); color: #fff; font: 900 14.5px/1 'Nunito', sans-serif;
  display: flex; align-items: center; justify-content: center; gap: 7px; position: relative; overflow: hidden;
  box-shadow: 0 8px 18px rgba(8,145,178,.32), 0 4px 0 var(--lagoon-dd), inset 1px 1px 3px rgba(255,255,255,.6); transition: transform .1s, box-shadow .1s; }
.go::after { content: ''; position: absolute; top: 0; left: -60%; width: 40%; height: 100%; transform: skewX(-20deg);
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.45), transparent); animation: shine 3.6s ease-in-out infinite; }
@keyframes shine { 0%, 60% { left: -60%; } 100% { left: 130%; } }
.go:active { transform: translateY(3px); box-shadow: 0 3px 8px rgba(8,145,178,.3), 0 1px 0 var(--lagoon-dd); }
.go[disabled] { opacity: .75; cursor: wait; }

.alt { margin-top: 13px; text-align: center; font-size: 12px; font-weight: 800; color: var(--muted); }
.alt a { color: #e11d48; font-weight: 900; text-decoration: none; margin-left: 2px; }
.alt a:hover { text-decoration: underline; }

.trust { margin-top: 14px; padding-top: 12px; border-top: 1.5px dashed rgba(8,145,178,.2); display: flex; align-items: center; justify-content: center; gap: 10px; }
.trust small { font-size: 9.5px; font-weight: 900; color: var(--muted); display: inline-flex; align-items: center; gap: 3px; }
.trust small i { color: var(--kelp); font-size: 12px; }
.trust img { height: 22px; max-width: 70px; object-fit: contain; }

@media (max-height: 640px) { body { padding-top: 54px; padding-bottom: 90px; } .reef { width: 112px; height: 86px; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; } }
</style>
</head>
<body>

<div class="sea" aria-hidden="true">
  <div class="sea-rays"></div>
  <svg class="sea-surface" viewBox="0 0 1440 46" preserveAspectRatio="none">
    <path d="M0 0H1440V22Q1380 38 1320 22T1200 22T1080 22T960 22T840 22T720 22T600 22T480 22T360 22T240 22T120 22T0 22Z" fill="#fff" opacity=".55"/>
    <path d="M0 0H1440V12Q1380 26 1320 12T1200 12T1080 12T960 12T840 12T720 12T600 12T480 12T360 12T240 12T120 12T0 12Z" fill="#fff" opacity=".7"/>
  </svg>

  <span class="bub" style="left:8%;width:10px;height:10px;animation-duration:11s"></span>
  <span class="bub" style="left:22%;width:6px;height:6px;animation-duration:8s;animation-delay:-3s"></span>
  <span class="bub" style="left:41%;width:8px;height:8px;animation-duration:13s;animation-delay:-7s"></span>
  <span class="bub" style="left:63%;width:12px;height:12px;animation-duration:12s;animation-delay:-2s"></span>
  <span class="bub" style="left:78%;width:7px;height:7px;animation-duration:9s;animation-delay:-5s"></span>
  <span class="bub" style="left:91%;width:9px;height:9px;animation-duration:14s;animation-delay:-9s"></span>

  <div class="fish" style="top:13%;animation-duration:24s;animation-delay:-6s"><img src="/assets/img/fish/biasa.png" alt=""></div>
  <div class="fish rev" style="top:74%;width:30px;height:30px;animation-duration:30s;animation-delay:-14s"><img src="/assets/img/fish/langka.png" alt=""></div>

  <svg class="sand" viewBox="0 0 600 40" preserveAspectRatio="none"><path d="M0 18Q75 4 150 14T300 12T450 10T600 14V40H0Z" fill="#fed7aa"/><path d="M0 28Q100 18 200 26T400 24T600 24V40H0Z" fill="#fdba74" opacity=".5"/></svg>
  <svg class="reef reef-l" viewBox="0 0 170 130">
    <path class="kelp" d="M30 130C22 104 42 92 32 70S40 36 32 14" stroke="#10b981" stroke-width="8" fill="none" stroke-linecap="round"/>
    <path class="kelp k2" d="M48 130C56 110 40 98 50 80S44 56 52 42" stroke="#34d399" stroke-width="6" fill="none" stroke-linecap="round"/>
    <g class="kelp k3"><path d="M100 128C98 108 96 96 86 84C78 74 74 64 78 56C84 62 88 70 92 78C94 66 92 54 98 46C104 56 102 68 102 80C108 70 116 64 124 62C122 72 114 80 106 90C104 104 106 116 108 128Z" fill="#fb7185"/>
      <circle cx="78" cy="55" r="5" fill="#fda4af"/><circle cx="98" cy="45" r="5" fill="#fda4af"/><circle cx="124" cy="61" r="5" fill="#fda4af"/></g>
    <ellipse cx="140" cy="122" rx="14" ry="8" fill="#a8a29e"/><ellipse cx="136" cy="119" rx="5" ry="2.5" fill="#fff" opacity=".45"/>
  </svg>
  <svg class="reef reef-r" viewBox="0 0 170 130">
    <g class="kelp k2"><path d="M70 128C70 112 66 100 58 90C52 82 48 72 52 64C58 70 62 78 66 86C68 74 66 60 72 52C78 62 76 74 76 86C82 76 92 70 100 70C98 80 88 88 80 98C78 110 80 120 82 128Z" fill="#c084fc"/>
      <circle cx="52" cy="63" r="5" fill="#e9d5ff"/><circle cx="72" cy="51" r="5" fill="#e9d5ff"/><circle cx="100" cy="69" r="5" fill="#e9d5ff"/></g>
    <path class="kelp" d="M140 130C132 106 150 92 140 70S148 40 140 20" stroke="#10b981" stroke-width="8" fill="none" stroke-linecap="round"/>
    <path d="M24 104l5 11l12 1l-9 8l3 12l-11 -6l-11 6l3 -12l-9 -8l12 -1z" fill="#fbbf24" transform="rotate(-12 24 116) translate(0 -6)"/>
    <path d="M112 126a10 10 0 0 1 20 0z" fill="#fecdd3"/><path d="M117 126l5 -9l5 9" stroke="#fb7185" stroke-width="1.5" fill="none"/>
  </svg>
</div>

<main class="card">
  <div class="logo"><img src="<?= htmlspecialchars($_site_logo) ?>" alt="<?= htmlspecialchars($_site_name) ?>"></div>

  <div class="hd">
    <h1>Selamat datang di <span><?= htmlspecialchars($_site_name) ?></span></h1>
    <p>Masuk untuk lanjut mancing &amp; panen cuan</p>
  </div>

  <?php if ($error): ?>
    <div class="err" role="alert"><i class="ph-fill ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
  <?php endif; ?>

  <form method="POST" id="login-form">
    <?= csrf_field() ?>

    <div class="fld">
      <label for="login-input">Username / Email</label>
      <div class="box">
        <i class="ph-bold ph-user"></i>
        <input type="text" id="login-input" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
               placeholder="nama kamu atau email" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
      </div>
    </div>

    <div class="fld">
      <label for="pwd-input">Kata Sandi</label>
      <div class="box">
        <i class="ph-bold ph-lock-key"></i>
        <input type="password" id="pwd-input" name="password" placeholder="••••••••" autocomplete="current-password" required>
        <button type="button" class="eye" onclick="togglePasswordVisibility()" title="Lihat kata sandi" aria-label="Lihat kata sandi">
          <i class="ph-bold ph-eye" id="eye-icon"></i>
        </button>
      </div>
    </div>

    <button type="submit" class="go" id="login-btn"><span>Masuk</span><i class="ph-bold ph-arrow-right"></i></button>
  </form>

  <div class="alt">Belum punya akun?<a href="/register">Daftar gratis</a></div>

  <div class="trust">
    <small><i class="ph-fill ph-shield-check"></i>Terdaftar</small>
    <img src="/assets/ojkkk.png?v=3" alt="Otoritas Jasa Keuangan">
    <img src="/assets/bap.png?v=3" alt="Bappebti">
  </div>
</main>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('pwd-input');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.className = show ? 'ph-bold ph-eye-slash' : 'ph-bold ph-eye';
}
document.getElementById('login-form').addEventListener('submit', function () {
  const b = document.getElementById('login-btn');
  setTimeout(() => { b.disabled = true; b.innerHTML = '<span>Memeriksa...</span>'; }, 0);
});
</script>
</body>
</html>
