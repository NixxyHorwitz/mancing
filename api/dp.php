<?php
declare(strict_types=1);
/**
 * Deposit Manager — Telegram Mini App (dibuka lewat /dp di chat pribadi bot).
 * Tanpa login: identitas diambil dari initData Telegram yang ditandatangani token bot,
 * lalu dicek harus anggota grup admin (tg_chat_id).
 */
require_once dirname(__DIR__) . '/bootstrap.php';

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$action = $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $out = static function (array $data, int $code = 200): never {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    };

    $tg = tg_webapp_user($pdo, (string)($_SERVER['HTTP_X_TG_INIT'] ?? ''));
    if (!$tg) $out(['ok' => false, 'error' => 'Sesi Telegram tidak valid. Buka lewat perintah /dp di bot.'], 401);
    if (!tg_dp_allowed($pdo, (int)$tg['id'])) $out(['ok' => false, 'error' => 'Akses ditolak. Khusus anggota grup admin.'], 403);
    depo_ensure_schema($pdo);
    $actor = 'Mini App @' . ($tg['username'] ?? $tg['first_name'] ?? $tg['id']);

    // ── Daftar deposit + ringkasan ───────────────────────────────────────────
    if ($action === 'list') {
        $tab    = $_GET['tab'] ?? 'pending';
        $q      = trim((string)($_GET['q'] ?? ''));
        $method = $_GET['method'] ?? 'all';
        $sort   = $_GET['sort'] ?? '';
        $exp    = $pdo->quote(DEPO_EXPIRED_NOTE);

        $where = match ($tab) {
            'expired'   => "d.status = 'rejected' AND d.admin_note = {$exp}",
            'confirmed' => "d.status = 'confirmed'",
            'nc'        => "d.status = 'confirmed' AND d.no_commission = 1",
            'rejected'  => "d.status = 'rejected' AND (d.admin_note IS NULL OR d.admin_note <> {$exp})",
            'today'     => "(d.created_at >= CURDATE() OR d.confirmed_at >= CURDATE())",
            'all'       => '1=1',
            default     => "d.status = 'pending'",
        };
        if ($method === 'qris')     $where .= " AND d.method = 'qris'";
        if ($method === 'transfer') $where .= " AND d.method <> 'qris'";

        $params = [];
        if ($q !== '') {
            $digits = preg_replace('/\D/', '', $q);
            $where .= " AND (u.username LIKE ? OR up.username LIKE ?" . ($digits !== '' ? " OR d.id = ? OR d.user_id = ? OR CAST(d.amount AS UNSIGNED) LIKE ?" : '') . ")";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
            if ($digits !== '') { $params[] = (int)$digits; $params[] = (int)$digits; $params[] = '%' . $digits . '%'; }
        }
        $order = match ($sort) {
            'old'   => 'd.id ASC',
            'new'   => 'd.id DESC',
            'big'   => 'd.amount DESC, d.id DESC',
            'small' => 'd.amount ASC, d.id DESC',
            default => $tab === 'pending' ? 'd.id ASC' : (in_array($tab, ['confirmed', 'nc'], true) ? 'd.confirmed_at DESC' : 'd.id DESC'),
        };

        $st = $pdo->prepare("
            SELECT d.id, d.user_id, d.amount, d.method, d.status, d.admin_note, d.proof_image, d.no_commission, d.tg_msg_id,
                   UNIX_TIMESTAMP(d.created_at) AS created_ts, UNIX_TIMESTAMP(d.confirmed_at) AS confirmed_ts,
                   u.username, u.balance_dep, u.balance_wd, UNIX_TIMESTAMP(u.created_at) AS joined_ts, u.is_flagged_abuse,
                   m.name AS member,
                   up.username AS upline, up.is_promotor AS upline_promotor,
                   (SELECT COUNT(*) FROM deposits d2 WHERE d2.user_id = d.user_id AND d2.status = 'confirmed' AND d2.id <> d.id) AS prev_ok,
                   (SELECT COALESCE(SUM(d3.amount), 0) FROM deposits d3 WHERE d3.user_id = d.user_id AND d3.status = 'confirmed') AS user_total
            FROM deposits d
            JOIN users u ON u.id = d.user_id
            LEFT JOIN memberships m ON m.id = u.membership_id
            LEFT JOIN users up ON up.referral_code = u.referred_by AND u.referred_by <> ''
            WHERE {$where}
            ORDER BY {$order}
            LIMIT 100
        ");
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $is_exp = $r['status'] === 'rejected' && $r['admin_note'] === DEPO_EXPIRED_NOTE;
            $items[] = [
                'id'        => (int)$r['id'],
                'uid'       => (int)$r['user_id'],
                'user'      => $r['username'],
                'amount'    => (float)$r['amount'],
                'method'    => $r['method'] === 'qris' ? 'QRIS' : 'Transfer',
                'status'    => $is_exp ? 'expired' : $r['status'],
                'note'      => $is_exp ? '' : (string)($r['admin_note'] ?? ''),
                'proof'     => $r['proof_image'] ? '/uploads/' . ltrim($r['proof_image'], '/') : '',
                'nc'        => (int)$r['no_commission'] === 1,
                'tg'        => !empty($r['tg_msg_id']),
                'created'   => (int)$r['created_ts'],
                'confirmed' => (int)$r['confirmed_ts'],
                'upline'    => $r['upline'],
                'promotor'  => (int)$r['upline_promotor'] === 1,
                'prev_ok'   => (int)$r['prev_ok'],
                'bal_dep'   => (float)$r['balance_dep'],
                'bal_wd'    => (float)$r['balance_wd'],
                'member'    => $r['member'] ?: 'Gratis',
                'joined'    => (int)$r['joined_ts'],
                'abuse'     => (int)$r['is_flagged_abuse'] === 1,
                'user_total'=> (float)$r['user_total'],
            ];
        }

        $s = $pdo->query("
            SELECT
              SUM(status = 'pending') AS p_n,
              COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) AS p_sum,
              MIN(CASE WHEN status = 'pending' THEN UNIX_TIMESTAMP(created_at) END) AS p_oldest,
              SUM(status = 'confirmed' AND confirmed_at >= CURDATE()) AS c_n,
              COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= CURDATE() THEN amount END), 0) AS c_sum,
              COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= CURDATE() - INTERVAL 1 DAY AND confirmed_at < CURDATE() THEN amount END), 0) AS y_sum,
              SUM(status = 'confirmed' AND confirmed_at >= CURDATE() AND no_commission = 1) AS nc_n,
              COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= CURDATE() AND no_commission = 1 THEN amount END), 0) AS nc_sum,
              SUM(status = 'rejected' AND admin_note = {$exp} AND created_at >= CURDATE()) AS e_n,
              SUM(status = 'rejected' AND (admin_note IS NULL OR admin_note <> {$exp}) AND created_at >= CURDATE()) AS r_n,
              SUM(created_at >= CURDATE()) AS new_n
            FROM deposits
            WHERE status = 'pending' OR created_at >= CURDATE() - INTERVAL 1 DAY OR confirmed_at >= CURDATE() - INTERVAL 1 DAY
        ")->fetch();

        $hours = array_fill(0, 24, 0.0);
        foreach ($pdo->query("SELECT HOUR(confirmed_at) h, SUM(amount) s FROM deposits WHERE status = 'confirmed' AND confirmed_at >= CURDATE() GROUP BY h") as $h) {
            $hours[(int)$h['h']] = (float)$h['s'];
        }
        $first_today = (int)$pdo->query("
            SELECT COUNT(DISTINCT d.user_id) FROM deposits d
            WHERE d.status = 'confirmed' AND d.confirmed_at >= CURDATE()
              AND NOT EXISTS (SELECT 1 FROM deposits o WHERE o.user_id = d.user_id AND o.status = 'confirmed' AND o.confirmed_at < CURDATE())
        ")->fetchColumn();

        $stats = [
            'pending' => (int)$s['p_n'], 'pending_sum' => (float)$s['p_sum'], 'pending_oldest' => (int)$s['p_oldest'],
            'today' => (int)$s['c_n'], 'today_sum' => (float)$s['c_sum'], 'yesterday_sum' => (float)$s['y_sum'],
            'nc' => (int)$s['nc_n'], 'nc_sum' => (float)$s['nc_sum'],
            'expired' => (int)$s['e_n'], 'rejected' => (int)$s['r_n'], 'created' => (int)$s['new_n'],
            'first' => $first_today, 'hours' => $hours,
        ];
        $out(['ok' => true, 'stats' => $stats, 'items' => $items, 'sig' => md5(json_encode([$stats, $items])),
              'now' => time(), 'expire_hours' => DEPO_EXPIRE_HOURS, 'me' => $actor]);
    }

    // ── Aksi ─────────────────────────────────────────────────────────────────
    if ($action === 'act' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in  = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id  = (int)($in['id'] ?? 0);
        $do  = (string)($in['do'] ?? '');
        if ($id <= 0) $out(['ok' => false, 'error' => 'ID deposit tidak valid.'], 400);

        if ($do === 'confirm' || $do === 'confirm_nc') {
            $nc  = $do === 'confirm_nc';
            $res = depo_admin_confirm($pdo, $id, !$nc, $actor);
            if (!$res['ok']) $out(['ok' => false, 'error' => $res['message']]);
            $dep = $res['dep'];
            if (!empty($dep['tg_msg_id'])) {
                $label = $res['was'] . ($nc ? ' tanpa komisi' : '') . ' via ' . $actor;
                $foot  = $nc ? 'Saldo masuk ke user. Tidak ada komisi referral dan tidak dihitung untuk promotor.'
                             : 'Deposit disetujui admin lewat Deposit Manager.';
                edit_telegram_notif($pdo, (int)$dep['tg_msg_id'], depo_confirmed_tg_text($dep, (float)$res['comm'], $label, $foot), []);
            }
            $msg = '#' . $id . ' ' . $res['message'] . ((float)$res['comm'] > 0 ? ' Komisi ' . format_rp((float)$res['comm']) . ' ke upline.' : '');
            $out(['ok' => true, 'message' => $msg]);
        }

        if ($do === 'reject') {
            $res = depo_admin_reject($pdo, $id, mb_substr(trim((string)($in['reason'] ?? '')), 0, 200));
            if (!$res['ok']) $out(['ok' => false, 'error' => $res['message']]);
            if (!empty($res['dep']['tg_msg_id'])) {
                edit_telegram_notif($pdo, (int)$res['dep']['tg_msg_id'], depo_rejected_tg_text($res['dep'], $actor), []);
            }
            $out(['ok' => true, 'message' => 'Deposit #' . $id . ' ditolak.']);
        }

        $out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
    }

    $out(['ok' => false, 'error' => 'Not found'], 404);
}

header('Cache-Control: no-store');
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title>Deposit Manager</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root {
  --bg: #0a0f1c; --card: #111827; --card2: #172033; --line: #1f2a3d; --line2: #2a3850;
  --tx: #e5edf7; --dim: #8b9ab3; --mute: #5d6b82;
  --cy: #22d3ee; --gr: #22c55e; --am: #f59e0b; --rd: #ef4444; --vi: #a78bfa; --bl: #60a5fa;
  color-scheme: dark;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; background: var(--bg); color: var(--tx); font: 13px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; }
button, input, select { font: inherit; color: inherit; }
.wrap { max-width: 1200px; margin: 0 auto; padding: 0 10px 70px; }
.num { font-variant-numeric: tabular-nums; }

/* Header */
.hdr { padding-top: 10px; }
.top { position: sticky; top: 0; z-index: 10; background: var(--bg); padding: 8px 0 2px; margin: 0 -10px; padding-left: 10px; padding-right: 10px; border-bottom: 1px solid var(--line); }
.head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.logo { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; background: linear-gradient(135deg, #0891b2, #6366f1); font-size: 15px; flex-shrink: 0; }
.head h1 { margin: 0; font-size: 15px; font-weight: 800; letter-spacing: -.2px; line-height: 1.1; }
.head h1 small { display: block; font-size: 10.5px; font-weight: 600; color: var(--dim); letter-spacing: 0; }
.head .sp { flex: 1; }
.live { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--dim); padding: 4px 8px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); white-space: nowrap; }
.live i { width: 7px; height: 7px; border-radius: 50%; background: var(--gr); box-shadow: 0 0 0 3px rgba(34,197,94,.2); animation: pulse 2s infinite; }
.live.off i { background: var(--rd); box-shadow: none; animation: none; }
@keyframes pulse { 50% { box-shadow: 0 0 0 5px rgba(34,197,94,.05); } }
.ibtn { width: 30px; height: 30px; border-radius: 9px; border: 1px solid var(--line); background: var(--card); display: grid; place-items: center; cursor: pointer; font-size: 14px; flex-shrink: 0; }
.ibtn:active { transform: scale(.92); }
.ibtn.spin { animation: spin .7s linear; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Stats */
.stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; margin-bottom: 6px; }
@media (min-width: 640px) { .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.st { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 8px 10px; min-width: 0; cursor: pointer; position: relative; overflow: hidden; }
.st::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--c, var(--cy)); }
.st small { display: flex; justify-content: space-between; gap: 4px; font-size: 10px; color: var(--dim); font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.st b { display: block; font-size: 18px; font-weight: 800; color: var(--c, var(--tx)); line-height: 1.2; margin-top: 2px; }
.st span { display: block; font-size: 10.5px; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.st .up { color: var(--gr); } .st .dn { color: var(--rd); }

.chart { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 7px 10px 6px; margin-bottom: 8px; }
.chart-h { display: flex; justify-content: space-between; font-size: 10px; color: var(--dim); font-weight: 700; margin-bottom: 4px; }
.bars { display: grid; grid-template-columns: repeat(24, 1fr); gap: 2px; align-items: end; height: 34px; }
.bars i { display: block; background: linear-gradient(var(--cy), #0e7490); border-radius: 2px 2px 0 0; min-height: 2px; opacity: .9; }
.bars i.z { background: var(--line2); }
.bars i.now { background: linear-gradient(#fbbf24, #d97706); }
.bars-x { display: grid; grid-template-columns: repeat(4, 1fr); font-size: 9px; color: var(--mute); margin-top: 2px; }
.bars-x span:last-child { text-align: right; }

/* Controls */
.ctl { display: flex; gap: 6px; margin-bottom: 6px; }
.search { flex: 1; min-width: 0; padding: 8px 11px 8px 30px; border-radius: 10px; border: 1px solid var(--line); background: var(--card) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%238b9ab3' stroke-width='2'%3E%3Ccircle cx='6' cy='6' r='4.5'/%3E%3Cpath d='m10 10 3 3'/%3E%3C/svg%3E") no-repeat 10px center; outline: none; }
.search:focus, .sel:focus { border-color: var(--cy); }
.sel { padding: 0 8px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); outline: none; max-width: 112px; }
.tabs { display: flex; gap: 4px; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
.tabs::-webkit-scrollbar { display: none; }
.tab { flex: 0 0 auto; padding: 6px 11px; border-radius: 999px; border: 1px solid var(--line); background: var(--card); color: var(--dim); font-weight: 700; font-size: 12px; cursor: pointer; white-space: nowrap; }
.tab.on { background: var(--cy); border-color: var(--cy); color: #04222a; }
.tab em { font-style: normal; font-size: 10px; margin-left: 4px; padding: 0 5px; border-radius: 999px; background: rgba(0,0,0,.25); }
.seg { display: inline-flex; border: 1px solid var(--line); border-radius: 999px; overflow: hidden; flex-shrink: 0; }
.seg button { border: 0; background: var(--card); color: var(--dim); padding: 6px 9px; font-size: 11px; font-weight: 700; cursor: pointer; }
.seg button.on { background: var(--card2); color: var(--tx); }
.meta { display: flex; justify-content: space-between; align-items: center; gap: 6px; font-size: 11px; color: var(--dim); margin: 8px 2px 6px; }
.top .ctl:last-child { margin-bottom: 4px; }

/* List */
.list { display: grid; grid-template-columns: 1fr; gap: 7px; }
@media (min-width: 760px) { .list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1100px) { .list { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 13px; padding: 9px 10px; border-left: 3px solid var(--sc, var(--line2)); display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.card.pending { --sc: var(--am); } .card.expired { --sc: var(--mute); } .card.confirmed { --sc: var(--gr); } .card.rejected { --sc: var(--rd); }
.card.flash { animation: fl 1.6s ease; }
@keyframes fl { from { background: #13324a; } to { background: var(--card); } }
.r1 { display: flex; align-items: center; gap: 7px; min-width: 0; }
.av { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; font-weight: 800; font-size: 13px; color: #fff; flex-shrink: 0; text-transform: uppercase; }
.who { flex: 1; min-width: 0; line-height: 1.2; }
.who b { display: block; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.who span { display: block; font-size: 10.5px; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.amt { text-align: right; flex-shrink: 0; line-height: 1.2; cursor: pointer; }
.amt b { display: block; font-size: 15px; font-weight: 800; letter-spacing: -.3px; }
.amt span { display: block; font-size: 10px; color: var(--dim); }
.chips { display: flex; flex-wrap: wrap; gap: 4px; }
.chip { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 999px; background: var(--card2); color: var(--dim); border: 1px solid var(--line); white-space: nowrap; }
.chip.s-pending { color: #fcd34d; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.3); }
.chip.s-expired { color: #cbd5e1; background: rgba(148,163,184,.12); border-color: rgba(148,163,184,.25); }
.chip.s-confirmed { color: #86efac; background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.3); }
.chip.s-rejected { color: #fca5a5; background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); }
.chip.prm { color: #c4b5fd; background: rgba(167,139,250,.12); border-color: rgba(167,139,250,.3); }
.chip.nc { color: #f9a8d4; background: rgba(236,72,153,.12); border-color: rgba(236,72,153,.3); }
.chip.new { color: #93c5fd; background: rgba(96,165,250,.12); border-color: rgba(96,165,250,.3); }
.chip.ab { color: #fff; background: var(--rd); border-color: var(--rd); }
.chip a { color: inherit; text-decoration: none; }
.exp { display: flex; align-items: center; gap: 6px; font-size: 10px; color: var(--dim); }
.exp .bar { flex: 1; height: 4px; border-radius: 999px; background: var(--line); overflow: hidden; }
.exp .bar i { display: block; height: 100%; background: var(--am); border-radius: 999px; }
.exp.late .bar i { background: var(--rd); } .exp.late b { color: #fca5a5; }
.note { font-size: 11px; color: var(--dim); background: var(--card2); border-radius: 8px; padding: 5px 8px; word-break: break-word; }
.det { display: none; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 10px; background: var(--card2); border-radius: 9px; padding: 7px 9px; font-size: 11px; }
.card.open .det { display: grid; }
.det div { display: flex; justify-content: space-between; gap: 6px; min-width: 0; }
.det span { color: var(--dim); white-space: nowrap; }
.det b { font-weight: 700; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.acts { display: grid; grid-template-columns: 34px minmax(0, 1fr) minmax(0, 1.35fr) minmax(0, 1fr); gap: 5px; }
.acts.two { grid-template-columns: 34px minmax(0, 1fr) minmax(0, 1fr); }
.acts.one { grid-template-columns: 34px 1fr; }
.b { height: 34px; border: 0; border-radius: 9px; font-weight: 800; font-size: 11.5px; cursor: pointer; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 6px; }
.b:active { transform: scale(.97); }
.b:disabled { opacity: .45; }
.b.ok { background: #16a34a; } .b.nc { background: #7c3aed; } .b.no { background: #b91c1c; }
.b.gh { background: var(--card2); color: var(--dim); border: 1px solid var(--line); font-size: 13px; }
.b.gh.txt { font-size: 11px; }

.empty { grid-column: 1 / -1; text-align: center; padding: 40px 12px; color: var(--dim); }
.empty b { display: block; font-size: 26px; margin-bottom: 6px; }
.err { grid-column: 1 / -1; text-align: center; padding: 40px 14px; color: var(--dim); }
.err b { display: block; color: var(--tx); font-size: 14px; margin-bottom: 4px; }
.skel { height: 116px; border-radius: 13px; background: linear-gradient(90deg, var(--card) 25%, var(--card2) 50%, var(--card) 75%); background-size: 200% 100%; animation: sk 1.2s infinite; }
@keyframes sk { to { background-position: -200% 0; } }

.toast { position: fixed; left: 50%; bottom: calc(16px + env(safe-area-inset-bottom)); transform: translate(-50%, 30px); opacity: 0; background: #0f766e; color: #fff; padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 700; transition: .25s; max-width: 92%; z-index: 40; pointer-events: none; box-shadow: 0 8px 24px rgba(0,0,0,.4); }
.toast.on { transform: translate(-50%, 0); opacity: 1; }
.toast.bad { background: #b91c1c; }

.sheet-bg { position: fixed; inset: 0; background: rgba(2,6,15,.7); display: none; align-items: flex-end; justify-content: center; z-index: 30; }
.sheet-bg.on { display: flex; }
.sheet { width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line); border-bottom: 0; border-radius: 16px 16px 0 0; padding: 14px 12px calc(14px + env(safe-area-inset-bottom)); }
.sheet h3 { margin: 0 0 3px; font-size: 14px; }
.sheet p { margin: 0 0 9px; font-size: 12px; color: var(--dim); }
.sheet textarea { width: 100%; min-height: 64px; padding: 9px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); resize: vertical; outline: none; }
.sheet textarea:focus { border-color: var(--rd); }
.quick { display: flex; flex-wrap: wrap; gap: 5px; margin: 8px 0 11px; }
.quick button { border: 1px solid var(--line); background: var(--card2); border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 600; cursor: pointer; }
.sheet .row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.foot { text-align: center; font-size: 10.5px; color: var(--mute); margin-top: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <div class="head">
      <div class="logo">💳</div>
      <h1>Deposit Manager<small id="me">Memuat sesi…</small></h1>
      <span class="sp"></span>
      <span class="live" id="live"><i></i><span id="live-t">Live</span></span>
      <button class="ibtn" id="refresh" title="Muat ulang">⟳</button>
    </div>

    <div class="stats">
      <div class="st" style="--c: var(--am)" data-go="pending"><small>Pending <em id="s-old"></em></small><b class="num" id="s-p">–</b><span class="num" id="s-ps">&nbsp;</span></div>
      <div class="st" style="--c: var(--gr)" data-go="confirmed"><small>Sukses hari ini <em id="s-cmp"></em></small><b class="num" id="s-c">–</b><span class="num" id="s-cs">&nbsp;</span></div>
      <div class="st" style="--c: #f472b6" data-go="nc"><small>Tanpa komisi</small><b class="num" id="s-nc">–</b><span class="num" id="s-ncs">hari ini</span></div>
      <div class="st" style="--c: var(--dim)" data-go="expired"><small>Gagal hari ini</small><b class="num" id="s-e">–</b><span class="num" id="s-es">&nbsp;</span></div>
    </div>

    <div class="chart">
      <div class="chart-h"><span>Masuk per jam (hari ini)</span><span class="num" id="ch-sum"></span></div>
      <div class="bars" id="bars"></div>
      <div class="bars-x"><span>00</span><span>06</span><span>12</span><span>18–23</span></div>
    </div>
  </div>

  <div class="top">
    <div class="ctl">
      <input class="search" id="q" type="search" placeholder="Cari user, upline, ID, nominal…" autocomplete="off">
      <select class="sel" id="sort" title="Urutkan">
        <option value="">Urutan</option>
        <option value="new">Terbaru</option>
        <option value="old">Terlama</option>
        <option value="big">Nominal besar</option>
        <option value="small">Nominal kecil</option>
      </select>
    </div>
    <div class="ctl">
      <div class="tabs" id="tabs">
        <button class="tab on" data-t="pending">⏳ Pending<em id="t-p">0</em></button>
        <button class="tab" data-t="today">📅 Hari ini</button>
        <button class="tab" data-t="confirmed">✅ Sukses</button>
        <button class="tab" data-t="nc">🚫 Tanpa Komisi</button>
        <button class="tab" data-t="expired">⌛ Expired</button>
        <button class="tab" data-t="rejected">❌ Ditolak</button>
        <button class="tab" data-t="all">Semua</button>
      </div>
    </div>
  </div>

  <div class="meta">
    <span id="meta-l">&nbsp;</span>
    <div class="seg" id="method">
      <button class="on" data-m="all">Semua</button><button data-m="qris">QRIS</button><button data-m="transfer">Transfer</button>
    </div>
  </div>

  <div class="list" id="list"><div class="skel"></div><div class="skel"></div><div class="skel"></div></div>
  <div class="foot">Data diperbarui otomatis tiap 4 detik · aksi tersinkron ke pesan grup Telegram</div>
</div>

<div class="toast" id="toast"></div>

<div class="sheet-bg" id="sheet">
  <div class="sheet">
    <h3>❌ Tolak deposit</h3>
    <p id="sh-p"></p>
    <textarea id="sh-r" placeholder="Alasan penolakan (opsional, terlihat oleh user)"></textarea>
    <div class="quick">
      <button type="button">Dana belum masuk</button>
      <button type="button">Nominal tidak sesuai</button>
      <button type="button">Bukti tidak valid</button>
      <button type="button">Deposit ganda</button>
      <button type="button">Kode unik salah</button>
    </div>
    <div class="row">
      <button class="b gh txt" id="sh-x" type="button">Batal</button>
      <button class="b no" id="sh-ok" type="button">Tolak Deposit</button>
    </div>
  </div>
</div>

<script>
(() => {
  const tg = window.Telegram && Telegram.WebApp;
  const INIT = tg ? tg.initData : '';
  if (tg) {
    tg.ready(); tg.expand();
    try { tg.setHeaderColor('#0a0f1c'); tg.setBackgroundColor('#0a0f1c'); } catch (e) {}
  }

  const $ = id => document.getElementById(id);
  const rp = n => 'Rp ' + Math.round(n).toLocaleString('id-ID');
  const rpS = n => n >= 1e6 ? 'Rp ' + (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + 'jt'
                 : n >= 1e3 ? 'Rp ' + (n / 1e3).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + 'rb' : rp(n);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const haptic = t => { try { tg && tg.HapticFeedback.notificationOccurred(t); } catch (e) {} };
  const nowS = () => Math.floor(Date.now() / 1000) - skew;
  const COLORS = ['#0891b2', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#2563eb', '#ca8a04', '#0d9488'];

  let tab = 'pending', q = '', method = 'all', sort = '';
  let sig = '', skew = 0, busy = false, timer = null, expH = 3;
  let known = new Set(), first = true, cache = {}, lastJ = null, open = new Set();

  const dur = s => {
    s = Math.max(0, Math.floor(s));
    if (s < 60) return s + 'd';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'j ' + Math.floor((s % 3600) / 60) + 'm';
    return Math.floor(s / 86400) + 'h ' + Math.floor((s % 86400) / 3600) + 'j';
  };
  const ago = ts => ts ? dur(nowS() - ts) + ' lalu' : '-';
  const dt = ts => {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) + ' ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  };

  function toast(msg, bad) {
    const t = $('toast');
    t.textContent = msg; t.className = 'toast on' + (bad ? ' bad' : '');
    clearTimeout(t._h); t._h = setTimeout(() => t.className = 'toast', 2800);
  }
  async function copy(text, label) {
    try { await navigator.clipboard.writeText(String(text)); toast('📋 ' + label + ' disalin'); haptic('success'); }
    catch (e) { toast('Gagal menyalin', true); }
  }

  async function api(action, params = {}, body = null) {
    const r = await fetch('?action=' + action + '&' + new URLSearchParams(params), {
      method: body ? 'POST' : 'GET',
      headers: Object.assign({ 'X-Tg-Init': INIT }, body ? { 'Content-Type': 'application/json' } : {}),
      body: body ? JSON.stringify(body) : undefined,
      cache: 'no-store'
    });
    const j = await r.json().catch(() => ({ ok: false, error: 'Respon server tidak valid (' + r.status + ').' }));
    if (!r.ok) j.ok = false;
    return j;
  }

  const ST = { pending: 'Pending', expired: 'Expired', confirmed: 'Sukses', rejected: 'Ditolak' };

  function card(d) {
    const color = COLORS[d.uid % COLORS.length];
    const chips = [
      `<span class="chip s-${d.status}">${ST[d.status]}</span>`,
      `<span class="chip">${d.method}</span>`,
      d.prev_ok ? `<span class="chip">Depo ke-${d.prev_ok + 1}</span>` : `<span class="chip new">★ Depo pertama</span>`,
      d.upline ? `<span class="chip ${d.promotor ? 'prm' : ''}">${d.promotor ? '📣 ' : '↑ '}${esc(d.upline)}</span>` : '<span class="chip">Tanpa upline</span>',
      d.nc ? '<span class="chip nc">🚫 Tanpa komisi</span>' : '',
      d.abuse ? '<span class="chip ab">⚠ Abuse</span>' : '',
      d.proof ? `<span class="chip"><a href="${esc(d.proof)}" target="_blank" rel="noopener">📎 Bukti</a></span>` : ''
    ].join('');

    let expBar = '';
    if (d.status === 'pending') {
      const total = expH * 3600, used = nowS() - d.created, left = total - used;
      const pct = Math.min(100, Math.max(0, used / total * 100));
      expBar = `<div class="exp ${left < 1800 ? 'late' : ''}"><span>⌛</span><div class="bar"><i style="width:${pct}%"></i></div>
        <b class="num">${left > 0 ? 'expired ' + dur(left) + ' lagi' : 'segera expired'}</b></div>`;
    }

    const when = d.status === 'confirmed' && d.confirmed ? '✓ ' + ago(d.confirmed) : ago(d.created);
    const det = `<div class="det">
      <div><span>Saldo depo</span><b class="num">${rp(d.bal_dep)}</b></div>
      <div><span>Saldo WD</span><b class="num">${rp(d.bal_wd)}</b></div>
      <div><span>Pangkat</span><b>${esc(d.member)}</b></div>
      <div><span>Total depo</span><b class="num">${rpS(d.user_total)}</b></div>
      <div><span>Daftar</span><b>${dt(d.joined)}</b></div>
      <div><span>User ID</span><b class="num">${d.uid}</b></div>
      <div><span>Dibuat</span><b>${dt(d.created)}</b></div>
      <div><span>${d.status === 'confirmed' ? 'Dikonfirmasi' : 'Pesan grup'}</span><b>${d.status === 'confirmed' ? dt(d.confirmed) : (d.tg ? 'Terhubung' : 'Tidak ada')}</b></div>
    </div>`;

    const info = `<button class="b gh" data-do="info" data-id="${d.id}" title="Detail">${open.has(d.id) ? '▴' : 'ⓘ'}</button>`;
    let acts = `<div class="acts one">${info}<button class="b gh txt" data-do="copy" data-id="${d.id}">📋 Salin ringkasan</button></div>`;
    if (d.status === 'pending') {
      acts = `<div class="acts">${info}
        <button class="b ok" data-do="confirm" data-id="${d.id}">✓ Acc</button>
        <button class="b nc" data-do="confirm_nc" data-id="${d.id}">Tanpa Komisi</button>
        <button class="b no" data-do="reject" data-id="${d.id}">✕ Tolak</button></div>`;
    } else if (d.status === 'expired' || d.status === 'rejected') {
      acts = `<div class="acts two">${info}
        <button class="b ok" data-do="confirm" data-id="${d.id}">⚡ Acc ${d.status === 'expired' ? 'Expired' : 'Ulang'}</button>
        <button class="b nc" data-do="confirm_nc" data-id="${d.id}">Tanpa Komisi</button></div>`;
    }

    return `<div class="card ${d.status}${open.has(d.id) ? ' open' : ''}${!first && !known.has(d.id) ? ' flash' : ''}" id="c${d.id}">
      <div class="r1">
        <div class="av" style="background:${color}">${esc(d.user.slice(0, 1))}</div>
        <div class="who"><b>${esc(d.user)}</b><span class="num">#${d.id} · ${when}</span></div>
        <div class="amt" data-copy="${Math.round(d.amount)}"><b class="num">${rp(d.amount)}</b><span>tap untuk salin</span></div>
      </div>
      <div class="chips">${chips}</div>
      ${expBar}
      ${d.note ? `<div class="note">📝 ${esc(d.note)}</div>` : ''}
      ${det}
      ${acts}</div>`;
  }

  function renderStats(s) {
    $('s-p').textContent = s.pending;
    $('s-ps').textContent = rpS(s.pending_sum) + (s.created ? ' · ' + s.created + ' dibuat hari ini' : '');
    $('s-old').textContent = s.pending_oldest ? 'tertua ' + dur(nowS() - s.pending_oldest) : '';
    $('s-c').textContent = s.today;
    $('s-cs').textContent = rpS(s.today_sum) + (s.first ? ' · ' + s.first + ' user baru' : '');
    if (s.yesterday_sum > 0) {
      const diff = Math.round((s.today_sum - s.yesterday_sum) / s.yesterday_sum * 100);
      $('s-cmp').innerHTML = `<span class="${diff >= 0 ? 'up' : 'dn'}">${diff >= 0 ? '▲' : '▼'}${Math.abs(diff)}%</span>`;
      $('s-cmp').title = 'Dibanding kemarin seharian: ' + rp(s.yesterday_sum);
    } else $('s-cmp').textContent = '';
    $('s-nc').textContent = s.nc;
    $('s-ncs').textContent = s.nc ? rpS(s.nc_sum) + ' hari ini' : 'hari ini';
    $('s-e').textContent = s.expired + s.rejected;
    $('s-es').textContent = s.expired + ' expired · ' + s.rejected + ' ditolak';
    $('t-p').textContent = s.pending;

    const max = Math.max(1, ...s.hours), hr = new Date().getHours();
    $('bars').innerHTML = s.hours.map((v, h) =>
      `<i class="${h === hr ? 'now' : (v ? '' : 'z')}" style="height:${v ? Math.max(8, v / max * 100) : 6}%" title="${String(h).padStart(2, '0')}:00 · ${rp(v)}"></i>`).join('');
    $('ch-sum').textContent = rp(s.today_sum);
  }

  function render(j, force) {
    lastJ = j;
    cache = Object.fromEntries(j.items.map(d => [d.id, d]));
    renderStats(j.stats);
    const total = j.items.reduce((a, d) => a + d.amount, 0);
    $('meta-l').textContent = j.items.length ? `${j.items.length}${j.items.length >= 100 ? '+' : ''} deposit · ${rp(total)}` : '0 deposit';
    if (!force && j.sig === sig) return;
    sig = j.sig;
    const L = $('list');
    L.innerHTML = j.items.length ? j.items.map(card).join('')
      : `<div class="empty"><b>${tab === 'pending' ? '🎉' : '🗂'}</b>${q ? 'Tidak ada hasil untuk “' + esc(q) + '”.' : (tab === 'pending' ? 'Semua deposit sudah diproses.' : 'Tidak ada data di tab ini.')}</div>`;
    if (!first && tab === 'pending' && j.items.some(d => !known.has(d.id))) { haptic('warning'); toast('🔔 Deposit pending baru masuk'); }
    known = new Set(j.items.map(d => d.id));
    first = false;
  }

  function setLive(on, text) {
    $('live').classList.toggle('off', !on);
    $('live-t').textContent = text;
  }

  async function load(force) {
    if (busy && !force) return;
    try {
      const j = await api('list', { tab, q, method, sort });
      if (!j.ok) {
        $('list').innerHTML = `<div class="err"><b>⛔ ${esc(j.error || 'Gagal memuat')}</b>Tutup mini app ini lalu ketik /dp lagi di chat pribadi bot.</div>`;
        setLive(false, 'Offline'); stop(); return;
      }
      skew = Math.floor(Date.now() / 1000) - j.now;
      expH = j.expire_hours || 3;
      $('me').textContent = j.me.replace('Mini App ', '');
      setLive(true, new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
      render(j, force);
    } catch (e) {
      setLive(false, 'Koneksi putus');
    }
  }
  function start() { stop(); timer = setInterval(() => { if (!document.hidden) load(); }, 4000); }
  function stop() { if (timer) clearInterval(timer); timer = null; }
  function reset() { sig = ''; first = true; $('list').innerHTML = '<div class="skel"></div><div class="skel"></div>'; load(true); }

  function confirmBox(text) {
    return new Promise(res => {
      if (tg && tg.showConfirm && tg.isVersionAtLeast && tg.isVersionAtLeast('6.2')) tg.showConfirm(text, ok => res(!!ok));
      else res(window.confirm(text));
    });
  }

  async function act(id, what, reason) {
    busy = true;
    document.querySelectorAll(`#c${id} .b`).forEach(b => b.disabled = true);
    try {
      const j = await api('act', {}, { id, do: what, reason: reason || '' });
      if (j.ok) { haptic('success'); toast('✅ ' + j.message); } else { haptic('error'); toast(j.error || 'Gagal', true); }
    } catch (e) { toast('Koneksi gagal, coba lagi', true); }
    busy = false;
    open.delete(id);
    sig = ''; load(true);
  }

  // Sheet tolak
  let sheetId = 0;
  const sheet = $('sheet');
  $('sh-x').onclick = () => sheet.classList.remove('on');
  sheet.onclick = e => { if (e.target === sheet) sheet.classList.remove('on'); };
  sheet.querySelectorAll('.quick button').forEach(b => b.onclick = () => { $('sh-r').value = b.textContent; });
  $('sh-ok').onclick = () => { sheet.classList.remove('on'); act(sheetId, 'reject', $('sh-r').value.trim()); };

  $('list').addEventListener('click', async e => {
    const amt = e.target.closest('.amt');
    if (amt) return copy(amt.dataset.copy, 'Nominal');
    const b = e.target.closest('button[data-do]');
    if (!b) return;
    const id = +b.dataset.id, what = b.dataset.do, d = cache[id];
    if (!d) return;
    if (what === 'info') {
      open.has(id) ? open.delete(id) : open.add(id);
      $('c' + id).classList.toggle('open', open.has(id));
      b.textContent = open.has(id) ? '▴' : 'ⓘ';
      return;
    }
    if (what === 'copy') return copy(`#${d.id} ${d.user} ${rp(d.amount)} ${ST[d.status]} (${d.method})`, 'Ringkasan');
    if (what === 'reject') {
      sheetId = id;
      $('sh-p').textContent = `#${d.id} · ${d.user} · ${rp(d.amount)}`;
      $('sh-r').value = '';
      sheet.classList.add('on');
      return;
    }
    const head = `#${id} · ${d.user} · ${rp(d.amount)}`;
    const text = what === 'confirm_nc'
      ? `ACC TANPA KOMISI\n${head}\n\nSaldo masuk ke user. Tidak ada komisi ke upline${d.upline ? ' (' + d.upline + ')' : ''} dan tidak dihitung untuk promotor.`
      : `${d.status === 'pending' ? 'ACC DEPOSIT' : 'ACC ' + ST[d.status].toUpperCase()}\n${head}${d.upline ? '\nKomisi diproses ke upline ' + d.upline : ''}`;
    if (await confirmBox(text)) act(id, what);
  });

  $('tabs').addEventListener('click', e => {
    const b = e.target.closest('.tab'); if (!b) return;
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('on', t === b));
    tab = b.dataset.t; reset();
  });
  document.querySelectorAll('.st[data-go]').forEach(s => s.addEventListener('click', () => {
    const b = document.querySelector(`.tab[data-t="${s.dataset.go}"]`); if (b) b.click();
  }));
  $('method').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    $('method').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
    method = b.dataset.m; reset();
  });
  $('sort').addEventListener('change', e => { sort = e.target.value; reset(); });
  let qt;
  $('q').addEventListener('input', e => { clearTimeout(qt); qt = setTimeout(() => { q = e.target.value.trim(); reset(); }, 350); });
  $('refresh').addEventListener('click', e => {
    e.currentTarget.classList.remove('spin'); void e.currentTarget.offsetWidth; e.currentTarget.classList.add('spin');
    sig = ''; load(true);
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });

  if (!INIT) {
    $('list').innerHTML = '<div class="err"><b>Buka lewat Telegram</b>Ketik /dp di chat pribadi bot untuk membuka Deposit Manager.</div>';
    $('me').textContent = 'Tidak ada sesi Telegram';
    setLive(false, 'Offline');
    return;
  }
  load(true); start();
  // Perbarui hitung mundur & "x lalu" tiap 20 detik walau data tidak berubah
  setInterval(() => { if (lastJ && !busy && !document.hidden) render(lastJ, true); }, 20000);
})();
</script>
</body>
</html>
