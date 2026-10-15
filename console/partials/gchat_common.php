<?php
/**
 * console/partials/gchat_common.php — helper bersama halaman Console › Global Chat
 * Dipakai setelah auth.php & sebelum partials/header.php.
 */
require_once dirname(__DIR__, 2) . '/lib/global_chat.php';
gc_ensure_schema($pdo);
gc_ensure_home_tile($pdo);

const GC_PAGES = [
    'global_chat'          => ['Moderasi', 'ph-shield-check'],
    'global_chat_settings' => ['Pengaturan & Izin', 'ph-sliders-horizontal'],
    'global_chat_users'    => ['User Khusus', 'ph-user-gear'],
];

function gc_tabs(string $active): void {
    echo '<div class="gc-tabs">';
    foreach (GC_PAGES as $k => [$l, $i]) {
        echo '<a href="/console/' . $k . '.php" class="gc-tab' . ($k === $active ? ' active' : '') . '"><i class="ph-bold ' . $i . '"></i> ' . htmlspecialchars($l) . '</a>';
    }
    echo '</div>';
}

function gc_flash(string $msg, string $type = 'success'): void {
    if ($msg === '') return;
    echo '<div class="alert alert-' . ($type === 'error' ? 'danger' : 'success') . ' py-2 px-3" style="font-size:13px;border-radius:10px">' . htmlspecialchars($msg) . '</div>';
}

/** Cari user dari ID atau username */
function gc_find_user(PDO $pdo, string $q): ?array {
    $q = trim($q);
    if ($q === '') return null;
    $s = ctype_digit($q) ? $pdo->prepare("SELECT * FROM users WHERE id = ?") : $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $s->execute([ctype_digit($q) ? (int)$q : $q]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gc_tristate($v): string {
    return $v === null ? '<span class="gc-pill" style="color:#94a3b8">ikut pangkat</span>'
        : ((int)$v ? '<span class="gc-pill" style="color:#34d399">izinkan</span>' : '<span class="gc-pill" style="color:#fb7185">larang</span>');
}

function gc_styles(): void { ?>
<style>
.gc-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
.gc-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; border: 1px solid #1f2235; background: #121524; color: #94a3b8; font-size: 12.5px; font-weight: 600; text-decoration: none; }
.gc-tab:hover { color: #f8fafc; border-color: rgba(34, 211, 238, .4); }
.gc-tab.active { background: linear-gradient(90deg, rgba(34, 211, 238, .18), rgba(34, 211, 238, .04)); color: #67e8f9; border-color: rgba(34, 211, 238, .45); font-weight: 700; }
.gc-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; border: 1px solid currentColor; white-space: nowrap; }
.gc-muted { color: #64748b; font-size: 12px; }
.gc-msg-img { max-width: 120px; max-height: 90px; border-radius: 8px; display: block; margin-top: 4px; }
.gc-status { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-radius: 14px; border: 1px solid #1f2235; background: #121524; margin-bottom: 14px; }
.gc-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.gc-matrix th, .gc-matrix td { text-align: center; vertical-align: middle; }
.gc-matrix td:first-child, .gc-matrix th:first-child { text-align: left; }
.gc-matrix input[type=number] { max-width: 90px; margin: 0 auto; }
</style>
<?php }
