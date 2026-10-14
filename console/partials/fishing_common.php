<?php
/**
 * console/partials/fishing_common.php — helper bersama halaman Console › Fish System
 * Dipakai oleh console/fishing_*.php (setelah auth.php & sebelum header.php).
 */
require_once dirname(__DIR__, 2) . '/lib/fishing.php';
fishing_ensure_schema($pdo);

const FS_PAGES = [
    'fishing_dashboard' => ['Dashboard',        'ph-chart-line-up'],
    'fishing_species'   => ['Harga Ikan',       'ph-tag'],
    'fishing_catches'   => ['Log Tangkapan',    'ph-fish-simple'],
    'fishing_sales'     => ['Penjualan',        'ph-hand-coins'],
    'fishing_baits'     => ['Umpan',            'ph-bug'],
    'fishing_rods'      => ['Joran',            'ph-magic-wand'],
    'fishing_market'    => ['Harga Pasar',      'ph-scales'],
    'fishing_players'   => ['Pemain',           'ph-users-three'],
    'fishing_settings'  => ['Pengaturan & Audit', 'ph-gear-six'],
];
const FS_BADGE_COLOR = ['biasa' => '#22d3ee', 'bagus' => '#34d399', 'langka' => '#fb7185', 'super' => '#a78bfa', 'jackpot' => '#fbbf24'];

function fs_badge(string $badge): string {
    $c = FS_BADGE_COLOR[$badge] ?? '#94a3b8';
    return '<span class="fs-pill" style="background:' . $c . '22;color:' . $c . ';border-color:' . $c . '55">' . htmlspecialchars(FISHING_BADGE_LABEL[$badge] ?? $badge) . '</span>';
}

function fs_tabs(string $active): void {
    echo '<div class="fs-tabs">';
    foreach (FS_PAGES as $key => [$label, $icon]) {
        echo '<a href="/console/' . $key . '.php" class="fs-tab' . ($key === $active ? ' active' : '') . '"><i class="ph-bold ' . $icon . '"></i> ' . htmlspecialchars($label) . '</a>';
    }
    echo '</div>';
}

function fs_flash(string $msg, string $type = 'success'): void {
    if ($msg === '') return;
    echo '<div class="alert alert-' . ($type === 'error' ? 'danger' : 'success') . ' py-2 px-3" style="font-size:13px;border-radius:10px">' . htmlspecialchars($msg) . '</div>';
}

/** Ambil user (id/username) untuk filter pencarian */
function fs_find_user_id(PDO $pdo, string $q): ?int {
    $q = trim($q);
    if ($q === '') return null;
    if (ctype_digit($q)) return (int)$q;
    $s = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $s->execute([$q]);
    $id = $s->fetchColumn();
    return $id ? (int)$id : -1;
}

/** Ekspresi SQL: kolom rarity (Common/Uncommon/...) → badge (biasa/bagus/...) */
const FS_BADGE_SQL = "CASE UPPER(rarity) WHEN 'UNCOMMON' THEN 'bagus' WHEN 'RARE' THEN 'langka' WHEN 'EPIC' THEN 'super' WHEN 'JACKPOT' THEN 'jackpot' ELSE 'biasa' END";

function fs_head(string $title, string $sub = ''): void {
    echo '<div class="mb-3"><h4 class="mb-1 text-white" style="font-weight:800">🎣 ' . htmlspecialchars($title) . '</h4>'
       . ($sub !== '' ? '<div class="fs-muted">' . htmlspecialchars($sub) . '</div>' : '') . '</div>';
}

function fs_stat(string $label, string $value, string $icon, string $color, string $sub = ''): void { ?>
  <div class="c-stat h-100" style="border-top:3px solid <?= $color ?>">
    <div class="d-flex justify-content-between align-items-start mb-2">
      <div class="c-stat__lbl"><?= htmlspecialchars($label) ?></div>
      <div class="c-stat__icon" style="background:<?= $color ?>22;color:<?= $color ?>;font-size:18px"><i class="ph-bold <?= $icon ?>"></i></div>
    </div>
    <div class="c-stat__val fs-num" style="color:<?= $color ?>"><?= $value ?></div>
    <?php if ($sub !== ''): ?><div class="fs-muted mt-1"><?= $sub ?></div><?php endif; ?>
  </div>
<?php }

function fs_user_link(int $id, ?string $username): string {
    return '<a href="/console/fishing_players.php?u=' . $id . '" style="color:#e2e8f0;font-weight:700;text-decoration:none">'
         . htmlspecialchars($username ?? ('#' . $id)) . '</a> <span class="fs-muted">#' . $id . '</span>';
}

function fs_styles(): void { ?>
<style>
.fs-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px; }
.fs-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; border: 1px solid var(--border-color, #1f2235); background: #121524; color: #94a3b8; font-size: 12.5px; font-weight: 600; text-decoration: none; }
.fs-tab:hover { color: #f8fafc; border-color: rgba(34, 211, 238, .4); }
.fs-tab.active { background: linear-gradient(90deg, rgba(34, 211, 238, .18), rgba(34, 211, 238, .04)); color: #67e8f9; border-color: rgba(34, 211, 238, .45); font-weight: 700; }
.fs-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; border: 1px solid; white-space: nowrap; }
.fs-muted { color: #64748b; font-size: 12px; }
.fs-bar { height: 8px; border-radius: 999px; background: #1f2235; overflow: hidden; }
.fs-bar > i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #22d3ee, #34d399); }
.fs-chart { display: flex; align-items: flex-end; gap: 4px; height: 140px; padding-top: 10px; }
.fs-chart .col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 0; }
.fs-chart .bar { width: 100%; border-radius: 6px 6px 2px 2px; background: linear-gradient(180deg, #22d3ee, #0e7490); min-height: 2px; }
.fs-chart .bar.alt { background: linear-gradient(180deg, #fbbf24, #b45309); }
.fs-chart small { font-size: 9.5px; color: #64748b; white-space: nowrap; }
.fs-chart .val { font-size: 9.5px; color: #94a3b8; }
.fs-filter { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; }
.fs-filter > div { min-width: 140px; flex: 1; }
.fs-num { font-variant-numeric: tabular-nums; }
.fs-mk { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 8px; }
.fs-mk > div { background: #0f1117; border: 1px solid #1f2235; border-radius: 12px; padding: 10px; text-align: center; }
.fs-mk b { font-size: 18px; }
.up { color: #34d399; } .down { color: #fb7185; } .flat { color: #94a3b8; }
@media (max-width: 768px) { .fs-mk { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<?php }
