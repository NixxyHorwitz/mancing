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
<meta name="theme-color" content="#78350f">
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
/* ══════════════════════════════════════════════════════════
   MANCINGCUAN — CARDLESS THEMED LOGIN PAGE
   Seamless Form • Themed Background • Zero Emojis
   ══════════════════════════════════════════════════════════ */
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  -webkit-tap-highlight-color: transparent;
}

body {
  font-family: 'Nunito', sans-serif;
  background-color: #fef8ee;
  background-image: 
    radial-gradient(circle at 15% 15%, rgba(251, 191, 36, 0.22) 0%, transparent 45%),
    radial-gradient(circle at 85% 85%, rgba(217, 119, 6, 0.16) 0%, transparent 50%),
    radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px);
  background-size: 100% 100%, 100% 100%, 18px 18px;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 36px 18px 24px;
  color: #1e293b;
  position: relative;
  overflow-x: hidden;
}

/* ── TOP HONEY DRIP DECORATION ── */
.honey-drip-top {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 60px;
  pointer-events: none;
  z-index: 1;
}
.honey-drip-svg {
  width: 100%;
  height: 100%;
  display: block;
}

/* ── FLOATING THEMED ASSETS ── */
.decor-float {
  position: fixed;
  pointer-events: none;
  z-index: 1;
  user-select: none;
}
.decor-float img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.decor-tl {
  top: 40px;
  left: max(10px, calc(50% - 280px));
  width: 68px;
  height: 68px;
  opacity: 0.85;
  filter: drop-shadow(0 8px 16px rgba(120, 53, 15, 0.15));
  animation: floatSlow 5s ease-in-out infinite;
}
.decor-br {
  bottom: 35px;
  right: max(10px, calc(50% - 280px));
  width: 76px;
  height: 76px;
  opacity: 0.85;
  filter: drop-shadow(0 8px 16px rgba(120, 53, 15, 0.15));
  animation: floatSlower 6s ease-in-out infinite;
}
.decor-tr {
  top: 80px;
  right: max(15px, calc(50% - 250px));
  width: 36px;
  height: 36px;
  opacity: 0.75;
  animation: floatSlower 4.5s ease-in-out infinite;
}
.decor-bl {
  bottom: 80px;
  left: max(15px, calc(50% - 250px));
  width: 40px;
  height: 40px;
  opacity: 0.75;
  animation: floatSlow 5.5s ease-in-out infinite;
}

@keyframes floatSlow {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-10px) rotate(4deg); }
}
@keyframes floatSlower {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(12px) rotate(-4deg); }
}

/* ── CARDLESS LOGIN WRAPPER ── */
.login-wrapper {
  width: 100%;
  max-width: 360px;
  position: relative;
  z-index: 10;
  margin: 0 auto;
}

/* Hero Header */
.login-hero {
  text-align: center;
  margin-bottom: 24px;
}
.mascot-wrap {
  position: relative;
  display: inline-block;
  margin-bottom: 12px;
}
.mascot-badge {
  width: 76px;
  height: 76px;
  border-radius: 24px;
  background: #ffffff;
  border: 3px solid #78350f;
  box-shadow: 0 4px 0 #78350f, 0 10px 24px rgba(217, 119, 6, 0.28);
  display: flex;
  align-items: center;
  justify-content: center;
  animation: buzzyBob 3s ease-in-out infinite;
  margin: 0 auto;
}
.mascot-badge img {
  width: 52px;
  height: 52px;
  object-fit: contain;
}
@keyframes buzzyBob {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-6px) rotate(3deg); }
}

.login-title {
  font-size: 24px;
  font-weight: 900;
  color: #78350f;
  letter-spacing: -0.5px;
  line-height: 1.15;
  margin-bottom: 4px;
  text-shadow: 0 1.5px 0 rgba(255, 255, 255, 0.8);
}
.login-sub {
  font-size: 12px;
  font-weight: 800;
  color: #92400e;
  line-height: 1.35;
}

/* Error Flash */
.auth-err {
  background: #fee2e2;
  border: 2px solid #dc2626;
  border-radius: 14px;
  padding: 10px 14px;
  font-size: 12px;
  font-weight: 800;
  color: #991b1b;
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 3px 0 #dc2626;
  line-height: 1.35;
}

/* Form Fields */
.login-form {
  width: 100%;
}
.inp-group {
  margin-bottom: 14px;
}
.inp-label {
  display: block;
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.inp-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 0 14px;
  height: 48px;
  box-shadow: 0 3px 0 #78350f;
  transition: all 0.15s ease;
}
.inp-wrap:focus-within {
  border-color: #d97706;
  box-shadow: 0 3.5px 0 #d97706, 0 0 12px rgba(245, 158, 11, 0.25);
  transform: translateY(-1px);
}
.inp-icon {
  font-size: 20px;
  color: #b45309;
  flex-shrink: 0;
}
.inp-field {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-family: inherit;
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
  width: 100%;
}
.inp-field::placeholder {
  color: #94a3b8;
  font-weight: 700;
  font-size: 12.5px;
}
.eye-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  color: #94a3b8;
  font-size: 19px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color 0.1s;
}
.eye-btn:hover {
  color: #78350f;
}

/* Submit CTA Button */
.btn-login-submit {
  width: 100%;
  height: 50px;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
  color: #ffffff;
  font-size: 14.5px;
  font-weight: 900;
  letter-spacing: 0.3px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: 0 4.5px 0 #78350f;
  text-shadow: 0 1px 2px #78350f;
  cursor: pointer;
  font-family: inherit;
  margin-top: 18px;
  transition: transform 0.1s, box-shadow 0.1s;
}
.btn-login-submit:active {
  transform: translateY(3.5px);
  box-shadow: 0 1px 0 #78350f;
}

/* Register Link Box */
.login-footer-links {
  text-align: center;
  margin-top: 16px;
  font-size: 12px;
  font-weight: 800;
  color: #78350f;
}
.login-reg-link {
  font-weight: 900;
  color: #b45309;
  text-decoration: underline;
  margin-left: 4px;
  transition: color 0.1s;
}
.login-reg-link:hover {
  color: #78350f;
}

/* ── TRUST & REGULATION BOX (OJK & BAPPEBTI) ── */
.auth-trust-box {
  margin-top: 24px;
  text-align: center;
}
.trust-lbl {
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-bottom: 9px;
}
.trust-logos {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  background: rgba(255, 255, 255, 0.95);
  border: 2px solid #78350f;
  border-radius: 16px;
  padding: 10px 16px;
  box-shadow: 0 3px 0 #78350f;
}
.trust-logo--ojk {
  height: 40px;
  max-width: 120px;
  object-fit: contain;
  display: block;
}
.trust-logo--bap {
  height: 32px;
  max-width: 135px;
  object-fit: contain;
  display: block;
}
.trust-sep {
  width: 1.5px;
  height: 32px;
  background: #cbd5e1;
  flex-shrink: 0;
}
</style>
</head>
<body>

<!-- TOP HONEY DRIP DECORATION -->
<div class="honey-drip-top">
  <svg viewBox="0 0 1440 90" preserveAspectRatio="none" class="honey-drip-svg">
    <path d="M0,0 L1440,0 L1440,25 Q1380,75 1320,35 Q1260,-5 1200,45 Q1140,95 1080,40 Q1020,-15 960,30 Q900,75 840,35 Q780,-5 720,55 Q660,115 600,45 Q540,-25 480,40 Q420,105 360,35 Q300,-35 240,50 Q180,135 120,40 Q60,-50 0,30 Z" fill="#d97706" opacity="0.12"></path>
    <path d="M0,0 L1440,0 L1440,18 Q1380,60 1320,25 Q1260,-8 1200,35 Q1140,80 1080,30 Q1020,-18 960,20 Q900,60 840,25 Q780,-8 720,45 Q660,95 600,35 Q540,-25 480,30 Q420,90 360,25 Q300,-35 240,40 Q180,115 120,30 Q60,-55 0,22 Z" fill="#f59e0b" opacity="0.22"></path>
    <path d="M0,0 L1440,0 L1440,12 Q1350,45 1260,16 Q1170,-12 1080,28 Q990,70 900,20 Q810,-28 720,28 Q630,85 540,24 Q450,-38 360,20 Q270,78 180,20 Q90,-38 0,16 Z" fill="#fbbf24" opacity="0.28"></path>
  </svg>
</div>

<!-- CARDLESS LOGIN WRAPPER -->
<div class="login-wrapper">

  <!-- Hero Mascot Header -->
  <div class="login-hero">
    <div class="mascot-wrap">
      <div class="mascot-badge">
        <img src="<?= htmlspecialchars($_site_logo) ?>" alt="<?= htmlspecialchars($_site_name) ?>">
      </div>
    </div>
    <h1 class="login-title">Masuk Akun <?= htmlspecialchars($_site_name) ?></h1>
    <p class="login-sub">Tonton Video &amp; Raih Saldo Rupiah Setiap Hari</p>
  </div>

  <?php if ($error): ?>
    <div class="auth-err">
      <i class="ph-bold ph-warning-circle" style="font-size:18px;flex-shrink:0;"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>

  <!-- Cardless Form -->
  <form method="POST" class="login-form">
    <?= csrf_field() ?>

    <!-- Username / Email Field -->
    <div class="inp-group">
      <label class="inp-label" for="login-input">Username atau Email</label>
      <div class="inp-wrap">
        <i class="ph-bold ph-user inp-icon"></i>
        <input type="text"
               id="login-input"
               name="login"
               class="inp-field"
               value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
               placeholder="Masukkan username atau email"
               autocomplete="username"
               required
               autofocus>
      </div>
    </div>

    <!-- Password Field -->
    <div class="inp-group">
      <label class="inp-label" for="pwd-input">Kata Sandi</label>
      <div class="inp-wrap">
        <i class="ph-bold ph-lock-key inp-icon"></i>
        <input type="password"
               id="pwd-input"
               name="password"
               class="inp-field"
               placeholder="Masukkan kata sandi"
               autocomplete="current-password"
               required>
        <button type="button"
                class="eye-btn"
                onclick="togglePasswordVisibility()"
                title="Lihat kata sandi">
          <i class="ph-bold ph-eye" id="eye-icon"></i>
        </button>
      </div>
    </div>

    <!-- Submit CTA Button -->
    <button type="submit" class="btn-login-submit">
      <span>Masuk Sekarang</span>
      <i class="ph-bold ph-arrow-right"></i>
    </button>
  </form>

  <!-- Register Link -->
  <div class="login-footer-links">
    <span>Belum punya akun?</span>
    <a href="/register" class="login-reg-link">Daftar Akun Baru</a>
  </div>

  <!-- ── LOGO DIAWASI OJK & BAPPEBTI ── -->
  <div class="auth-trust-box">
    <div class="trust-lbl">
      <i class="ph-fill ph-shield-check" style="color:#059669;font-size:14px;"></i>
      <span>Diawasi &amp; Terdaftar Resmi</span>
    </div>
    <div class="trust-logos">
      <img src="/assets/ojkkk.png?v=3" alt="Otoritas Jasa Keuangan" class="trust-logo--ojk">
      <div class="trust-sep"></div>
      <img src="/assets/bap.png?v=3" alt="Bappebti" class="trust-logo--bap">
    </div>
  </div>

</div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('pwd-input');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;

  if (inp.type === 'password') {
    inp.type = 'text';
    ico.className = 'ph-bold ph-eye-slash';
  } else {
    inp.type = 'password';
    ico.className = 'ph-bold ph-eye';
  }
}
</script>
</body>
</html>
