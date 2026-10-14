<?php
// console/auth.php — unified auth middleware for admin + staff
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

// ── All available permission keys ──────────────────────────────────────────
define('STAFF_PERMISSIONS', [
    'dashboard'       => 'Dashboard',
    'users'           => 'Pengguna',
    'user_txns'       => 'Transaksi User',
    'videos'          => 'Manajemen Video',
    'deposits'        => 'Deposit',
    'withdrawals'     => 'Withdraw',
    'upgrades'        => 'Upgrade Orders',
    'memberships'     => 'Paket Membership',
    'redeem'          => 'Kode Redeem',
    'analytics'       => 'Traffic Analytics',
    'video_analytics' => 'Analisis Video',
    'livechat'        => 'Live Chat',
    'notifications'   => 'Push Notifikasi',
    'panduan'         => 'Panduan & Popup',
    'contacts'        => 'Tombol Kontak',
    'payment'         => 'Rekening & QRIS',
    'seo'             => 'SEO Management',
    'settings'        => 'Pengaturan Umum',
    'orders'          => 'Orders',
    'vouchers'        => 'Voucher Diskon',
    'investments'     => 'Investasi Ponzi',
    'target'          => 'Persentase Target',
    'staff'           => 'Manajemen Staff',
    'staff_roles'     => 'Peran & Izin',
    'orderkuota'      => 'OrderKuota API',
    'surveys'         => 'Survei Pengguna',
    'home_cms'        => 'CMS Beranda & Mancing',
    'fishing'         => 'Fish System',
]);

// ── Determine who is logged in ─────────────────────────────────────────────
$_is_head_admin  = !empty($_SESSION['admin']);
$_is_staff       = !empty($_SESSION['staff_id']);

if (!$_is_head_admin && !$_is_staff) {
    $redir = urlencode($_SERVER['REQUEST_URI'] ?? '/console/');
    redirect("/console/login?next={$redir}");
}

// ── Load staff permissions (fresh on every load) ─────────────────────────────
if ($_is_staff) {
    $sp = $pdo->prepare("
        SELECT p.permission FROM staff_role_permissions p
        JOIN staff s ON s.role_id = p.role_id
        WHERE s.id = ? AND s.is_active = 1
    ");
    $sp->execute([$_SESSION['staff_id']]);
    $_SESSION['staff_permissions'] = $sp->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// ── Helpers ────────────────────────────────────────────────────────────────
/**
 * Returns true if current session can access the given permission.
 * Head admin always returns true.
 */
function staff_can(string $perm): bool {
    if (!empty($_SESSION['admin'])) return true;
    return in_array($perm, $_SESSION['staff_permissions'] ?? [], true);
}

/**
 * Aborts with 403 if current session lacks the given permission.
 * Head admin always passes.
 */
function staff_require(string $perm): void {
    if (staff_can($perm)) return;
    http_response_code(403);
    // Try to render a nice 403 if possible, else plain text
    $name = STAFF_PERMISSIONS[$perm] ?? $perm;
    die('<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">
<title>Akses Ditolak</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>body{background:#0f1117;color:#e0e0f0;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:Inter,sans-serif}</style>
</head><body>
<div style="text-align:center;padding:40px">
  <div style="font-size:64px;margin-bottom:16px">🔒</div>
  <h3 style="font-weight:800;margin-bottom:8px">Akses Ditolak</h3>
  <p style="color:#666;margin-bottom:20px">Kamu tidak memiliki izin untuk mengakses halaman <strong style="color:#FF6B35">' . htmlspecialchars($name) . '</strong>.</p>
  <a href="/console/" style="color:#FF6B35;font-weight:700;text-decoration:none">← Kembali ke Dashboard</a>
</div>
</body></html>');
}

/**
 * True if current session is the master/head admin.
 */
function is_head_admin(): bool {
    return !empty($_SESSION['admin']);
}

/**
 * Returns the best "home" URL for the current session.
 * Head admin → /console/, staff → first permitted page.
 */
function staff_home_url(): string {
    if (!empty($_SESSION['admin'])) return '/console/';

    $perm_map = [
        'dashboard'       => '/console/',
        'withdrawals'     => '/console/withdrawals.php',
        'deposits'        => '/console/deposits.php',
        'users'           => '/console/users.php',
        'user_txns'       => '/console/user_txns',
        'videos'          => '/console/videos.php',
        'upgrades'        => '/console/upgrades.php',
        'livechat'        => '/console/livechat.php',
        'memberships'     => '/console/memberships.php',
        'redeem'          => '/console/redeem.php',
        'analytics'       => '/console/analytics.php',
        'video_analytics' => '/console/video_analytics.php',
        'notifications'   => '/console/notifications',
        'panduan'         => '/console/panduan',
        'contacts'        => '/console/contacts',
        'payment'         => '/console/payment.php',
        'seo'             => '/console/seo.php',
        'settings'        => '/console/settings.php',
        'orders'          => '/console/orders.php',
        'vouchers'        => '/console/vouchers.php',
        'investments'     => '/console/investments.php',
        'orderkuota'      => '/console/orderkuota.php',
        'fishing'         => '/console/fishing_dashboard.php',
    ];

    $perms = $_SESSION['staff_permissions'] ?? [];
    foreach ($perm_map as $perm => $url) {
        if (in_array($perm, $perms, true)) return $url;
    }
    return '/console/login'; // no permissions at all
}

// ── Rotate session every 30 min ────────────────────────────────────────────
$_rot_key = $_is_head_admin ? 'admin_last_rotate' : 'staff_last_rotate';
if (empty($_SESSION[$_rot_key]) || (time() - $_SESSION[$_rot_key]) > 1800) {
    session_regenerate_id(true);
    $_SESSION[$_rot_key] = time();
}
