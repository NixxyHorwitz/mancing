<?php
declare(strict_types=1);

/**
 * Status antrian withdraw per level (pangkat): Normal / Ramai / Overload.
 * Diatur di Console › Pengaturan Withdraw, ditampilkan di halaman Withdraw
 * hanya untuk user di level tersebut.
 *
 * Setting `wd_level_status` (JSON): { "<membership_id>": { mode, busy_at, overload_at, eta, message, show_normal } }
 *   mode: auto | normal | busy | overload  (auto = berdasar jumlah WD pending/hold level itu)
 */

const WD_LEVEL_STATES = [
    'normal'   => ['label' => 'Lancar',            'icon' => 'ph-check-circle',   'eta' => '1–10 menit',
                   'msg' => 'Antrian penarikan level kamu lancar. Penarikan diproses seperti biasa.'],
    'busy'     => ['label' => 'Ramai',             'icon' => 'ph-hourglass-medium', 'eta' => '1–3 jam',
                   'msg' => 'Antrian penarikan level kamu sedang ramai. Pengajuan tetap diproses berurutan, mohon sabar ya.'],
    'overload' => ['label' => 'Withdraw Overload', 'icon' => 'ph-warning-octagon', 'eta' => '6–24 jam',
                   'msg' => 'Antrian penarikan level kamu sedang penuh (overload). Pengajuan tetap diterima dan diproses sesuai urutan.'],
];

/** Konfigurasi tersimpan untuk semua level. */
function wd_level_config(PDO $pdo): array {
    $cfg = json_decode(setting($pdo, 'wd_level_status', '{}'), true);
    return is_array($cfg) ? $cfg : [];
}

/** Jumlah & total WD pending/hold per membership_id (level gratis memakai id level harga 0). */
function wd_level_queue(PDO $pdo): array {
    $free_id = (int)$pdo->query("SELECT id FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
    $out = [];
    $rows = $pdo->query("
        SELECT CASE WHEN u.membership_id IS NOT NULL AND u.membership_expires_at > NOW() THEN u.membership_id ELSE 0 END lvl,
               COUNT(*) n, COALESCE(SUM(w.amount), 0) s, SUM(w.status = 'pending') p, SUM(w.status = 'hold') h
        FROM withdrawals w JOIN users u ON u.id = w.user_id
        WHERE w.status IN ('pending', 'hold')
        GROUP BY lvl")->fetchAll();
    foreach ($rows as $r) {
        $id = (int)$r['lvl'] ?: $free_id;
        $cur = $out[$id] ?? ['n' => 0, 'sum' => 0.0, 'pending' => 0, 'hold' => 0];
        $out[$id] = ['n' => $cur['n'] + (int)$r['n'], 'sum' => $cur['sum'] + (float)$r['s'],
                     'pending' => $cur['pending'] + (int)$r['p'], 'hold' => $cur['hold'] + (int)$r['h']];
    }
    return $out;
}

/**
 * Status akhir satu level.
 * @return array{state:string,label:string,icon:string,eta:string,message:string,queue:int,show:bool,mode:string}
 */
function wd_level_status(PDO $pdo, int $membership_id, ?array $queue = null, ?array $cfgAll = null): array {
    $cfgAll ??= wd_level_config($pdo);
    $queue  ??= wd_level_queue($pdo);
    $c = $cfgAll[(string)$membership_id] ?? [];
    $mode = $c['mode'] ?? 'auto';
    $n = (int)($queue[$membership_id]['n'] ?? 0);

    if ($mode === 'auto') {
        $busyAt = max(1, (int)($c['busy_at'] ?? 10));
        $overAt = max($busyAt + 1, (int)($c['overload_at'] ?? 25));
        $state = $n >= $overAt ? 'overload' : ($n >= $busyAt ? 'busy' : 'normal');
    } else {
        $state = isset(WD_LEVEL_STATES[$mode]) ? $mode : 'normal';
    }
    $def = WD_LEVEL_STATES[$state];
    $msgs = $c['messages'] ?? [];
    $etas = $c['etas'] ?? [];
    return [
        'state'   => $state,
        'mode'    => $mode,
        'label'   => $def['label'],
        'icon'    => $def['icon'],
        'eta'     => trim((string)($etas[$state] ?? '')) ?: $def['eta'],
        'message' => trim((string)($msgs[$state] ?? '')) ?: $def['msg'],
        'queue'   => $n,
        'show'    => $state !== 'normal' || !empty($c['show_normal']),
    ];
}
