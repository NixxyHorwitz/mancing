<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (!empty($_SESSION['admin']) || !empty($_SESSION['staff_id'])) redirect('/console/');
csrf_enforce();

$error = '';
$next  = preg_replace('/[^\\/a-zA-Z0-9_.?=&%-]/', '', $_GET['next'] ?? '/console/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1. Try head admin
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = ['id' => $admin['id'], 'username' => $admin['username']];
        $_SESSION['admin_last_rotate'] = time();
        redirect($next);
    }

    // 2. Try staff
    $stmt2 = $pdo->prepare("SELECT * FROM staff WHERE username=? AND is_active=1");
    $stmt2->execute([$username]);
    $staff = $stmt2->fetch();
    if ($staff && password_verify($password, $staff['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['staff_id']          = $staff['id'];
        $_SESSION['staff_username']    = $staff['username'];
        $_SESSION['staff_display']     = $staff['display_name'];
        $_SESSION['staff_last_rotate'] = time();
        // Load permissions
        $sp = $pdo->prepare("SELECT p.permission FROM staff_role_permissions p WHERE p.role_id = ?");
        $sp->execute([$staff['role_id']]);
        $_SESSION['staff_permissions'] = $sp->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $pdo->prepare("UPDATE staff SET last_login=NOW() WHERE id=?")->execute([$staff['id']]);
        // Need auth.php helpers loaded to call staff_home_url()
        // But auth.php redirects if not logged in — load bootstrap only
        require_once dirname(__DIR__) . '/bootstrap.php';
        // Manually compute first accessible page
        $perm_priority = ['dashboard','withdrawals','deposits','users','user_txns','videos','upgrades',
            'livechat','memberships','redeem','analytics','video_analytics',
            'notifications','panduan','contacts','payment','seo','settings','orders'];
        $perm_urls = [
            'dashboard'=>'/console/',
            'withdrawals'=>'/console/withdrawals.php',
            'deposits'=>'/console/deposits.php','users'=>'/console/users.php',
            'user_txns'=>'/console/user_txns','videos'=>'/console/videos.php','upgrades'=>'/console/upgrades.php',
            'livechat'=>'/console/livechat.php','memberships'=>'/console/memberships.php',
            'redeem'=>'/console/redeem.php','analytics'=>'/console/analytics.php',
            'video_analytics'=>'/console/video_analytics.php',
            'notifications'=>'/console/notifications','panduan'=>'/console/panduan',
            'contacts'=>'/console/contacts','payment'=>'/console/payment.php',
            'seo'=>'/console/seo.php','settings'=>'/console/settings.php','orders'=>'/console/orders.php',
        ];
        $staff_perms = $_SESSION['staff_permissions'];
        $home = '/console/';
        foreach ($perm_priority as $p) {
            if (in_array($p, $staff_perms, true)) { $home = $perm_urls[$p]; break; }
        }
        redirect($next !== '/console/' ? $next : $home);
    }

    $error = 'Username atau password salah.';
}

$_favicon    = setting($pdo, 'favicon_path', '');
$absolute_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>MancingCuan — Admin Console Login</title>
<?php if ($absolute_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__) . $_favicon) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__) . $_favicon) ?: time() ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{font-family:'Inter',sans-serif}
body{background:#080a12;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}
.login-card{background:#111422;border:1px solid #1e243d;border-radius:20px;padding:36px;width:100%;max-width:390px;box-shadow:0 10px 40px rgba(0,0,0,0.5)}
.brand-icon{width:56px;height:56px;background:linear-gradient(135deg,#f59e0b,#d97706);border:2px solid #b45309;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;box-shadow:0 4px 15px rgba(245,158,11,0.3)}
.form-control{background:#0b0d17;border:1.5px solid #1e243d;color:#f8fafc;border-radius:12px;padding:11px 14px}
.form-control:focus{background:#0b0d17;border-color:#f59e0b;color:#fff;box-shadow:0 0 0 3px rgba(245,158,11,.15)}
.btn-login{background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:12px;font-weight:700;padding:12px;font-size:15px;color:#fff;box-shadow:0 4px 14px rgba(245,158,11,0.3)}
.btn-login:hover{background:linear-gradient(135deg,#d97706,#b45309);color:#fff}
</style>
</head>
<body>
<div class="login-card text-center">
  <div class="brand-icon">🎣</div>
  <h4 class="text-white fw-bold mb-1">MancingCuan</h4>
  <p class="text-secondary mb-4" style="font-size:13px">Console &amp; Backoffice Management</p>
  <?php if ($error): ?>
  <div class="alert alert-danger py-2" style="font-size:13px;border-radius:10px"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST" class="text-start">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label text-secondary" style="font-size:13px">Username</label>
      <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus required>
    </div>
    <div class="mb-4">
      <label class="form-label text-secondary" style="font-size:13px">Password</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-login w-100">Masuk ke Console</button>
  </form>
</div>
</body>
</html>
