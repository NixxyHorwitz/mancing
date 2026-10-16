<?php
declare(strict_types=1);
/**
 * Menu Admin — Telegram Mini App (dibuka lewat /menu di chat pribadi bot).
 * Kelola Deposit, Withdraw, dan Permintaan user dalam satu tempat.
 * Tanpa login: identitas dari initData Telegram (ditandatangani token bot) + wajib anggota grup admin.
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
    if (!$tg) $out(['ok' => false, 'error' => 'Sesi Telegram tidak valid. Buka lewat perintah /menu di bot.'], 401);
    if (!tg_dp_allowed($pdo, (int)$tg['id'])) $out(['ok' => false, 'error' => 'Akses ditolak. Khusus anggota grup admin.'], 403);
    depo_ensure_schema($pdo);
    admin_ops_ensure_schema($pdo);
    $actor = 'Menu @' . ($tg['username'] ?? $tg['first_name'] ?? $tg['id']);
    $exp   = $pdo->quote(DEPO_EXPIRED_NOTE);

    // ── Ringkasan (badge & dashboard) ────────────────────────────────────────
    $summary = static function () use ($pdo, $exp): array {
        $d = $pdo->query("
            SELECT
              SUM(status = 'pending') p_n, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) p_sum,
              MIN(CASE WHEN status = 'pending' THEN UNIX_TIMESTAMP(created_at) END) p_old,
              SUM(status = 'confirmed' AND confirmed_at >= CURDATE()) c_n,
              COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= CURDATE() THEN amount END), 0) c_sum,
              COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= CURDATE() - INTERVAL 1 DAY AND confirmed_at < CURDATE() THEN amount END), 0) y_sum,
              SUM(status = 'confirmed' AND confirmed_at >= CURDATE() AND no_commission = 1) nc_n,
              COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= CURDATE() AND no_commission = 1 THEN amount END), 0) nc_sum,
              SUM(status = 'rejected' AND admin_note = {$exp} AND created_at >= CURDATE()) e_n,
              SUM(status = 'rejected' AND (admin_note IS NULL OR admin_note <> {$exp}) AND created_at >= CURDATE()) r_n,
              SUM(created_at >= CURDATE()) new_n
            FROM deposits
            WHERE status = 'pending' OR created_at >= CURDATE() - INTERVAL 1 DAY OR confirmed_at >= CURDATE() - INTERVAL 1 DAY
        ")->fetch();
        $w = $pdo->query("
            SELECT
              SUM(status = 'pending') p_n, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) p_sum,
              MIN(CASE WHEN status = 'pending' THEN UNIX_TIMESTAMP(created_at) END) p_old,
              SUM(status = 'hold') h_n, COALESCE(SUM(CASE WHEN status = 'hold' THEN amount END), 0) h_sum,
              SUM(status = 'approved' AND processed_at >= CURDATE()) a_n,
              COALESCE(SUM(CASE WHEN status = 'approved' AND processed_at >= CURDATE() THEN amount END), 0) a_sum,
              SUM(status = 'rejected' AND processed_at >= CURDATE()) r_n,
              SUM(status = 'refunded' AND processed_at >= CURDATE()) f_n,
              SUM(created_at >= CURDATE()) new_n
            FROM withdrawals
        ")->fetch();
        $r = ['total' => 0, 'types' => [], 'old' => 0];
        foreach ($pdo->query("SELECT type, COUNT(*) n, MIN(UNIX_TIMESTAMP(created_at)) o FROM admin_requests WHERE status = 'pending' GROUP BY type") as $row) {
            $r['types'][$row['type']] = (int)$row['n'];
            $r['total'] += (int)$row['n'];
            $r['old'] = $r['old'] ? min($r['old'], (int)$row['o']) : (int)$row['o'];
        }
        $r['done_today'] = (int)$pdo->query("SELECT COUNT(*) FROM admin_requests WHERE status <> 'pending' AND updated_at >= CURDATE()")->fetchColumn();

        $hours = ['in' => array_fill(0, 24, 0.0), 'out' => array_fill(0, 24, 0.0)];
        foreach ($pdo->query("SELECT HOUR(confirmed_at) h, SUM(amount) s FROM deposits WHERE status = 'confirmed' AND confirmed_at >= CURDATE() GROUP BY h") as $h) $hours['in'][(int)$h['h']] = (float)$h['s'];
        foreach ($pdo->query("SELECT HOUR(processed_at) h, SUM(amount) s FROM withdrawals WHERE status = 'approved' AND processed_at >= CURDATE() GROUP BY h") as $h) $hours['out'][(int)$h['h']] = (float)$h['s'];

        $u = $pdo->query("SELECT SUM(created_at >= CURDATE()) new_n, SUM(last_seen >= NOW() - INTERVAL 10 MINUTE) online FROM users")->fetch();
        $first = (int)$pdo->query("
            SELECT COUNT(DISTINCT d.user_id) FROM deposits d
            WHERE d.status = 'confirmed' AND d.confirmed_at >= CURDATE()
              AND NOT EXISTS (SELECT 1 FROM deposits o WHERE o.user_id = d.user_id AND o.status = 'confirmed' AND o.confirmed_at < CURDATE())
        ")->fetchColumn();

        return [
            'depo' => ['pending' => (int)$d['p_n'], 'pending_sum' => (float)$d['p_sum'], 'oldest' => (int)$d['p_old'],
                       'today' => (int)$d['c_n'], 'today_sum' => (float)$d['c_sum'], 'yesterday_sum' => (float)$d['y_sum'],
                       'nc' => (int)$d['nc_n'], 'nc_sum' => (float)$d['nc_sum'], 'expired' => (int)$d['e_n'], 'rejected' => (int)$d['r_n'],
                       'created' => (int)$d['new_n'], 'first' => $first],
            'wd'   => ['pending' => (int)$w['p_n'], 'pending_sum' => (float)$w['p_sum'], 'oldest' => (int)$w['p_old'],
                       'hold' => (int)$w['h_n'], 'hold_sum' => (float)$w['h_sum'], 'today' => (int)$w['a_n'], 'today_sum' => (float)$w['a_sum'],
                       'rejected' => (int)$w['r_n'], 'refunded' => (int)$w['f_n'], 'created' => (int)$w['new_n']],
            'req'  => $r,
            'users'=> ['new' => (int)$u['new_n'], 'online' => (int)$u['online']],
            'hours'=> $hours,
        ];
    };

    $user_cols = "u.username, u.balance_dep, u.balance_wd, UNIX_TIMESTAMP(u.created_at) AS joined_ts, u.is_flagged_abuse, u.bank_name AS u_bank, u.account_number AS u_acc, u.account_name AS u_accname,
                  m.name AS member, up.username AS upline, up.is_promotor AS upline_promotor";
    $user_joins = "JOIN users u ON u.id = x.user_id
                   LEFT JOIN memberships m ON m.id = u.membership_id
                   LEFT JOIN users up ON up.referral_code = u.referred_by AND u.referred_by <> ''";
    $user_info = static fn(array $r): array => [
        'uid' => (int)$r['user_id'], 'user' => $r['username'], 'bal_dep' => (float)$r['balance_dep'], 'bal_wd' => (float)$r['balance_wd'],
        'joined' => (int)$r['joined_ts'], 'abuse' => (int)$r['is_flagged_abuse'] === 1, 'member' => $r['member'] ?: 'Gratis',
        'upline' => $r['upline'], 'promotor' => (int)$r['upline_promotor'] === 1,
    ];
    $search = static function (string $q, array $text_cols, array $num_cols) {
        $where = []; $params = [];
        if ($q === '') return ['', []];
        foreach ($text_cols as $c) { $where[] = "{$c} LIKE ?"; $params[] = '%' . $q . '%'; }
        $digits = preg_replace('/\D/', '', $q);
        if ($digits !== '') foreach ($num_cols as $c) {
            if (str_starts_with($c, 'LIKE:')) { $where[] = substr($c, 5) . ' LIKE ?'; $params[] = '%' . $digits . '%'; }
            else { $where[] = "{$c} = ?"; $params[] = (int)$digits; }
        }
        return [' AND (' . implode(' OR ', $where) . ')', $params];
    };

    // ── Daftar per bagian ────────────────────────────────────────────────────
    if ($action === 'list') {
        $s    = $_GET['s'] ?? 'home';
        $tab  = $_GET['tab'] ?? 'pending';
        $q    = trim((string)($_GET['q'] ?? ''));
        $sort = $_GET['sort'] ?? '';
        $flt  = $_GET['f'] ?? 'all';
        $items = [];

        if ($s === 'depo') {
            $where = match ($tab) {
                'expired'   => "x.status = 'rejected' AND x.admin_note = {$exp}",
                'confirmed' => "x.status = 'confirmed'",
                'nc'        => "x.status = 'confirmed' AND x.no_commission = 1",
                'rejected'  => "x.status = 'rejected' AND (x.admin_note IS NULL OR x.admin_note <> {$exp})",
                'today'     => "(x.created_at >= CURDATE() OR x.confirmed_at >= CURDATE())",
                'all'       => '1=1',
                default     => "x.status = 'pending'",
            };
            if ($flt === 'qris')     $where .= " AND x.method = 'qris'";
            if ($flt === 'transfer') $where .= " AND x.method <> 'qris'";
            [$sw, $params] = $search($q, ['u.username', 'up.username'], ['x.id', 'x.user_id', 'LIKE:CAST(x.amount AS UNSIGNED)']);
            $order = match ($sort) {
                'old' => 'x.id ASC', 'new' => 'x.id DESC', 'big' => 'x.amount DESC, x.id DESC', 'small' => 'x.amount ASC, x.id DESC',
                default => in_array($tab, ['confirmed', 'nc'], true) ? 'x.confirmed_at DESC' : 'x.id DESC', // default: terbaru
            };
            $st = $pdo->prepare("
                SELECT x.id, x.user_id, x.amount, x.method, x.status, x.admin_note, x.proof_image, x.no_commission, x.tg_msg_id,
                       UNIX_TIMESTAMP(x.created_at) created_ts, UNIX_TIMESTAMP(x.confirmed_at) confirmed_ts, {$user_cols},
                       (SELECT COUNT(*) FROM deposits d2 WHERE d2.user_id = x.user_id AND d2.status = 'confirmed' AND d2.id <> x.id) prev_ok,
                       (SELECT COALESCE(SUM(d3.amount), 0) FROM deposits d3 WHERE d3.user_id = x.user_id AND d3.status = 'confirmed') user_total
                FROM deposits x {$user_joins}
                WHERE {$where}{$sw} ORDER BY {$order} LIMIT 100");
            $st->execute($params);
            foreach ($st->fetchAll() as $r) {
                $is_exp = $r['status'] === 'rejected' && $r['admin_note'] === DEPO_EXPIRED_NOTE;
                $items[] = $user_info($r) + [
                    'id' => (int)$r['id'], 'amount' => (float)$r['amount'], 'method' => $r['method'] === 'qris' ? 'QRIS' : 'Transfer',
                    'status' => $is_exp ? 'expired' : $r['status'], 'note' => $is_exp ? '' : (string)($r['admin_note'] ?? ''),
                    'proof' => $r['proof_image'] ? '/uploads/' . ltrim($r['proof_image'], '/') : '',
                    'nc' => (int)$r['no_commission'] === 1, 'tg' => !empty($r['tg_msg_id']),
                    'created' => (int)$r['created_ts'], 'done' => (int)$r['confirmed_ts'],
                    'prev_ok' => (int)$r['prev_ok'], 'user_total' => (float)$r['user_total'],
                ];
            }
        } elseif ($s === 'wd') {
            $where = match ($tab) {
                'hold'     => "x.status = 'hold'",
                'approved' => "x.status = 'approved'",
                'rejected' => "x.status = 'rejected'",
                'refunded' => "x.status = 'refunded'",
                'today'    => "(x.created_at >= CURDATE() OR x.processed_at >= CURDATE())",
                'all'      => '1=1',
                default    => "x.status = 'pending'",
            };
            if ($flt === 'big') $where .= " AND x.amount >= 100000";
            [$sw, $params] = $search($q, ['u.username', 'x.account_name', 'x.bank_name', 'up.username'], ['x.id', 'x.user_id', 'LIKE:x.account_number', 'LIKE:CAST(x.amount AS UNSIGNED)']);
            $order = match ($sort) {
                'old' => 'x.id ASC', 'new' => 'x.id DESC', 'big' => 'x.amount DESC, x.id DESC', 'small' => 'x.amount ASC, x.id DESC',
                default => in_array($tab, ['pending', 'hold'], true) ? 'x.id ASC' : 'COALESCE(x.processed_at, x.created_at) DESC',
            };
            $st = $pdo->prepare("
                SELECT x.*, UNIX_TIMESTAMP(x.created_at) created_ts, UNIX_TIMESTAMP(x.processed_at) processed_ts, {$user_cols},
                       (SELECT COALESCE(SUM(d.amount), 0) FROM deposits d WHERE d.user_id = x.user_id AND d.status = 'confirmed') dep_total,
                       (SELECT COUNT(*) FROM withdrawals w2 WHERE w2.user_id = x.user_id AND w2.status = 'approved') wd_ok_n,
                       (SELECT COALESCE(SUM(w3.amount), 0) FROM withdrawals w3 WHERE w3.user_id = x.user_id AND w3.status = 'approved') wd_ok_sum,
                       (SELECT COUNT(*) FROM withdrawals w4 WHERE w4.user_id = x.user_id AND w4.status IN ('pending', 'hold') AND w4.id <> x.id) wd_open,
                       (SELECT COUNT(*) FROM withdrawals w5 WHERE w5.account_number = x.account_number AND w5.user_id <> x.user_id) acc_shared
                FROM withdrawals x {$user_joins}
                WHERE {$where}{$sw} ORDER BY {$order} LIMIT 100");
            $st->execute($params);
            foreach ($st->fetchAll() as $r) {
                $items[] = $user_info($r) + [
                    'id' => (int)$r['id'], 'amount' => (float)$r['amount'], 'status' => $r['status'], 'note' => (string)($r['admin_note'] ?? ''),
                    'bank' => $r['bank_name'], 'acc' => $r['account_number'], 'accname' => $r['account_name'],
                    'bank_changed' => $r['u_acc'] !== null && $r['u_acc'] !== $r['account_number'],
                    'tg' => !empty($r['tg_msg_id']), 'created' => (int)$r['created_ts'], 'done' => (int)$r['processed_ts'],
                    'dep_total' => (float)$r['dep_total'], 'wd_ok_n' => (int)$r['wd_ok_n'], 'wd_ok_sum' => (float)$r['wd_ok_sum'],
                    'wd_open' => (int)$r['wd_open'], 'acc_shared' => (int)$r['acc_shared'],
                ];
            }
        } elseif ($s === 'req') {
            $where = match ($tab) {
                'approved' => "x.status = 'approved'",
                'rejected' => "x.status = 'rejected'",
                'all'      => '1=1',
                default    => "x.status = 'pending'",
            };
            if ($flt !== 'all' && isset(REQ_TYPE_LABELS[$flt])) $where .= ' AND x.type = ' . $pdo->quote($flt);
            if ($flt === 'threads') $where .= " AND x.type LIKE 'threads_campaign%'";
            [$sw, $params] = $search($q, ['u.username', 'up.username'], ['x.id', 'x.user_id']);
            $order = $sort === 'new' ? 'x.id DESC' : ($sort === 'old' || $tab === 'pending' ? 'x.id ASC' : 'x.updated_at DESC');
            $st = $pdo->prepare("
                SELECT x.*, UNIX_TIMESTAMP(x.created_at) created_ts, UNIX_TIMESTAMP(x.updated_at) updated_ts, u.refund_cut_percent, u.membership_id, {$user_cols},
                       (SELECT COUNT(*) FROM users r WHERE r.referred_by = u.referral_code) ref_n
                FROM admin_requests x {$user_joins}
                WHERE {$where}{$sw} ORDER BY {$order} LIMIT 100");
            $st->execute($params);
            foreach ($st->fetchAll() as $r) {
                $p = json_decode((string)$r['payload'], true) ?: [];
                $detail = [];
                $amount = 0.0;
                switch ($r['type']) {
                    case 'change_bank':
                        $detail = ['old' => [$r['u_bank'], $r['u_acc'], $r['u_accname']], 'new' => [$p['bank_name'] ?? '', $p['account_number'] ?? '', $p['account_name'] ?? '']];
                        break;
                    case 'refund_wd_hold':
                        $w = $pdo->prepare("SELECT id, amount, status, bank_name, account_number, UNIX_TIMESTAMP(created_at) c FROM withdrawals WHERE id = ?");
                        $w->execute([(int)($p['withdrawal_id'] ?? 0)]);
                        $wd = $w->fetch() ?: null;
                        $amount = $wd ? (float)$wd['amount'] : 0.0;
                        $detail = ['wd' => $wd ? ['id' => (int)$wd['id'], 'status' => $wd['status'], 'bank' => $wd['bank_name'] . ' - ' . $wd['account_number'], 'created' => (int)$wd['c']] : null];
                        break;
                    case 'refund_level':
                        $base = 0.0;
                        if ($r['membership_id']) {
                            $o = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id = ? AND membership_id = ? AND status = 'confirmed' ORDER BY id DESC LIMIT 1");
                            $o->execute([$r['user_id'], $r['membership_id']]);
                            $base = (float)$o->fetchColumn();
                            if (!$base) $base = (float)$pdo->query("SELECT price FROM memberships WHERE id = " . (int)$r['membership_id'])->fetchColumn();
                        }
                        $cut = (float)$r['refund_cut_percent'];
                        $amount = $base * (100 - $cut) / 100;
                        $detail = ['level' => $r['member'], 'base' => $base, 'cut' => $cut, 'has_level' => (bool)$r['membership_id']];
                        break;
                    default:
                        $imgs = $p['proof_images'] ?? (isset($p['proof_image']) ? [$p['proof_image']] : []);
                        $amount = (float)($p['reward_amount'] ?? 0);
                        $detail = ['images' => array_values(array_map(fn($i) => '/' . ltrim((string)$i, '/'), (array)$imgs)), 'ref_n' => (int)$r['ref_n'],
                                   'views' => $p['views_rule'] ?? null];
                }
                $items[] = $user_info($r) + [
                    'id' => (int)$r['id'], 'type' => $r['type'], 'label' => REQ_TYPE_LABELS[$r['type']] ?? $r['type'], 'status' => $r['status'],
                    'note' => (string)($r['admin_note'] ?? ''), 'amount' => $amount, 'detail' => $detail, 'tg' => !empty($r['tg_msg_id']),
                    'created' => (int)$r['created_ts'], 'done' => $r['status'] === 'pending' ? 0 : (int)$r['updated_ts'],
                ];
            }
        }

        $sum = $summary();
        $out(['ok' => true, 'summary' => $sum, 'items' => $items, 'sig' => md5(json_encode([$sum, $items])),
              'now' => time(), 'expire_hours' => DEPO_EXPIRE_HOURS, 'me' => $actor]);
    }

    // ── Aksi ─────────────────────────────────────────────────────────────────
    if ($action === 'act' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in     = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $s      = (string)($in['s'] ?? '');
        $id     = (int)($in['id'] ?? 0);
        $do     = (string)($in['do'] ?? '');
        $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 200);
        if ($id <= 0) $out(['ok' => false, 'error' => 'ID tidak valid.'], 400);

        if ($s === 'depo') {
            if ($do === 'confirm' || $do === 'confirm_nc') {
                $nc  = $do === 'confirm_nc';
                $res = depo_admin_confirm($pdo, $id, !$nc, $actor);
                if (!$res['ok']) $out(['ok' => false, 'error' => $res['message']]);
                $dep = $res['dep'];
                if (!empty($dep['tg_msg_id'])) {
                    $foot = $nc ? 'Saldo masuk ke user. Tidak ada komisi referral dan tidak dihitung untuk promotor.' : 'Deposit disetujui admin lewat Menu Admin.';
                    edit_telegram_notif($pdo, (int)$dep['tg_msg_id'],
                        depo_confirmed_tg_text($dep, (float)$res['comm'], $res['was'] . ($nc ? ' V2' : '') . ' via ' . $actor, $foot), []);
                }
                $out(['ok' => true, 'message' => '#' . $id . ' ' . $res['message'] . ((float)$res['comm'] > 0 ? ' Komisi ' . format_rp((float)$res['comm']) . ' ke upline.' : '')]);
            }
            if ($do === 'reject') {
                $res = depo_admin_reject($pdo, $id, $reason);
                if (!$res['ok']) $out(['ok' => false, 'error' => $res['message']]);
                if (!empty($res['dep']['tg_msg_id'])) edit_telegram_notif($pdo, (int)$res['dep']['tg_msg_id'], depo_rejected_tg_text($res['dep'], $actor), []);
                $out(['ok' => true, 'message' => 'Deposit #' . $id . ' ditolak.']);
            }
            if ($do === 'cancel') {
                $res = depo_admin_cancel($pdo, $id, $reason);
                if (!$res['ok']) $out(['ok' => false, 'error' => $res['message']]);
                if (!empty($res['dep']['tg_msg_id'])) edit_telegram_notif($pdo, (int)$res['dep']['tg_msg_id'], depo_cancelled_tg_text($res['dep'], $actor), []);
                send_telegram_notif($pdo, depo_cancelled_tg_text($res['dep'], $actor), [], 'log');
                $out(['ok' => true, 'message' => $res['message']]);
            }
        }

        if ($s === 'wd') {
            $res = wd_admin_action($pdo, $id, $do, $reason, $actor);
            $out($res['ok'] ? ['ok' => true, 'message' => $res['message']] : ['ok' => false, 'error' => $res['message']]);
        }

        if ($s === 'req') {
            $tgid = static function () use ($pdo, $id): int {
                $q = $pdo->prepare("SELECT tg_msg_id FROM admin_requests WHERE id = ?");
                $q->execute([$id]);
                return (int)$q->fetchColumn();
            };
            if ($do === 'approve') {
                $res = req_admin_approve($pdo, $id);
                if (!$res['ok']) $out(['ok' => false, 'error' => $res['message']]);
                tg_sync_msg($pdo, $tgid(), $res['tg_text'] . "\n<i>via {$actor}</i>", (bool)$res['is_photo']);
                $out(['ok' => true, 'message' => $res['message']]);
            }
            if ($do === 'reject') {
                $res = do_req_reject($pdo, $id, $reason);
                if ($res['status'] !== 'ok') $out(['ok' => false, 'error' => $res['message']]);
                tg_sync_msg($pdo, $tgid(), $res['msg'] . "\n<i>via {$actor}</i>", str_starts_with((string)$res['req_type'], 'threads_campaign'));
                $out(['ok' => true, 'message' => 'Permintaan #' . $id . ' ditolak.']);
            }
        }

        $out(['ok' => false, 'error' => 'Aksi tidak dikenal.'], 400);
    }

    $out(['ok' => false, 'error' => 'Not found'], 404);
}

$start_section = in_array($_GET['s'] ?? '', ['home', 'depo', 'wd', 'req'], true) ? $_GET['s'] : 'home';
header('Cache-Control: no-store');
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="dark">
<title>Menu Admin</title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root {
  --bg: #0a0f1c; --card: #111827; --card2: #172033; --line: #1f2a3d; --line2: #2a3850;
  --tx: #e5edf7; --dim: #8b9ab3; --mute: #5d6b82;
  --cy: #22d3ee; --gr: #22c55e; --am: #f59e0b; --rd: #ef4444; --vi: #a78bfa; --bl: #60a5fa; --pk: #f472b6;
  color-scheme: dark;
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; background: var(--bg); color: var(--tx); font: 13px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif; }
button, input, select { font: inherit; color: inherit; }
a { color: var(--cy); }
.num { font-variant-numeric: tabular-nums; }
.wrap { max-width: 1200px; margin: 0 auto; padding: 0 10px calc(78px + env(safe-area-inset-bottom)); }

/* Header */
.head { display: flex; align-items: center; gap: 8px; padding: 10px 0 8px; }
.logo { width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; background: linear-gradient(135deg, #0891b2, #6366f1); font-size: 15px; flex-shrink: 0; }
.head h1 { margin: 0; font-size: 15px; font-weight: 800; line-height: 1.1; flex: 1; min-width: 0; }
.head h1 small { display: block; font-size: 10.5px; font-weight: 600; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.live { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; color: var(--dim); padding: 4px 8px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); white-space: nowrap; }
.live i { width: 7px; height: 7px; border-radius: 50%; background: var(--gr); box-shadow: 0 0 0 3px rgba(34,197,94,.2); }
.live.off i { background: var(--rd); box-shadow: none; }
.ibtn { width: 30px; height: 30px; border-radius: 9px; border: 1px solid var(--line); background: var(--card); display: grid; place-items: center; cursor: pointer; font-size: 14px; flex-shrink: 0; }
.ibtn.spin { animation: spin .7s linear; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Bottom nav */
.nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; background: rgba(10,15,28,.96); backdrop-filter: blur(8px); border-top: 1px solid var(--line); padding: 5px 6px calc(5px + env(safe-area-inset-bottom)); }
.nav-in { max-width: 560px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; }
.nv { border: 0; background: transparent; color: var(--dim); border-radius: 11px; padding: 6px 2px 5px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 2px; font-size: 10.5px; font-weight: 700; position: relative; }
.nv span.ic { font-size: 18px; line-height: 1; }
.nv.on { color: var(--tx); background: var(--card2); }
.nv b { position: absolute; top: 2px; left: 50%; margin-left: 6px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 999px; background: var(--rd); color: #fff; font-size: 10px; display: grid; place-items: center; }
.nv b:empty { display: none; }

/* Stat tiles */
.grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; margin-bottom: 8px; }
@media (min-width: 640px) { .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.st { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 8px 10px; min-width: 0; cursor: pointer; position: relative; overflow: hidden; }
.st::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--c, var(--cy)); }
.st small { display: flex; justify-content: space-between; gap: 4px; font-size: 10px; color: var(--dim); font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.st b { display: block; font-size: 18px; font-weight: 800; color: var(--c, var(--tx)); line-height: 1.2; margin-top: 2px; }
.st span { display: block; font-size: 10.5px; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.up { color: var(--gr); } .dn { color: var(--rd); }
.sec-t { font-size: 11px; font-weight: 800; color: var(--dim); text-transform: uppercase; letter-spacing: .4px; margin: 12px 2px 6px; display: flex; justify-content: space-between; }
.box { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 9px 10px; margin-bottom: 8px; }
.chart-h { display: flex; justify-content: space-between; font-size: 10.5px; color: var(--dim); font-weight: 700; margin-bottom: 5px; }
.chart-h i { font-style: normal; display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin: 0 3px 0 8px; }
.bars { display: grid; grid-template-columns: repeat(24, 1fr); gap: 2px; align-items: end; height: 46px; }
.bars div { display: flex; gap: 1px; align-items: end; height: 100%; }
.bars span { flex: 1; border-radius: 2px 2px 0 0; min-height: 2px; }
.bars .in { background: var(--gr); } .bars .out { background: var(--rd); opacity: .85; }
.bars .z { background: var(--line2); }
.bars-x { display: grid; grid-template-columns: repeat(4, 1fr); font-size: 9px; color: var(--mute); margin-top: 3px; }
.bars-x span:last-child { text-align: right; }
.net { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 8px; }
.net div { background: var(--card2); border-radius: 9px; padding: 6px 8px; }
.net small { display: block; font-size: 10px; color: var(--dim); }
.net b { font-size: 13px; }
.queue { display: grid; gap: 6px; }
.qrow { display: flex; align-items: center; gap: 9px; background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 9px 10px; cursor: pointer; }
.qrow .qi { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; font-size: 17px; flex-shrink: 0; }
.qrow .qt { flex: 1; min-width: 0; line-height: 1.25; }
.qrow .qt b { display: block; font-size: 13px; }
.qrow .qt span { display: block; font-size: 10.5px; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.qrow .qn { font-size: 20px; font-weight: 800; }
.qrow .go { color: var(--mute); font-size: 16px; }

/* Controls */
.top { position: sticky; top: 0; z-index: 10; background: var(--bg); margin: 0 -10px 4px; padding: 6px 10px 6px; border-bottom: 1px solid var(--line); }
.ctl { display: flex; gap: 6px; margin-bottom: 6px; }
.ctl:last-child { margin-bottom: 0; }
.search { flex: 1; min-width: 0; padding: 8px 11px 8px 30px; border-radius: 10px; border: 1px solid var(--line); background: var(--card) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%238b9ab3' stroke-width='2'%3E%3Ccircle cx='6' cy='6' r='4.5'/%3E%3Cpath d='m10 10 3 3'/%3E%3C/svg%3E") no-repeat 10px center; outline: none; }
.search:focus, .sel:focus { border-color: var(--cy); }
.sel { padding: 0 8px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); outline: none; max-width: 112px; }
.tabs { display: flex; gap: 4px; overflow-x: auto; scrollbar-width: none; flex: 1; }
.tabs::-webkit-scrollbar { display: none; }
.tab { flex: 0 0 auto; padding: 6px 11px; border-radius: 999px; border: 1px solid var(--line); background: var(--card); color: var(--dim); font-weight: 700; font-size: 12px; cursor: pointer; white-space: nowrap; }
.tab.on { background: var(--cy); border-color: var(--cy); color: #04222a; }
.tab em { font-style: normal; font-size: 10px; margin-left: 4px; padding: 0 5px; border-radius: 999px; background: rgba(0,0,0,.25); }
.tab em:empty { display: none; }
.meta { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 6px; font-size: 11px; color: var(--dim); margin: 6px 2px; }
#meta-l { white-space: nowrap; }
.meta .seg { margin-left: auto; }
.seg { display: inline-flex; border: 1px solid var(--line); border-radius: 999px; overflow: hidden; flex-shrink: 0; max-width: 100%; overflow-x: auto; scrollbar-width: none; }
.seg button { border: 0; background: var(--card); color: var(--dim); padding: 6px 9px; font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.seg button.on { background: var(--card2); color: var(--tx); }

/* Cards */
.list { display: grid; grid-template-columns: 1fr; gap: 7px; }
@media (min-width: 760px) { .list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1100px) { .list { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 13px; padding: 9px 10px; border-left: 3px solid var(--sc, var(--line2)); display: flex; flex-direction: column; gap: 6px; min-width: 0; }
.card.s-pending { --sc: var(--am); } .card.s-expired { --sc: var(--mute); } .card.s-confirmed, .card.s-approved { --sc: var(--gr); }
.card.s-rejected { --sc: var(--rd); } .card.s-hold { --sc: var(--vi); } .card.s-refunded { --sc: var(--bl); }
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
.chip a { color: inherit; text-decoration: none; }
.c-am { color: #fcd34d; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.3); }
.c-sl { color: #cbd5e1; background: rgba(148,163,184,.12); border-color: rgba(148,163,184,.25); }
.c-gr { color: #86efac; background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.3); }
.c-rd { color: #fca5a5; background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.3); }
.c-vi { color: #c4b5fd; background: rgba(167,139,250,.12); border-color: rgba(167,139,250,.3); }
.c-bl { color: #93c5fd; background: rgba(96,165,250,.12); border-color: rgba(96,165,250,.3); }
.c-pk { color: #f9a8d4; background: rgba(236,72,153,.12); border-color: rgba(236,72,153,.3); }
.c-ab { color: #fff; background: var(--rd); border-color: var(--rd); }
.bankbox { display: flex; align-items: center; gap: 8px; background: var(--card2); border-radius: 9px; padding: 7px 9px; }
.bankbox .bt { flex: 1; min-width: 0; line-height: 1.25; }
.bankbox .bt b { display: block; font-size: 13px; letter-spacing: .3px; font-variant-numeric: tabular-nums; word-break: break-all; }
.bankbox .bt span { display: block; font-size: 10.5px; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp { border: 1px solid var(--line2); background: var(--card); border-radius: 8px; padding: 5px 8px; font-size: 11px; font-weight: 700; cursor: pointer; flex-shrink: 0; }
.cmp { display: grid; grid-template-columns: 1fr auto 1fr; gap: 6px; align-items: center; font-size: 11px; }
.cmp > div { background: var(--card2); border-radius: 9px; padding: 6px 8px; min-width: 0; }
.cmp small { display: block; font-size: 9.5px; color: var(--dim); font-weight: 700; text-transform: uppercase; }
.cmp b { display: block; word-break: break-all; }
.cmp span { display: block; color: var(--dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cmp .arr { color: var(--mute); background: none; padding: 0; }
.thumbs { display: flex; gap: 5px; overflow-x: auto; }
.thumbs img { width: 74px; height: 74px; object-fit: cover; border-radius: 9px; border: 1px solid var(--line2); cursor: zoom-in; flex-shrink: 0; background: var(--card2); }
.exp { display: flex; align-items: center; gap: 6px; font-size: 10px; color: var(--dim); }
.exp .bar { flex: 1; height: 4px; border-radius: 999px; background: var(--line); overflow: hidden; }
.exp .bar i { display: block; height: 100%; background: var(--am); border-radius: 999px; }
.exp.late .bar i { background: var(--rd); } .exp.late b { color: #fca5a5; }
.note { font-size: 11px; color: var(--dim); background: var(--card2); border-radius: 8px; padding: 5px 8px; word-break: break-word; }
.warn { font-size: 11px; color: #fcd34d; background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.25); border-radius: 8px; padding: 5px 8px; }
.det { display: none; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 10px; background: var(--card2); border-radius: 9px; padding: 7px 9px; font-size: 11px; }
.card.open .det { display: grid; }
.det div { display: flex; justify-content: space-between; gap: 6px; min-width: 0; }
.det span { color: var(--dim); white-space: nowrap; }
.det b { font-weight: 700; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.acts { display: flex; gap: 5px; }
.b { height: 34px; border: 0; border-radius: 9px; font-weight: 800; font-size: 11.5px; cursor: pointer; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 8px; flex: 1 1 0; min-width: 0; }
.b:active { transform: scale(.97); }
.b:disabled { opacity: .45; }
.b.ok { background: #16a34a; } .b.nc { background: #7c3aed; flex-grow: 1.35; } .b.no { background: #b91c1c; } .b.hd { background: #6d28d9; } .b.rf { background: #2563eb; }
.b.gh { background: var(--card2); color: var(--dim); border: 1px solid var(--line); font-size: 13px; flex: 0 0 34px; padding: 0; }
.empty, .err { grid-column: 1 / -1; text-align: center; padding: 40px 12px; color: var(--dim); }
.empty b, .err b { display: block; font-size: 15px; color: var(--tx); margin-bottom: 4px; }
.empty b.big { font-size: 26px; }
.skel { height: 116px; border-radius: 13px; background: linear-gradient(90deg, var(--card) 25%, var(--card2) 50%, var(--card) 75%); background-size: 200% 100%; animation: sk 1.2s infinite; }
@keyframes sk { to { background-position: -200% 0; } }

.toast { position: fixed; left: 50%; bottom: calc(76px + env(safe-area-inset-bottom)); transform: translate(-50%, 20px); opacity: 0; background: #0f766e; color: #fff; padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 700; transition: .25s; max-width: 92%; z-index: 40; pointer-events: none; box-shadow: 0 8px 24px rgba(0,0,0,.4); }
.toast.on { transform: translate(-50%, 0); opacity: 1; }
.toast.bad { background: #b91c1c; }
.sheet-bg { position: fixed; inset: 0; background: rgba(2,6,15,.7); display: none; align-items: flex-end; justify-content: center; z-index: 30; }
.sheet-bg.on { display: flex; }
.sheet { width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line); border-bottom: 0; border-radius: 16px 16px 0 0; padding: 14px 12px calc(14px + env(safe-area-inset-bottom)); }
.sheet h3 { margin: 0 0 3px; font-size: 14px; }
.sheet p { margin: 0 0 9px; font-size: 12px; color: var(--dim); white-space: pre-line; }
.sheet textarea { width: 100%; min-height: 64px; padding: 9px; border-radius: 10px; border: 1px solid var(--line); background: var(--bg); resize: vertical; outline: none; }
.quick { display: flex; flex-wrap: wrap; gap: 5px; margin: 8px 0 11px; }
.quick button { border: 1px solid var(--line); background: var(--card2); border-radius: 999px; padding: 5px 10px; font-size: 11px; font-weight: 600; cursor: pointer; }
.sheet .row { display: flex; gap: 6px; }
.lb { position: fixed; inset: 0; z-index: 50; background: rgba(0,0,0,.92); display: none; align-items: center; justify-content: center; padding: 14px; }
.lb.on { display: flex; }
.lb img { max-width: 100%; max-height: 100%; border-radius: 8px; }
.foot { text-align: center; font-size: 10.5px; color: var(--mute); margin-top: 12px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <div class="logo">🛠</div>
    <h1>Menu Admin<small id="me">Memuat sesi…</small></h1>
    <span class="live" id="live"><i></i><span id="live-t">Live</span></span>
    <button class="ibtn" id="refresh" title="Muat ulang">⟳</button>
  </div>
  <div id="view"></div>
  <div class="foot">Diperbarui otomatis tiap 4 detik · aksi tersinkron ke pesan grup Telegram</div>
</div>

<nav class="nav"><div class="nav-in">
  <button class="nv" data-s="home"><span class="ic">📊</span>Ringkasan</button>
  <button class="nv" data-s="depo"><span class="ic">💳</span>Deposit<b id="bd-depo"></b></button>
  <button class="nv" data-s="wd"><span class="ic">💸</span>Withdraw<b id="bd-wd"></b></button>
  <button class="nv" data-s="req"><span class="ic">📨</span>Permintaan<b id="bd-req"></b></button>
</div></nav>

<div class="toast" id="toast"></div>
<div class="lb" id="lb"><img id="lb-img" alt=""></div>
<div class="sheet-bg" id="sheet">
  <div class="sheet">
    <h3 id="sh-t"></h3>
    <p id="sh-p"></p>
    <textarea id="sh-r"></textarea>
    <div class="quick" id="sh-q"></div>
    <div class="row">
      <button class="b gh" id="sh-x" type="button" style="flex:1;font-size:12px">Batal</button>
      <button class="b no" id="sh-ok" type="button" style="flex:1.4"></button>
    </div>
  </div>
</div>

<script>
(() => {
  const tg = window.Telegram && Telegram.WebApp;
  const INIT = tg ? tg.initData : '';
  if (tg) { tg.ready(); tg.expand(); try { tg.setHeaderColor('#0a0f1c'); tg.setBackgroundColor('#0a0f1c'); } catch (e) {} }

  const $ = id => document.getElementById(id);
  const rp = n => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
  const rpS = n => n >= 1e6 ? 'Rp ' + (n / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + 'jt'
                 : n >= 1e3 ? 'Rp ' + (n / 1e3).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + 'rb' : rp(n);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const haptic = t => { try { tg && tg.HapticFeedback.notificationOccurred(t); } catch (e) {} };
  const COLORS = ['#0891b2', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#2563eb', '#ca8a04', '#0d9488'];
  let skew = 0, expH = 3;
  const nowS = () => Math.floor(Date.now() / 1000) - skew;
  const dur = s => {
    s = Math.max(0, Math.floor(s));
    if (s < 60) return s + 'd';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'j ' + Math.floor((s % 3600) / 60) + 'm';
    return Math.floor(s / 86400) + 'h ' + Math.floor((s % 86400) / 3600) + 'j';
  };
  const ago = ts => ts ? dur(nowS() - ts) + ' lalu' : '-';
  // Jam (HH:MM); tambah tanggal bila bukan hari ini
  const clock = ts => {
    if (!ts) return '-';
    const d = new Date(ts * 1000), t = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    return d.toDateString() === new Date().toDateString() ? t : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) + ' ' + t;
  };
  const dt = ts => {
    if (!ts) return '-';
    const d = new Date(ts * 1000);
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) + ' ' + d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  };

  // ── Konfigurasi tiap bagian ─────────────────────────────────────────────
  const SECTIONS = {
    depo: {
      title: 'Deposit',
      tabs: [['pending', '⏳ Pending', s => s.depo.pending], ['today', '📅 Hari ini'], ['confirmed', '✅ Sukses'], ['nc', '💠 Accepted v2'],
             ['expired', '⌛ Expired', s => s.depo.expired], ['rejected', '❌ Ditolak'], ['all', 'Semua']],
      filters: [['all', 'Semua'], ['qris', 'QRIS'], ['transfer', 'Transfer']],
      sorts: [['', 'Terbaru'], ['old', 'Terlama'], ['big', 'Nominal besar'], ['small', 'Nominal kecil']],
      ph: 'Cari user, upline, ID, nominal…',
    },
    wd: {
      title: 'Withdraw',
      tabs: [['pending', '⏳ Pending', s => s.wd.pending], ['hold', '⏸ Hold', s => s.wd.hold], ['today', '📅 Hari ini'], ['approved', '✅ Approved'],
             ['rejected', '❌ Ditolak'], ['refunded', '↩️ Refund'], ['all', 'Semua']],
      filters: [['all', 'Semua'], ['big', '≥ Rp100rb']],
      sorts: [['', 'Urutan'], ['new', 'Terbaru'], ['old', 'Terlama'], ['big', 'Nominal besar'], ['small', 'Nominal kecil']],
      ph: 'Cari user, rekening, a/n, bank, nominal…',
    },
    req: {
      title: 'Permintaan',
      tabs: [['pending', '⏳ Pending', s => s.req.total], ['approved', '✅ Disetujui'], ['rejected', '❌ Ditolak'], ['all', 'Semua']],
      filters: [['all', 'Semua'], ['threads', 'Threads'], ['change_bank', 'Rekening'], ['refund_wd_hold', 'Refund WD'], ['refund_level', 'Refund Level']],
      sorts: [['', 'Urutan'], ['new', 'Terbaru'], ['old', 'Terlama']],
      ph: 'Cari user, upline, ID…',
    },
  };
  const state = { s: <?= json_encode($start_section) ?>, depo: { tab: 'pending', q: '', f: 'all', sort: '' }, wd: { tab: 'pending', q: '', f: 'all', sort: '' }, req: { tab: 'pending', q: '', f: 'all', sort: '' } };
  if (['home', 'depo', 'wd', 'req'].includes(location.hash.slice(1))) state.s = location.hash.slice(1);

  let sig = '', busy = false, timer = null, lastJ = null, cache = {}, open = new Set(), known = {}, firstLoad = {};

  function toast(msg, bad) {
    const t = $('toast'); t.textContent = msg; t.className = 'toast on' + (bad ? ' bad' : '');
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
      body: body ? JSON.stringify(body) : undefined, cache: 'no-store'
    });
    const j = await r.json().catch(() => ({ ok: false, error: 'Respon server tidak valid (' + r.status + ').' }));
    if (!r.ok) j.ok = false;
    return j;
  }

  // ── Kerangka tampilan per bagian ─────────────────────────────────────────
  function buildView() {
    const s = state.s;
    document.querySelectorAll('.nv').forEach(n => n.classList.toggle('on', n.dataset.s === s));
    if (s === 'home') { $('view').innerHTML = '<div id="home"><div class="skel"></div></div>'; return; }
    const cfg = SECTIONS[s], st = state[s];
    $('view').innerHTML = `
      <div class="top">
        <div class="ctl">
          <input class="search" id="q" type="search" placeholder="${cfg.ph}" autocomplete="off" value="${esc(st.q)}">
          <select class="sel" id="sort">${cfg.sorts.map(([v, l]) => `<option value="${v}"${v === st.sort ? ' selected' : ''}>${l}</option>`).join('')}</select>
        </div>
        <div class="ctl"><div class="tabs" id="tabs">${cfg.tabs.map(([v, l]) => `<button class="tab${v === st.tab ? ' on' : ''}" data-t="${v}">${l}<em data-c="${v}"></em></button>`).join('')}</div></div>
      </div>
      <div class="meta"><span id="meta-l">&nbsp;</span>
        <div class="seg" id="flt">${cfg.filters.map(([v, l]) => `<button class="${v === st.f ? 'on' : ''}" data-f="${v}">${l}</button>`).join('')}</div></div>
      <div class="list" id="list"><div class="skel"></div><div class="skel"></div></div>`;
    let qt;
    $('q').oninput = e => { clearTimeout(qt); qt = setTimeout(() => { st.q = e.target.value.trim(); reload(); }, 350); };
    $('sort').onchange = e => { st.sort = e.target.value; reload(); };
    $('tabs').onclick = e => { const b = e.target.closest('.tab'); if (!b) return; st.tab = b.dataset.t; document.querySelectorAll('.tab').forEach(t => t.classList.toggle('on', t === b)); reload(); };
    $('flt').onclick = e => { const b = e.target.closest('button'); if (!b) return; st.f = b.dataset.f; $('flt').querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b)); reload(); };
  }

  function go(s, tab) {
    state.s = s;
    if (tab && state[s]) state[s].tab = tab;
    history.replaceState(null, '', '#' + s);
    buildView(); reload(); window.scrollTo(0, 0);
  }
  function reload() { sig = ''; firstLoad[state.s] = true; if ($('list')) $('list').innerHTML = '<div class="skel"></div><div class="skel"></div>'; load(true); }

  // ── Ringkasan ────────────────────────────────────────────────────────────
  function renderHome(S) {
    const d = S.depo, w = S.wd, r = S.req, hrs = S.hours, hr = new Date().getHours();
    const max = Math.max(1, ...hrs.in, ...hrs.out);
    const cmp = d.yesterday_sum > 0 ? Math.round((d.today_sum - d.yesterday_sum) / d.yesterday_sum * 100) : null;
    const types = Object.entries(r.types).map(([t, n]) => `${({ change_bank: 'Rekening', refund_level: 'Refund Level', refund_wd_hold: 'Refund WD', threads_campaign: 'Threads 1', threads_campaign_step2: 'Threads 2' })[t] || t} ${n}`).join(' · ');
    const net = d.today_sum - w.today_sum;
    $('home').innerHTML = `
      <div class="sec-t"><span>Butuh tindakan</span><span>${d.pending + w.pending + r.total} antrian</span></div>
      <div class="queue">
        <div class="qrow" data-go="depo" data-tab="pending"><div class="qi" style="background:rgba(245,158,11,.15)">💳</div>
          <div class="qt"><b>Deposit pending</b><span>${rpS(d.pending_sum)}${d.oldest ? ' · tertua ' + dur(nowS() - d.oldest) : ''}</span></div><div class="qn num" style="color:var(--am)">${d.pending}</div><div class="go">›</div></div>
        <div class="qrow" data-go="wd" data-tab="pending"><div class="qi" style="background:rgba(239,68,68,.15)">💸</div>
          <div class="qt"><b>Withdraw pending</b><span>${rpS(w.pending_sum)}${w.oldest ? ' · tertua ' + dur(nowS() - w.oldest) : ''}</span></div><div class="qn num" style="color:var(--rd)">${w.pending}</div><div class="go">›</div></div>
        <div class="qrow" data-go="req" data-tab="pending"><div class="qi" style="background:rgba(96,165,250,.15)">📨</div>
          <div class="qt"><b>Permintaan pending</b><span>${types || 'Tidak ada'}${r.old ? ' · tertua ' + dur(nowS() - r.old) : ''}</span></div><div class="qn num" style="color:var(--bl)">${r.total}</div><div class="go">›</div></div>
        <div class="qrow" data-go="wd" data-tab="hold"><div class="qi" style="background:rgba(167,139,250,.15)">⏸</div>
          <div class="qt"><b>Withdraw hold</b><span>${rpS(w.hold_sum)} tertahan</span></div><div class="qn num" style="color:var(--vi)">${w.hold}</div><div class="go">›</div></div>
      </div>

      <div class="sec-t"><span>Hari ini</span><span>${new Date().toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'short' })}</span></div>
      <div class="grid">
        <div class="st" style="--c:var(--gr)" data-go="depo" data-tab="confirmed"><small>Deposit masuk ${cmp === null ? '' : `<span class="${cmp >= 0 ? 'up' : 'dn'}">${cmp >= 0 ? '▲' : '▼'}${Math.abs(cmp)}%</span>`}</small><b class="num">${rpS(d.today_sum)}</b><span>${d.today} transaksi · ${d.first} user baru</span></div>
        <div class="st" style="--c:var(--rd)" data-go="wd" data-tab="approved"><small>Withdraw keluar</small><b class="num">${rpS(w.today_sum)}</b><span>${w.today} approved · ${w.rejected} ditolak</span></div>
        <div class="st" style="--c:var(--pk)" data-go="depo" data-tab="nc"><small>Accepted v2</small><b class="num">${d.nc}</b><span>${rpS(d.nc_sum)}</span></div>
        <div class="st" style="--c:var(--cy)"><small>User</small><b class="num">${S.users.online} online</b><span>${S.users.new} daftar hari ini</span></div>
      </div>
      <div class="box">
        <div class="chart-h"><span>Arus kas per jam</span><span><i style="background:var(--gr)"></i>Masuk<i style="background:var(--rd)"></i>Keluar</span></div>
        <div class="bars">${hrs.in.map((v, h) => `<div title="${String(h).padStart(2, '0')}:00 · masuk ${rp(v)} · keluar ${rp(hrs.out[h])}">
          <span class="${v ? 'in' : 'z'}" style="height:${v ? Math.max(6, v / max * 100) : 4}%;${h === hr ? 'outline:1px solid #fbbf24' : ''}"></span>
          <span class="${hrs.out[h] ? 'out' : 'z'}" style="height:${hrs.out[h] ? Math.max(6, hrs.out[h] / max * 100) : 4}%"></span></div>`).join('')}</div>
        <div class="bars-x"><span>00</span><span>06</span><span>12</span><span>18–23</span></div>
        <div class="net">
          <div><small>Masuk</small><b class="num up">${rpS(d.today_sum)}</b></div>
          <div><small>Keluar</small><b class="num dn">${rpS(w.today_sum)}</b></div>
          <div><small>Selisih</small><b class="num ${net >= 0 ? 'up' : 'dn'}">${net < 0 ? '−' : ''}${rpS(Math.abs(net))}</b></div>
        </div>
      </div>
      <div class="grid">
        <div class="st" style="--c:var(--mute)" data-go="depo" data-tab="expired"><small>Depo gagal</small><b class="num">${d.expired + d.rejected}</b><span>${d.expired} expired · ${d.rejected} ditolak</span></div>
        <div class="st" style="--c:var(--am)" data-go="depo" data-tab="today"><small>Depo dibuat</small><b class="num">${d.created}</b><span>${d.today} sukses</span></div>
        <div class="st" style="--c:var(--bl)" data-go="wd" data-tab="today"><small>WD diajukan</small><b class="num">${w.created}</b><span>${w.refunded} refund hari ini</span></div>
        <div class="st" style="--c:var(--vi)" data-go="req" data-tab="all"><small>Permintaan diproses</small><b class="num">${r.done_today}</b><span>hari ini</span></div>
      </div>`;
  }

  function badges(S) {
    $('bd-depo').textContent = S.depo.pending || '';
    $('bd-wd').textContent = S.wd.pending || '';
    $('bd-req').textContent = S.req.total || '';
    const cfg = SECTIONS[state.s];
    if (cfg) cfg.tabs.forEach(([v, , fn]) => { const e = document.querySelector(`em[data-c="${v}"]`); if (e) e.textContent = fn ? (fn(S) || '') : ''; });
  }

  // ── Kartu ───────────────────────────────────────────────────────────────
  const userHead = (d, amountLabel) => `
    <div class="r1">
      <div class="av" style="background:${COLORS[d.uid % COLORS.length]}">${esc((d.user || '?').slice(0, 1))}</div>
      <div class="who"><b>${esc(d.user)}</b><span class="num">#${d.id} · 🕒 ${clock(d.done || d.created)} · ${d.done ? '✓ ' + ago(d.done) : ago(d.created)}</span></div>
      ${d.amount ? `<div class="amt" data-copy="${Math.round(d.amount)}"><b class="num">${rp(d.amount)}</b><span>${amountLabel}</span></div>` : `<div class="amt"><span>${amountLabel}</span></div>`}
    </div>`;
  const userChips = d => [
    d.upline ? `<span class="chip ${d.promotor ? 'c-vi' : ''}">${d.promotor ? '📣 ' : '↑ '}${esc(d.upline)}</span>` : '',
    `<span class="chip">${esc(d.member)}</span>`,
    d.abuse ? '<span class="chip c-ab">⚠ Abuse</span>' : '',
  ].join('');
  const userDet = (d, extra = []) => `<div class="det">${[
    ['Saldo depo', rp(d.bal_dep)], ['Saldo WD', rp(d.bal_wd)], ['Pangkat', esc(d.member)], ['User ID', d.uid],
    ['Daftar', dt(d.joined)], ['Dibuat', dt(d.created)], ...extra,
    [d.done ? 'Diproses' : 'Pesan grup', d.done ? dt(d.done) : (d.tg ? 'Terhubung' : 'Tidak ada')],
  ].map(([k, v]) => `<div><span>${k}</span><b>${v}</b></div>`).join('')}</div>`;
  const infoBtn = d => `<button class="b gh" data-do="info" data-id="${d.id}" title="Detail">${open.has(state.s + d.id) ? '▴' : 'ⓘ'}</button>`;
  const btn = (cls, act, id, label) => `<button class="b ${cls}" data-do="${act}" data-id="${id}">${label}</button>`;

  const ST_DEPO = { pending: ['c-am', 'Pending'], expired: ['c-sl', 'Expired'], confirmed: ['c-gr', 'Sukses'], rejected: ['c-rd', 'Ditolak'] };
  function cardDepo(d) {
    let expBar = '';
    if (d.status === 'pending') {
      const total = expH * 3600, used = nowS() - d.created, left = total - used;
      expBar = `<div class="exp ${left < 1800 ? 'late' : ''}"><span>⌛</span><div class="bar"><i style="width:${Math.min(100, Math.max(0, used / total * 100))}%"></i></div><b class="num">${left > 0 ? 'expired ' + dur(left) + ' lagi' : 'segera expired'}</b></div>`;
    }
    let acts = `<div class="acts">${infoBtn(d)}</div>`;
    if (d.status === 'pending') acts = `<div class="acts">${infoBtn(d)}${btn('ok', 'confirm', d.id, '✓ Acc')}${btn('nc', 'confirm_nc', d.id, 'Acc V2')}${btn('no', 'reject', d.id, '✕ Tolak')}</div>`;
    else if (d.status === 'expired' || d.status === 'rejected') acts = `<div class="acts">${infoBtn(d)}${btn('ok', 'confirm', d.id, '⚡ Acc ' + (d.status === 'expired' ? 'Expired' : 'Ulang'))}${btn('nc', 'confirm_nc', d.id, 'Acc V2')}</div>`;
    else if (d.status === 'confirmed') acts = `<div class="acts">${infoBtn(d)}${btn('no', 'cancel', d.id, '↩️ Batalkan & Tarik Saldo')}</div>`;
    return `${userHead(d, d.method)}
      <div class="chips"><span class="chip ${ST_DEPO[d.status][0]}">${ST_DEPO[d.status][1]}</span>
        ${d.prev_ok ? `<span class="chip">Depo ke-${d.prev_ok + 1}</span>` : '<span class="chip c-bl">★ Depo pertama</span>'}
        ${userChips(d)}${d.nc ? '<span class="chip c-pk">Accepted v2</span>' : ''}
        ${d.proof ? `<span class="chip"><a href="#" data-img="${esc(d.proof)}">📎 Bukti</a></span>` : ''}</div>
      ${expBar}${d.note ? `<div class="note">📝 ${esc(d.note)}</div>` : ''}
      ${userDet(d, [['Total depo', rpS(d.user_total)]])}${acts}`;
  }

  const ST_WD = { pending: ['c-am', 'Pending'], hold: ['c-vi', 'Hold'], approved: ['c-gr', 'Approved'], rejected: ['c-rd', 'Ditolak'], refunded: ['c-bl', 'Refund'] };
  function cardWd(d) {
    let acts = `<div class="acts">${infoBtn(d)}</div>`;
    if (d.status === 'pending') acts = `<div class="acts">${infoBtn(d)}${btn('ok', 'approve', d.id, '✓ Approve')}${btn('hd', 'hold', d.id, '⏸ Hold')}${btn('no', 'reject', d.id, '✕ Tolak')}</div>`;
    else if (d.status === 'hold') acts = `<div class="acts">${infoBtn(d)}${btn('ok', 'approve', d.id, '✓ Approve')}${btn('rf', 'refund', d.id, '↩️ Refund Saldo')}</div>`;
    const warns = [
      d.bank_changed ? 'Rekening WD berbeda dengan rekening di profil saat ini.' : '',
      d.acc_shared ? `Nomor rekening ini juga dipakai ${d.acc_shared} WD milik user lain.` : '',
      d.wd_open ? `User punya ${d.wd_open} WD lain yang masih pending/hold.` : '',
      d.dep_total <= 0 ? 'User belum pernah deposit.' : '',
    ].filter(Boolean);
    return `${userHead(d, 'withdraw')}
      <div class="bankbox"><div class="bt"><b>${esc(d.acc)}</b><span>${esc(d.bank)} · a/n ${esc(d.accname)}</span></div>
        <button class="cp" data-cp="${esc(d.acc)}" data-l="No. rekening">📋 No.</button><button class="cp" data-cp="${Math.round(d.amount)}" data-l="Nominal">📋 Rp</button></div>
      <div class="chips"><span class="chip ${ST_WD[d.status][0]}">${ST_WD[d.status][1]}</span>
        <span class="chip">Depo ${rpS(d.dep_total)}</span><span class="chip">WD ok ${d.wd_ok_n}× · ${rpS(d.wd_ok_sum)}</span>${userChips(d)}</div>
      ${d.status === 'pending' || d.status === 'hold' ? warns.map(w => `<div class="warn">⚠ ${w}</div>`).join('') : ''}
      ${d.note ? `<div class="note">📝 ${esc(d.note)}</div>` : ''}
      ${userDet(d, [['Total depo', rpS(d.dep_total)], ['Total WD ok', rpS(d.wd_ok_sum)]])}${acts}`;
  }

  const ST_REQ = { pending: ['c-am', 'Pending'], approved: ['c-gr', 'Disetujui'], rejected: ['c-rd', 'Ditolak'] };
  function cardReq(d) {
    const x = d.detail || {};
    let body = '';
    if (d.type === 'change_bank') {
      body = `<div class="cmp"><div><small>Lama</small><b>${esc(x.old[1] || '-')}</b><span>${esc(x.old[0] || '-')} · ${esc(x.old[2] || '-')}</span></div>
        <div class="arr">→</div><div><small>Baru</small><b>${esc(x.new[1])}</b><span>${esc(x.new[0])} · ${esc(x.new[2])}</span></div></div>`;
    } else if (d.type === 'refund_wd_hold') {
      body = x.wd ? `<div class="bankbox"><div class="bt"><b>WD #${x.wd.id} · ${rp(d.amount)}</b><span>${esc(x.wd.bank)} · status ${esc(x.wd.status)} · ${dt(x.wd.created)}</span></div></div>`
                  : '<div class="warn">⚠ WD tidak ditemukan.</div>';
      if (x.wd && x.wd.status !== 'hold' && d.status === 'pending') body += '<div class="warn">⚠ WD sudah tidak berstatus Hold, approve akan gagal.</div>';
    } else if (d.type === 'refund_level') {
      body = `<div class="bankbox"><div class="bt"><b>${esc(x.level || 'Tanpa level')}</b><span>Harga ${rp(x.base)} · potongan ${x.cut}% · kembali ${rp(d.amount)} ke Saldo Beli</span></div></div>`;
      if (!x.has_level && d.status === 'pending') body += '<div class="warn">⚠ User tidak punya level aktif, approve akan gagal.</div>';
    } else {
      body = (x.images && x.images.length ? `<div class="thumbs">${x.images.map(i => `<img src="${esc(i)}" data-img="${esc(i)}" loading="lazy" alt="">`).join('')}</div>` : '')
           + `<div class="chips"><span class="chip c-gr">Reward ${rp(d.amount)}</span><span class="chip">${x.ref_n} referral</span>${x.views ? '<span class="chip">Min 5K views</span>' : ''}</div>`;
    }
    const acts = d.status === 'pending'
      ? `<div class="acts">${infoBtn(d)}${btn('ok', 'approve', d.id, '✓ Setujui')}${btn('no', 'reject', d.id, '✕ Tolak')}</div>`
      : `<div class="acts">${infoBtn(d)}</div>`;
    return `${userHead(d, esc(d.label))}
      <div class="chips"><span class="chip ${ST_REQ[d.status] ? ST_REQ[d.status][0] : ''}">${ST_REQ[d.status] ? ST_REQ[d.status][1] : esc(d.status)}</span><span class="chip c-bl">${esc(d.label)}</span>${userChips(d)}</div>
      ${body}${d.note ? `<div class="note">📝 ${esc(d.note)}</div>` : ''}${userDet(d)}${acts}`;
  }

  const CARD = { depo: cardDepo, wd: cardWd, req: cardReq };

  function renderList(j, force) {
    const s = state.s, L = $('list');
    if (!L) return;
    cache = Object.fromEntries(j.items.map(d => [d.id, d]));
    const total = j.items.reduce((a, d) => a + (d.amount || 0), 0);
    $('meta-l').textContent = `${j.items.length}${j.items.length >= 100 ? '+' : ''} data${total ? ' · ' + rp(total) : ''}`;
    if (!force && j.sig === sig) return;
    sig = j.sig;
    const kn = known[s] || new Set(), fl = firstLoad[s];
    L.innerHTML = j.items.length
      ? j.items.map(d => `<div class="card s-${d.status}${open.has(s + d.id) ? ' open' : ''}${!fl && !kn.has(d.id) ? ' flash' : ''}" id="c${d.id}">${CARD[s](d)}</div>`).join('')
      : `<div class="empty"><b class="big">${state[s].tab === 'pending' ? '🎉' : '🗂'}</b>${state[s].q ? 'Tidak ada hasil untuk “' + esc(state[s].q) + '”.' : (state[s].tab === 'pending' ? 'Semua sudah diproses.' : 'Tidak ada data.')}</div>`;
    if (!fl && state[s].tab === 'pending' && j.items.some(d => !kn.has(d.id))) { haptic('warning'); toast('🔔 ' + SECTIONS[s].title + ' baru masuk'); }
    known[s] = new Set(j.items.map(d => d.id));
    firstLoad[s] = false;
  }

  function render(j, force) {
    lastJ = j;
    badges(j.summary);
    if (state.s === 'home') { if (force || j.sig !== sig) { sig = j.sig; renderHome(j.summary); } }
    else renderList(j, force);
  }

  function setLive(on, text) { $('live').classList.toggle('off', !on); $('live-t').textContent = text; }

  async function load(force) {
    if (busy && !force) return;
    const s = state.s, st = state[s] || {};
    try {
      const j = await api('list', { s, tab: st.tab || '', q: st.q || '', f: st.f || '', sort: st.sort || '' });
      if (s !== state.s) return; // pindah bagian saat menunggu
      if (!j.ok) {
        $('view').innerHTML = `<div class="err"><b>⛔ ${esc(j.error || 'Gagal memuat')}</b>Tutup mini app lalu ketik /menu lagi di chat pribadi bot.</div>`;
        setLive(false, 'Offline'); stop(); return;
      }
      skew = Math.floor(Date.now() / 1000) - j.now;
      expH = j.expire_hours || 3;
      $('me').textContent = j.me.replace('Menu ', '');
      setLive(true, new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
      render(j, force);
    } catch (e) { setLive(false, 'Koneksi putus'); }
  }
  function start() { stop(); timer = setInterval(() => { if (!document.hidden) load(); }, 4000); }
  function stop() { if (timer) clearInterval(timer); timer = null; }

  function confirmBox(text) {
    return new Promise(res => {
      if (tg && tg.showConfirm && tg.isVersionAtLeast && tg.isVersionAtLeast('6.2')) tg.showConfirm(text, ok => res(!!ok));
      else res(window.confirm(text));
    });
  }
  async function act(id, what, reason) {
    const s = state.s;
    busy = true;
    document.querySelectorAll(`#c${id} .b`).forEach(b => b.disabled = true);
    try {
      const j = await api('act', {}, { s, id, do: what, reason: reason || '' });
      if (j.ok) { haptic('success'); toast('✅ ' + j.message); } else { haptic('error'); toast(j.error || 'Gagal', true); }
    } catch (e) { toast('Koneksi gagal, coba lagi', true); }
    busy = false; open.delete(s + id); sig = ''; load(true);
  }

  // Sheet alasan (tolak / hold / refund)
  const REASONS = {
    'depo:reject': ['❌ Tolak deposit', 'Tolak Deposit', 'no', ['Dana belum masuk', 'Nominal tidak sesuai', 'Bukti tidak valid', 'Deposit ganda', 'Kode unik salah']],
    'depo:cancel': ['↩️ Batalkan deposit sukses (saldo ditarik kembali)', 'Batalkan & Tarik Saldo', 'no', ['Salah acc, dana tidak masuk', 'Deposit ganda', 'Terindikasi penipuan', 'Dana dikembalikan ke user']],
    'wd:reject':   ['❌ Tolak withdraw (saldo dikembalikan)', 'Tolak & Kembalikan', 'no', ['Data rekening tidak sesuai', 'Nama rekening tidak cocok', 'Rekening tidak aktif', 'Terindikasi abuse']],
    'wd:hold':     ['⏸ Hold withdraw (saldo TIDAK dikembalikan)', 'Hold Withdraw', 'hd', ['Peninjauan antrean audit keuangan', 'Verifikasi aktivitas akun', 'Menunggu konfirmasi data']],
    'wd:refund':   ['↩️ Refund saldo WD hold', 'Refund Saldo', 'rf', ['Refund saldo dari status Hold', 'Hasil peninjauan selesai']],
    'req:reject':  ['❌ Tolak permintaan', 'Tolak Permintaan', 'no', ['Ketentuan belum terpenuhi', 'Bukti tidak valid', 'Views belum mencapai syarat', 'Data rekening tidak valid']],
  };
  let sheetCtx = null;
  const sheet = $('sheet');
  function openSheet(key, d) {
    const [title, okLabel, cls, quick] = REASONS[key];
    sheetCtx = { id: d.id, what: key.split(':')[1] };
    $('sh-t').textContent = title;
    $('sh-p').textContent = `#${d.id} · ${d.user}${d.amount ? ' · ' + rp(d.amount) : ''}`
      + (key === 'depo:cancel' ? `\nSaldo user sekarang: Beli ${rp(d.bal_dep)} · WD ${rp(d.bal_wd)}. Ditarik dari Saldo Beli dulu, kurangnya dari Saldo WD, sisanya jadi minus. Komisi upline yang belum cair ikut dibatalkan.` : '');
    $('sh-r').value = ''; $('sh-r').placeholder = 'Alasan / catatan (opsional, terlihat oleh user)';
    $('sh-q').innerHTML = quick.map(q => `<button type="button">${esc(q)}</button>`).join('');
    $('sh-ok').textContent = okLabel; $('sh-ok').className = 'b ' + cls;
    sheet.classList.add('on');
  }
  $('sh-q').onclick = e => { const b = e.target.closest('button'); if (b) $('sh-r').value = b.textContent; };
  $('sh-x').onclick = () => sheet.classList.remove('on');
  sheet.onclick = e => { if (e.target === sheet) sheet.classList.remove('on'); };
  $('sh-ok').onclick = () => { sheet.classList.remove('on'); if (sheetCtx) act(sheetCtx.id, sheetCtx.what, $('sh-r').value.trim()); };

  $('lb').onclick = () => $('lb').classList.remove('on');

  // ── Event utama ─────────────────────────────────────────────────────────
  $('view').addEventListener('click', async e => {
    const g = e.target.closest('[data-go]');
    if (g) return go(g.dataset.go, g.dataset.tab);
    const img = e.target.closest('[data-img]');
    if (img) { e.preventDefault(); $('lb-img').src = img.dataset.img; $('lb').classList.add('on'); return; }
    const cp = e.target.closest('[data-cp]');
    if (cp) return copy(cp.dataset.cp, cp.dataset.l);
    const amt = e.target.closest('.amt');
    if (amt) return copy(amt.dataset.copy, 'Nominal');
    const b = e.target.closest('button[data-do]');
    if (!b) return;
    const s = state.s, id = +b.dataset.id, what = b.dataset.do, d = cache[id];
    if (!d) return;
    if (what === 'info') {
      const k = s + id;
      open.has(k) ? open.delete(k) : open.add(k);
      $('c' + id).classList.toggle('open', open.has(k));
      b.textContent = open.has(k) ? '▴' : 'ⓘ';
      return;
    }
    if (REASONS[s + ':' + what]) return openSheet(s + ':' + what, d);
    const head = `#${id} · ${d.user}${d.amount ? ' · ' + rp(d.amount) : ''}`;
    let text = '';
    if (s === 'depo') text = what === 'confirm_nc'
      ? `ACC V2\n${head}\n\nSaldo masuk ke user. Tidak ada komisi ke upline${d.upline ? ' (' + d.upline + ')' : ''} dan tidak dihitung untuk promotor.`
      : `${d.status === 'pending' ? 'ACC DEPOSIT' : 'ACC ' + ST_DEPO[d.status][1].toUpperCase()}\n${head}${d.upline ? '\nKomisi diproses ke upline ' + d.upline : ''}`;
    if (s === 'wd') text = `APPROVE WITHDRAW\n${head}\n${d.bank} ${d.acc} a/n ${d.accname}\n\nPastikan dana sudah ditransfer.`;
    if (s === 'req') text = `SETUJUI ${d.label.toUpperCase()}\n${head}`;
    if (await confirmBox(text)) act(id, what);
  });

  document.querySelectorAll('.nv').forEach(n => n.onclick = () => go(n.dataset.s));
  $('refresh').onclick = e => { const t = e.currentTarget; t.classList.remove('spin'); void t.offsetWidth; t.classList.add('spin'); sig = ''; load(true); };
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(true); });

  buildView();
  if (!INIT) {
    $('view').innerHTML = '<div class="err"><b>Buka lewat Telegram</b>Ketik /menu di chat pribadi bot untuk membuka Menu Admin.</div>';
    $('me').textContent = 'Tidak ada sesi Telegram'; setLive(false, 'Offline');
    return;
  }
  firstLoad[state.s] = true;
  load(true); start();
  setInterval(() => { if (lastJ && !busy && !document.hidden) render(lastJ, true); }, 20000);
})();
</script>
</body>
</html>
