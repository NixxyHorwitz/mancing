<?php
$pageTitle  = $pageTitle  ?? 'Console';
$activePage = $activePage ?? '';
// Support both admin and staff sessions
if (!empty($_SESSION['admin'])) {
    $admin = $_SESSION['admin'];
    $adminLabel = 'Head Admin';
    $adminInitial = strtoupper(substr($admin['username'], 0, 1));
} else {
    $admin = ['username' => $_SESSION['staff_display'] ?? $_SESSION['staff_username'] ?? 'Staff'];
    $adminLabel = 'Staff';
    $adminInitial = strtoupper(substr($admin['username'], 0, 1));
}

// Pending counts for badges
try {
    $pending_wd  = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn();
    $pending_dep = (int)$pdo->query("SELECT COUNT(*) FROM deposits WHERE status='pending'")->fetchColumn();
    $pending_upg = (int)$pdo->query("SELECT COUNT(*) FROM upgrade_orders WHERE status='pending'")->fetchColumn();
    $pending_ord = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
    $pending_req = (int)$pdo->query("SELECT COUNT(*) FROM admin_requests WHERE status='pending'")->fetchColumn();
} catch(\Throwable) { $pending_wd = $pending_dep = $pending_upg = $pending_ord = $pending_req = 0; }

$_favicon    = setting($pdo, 'favicon_path', '');
$absolute_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?>   Admin</title>
<?php if ($absolute_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__, 2) . $_favicon) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__, 2) . $_favicon) ?: time() ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/bold/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css">
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/bold/style.css">
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/fill/style.css">
<style>
:root {
  --brand: #f59e0b;
  --brand-hover: #d97706;
  --sidebar-w: 250px;
  --sidebar-bg: #0b0d17;
  --topbar-h: 62px;
  --card-bg: #111422;
  --border-color: #1d2238;
}
*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #080a12; color: #e2e8f0; min-height: 100vh; font-size: 13.5px; }

/* Ensure Phosphor Icons are never overridden by Inter font */
[class^="ph-"], [class*=" ph-"], i[class*="ph-"], .ph, .ph-bold, .ph-fill {
  font-family: "Phosphor-Bold", "Phosphor-Fill", "Phosphor" !important;
  font-style: normal;
  display: inline-block;
  line-height: 1;
}
[class^="ph-"]::before, [class*=" ph-"]::before, i[class*="ph-"]::before {
  font-family: inherit !important;
}

/* ── Sidebar ── */
.c-sidebar {
  position: fixed; top: 0; left: 0;
  width: var(--sidebar-w); height: 100vh;
  background: var(--sidebar-bg);
  border-right: 1px solid var(--border-color);
  display: flex; flex-direction: column;
  z-index: 1050;
  transition: transform .25s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 4px 0 24px rgba(0,0,0,0.4);
}
.c-sidebar__logo {
  display: flex; align-items: center; gap: 12px;
  padding: 18px 20px;
  border-bottom: 1px solid var(--border-color);
  background: rgba(255,255,255,0.02);
}
.c-sidebar__icon {
  width: 40px; height: 40px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-radius: 12px;
  border: 1.5px solid #b45309;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 10px rgba(245,158,11,0.3);
  font-size: 20px;
  flex-shrink: 0;
}
.c-sidebar__brand { font-size: 16px; font-weight: 800; color: #fff; line-height: 1.2; letter-spacing: -0.3px; }
.c-sidebar__sub { font-size: 10.5px; color: #f59e0b; font-weight: 600; }
.c-sidebar__nav { flex: 1; overflow-y: auto; padding: 10px 8px; scrollbar-width: thin; scrollbar-color: rgba(245,158,11,0.2) transparent; }
.c-sidebar__nav::-webkit-scrollbar { width: 4px; }
.c-sidebar__nav::-webkit-scrollbar-thumb { background: #1e243d; border-radius: 4px; }
.c-sidebar__label { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .7px; color: #505876; padding: 12px 10px 4px; }
.c-nav-link {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 10px;
  border-radius: 8px;
  color: #94a3b8;
  font-size: 12.5px;
  font-weight: 600;
  text-decoration: none;
  margin-bottom: 2px;
  transition: all .15s ease;
  position: relative;
}
.c-nav-link:hover { background: rgba(255,255,255,0.05); color: #f8fafc; transform: translateX(2px); }
.c-nav-link.active {
  background: linear-gradient(90deg, rgba(245,158,11,0.18), rgba(245,158,11,0.04));
  color: var(--brand); font-weight: 700;
  border-left: 3px solid var(--brand);
}
.c-nav-link.active svg { color: var(--brand) !important; }
.c-nav-link .badge-dot {
  margin-left: auto;
  background: #e11d48;
  color: #fff;
  font-size: 9.5px;
  font-weight: 800;
  padding: 1.5px 6px;
  border-radius: 10px;
  min-width: 18px;
  text-align: center;
}

/* ── Collapsible Menu (Compact & Clean) ── */
.c-nav-collapse-group {
  margin-bottom: 3px;
  border-radius: 9px;
}
.c-nav-collapse-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 10px;
  border-radius: 8px;
  background: transparent;
  border: 1px solid transparent;
  color: #94a3b8;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  transition: all .18s ease;
  text-align: left;
}
.c-nav-collapse-btn:hover {
  background: rgba(255,255,255,0.05);
  color: #f8fafc;
}
.c-nav-collapse-btn.is-active,
.c-nav-collapse-group.open .c-nav-collapse-btn {
  color: #f8fafc;
}
.c-nav-collapse-btn.is-active {
  background: rgba(245, 158, 11, 0.09);
  border-color: rgba(245, 158, 11, 0.25);
  color: var(--brand);
}
.c-nav-collapse-btn__left {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
}
.c-nav-collapse-btn__left svg {
  color: #f59e0b;
  flex-shrink: 0;
}
.c-nav-collapse-btn__right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.c-nav-collapse-badge {
  font-size: 9.5px;
  font-weight: 800;
  padding: 1px 6px;
  border-radius: 8px;
  background: rgba(245, 158, 11, 0.15);
  color: #f59e0b;
  border: 1px solid rgba(245, 158, 11, 0.25);
}
.c-nav-collapse-chevron {
  transition: transform .22s cubic-bezier(0.4, 0, 0.2, 1);
  color: #64748b;
}
.c-nav-collapse-group.open .c-nav-collapse-chevron {
  transform: rotate(180deg);
  color: #f59e0b;
}
.c-nav-collapse-body {
  max-height: 0;
  overflow: hidden;
  transition: max-height .28s cubic-bezier(0.4, 0, 0.2, 1);
}
.c-nav-collapse-group.open .c-nav-collapse-body {
  max-height: 650px;
}
.c-nav-collapse-inner {
  margin: 3px 0 5px 14px;
  padding-left: 10px;
  border-left: 1.5px dashed rgba(245, 158, 11, 0.25);
  display: flex;
  flex-direction: column;
  gap: 1.5px;
}
.c-nav-sublink {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6.5px 8px;
  border-radius: 7px;
  color: #94a3b8;
  font-size: 11.5px;
  font-weight: 550;
  text-decoration: none;
  transition: all .15s ease;
  position: relative;
}
.c-nav-sublink svg {
  flex-shrink: 0;
  color: #64748b;
  transition: color .15s ease;
}
.c-nav-sublink:hover {
  background: rgba(255,255,255,0.05);
  color: #fff;
  transform: translateX(2px);
}
.c-nav-sublink:hover svg {
  color: #f59e0b;
}
.c-nav-sublink.active {
  background: linear-gradient(90deg, rgba(245, 158, 11, 0.18), rgba(245, 158, 11, 0.04));
  color: #fbbf24;
  font-weight: 700;
  border-left: 2.5px solid #f59e0b;
  padding-left: 6px;
}
.c-nav-sublink.active svg {
  color: #fbbf24 !important;
}
.c-sidebar__footer { padding: 12px; border-top: 1px solid var(--border-color); background: rgba(0,0,0,0.2); }

/* ── Topbar ── */
.c-topbar {
  position: fixed; top: 0; left: var(--sidebar-w); right: 0;
  height: var(--topbar-h);
  background: rgba(14, 17, 29, 0.92);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border-color);
  display: flex; align-items: center;
  padding: 0 24px;
  gap: 14px;
  z-index: 1040;
  transition: left .25s;
}
.c-topbar__toggle {
  display: none; background: #161a2b; border: 1px solid var(--border-color);
  color: #f8fafc; cursor: pointer; padding: 7px; border-radius: 8px;
}
.c-topbar__title { font-weight: 700; font-size: 15px; flex: 1; color: #f8fafc; }
.c-topbar__clock { font-size: 12px; color: #94a3b8; font-weight: 600; background: #121524; padding: 4px 10px; border-radius: 8px; border: 1px solid var(--border-color); }
.c-topbar__avatar {
  width: 36px; height: 36px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 14px; color: #fff;
  border: 2px solid #b45309;
}

/* ── Main content ── */
.c-main {
  margin-left: var(--sidebar-w);
  padding-top: var(--topbar-h);
  min-height: 100vh;
  transition: margin .25s;
}
.c-content { padding: 24px; }

/* ── Cards ── */
.c-card {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,0.25);
  margin-bottom: 18px;
}
.c-card-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-color);
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(255,255,255,0.015);
}
.c-card-title { font-weight: 700; font-size: 14.5px; color: #f8fafc; }
.c-card-body { padding: 20px; }

/* ── Stats ── */
.c-stat {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  padding: 18px 20px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
  transition: transform .2s, border-color .2s;
}
.c-stat:hover { transform: translateY(-2px); border-color: rgba(245,158,11,0.4); }
.c-stat__val { font-size: 22px; font-weight: 800; color: #f8fafc; }
.c-stat__lbl { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
.c-stat__icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }

/* ── Table ── */
.c-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.c-table th { background: #0f1117; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; padding: 10px 14px; border-bottom: 1px solid #1f2235; }
.c-table td { padding: 12px 14px; border-bottom: 1px solid #1a1d27; vertical-align: middle; }
.c-table tbody tr:hover { background: #15182a; }
.c-table tbody tr:last-child td { border-bottom: none; }

/* ── DataTables Overrides ── */
.dataTables_wrapper { font-size: 13px; color: #888; padding: 10px; }
.dataTables_wrapper .dataTables_length select,
.dataTables_wrapper .dataTables_filter input {
  background: #0f1117; border: 1.5px solid #1f2235; border-radius: 6px;
  color: #e0e0f0; padding: 4px 8px; outline: none;
}
.dataTables_wrapper .dataTables_length select:focus,
.dataTables_wrapper .dataTables_filter input:focus { border-color: var(--brand); }
.dataTables_wrapper .pagination .page-link {
  background: #0f1117; border-color: #1f2235; color: #888;
}
.dataTables_wrapper .pagination .page-item.active .page-link {
  background: var(--brand); border-color: var(--brand); color: #fff;
}

/* ── Badges ── */
.b-success { background: rgba(76,175,130,.15); color: #4CAF82; }
.b-warn    { background: rgba(242,153,0,.15); color: #F29900; }
.b-danger  { background: rgba(244,78,59,.15); color: #F44E3B; }
.b-neutral { background: rgba(255,255,255,.07); color: #888; }

/* ── Forms ── */
.c-form-control {
  background: #0f1117;
  border: 1.5px solid #1f2235;
  border-radius: 8px;
  color: #e0e0f0;
  padding: 9px 12px;
  font-size: 13px;
  width: 100%;
  outline: none;
  transition: border-color .18s;
  font-family: inherit;
}
.c-form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(255,107,53,.12); }
.c-form-control::placeholder { color: #444; }
.c-label { font-size: 12px; font-weight: 600; color: #888; margin-bottom: 5px; display: block; }
.c-form-group { margin-bottom: 14px; }

/* ── Backdrop (mobile) ── */
.c-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.6); z-index: 1045; }

/* ── Mobile Responsive Optimizations ── */
@media (max-width: 991px) {
  .c-sidebar { transform: translateX(-100%); }
  .c-sidebar.open { transform: translateX(0); }
  .c-topbar { left: 0; padding: 0 16px; }
  .c-main { margin-left: 0; }
  .c-content { padding: 16px 14px; }
  .c-topbar__toggle { display: flex; align-items: center; justify-content: center; }
  .c-backdrop.active { display: block; }
}

@media (max-width: 576px) {
  .c-topbar__clock { display: none; }
  .c-topbar__title { font-size: 13.5px; }
  .c-stat { padding: 14px 14px; }
  .c-stat__val { font-size: 18px; }
  .c-stat__lbl { font-size: 10px; }
}
</style>
</head>
<body>

<div class="c-backdrop" id="backdrop" onclick="closeSidebar()"></div>

<aside class="c-sidebar" id="sidebar">
  <div class="c-sidebar__logo">
    <div class="c-sidebar__icon" style="display:flex;align-items:center;justify-content:center">
      <img src="<?= htmlspecialchars(site_logo($pdo)) ?>" alt="Logo" style="width:28px;height:28px;object-fit:contain;border-radius:6px">
    </div>
    <div style="flex:1;min-width:0">
      <div class="c-sidebar__brand"><?= htmlspecialchars(setting($pdo, 'site_name', 'MancingCuan')) ?></div>
      <div class="c-sidebar__sub">Farm Console</div>
    </div>
    <button type="button" class="btn-close btn-close-white d-lg-none" onclick="closeSidebar()" aria-label="Close" style="font-size:11px"></button>
  </div>

  <nav class="c-sidebar__nav">
    <!-- 1. UTAMA -->
    <div class="c-sidebar__label">Utama</div>
    <?php if (staff_can('dashboard')): ?>
    <a href="/console/" class="c-nav-link <?= $activePage==='dashboard'?'active':'' ?>">
      <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      <span>Dashboard</span>
    </a>
    <?php endif; ?>

    <!-- 2. PUSAT ANALISIS & DATA (COLLAPSIBLE) -->
    <?php
    $analPages = ['activity_analytics', 'transaction_flow', 'analytics', 'video_analytics', 'registration_analytics', 'target', 'heartbeats', 'qris_logs', 'missions'];
    $isAnalActive = in_array($activePage ?? '', $analPages, true);
    $canAnal = staff_can('analytics') || staff_can('video_analytics') || staff_can('target') || staff_can('transaction_flow') || staff_can('deposits');
    ?>
    <?php if ($canAnal): ?>
    <div class="c-sidebar__label" style="margin-top:6px">Pusat Analisis &amp; Data 📈</div>
    <div class="c-nav-collapse-group <?= $isAnalActive ? 'open' : '' ?>" id="nav-col-analytics">
      <button type="button" class="c-nav-collapse-btn <?= $isAnalActive ? 'is-active' : '' ?>" onclick="toggleSidebarCollapse('nav-col-analytics')" aria-expanded="<?= $isAnalActive ? 'true' : 'false' ?>">
        <div class="c-nav-collapse-btn__left">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
          <span>Pusat Analitik</span>
        </div>
        <div class="c-nav-collapse-btn__right">
          <span class="c-nav-collapse-badge">9 Menu</span>
          <svg class="c-nav-collapse-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>

      <div class="c-nav-collapse-body">
        <div class="c-nav-collapse-inner">
          <a href="/console/activity_analytics.php" class="c-nav-sublink <?= $activePage==='activity_analytics'?'active':'' ?>" style="background:rgba(245,158,11,0.08);color:#fbbf24;font-weight:700;">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:#fbbf24;"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
            <span>⭐ Analisis Aktivitas Utama</span>
          </a>

          <?php if (staff_can('transaction_flow') || staff_can('deposits')): ?>
          <a href="/console/transaction_flow.php" class="c-nav-sublink <?= $activePage==='transaction_flow'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span>Transaction Flow</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('analytics')): ?>
          <a href="/console/analytics.php" class="c-nav-sublink <?= $activePage==='analytics'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            <span>Traffic &amp; Pengunjung</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('video_analytics')): ?>
          <a href="/console/video_analytics.php" class="c-nav-sublink <?= $activePage==='video_analytics'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
            <span>Analisis Video</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('analytics')): ?>
          <a href="/console/registration_analytics.php" class="c-nav-sublink <?= $activePage==='registration_analytics'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            <span>Analisis Pendaftaran</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('target')): ?>
          <a href="/console/target.php" class="c-nav-sublink <?= $activePage==='target'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
            <span>Persentase Target</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('analytics')): ?>
          <a href="/console/heartbeats.php" class="c-nav-sublink <?= $activePage==='heartbeats'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20.42 4.58a5.4 5.4 0 00-7.65 0l-.77.78-.77-.78a5.4 5.4 0 00-7.65 0C1.46 6.7 1.33 10.28 4 13l8 8 8-8c2.67-2.72 2.54-6.3.42-8.42z"/></svg>
            <span>Status Forwarder</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('analytics') || staff_can('deposits')): ?>
          <a href="/console/qris_logs.php" class="c-nav-sublink <?= $activePage==='qris_logs'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><rect x="7" y="7" width="3" height="3"/><rect x="14" y="7" width="3" height="3"/><rect x="7" y="14" width="3" height="3"/><rect x="14" y="14" width="3" height="3"/></svg>
            <span>Log QRIS Otomatis</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('analytics')): ?>
          <a href="/console/missions.php" class="c-nav-sublink <?= $activePage==='missions'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
            <span>Log Misi User</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 3. KONTEN & VIDEO (COLLAPSIBLE) -->
    <?php
    $farmPages = ['videos', 'memberships', 'home_cms'];
    $isFarmActive = in_array($activePage ?? '', $farmPages, true);
    $canFarm = staff_can('videos') || staff_can('memberships') || staff_can('home_cms') || staff_can('settings');
    ?>
    <?php if ($canFarm): ?>
    <div class="c-sidebar__label" style="margin-top:6px">Video &amp; Konten 🎬</div>
    <div class="c-nav-collapse-group <?= $isFarmActive ? 'open' : '' ?>" id="nav-col-farm">
      <button type="button" class="c-nav-collapse-btn <?= $isFarmActive ? 'is-active' : '' ?>" onclick="toggleSidebarCollapse('nav-col-farm')" aria-expanded="<?= $isFarmActive ? 'true' : 'false' ?>">
        <div class="c-nav-collapse-btn__left">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
          <span>Sistem Konten</span>
        </div>
        <div class="c-nav-collapse-btn__right">
          <svg class="c-nav-collapse-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>

      <div class="c-nav-collapse-body">
        <div class="c-nav-collapse-inner">
          <?php if (staff_can('videos')): ?>
          <a href="/console/videos.php" class="c-nav-sublink <?= $activePage==='videos'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
            <span>Manajemen Video</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('memberships')): ?>
          <a href="/console/memberships.php" class="c-nav-sublink <?= $activePage==='memberships'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>
            <span>Paket Membership</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('home_cms') || staff_can('settings')): ?>
          <a href="/console/home_cms.php" class="c-nav-sublink <?= $activePage==='home_cms'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
            <span>CMS Beranda &amp; Mancing</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 4. KEUANGAN & TRANSAKSI (COLLAPSIBLE) -->
    <?php
    $finPages = ['deposits', 'withdrawals', 'wd_settings', 'upgrades', 'orders'];
    $isFinActive = in_array($activePage ?? '', $finPages, true);
    $canFin = staff_can('deposits') || staff_can('withdrawals') || staff_can('settings') || staff_can('upgrades') || staff_can('orders');
    $finPendingSum = $pending_dep + $pending_wd + $pending_upg + $pending_ord;
    ?>
    <?php if ($canFin): ?>
    <div class="c-sidebar__label" style="margin-top:6px">Keuangan &amp; Order 💰</div>
    <div class="c-nav-collapse-group <?= $isFinActive ? 'open' : '' ?>" id="nav-col-finance">
      <button type="button" class="c-nav-collapse-btn <?= $isFinActive ? 'is-active' : '' ?>" onclick="toggleSidebarCollapse('nav-col-finance')" aria-expanded="<?= $isFinActive ? 'true' : 'false' ?>">
        <div class="c-nav-collapse-btn__left">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          <span>Keuangan Platform</span>
        </div>
        <div class="c-nav-collapse-btn__right">
          <?php if ($finPendingSum > 0): ?>
            <span class="badge-dot" style="background:#e11d48;color:#fff;"><?= $finPendingSum ?></span>
          <?php endif; ?>
          <svg class="c-nav-collapse-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>

      <div class="c-nav-collapse-body">
        <div class="c-nav-collapse-inner">
          <?php if (staff_can('deposits')): ?>
          <a href="/console/deposits.php" class="c-nav-sublink <?= $activePage==='deposits'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
            <span>Deposit User</span>
            <?php if ($pending_dep > 0): ?><span class="badge-dot ms-auto"><?= $pending_dep ?></span><?php endif; ?>
          </a>
          <?php endif; ?>

          <?php if (staff_can('withdrawals')): ?>
          <a href="/console/withdrawals.php" class="c-nav-sublink <?= $activePage==='withdrawals'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
            <span>Withdraw</span>
            <?php if ($pending_wd > 0): ?><span class="badge-dot ms-auto"><?= $pending_wd ?></span><?php endif; ?>
          </a>
          <?php endif; ?>

          <?php if (staff_can('settings')): ?>
          <a href="/console/wd_settings.php" class="c-nav-sublink <?= $activePage==='wd_settings'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/><path d="M21 12H3"/></svg>
            <span>WD Settings</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('upgrades')): ?>
          <a href="/console/upgrades.php" class="c-nav-sublink <?= $activePage==='upgrades'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
            <span>Upgrade Orders</span>
            <?php if ($pending_upg > 0): ?><span class="badge-dot ms-auto"><?= $pending_upg ?></span><?php endif; ?>
          </a>
          <?php endif; ?>

          <?php if (staff_can('orders')): ?>
          <a href="/console/orders.php" class="c-nav-sublink <?= $activePage==='orders'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>
            <span>Orders Produk</span>
            <?php if ($pending_ord > 0): ?><span class="badge-dot ms-auto"><?= $pending_ord ?></span><?php endif; ?>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 5. PENGGUNA & AKSES (COLLAPSIBLE) -->
    <?php
    $usrPages = ['users', 'user_txns', 'requests', 'livechat'];
    $isUsrActive = in_array($activePage ?? '', $usrPages, true);
    $canUsr = staff_can('users') || staff_can('user_txns') || staff_can('livechat');
    $usrPendingSum = $pending_req;
    try {
        $pChat = (int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status='open'")->fetchColumn();
    } catch(\Throwable) { $pChat = 0; }
    $usrPendingSum += $pChat;
    ?>
    <?php if ($canUsr): ?>
    <div class="c-sidebar__label" style="margin-top:6px">Pengguna &amp; Chat 👥</div>
    <div class="c-nav-collapse-group <?= $isUsrActive ? 'open' : '' ?>" id="nav-col-users">
      <button type="button" class="c-nav-collapse-btn <?= $isUsrActive ? 'is-active' : '' ?>" onclick="toggleSidebarCollapse('nav-col-users')" aria-expanded="<?= $isUsrActive ? 'true' : 'false' ?>">
        <div class="c-nav-collapse-btn__left">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          <span>Pengguna &amp; CS</span>
        </div>
        <div class="c-nav-collapse-btn__right">
          <?php if ($usrPendingSum > 0): ?>
            <span class="badge-dot" style="background:#e11d48;color:#fff;"><?= $usrPendingSum ?></span>
          <?php endif; ?>
          <svg class="c-nav-collapse-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>

      <div class="c-nav-collapse-body">
        <div class="c-nav-collapse-inner">
          <?php if (staff_can('users')): ?>
          <a href="/console/users.php" class="c-nav-sublink <?= $activePage==='users'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            <span>Kelola Pengguna</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('user_txns')): ?>
          <a href="/console/user_txns" class="c-nav-sublink <?= $activePage==='user_txns'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <span>Transaksi User</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('users')): ?>
          <a href="/console/requests.php" class="c-nav-sublink <?= $activePage==='requests'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            <span>Permintaan</span>
            <?php if ($pending_req > 0): ?><span class="badge-dot ms-auto"><?= $pending_req ?></span><?php endif; ?>
          </a>
          <?php endif; ?>

          <?php if (staff_can('livechat')): ?>
          <a href="/console/livechat.php" class="c-nav-sublink <?= $activePage==='livechat'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            <span>Live Chat CS</span>
            <?php if ($pChat > 0): ?><span class="badge-dot ms-auto"><?= $pChat ?></span><?php endif; ?>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 6. PROMOSI & MARKETING (COLLAPSIBLE) -->
    <?php
    $mktPages = ['promotors', 'surveys', 'redeem', 'vouchers', 'investments', 'notifications'];
    $isMktActive = in_array($activePage ?? '', $mktPages, true);
    $canMkt = staff_can('analytics') || staff_can('users') || staff_can('surveys') || staff_can('redeem') || staff_can('vouchers') || staff_can('investments') || staff_can('notifications');
    try {
        $surveyCount = (int)$pdo->query("SELECT COUNT(*) FROM user_surveys")->fetchColumn();
    } catch(\Throwable) { $surveyCount = 0; }
    ?>
    <?php if ($canMkt): ?>
    <div class="c-sidebar__label" style="margin-top:6px">Promosi &amp; Reward 🎁</div>
    <div class="c-nav-collapse-group <?= $isMktActive ? 'open' : '' ?>" id="nav-col-promo">
      <button type="button" class="c-nav-collapse-btn <?= $isMktActive ? 'is-active' : '' ?>" onclick="toggleSidebarCollapse('nav-col-promo')" aria-expanded="<?= $isMktActive ? 'true' : 'false' ?>">
        <div class="c-nav-collapse-btn__left">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 12v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><path d="M12 14v4"/><path d="M9 16h6"/><rect x="4" y="12" width="16" height="10" rx="2"/></svg>
          <span>Program &amp; Promo</span>
        </div>
        <div class="c-nav-collapse-btn__right">
          <?php if ($surveyCount > 0): ?>
            <span class="badge" style="background:#f59e0b;color:#000;font-size:9.5px;font-weight:800;padding:1px 5px;border-radius:6px;"><?= $surveyCount ?></span>
          <?php endif; ?>
          <svg class="c-nav-collapse-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>

      <div class="c-nav-collapse-body">
        <div class="c-nav-collapse-inner">
          <?php if (staff_can('analytics') || staff_can('users')): ?>
          <a href="/console/promotors.php" class="c-nav-sublink <?= $activePage==='promotors'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
            <span>Kelola Promotor</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('surveys') || staff_can('analytics') || staff_can('users')): ?>
          <a href="/console/surveys.php" class="c-nav-sublink <?= $activePage==='surveys'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>Survei Pengguna</span>
            <?php if ($surveyCount > 0): ?><span class="badge ms-auto" style="background:#f59e0b;color:#000;font-size:9.5px;font-weight:800;padding:1px 5px;"><?= $surveyCount ?></span><?php endif; ?>
          </a>
          <?php endif; ?>

          <?php if (staff_can('redeem')): ?>
          <a href="/console/redeem.php" class="c-nav-sublink <?= $activePage==='redeem'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 12v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><path d="M12 14v4"/><path d="M9 16h6"/><rect x="4" y="12" width="16" height="10" rx="2"/></svg>
            <span>Kode Redeem</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('vouchers')): ?>
          <a href="/console/vouchers.php" class="c-nav-sublink <?= $activePage==='vouchers'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 5v2m0 4v2M5 5v2m0 4v2M3 7h18M3 17h18M3 12h18"/></svg>
            <span>Voucher Diskon</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('investments')): ?>
          <a href="/console/investments.php" class="c-nav-sublink <?= $activePage==='investments'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            <span>Investasi Ponzi</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('notifications')): ?>
          <a href="/console/notifications" class="c-nav-sublink <?= $activePage==='notifications'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            <span>Push Notifikasi</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- 7. SISTEM & PENGATURAN (COLLAPSIBLE) -->
    <?php
    $sysPages = ['payment', 'banks', 'seo', 'panduan', 'contacts', 'orderkuota', 'settings', 'staff'];
    $isSysActive = in_array($activePage ?? '', $sysPages, true);
    $canSys = staff_can('payment') || staff_can('seo') || staff_can('panduan') || staff_can('contacts') || staff_can('orderkuota') || staff_can('settings') || staff_can('staff');
    ?>
    <?php if ($canSys): ?>
    <div class="c-sidebar__label" style="margin-top:6px">Sistem &amp; Pengaturan ⚙️</div>
    <div class="c-nav-collapse-group <?= $isSysActive ? 'open' : '' ?>" id="nav-col-settings">
      <button type="button" class="c-nav-collapse-btn <?= $isSysActive ? 'is-active' : '' ?>" onclick="toggleSidebarCollapse('nav-col-settings')" aria-expanded="<?= $isSysActive ? 'true' : 'false' ?>">
        <div class="c-nav-collapse-btn__left">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          <span>Konfigurasi &amp; App</span>
        </div>
        <div class="c-nav-collapse-btn__right">
          <svg class="c-nav-collapse-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </button>

      <div class="c-nav-collapse-body">
        <div class="c-nav-collapse-inner">
          <?php if (staff_can('payment')): ?>
          <a href="/console/payment.php" class="c-nav-sublink <?= $activePage==='payment'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            <span>Rekening &amp; QRIS</span>
          </a>
          <a href="/console/banks" class="c-nav-sublink <?= $activePage==='banks'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>Bank &amp; E-Wallet</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('seo')): ?>
          <a href="/console/seo.php" class="c-nav-sublink <?= $activePage==='seo'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <span>SEO Management</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('panduan')): ?>
          <a href="/console/panduan" class="c-nav-sublink <?= $activePage==='panduan'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2z"/><path d="M22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7z"/></svg>
            <span>Panduan &amp; Popup</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('contacts')): ?>
          <a href="/console/contacts" class="c-nav-sublink <?= $activePage==='contacts'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            <span>Tombol Kontak</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('orderkuota')): ?>
          <a href="/console/orderkuota.php" class="c-nav-sublink <?= $activePage==='orderkuota'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <span>API OrderKuota</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('settings')): ?>
          <a href="/console/settings.php" class="c-nav-sublink <?= $activePage==='settings'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
            <span>Pengaturan Umum</span>
          </a>
          <?php endif; ?>

          <?php if (staff_can('staff')): ?>
          <a href="/console/staff.php" class="c-nav-sublink <?= $activePage==='staff'?'active':'' ?>">
            <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/><line x1="20" y1="8" x2="20" y2="14"/></svg>
            <span>Kelola Staff</span>
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </nav>

  <div class="c-sidebar__footer">
    <a href="/" target="_blank" class="c-nav-link">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      Lihat Website
    </a>
    <a href="/console/logout.php" class="c-nav-link" style="color:#F44E3B">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</aside>

<script>
function toggleSidebarCollapse(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const isOpen = el.classList.toggle('open');
  const btn = el.querySelector('.c-nav-collapse-btn');
  if (btn) btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  try {
    sessionStorage.setItem(id + '_state', isOpen ? 'open' : 'closed');
  } catch(e) {}
}

document.addEventListener('DOMContentLoaded', function() {
  const collapseIds = ['nav-col-analytics', 'nav-col-farm', 'nav-col-finance', 'nav-col-users', 'nav-col-promo', 'nav-col-settings'];
  collapseIds.forEach(function(id) {
    const el = document.getElementById(id);
    if (el && !el.classList.contains('open')) {
      try {
        const saved = sessionStorage.getItem(id + '_state');
        if (saved === 'open') {
          el.classList.add('open');
          const btn = el.querySelector('.c-nav-collapse-btn');
          if (btn) btn.setAttribute('aria-expanded', 'true');
        }
      } catch(e) {}
    }
  });
});
</script>

<div class="c-main">
  <header class="c-topbar">
    <button class="c-topbar__toggle" onclick="toggleSidebar()">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="c-topbar__title"><?= htmlspecialchars($pageTitle) ?></div>
    <div class="c-topbar__clock" id="c-clock"></div>
    <div style="display:flex;align-items:center;gap:8px">
      <div style="text-align:right">
        <div style="font-size:12px;font-weight:700;color:#e0e0f0"><?= htmlspecialchars($admin['username']) ?></div>
        <div style="font-size:10px;color:#555"><?= is_head_admin() ? '👑 Head Admin' : '🧑‍💼 Staff' ?></div>
      </div>
      <div class="c-topbar__avatar"><?= $adminInitial ?></div>
    </div>
  </header>
  <div class="c-content">
