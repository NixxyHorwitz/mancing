<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (!isset($update)) {
    $json   = file_get_contents('php://input');
    $update = json_decode($json, true);
}

if (!$update) { http_response_code(200); return; }

$token = setting($pdo, 'tg_bot_token', '');
if (!$token) { http_response_code(200); exit; }

$admin_chat_id = setting($pdo, 'tg_chat_id', '');

// ── Helpers ─────────────────────────────────────────────────────────────────

function wh_tg_api(string $token, string $method, array $post): ?array {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
    ]);
    $res = curl_exec($ch);
    return json_decode($res ?: '{}', true);
}

function wh_tg_api_json(string $token, string $method, array $body): void {
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    curl_exec($ch);
}

function answer_cb(string $token, string $cb_id, string $text): void {
    wh_tg_api($token, 'answerCallbackQuery', ['callback_query_id' => $cb_id, 'text' => $text, 'show_alert' => false]);
}

function edit_msg(string $token, $chat_id, $msg_id, string $text, ?array $kb = null): void {
    $body = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($kb !== null) $body['reply_markup'] = ['inline_keyboard' => $kb];
    wh_tg_api_json($token, 'editMessageText', $body);
}

function edit_caption(string $token, $chat_id, $msg_id, string $caption, ?array $kb = null): void {
    $body = ['chat_id' => $chat_id, 'message_id' => $msg_id, 'caption' => $caption, 'parse_mode' => 'HTML'];
    if ($kb !== null) $body['reply_markup'] = ['inline_keyboard' => $kb];
    wh_tg_api_json($token, 'editMessageCaption', $body);
}

function send_msg(string $token, $chat_id, string $text, ?array $kb = null, $thread_id = null): ?int {
    $body = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($kb !== null) $body['reply_markup'] = ['inline_keyboard' => $kb];
    if ($thread_id) $body['message_thread_id'] = (int)$thread_id;
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);
    $res = curl_exec($ch);
    $data = json_decode($res ?: '{}', true);
    return $data['result']['message_id'] ?? null;
}

/** Save pending reject state to settings table */
function set_tg_state(PDO $pdo, $chat_id, string $state): void {
    $key = 'tg_state_' . $chat_id;
    $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
        ->execute([$key, $state, $state]);
}

/** Get and clear pending state */
function get_tg_state(PDO $pdo, $chat_id): string {
    $key = 'tg_state_' . $chat_id;
    $s = $pdo->prepare("SELECT `value` FROM settings WHERE `key`=?");
    $s->execute([$key]);
    return (string)($s->fetchColumn() ?: '');
}

function clear_tg_state(PDO $pdo, $chat_id): void {
    $pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute(['tg_state_' . $chat_id]);
}

/** Do the actual deposit reject */
function do_depo_reject(PDO $pdo, int $id, string $reason): string {
    $pdo->beginTransaction();
    $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? FOR UPDATE");
    $dep->execute([$id]);
    $dep = $dep->fetch();
    if (!$dep || $dep['status'] !== 'pending') {
        $pdo->rollBack();
        return 'Deposit tidak ditemukan atau bukan pending.';
    }
    $note = $reason ?: 'Bukti transfer tidak valid atau belum masuk';
    $pdo->prepare("UPDATE deposits SET status='rejected', admin_note=? WHERE id=?")->execute([$note, $id]);
    $pdo->commit();
    return 'ok';
}

/** Do the actual withdraw reject */
function do_wd_reject(PDO $pdo, int $id, string $reason): string {
    $pdo->beginTransaction();
    $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
    $wd->execute([$id]);
    $wd = $wd->fetch();
    if (!$wd || $wd['status'] !== 'pending') {
        $pdo->rollBack();
        return 'WD tidak ditemukan atau bukan pending.';
    }
    $note = $reason ?: 'Dibatalkan oleh Admin (Data rekening tidak sesuai)';
    $pdo->prepare("UPDATE withdrawals SET status='rejected', admin_note=? WHERE id=?")->execute([$note, $id]);
    $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
    $pdo->commit();
    return 'ok';
}

/** Do the actual withdraw hold (no refund) */
function do_wd_hold(PDO $pdo, int $id, string $reason): string {
    $pdo->beginTransaction();
    $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? FOR UPDATE");
    $wd->execute([$id]);
    $wd = $wd->fetch();
    if (!$wd || $wd['status'] !== 'pending') {
        $pdo->rollBack();
        return 'WD tidak ditemukan atau bukan pending.';
    }
    $note = $reason ?: 'Peninjauan antrean audit keuangan';
    $pdo->prepare("UPDATE withdrawals SET status='hold', admin_note=?, processed_at=NOW() WHERE id=?")->execute([$note, $id]);
    $pdo->commit();
    return 'ok';
}

// ── Callback Query Handler ───────────────────────────────────────────────────

if (isset($update['callback_query'])) {
    $cb      = $update['callback_query'];
    $data    = $cb['data'] ?? '';
    $chat_id = $cb['message']['chat']['id'] ?? '';
    $msg_id  = $cb['message']['message_id'] ?? '';
    $cb_id   = $cb['id'] ?? '';
    $orig    = $cb['message']['text'] ?? '';
    $thread_id = $cb['message']['message_thread_id'] ?? null;

    if ((string)$chat_id !== (string)$admin_chat_id) {
        answer_cb($token, $cb_id, "⚠️ Akses Ditolak! Chat ID anda ({$chat_id}) tidak cocok dengan ID Admin di pengaturan.");
        http_response_code(200); return;
    }

    // ── GLOBAL CHAT (tombol notifikasi 🚨 Deteksi Abuse) ─────────────────────
    if (preg_match('/^gc_(del|mute|block)_(\d+)$/', $data, $m)) {
        require_once __DIR__ . '/lib/global_chat.php';
        gc_ensure_schema($pdo);
        $id = (int)$m[2];
        $who = 'telegram:' . ($cb['from']['username'] ?? $cb['from']['id'] ?? '?');
        if ($m[1] === 'del') {
            $st = $pdo->prepare("UPDATE gc_messages SET is_deleted = 1, deleted_by = ?, deleted_at = NOW() WHERE id = ? AND is_deleted = 0");
            $st->execute([$who, $id]);
            $uid = (int)$pdo->query("SELECT user_id FROM gc_messages WHERE id = " . $id)->fetchColumn();
            gc_admin_log($pdo, $who, 'msg.delete', $uid ?: null, ['msg_id' => $id]);
            $txt = $st->rowCount() ? "🗑 Pesan #{$id} dihapus." : "Pesan #{$id} sudah terhapus / tidak ada.";
        } elseif ($m[1] === 'mute') {
            $pdo->prepare("INSERT INTO gc_user_overrides (user_id, muted_until) VALUES (?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
                           ON DUPLICATE KEY UPDATE muted_until = VALUES(muted_until)")->execute([$id]);
            gc_admin_log($pdo, $who, 'user.mute', $id, ['hours' => 24]);
            $txt = "🔇 User #{$id} dibisukan 24 jam.";
        } else {
            $pdo->prepare("INSERT INTO gc_user_overrides (user_id, can_send) VALUES (?, 0)
                           ON DUPLICATE KEY UPDATE can_send = 0")->execute([$id]);
            gc_admin_log($pdo, $who, 'user.block', $id);
            $txt = "⛔ User #{$id} diblokir dari mengirim pesan.";
        }
        answer_cb($token, $cb_id, $txt);
        http_response_code(200); return;
    }

    // ── REFRESH ──────────────────────────────────────────────────────────────
    if (preg_match('/^refresh_(depo|wd)_(\d+)$/', $data, $m)) {
        $type = $m[1];
        $id   = (int)$m[2];

        if ($type === 'depo') {
            $row = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.id=?");
            $row->execute([$id]); $row = $row->fetch();
            if (!$row) {
                answer_cb($token, $cb_id, '⚠️ Deposit tidak ditemukan.');
            } elseif ($row['status'] === 'rejected' && (string)$row['admin_note'] === DEPO_EXPIRED_NOTE) {
                // Expired otomatis (3 jam): tetap sediakan tombol Acc Expired
                [$msg, $kb] = depo_expired_tg($row);
                edit_msg($token, $chat_id, $msg_id, $msg, $kb);
                answer_cb($token, $cb_id, '⌛ Deposit ini sudah expired (tidak dibayar ' . DEPO_EXPIRE_HOURS . ' jam).');
            } elseif ($row['status'] !== 'pending') {
                if ($row['status'] === 'confirmed') {
                    $msg = "✅ <b>DEPOSIT QRIS BERHASIL (CONFIRMED)</b>\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username']) . "</code>\n";
                    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
                    $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s', strtotime($row['confirmed_at'] ?? $row['created_at'])) . " WIB</code>\n";
                    $msg .= "💳 <b>Method:</b> <code>" . depo_method_label($row) . "</code>\n";
                    if (!empty($row['no_commission'])) {
                        $msg .= "💠 <b>Tipe:</b> <code>Accepted v2</code>\n";
                    }
                    $msg .= "✅ <b>Status:</b> <code>Sukses</code>\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "<i>Pembayaran terverifikasi otomatis oleh sistem. Saldo telah ditambahkan ke akun pengguna.</i>";
                } else {
                    $msg = "❌ <b>DEPOSIT QRIS DITOLAK (REJECTED)</b>\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username']) . "</code>\n";
                    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
                    $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s', strtotime($row['created_at'])) . " WIB</code>\n";
                    $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
                    $msg .= "❌ <b>Status:</b> <code>Rejected</code>\n";
                    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                    $msg .= "<i>Alasan: " . htmlspecialchars($row['admin_note'] ?: '-') . "</i>";
                }
                edit_msg($token, $chat_id, $msg_id, $msg, []);
                answer_cb($token, $cb_id, '✅ Status deposit berhasil diperbarui!');
            } else {
                answer_cb($token, $cb_id, '⏳ Deposit masih pending, belum terbayar.');
            }
        } else {
            $row = $pdo->prepare("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE w.id=?");
            $row->execute([$id]); $row = $row->fetch();
            if (!$row) {
                answer_cb($token, $cb_id, '⚠️ WD tidak ditemukan.');
            } elseif ($row['status'] !== 'pending') {
                $icon = $row['status'] === 'approved' ? '✅' : '❌';
                $status_lbl = $row['status'] === 'approved' ? 'Approved' : ($row['status'] === 'hold' ? 'Hold' : 'Rejected');
                
                $msg = "<b>💸 WITHDRAW DI-HANDLE ({$status_lbl})</b>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username']) . "</code>\n";
                $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
                $msg .= "🏦 <b>Bank:</b> <code>" . htmlspecialchars($row['bank_name']) . " - " . htmlspecialchars($row['account_number']) . "</code>\n";
                $msg .= "👨‍💼 <b>a/n:</b> <code>" . htmlspecialchars($row['account_name']) . "</code>\n";
                $msg .= "📌 <b>Status:</b> <code>{$icon} {$status_lbl}</code>\n";
                if ($row['admin_note']) {
                    $msg .= "📝 <b>Note:</b> <code>" . htmlspecialchars($row['admin_note']) . "</code>\n";
                }
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "<i>Penarikan dana telah diproses oleh admin.</i>";

                edit_msg($token, $chat_id, $msg_id, $msg, []);
                answer_cb($token, $cb_id, '✅ Status WD berhasil diperbarui!');
            } else {
                answer_cb($token, $cb_id, '⏳ WD masih pending, belum diproses admin.');
            }
        }
        http_response_code(200); exit;
    }

    // ── APPROVE ──────────────────────────────────────────────────────────────
    if (preg_match('/^depo_approve_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        $pdo->beginTransaction();
        $dep = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id=? FOR UPDATE");
        $dep->execute([$id]); $dep = $dep->fetch();
        if ($dep && $dep['status'] === 'pending') {
            $pdo->prepare("UPDATE deposits SET status='confirmed', confirmed_at=NOW() WHERE id=?")->execute([$id]);
            $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")->execute([$dep['amount'], $dep['user_id']]);
            $comm = credit_deposit_referral_commission($pdo, (int)$dep['user_id'], (float)$dep['amount']);
            $pdo->commit();
            $toast = $comm > 0 ? '✅ Deposit Approved! (+Komisi ' . format_rp($comm) . ' ke upline)' : '✅ Deposit Approved!';
            answer_cb($token, $cb_id, $toast);
            
            $msg = "✅ <b>DEPOSIT QRIS BERHASIL (CONFIRMED)</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($dep['username']) . "</code>\n";
            $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
            if ($comm > 0) {
                $msg .= "🎁 <b>Komisi Referral:</b> <code>" . format_rp($comm) . "</code> (dikirim ke upline)\n";
            }
            $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
            $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
            $msg .= "✅ <b>Status:</b> <code>Approved via Bot</code>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Deposit telah disetujui secara manual oleh admin via Telegram Bot.</i>";
            edit_msg($token, $chat_id, $msg_id, $msg, []);
        } else {
            $pdo->rollBack();
            answer_cb($token, $cb_id, '⚠️ Sudah diproses atau tidak ditemukan.');
        }
        http_response_code(200); exit;
    }

    // ── ACC TANPA KOMISI (pending / expired) ─────────────────────────────────
    if (preg_match('/^depo_(approvenc|accexpnc)_(\d+)$/', $data, $m)) {
        $res = depo_admin_confirm($pdo, (int)$m[2], false, 'Bot');
        if ($res['ok']) {
            answer_cb($token, $cb_id, '✅ Deposit Accepted v2!');
            $label = $res['was'] === 'Approve' ? 'Accepted v2 via Bot' : 'Acc Expired V2 via Bot';
            edit_msg($token, $chat_id, $msg_id, depo_confirmed_tg_text($res['dep'], 0, $label,
                'Saldo masuk ke user. Tidak ada komisi referral dan tidak dihitung untuk promotor.'), []);
        } else {
            answer_cb($token, $cb_id, '⚠️ ' . $res['message']);
        }
        http_response_code(200); exit;
    }

    // ── DEPO ACC EXPIRED ─────────────────────────────────────────────────────
    if (preg_match('/^depo_accexp_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        $pdo->beginTransaction();
        try {
            $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? FOR UPDATE");
            $dep->execute([$id]); $dep = $dep->fetch();
            if ($dep && $dep['status'] === 'rejected') {
                // 1. Credit balance_dep to user
                $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")->execute([$dep['amount'], $dep['user_id']]);
                // 2. Mark deposit as confirmed
                $pdo->prepare("UPDATE deposits SET status='confirmed', admin_note=NULL, confirmed_at=NOW() WHERE id=?")->execute([$id]);
                
                // 3. Process referral commission
                $comm = credit_deposit_referral_commission($pdo, (int)$dep['user_id'], (float)$dep['amount']);
                $pdo->commit();
                $toast = $comm > 0 ? '✅ Acc Expired Berhasil! (+Komisi ' . format_rp($comm) . ' ke upline)' : '✅ Deposit Expired Berhasil Di-Acc!';
                answer_cb($token, $cb_id, $toast);
                
                $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $u_stmt->execute([$dep['user_id']]);
                $uname = $u_stmt->fetchColumn() ?: 'User';
                
                $msg = "✅ <b>DEPOSIT QRIS BERHASIL (CONFIRMED)</b>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($uname) . "</code>\n";
                $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
                if ($comm > 0) {
                    $msg .= "🎁 <b>Komisi Referral:</b> <code>" . format_rp($comm) . "</code> (dikirim ke upline)\n";
                }
                $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
                $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
                $msg .= "✅ <b>Status:</b> <code>Approved (Acc Expired)</code>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "<i>Deposit yang kedaluwarsa disetujui kembali oleh admin via Telegram Bot.</i>";
                edit_msg($token, $chat_id, $msg_id, $msg, []);
            } else {
                $pdo->rollBack();
                answer_cb($token, $cb_id, '⚠️ Deposit harus berstatus Rejected.');
            }
        } catch (\Throwable $th) {
            $pdo->rollBack();
            answer_cb($token, $cb_id, '⚠️ Error: ' . $th->getMessage());
        }
        http_response_code(200); exit;
    }

    if (preg_match('/^wd_approve_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        $pdo->beginTransaction();
        $wd = $pdo->prepare("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id=? FOR UPDATE");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd && $wd['status'] === 'pending') {
            $pdo->prepare("UPDATE withdrawals SET status='approved', processed_at=NOW() WHERE id=?")->execute([$id]);
            $pdo->commit();
            answer_cb($token, $cb_id, '✅ Withdraw Approved!');
            
            $msg = "<b>💸 WITHDRAW DI-HANDLE (Approved)</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($wd['username']) . "</code>\n";
            $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$wd['amount']) . "</code>\n";
            $msg .= "🏦 <b>Bank:</b> <code>" . htmlspecialchars($wd['bank_name']) . " - " . htmlspecialchars($wd['account_number']) . "</code>\n";
            $msg .= "👨‍💼 <b>a/n:</b> <code>" . htmlspecialchars($wd['account_name']) . "</code>\n";
            $msg .= "📌 <b>Status:</b> <code>✅ Approved via Bot</code>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Penarikan dana disetujui oleh admin via Telegram Bot.</i>";
            edit_msg($token, $chat_id, $msg_id, $msg, []);
        } else {
            $pdo->rollBack();
            answer_cb($token, $cb_id, '⚠️ Sudah diproses atau tidak ditemukan.');
        }
        http_response_code(200); exit;
    }

    // ── ADMIN REQUEST APPROVE ────────────────────────────────────────────────
    if (preg_match('/^req_approve_(\d+)$/', $data, $m)) {
        $res = req_admin_approve($pdo, (int)$m[1]);
        if ($res['ok']) {
            answer_cb($token, $cb_id, "✅ Request Approved!");
            if ($res['is_photo']) {
                edit_caption($token, $chat_id, $msg_id, $res['tg_text'], []);
            } else {
                edit_msg($token, $chat_id, $msg_id, $res['tg_text'], []);
            }
        } else {
            answer_cb($token, $cb_id, '⚠️ ' . $res['message']);
        }
        http_response_code(200); exit;
    }

    // ── ADMIN REQUEST REJECT (ask for reason) ────────────────────────────────
    if (preg_match('/^req_reject_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        
        $req = $pdo->prepare("SELECT r.*, u.username FROM admin_requests r JOIN users u ON u.id = r.user_id WHERE r.id=?");
        $req->execute([$id]);
        $req = $req->fetch();
        if (!$req || $req['status'] !== 'pending') {
            answer_cb($token, $cb_id, '⚠️ Request tidak ditemukan atau sudah diproses.');
            http_response_code(200); exit;
        }
        
        $uname = $req['username'] ?: 'Unknown';
        
        answer_cb($token, $cb_id, "📝 Ketik alasan penolakan...");
        $prompt_msg_id = send_msg($token, $chat_id,
            "📝 <b>Ketik alasan penolakan</b> untuk Request #{$id} (User: <b>{$uname}</b>) dan kirim sebagai pesan.\n\nAtau tekan tombol di bawah untuk langsung memproses tanpa alasan.",
            [[['text' => '⏭ Skip (Tanpa Alasan)', 'callback_data' => "req_reject_skip_{$id}"]]], $thread_id
        );
        
        // Save state: awaiting_req_reason|reject|id|msg_id|orig_b64|prompt_msg_id
        $state = implode('|', ['awaiting_req_reason', 'reject', $id, $msg_id, base64_encode($orig), (int)$prompt_msg_id]);
        set_tg_state($pdo, $chat_id, $state);
        http_response_code(200); exit;
    }

    // ── ADMIN REQUEST REJECT SKIP (no reason) ────────────────────────────────
    if (preg_match('/^req_reject_skip_(\d+)$/', $data, $m)) {
        $id = (int)$m[1];
        clear_tg_state($pdo, $chat_id);
        
        $res = do_req_reject($pdo, $id, '');
        if ($res['status'] === 'ok') {
            answer_cb($token, $cb_id, '❌ Request ditolak tanpa alasan.');
            if ($res['req_type'] === 'threads_campaign') {
                edit_caption($token, $chat_id, $msg_id, $res['msg'], []);
            } else {
                edit_msg($token, $chat_id, $msg_id, $res['msg'], []);
            }
        } else {
            answer_cb($token, $cb_id, '⚠️ ' . $res['message']);
        }
        http_response_code(200); exit;
    }

    // ── EDIT REFUND CUT ──────────────────────────────────────────────────────
    if (preg_match('/^edit_refcut_(\d+)$/', $data, $m)) {
        $uid = (int)$m[1];
        answer_cb($token, $cb_id, "📝 Ketik persentase potongan baru (contoh: 25)...");
        $prompt_msg_id = send_msg($token, $chat_id, "📝 <b>Ketik angka persentase potongan refund baru</b> (0 - 100) untuk User ID: <b>{$uid}</b> lalu kirim sebagai pesan.", [], $thread_id);
        $state = implode('|', ['awaiting_refcut', $uid, $msg_id, base64_encode($orig), (int)$prompt_msg_id]);
        set_tg_state($pdo, $chat_id, $state);
        http_response_code(200); exit;
    }

    // ── TOGGLE REFUND ACCESS ────────────────────────────────────────────────
    if (preg_match('/^toggle_ref_(\d+)$/', $data, $m)) {
        $uid = (int)$m[1];
        $s = $pdo->prepare("SELECT is_refund_enabled FROM users WHERE id=?");
        $s->execute([$uid]);
        $curr = (int)$s->fetchColumn();
        $new_val = $curr ? 0 : 1;
        $pdo->prepare("UPDATE users SET is_refund_enabled=? WHERE id=?")->execute([$new_val, $uid]);
        
        $txt = $new_val ? "✅ Akses refund diaktifkan." : "🔒 Akses refund diblokir.";
        answer_cb($token, $cb_id, $txt);
        http_response_code(200); exit;
    }

    // ── REJECT / HOLD (ask for reason) ─────────────────────────────────────────
    if (preg_match('/^(depo|wd)_(reject|hold)_(\d+)$/', $data, $m)) {
        $type   = $m[1];
        $action = $m[2];
        $id     = (int)$m[3];
        $act_id = "{$type}_{$action}";
        $label  = $action === 'hold' ? 'Hold' : 'penolakan';
        
        $table = $type === 'depo' ? 'deposits' : 'withdrawals';
        $item = $pdo->prepare("SELECT u.username FROM {$table} t JOIN users u ON u.id=t.user_id WHERE t.id=?");
        $item->execute([$id]);
        $uname = $item->fetchColumn() ?: 'Unknown';
        
        answer_cb($token, $cb_id, "📝 Ketik alasan {$label}...");
        // Send prompt and capture its message_id
        $prompt_msg_id = send_msg($token, $chat_id,
            "📝 <b>Ketik alasan {$label}</b> untuk {$type} #{$id} (User: <b>{$uname}</b>) dan kirim sebagai pesan.\n\nAtau tekan tombol di bawah untuk langsung memproses tanpa alasan.",
            [[['text' => '⏭ Skip (Tanpa Alasan)', 'callback_data' => "{$act_id}_skip_{$id}"]]], $thread_id
        );
        // Save state: awaiting_reason|type|action|id|orig_msg_id|orig_b64|prompt_msg_id
        $state = implode('|', ['awaiting_reason', $type, $action, $id, $msg_id, base64_encode($orig), (int)$prompt_msg_id]);
        set_tg_state($pdo, $chat_id, $state);
        http_response_code(200); exit;
    }

    // ── REJECT / HOLD SKIP (no reason) ─────────────────────────────────────────
    if (preg_match('/^(depo|wd)_(reject|hold)_skip_(\d+)$/', $data, $m)) {
        $type   = $m[1];
        $action = $m[2];
        $id     = (int)$m[3];
        clear_tg_state($pdo, $chat_id);

        if ($type === 'depo') {
            $res = do_depo_reject($pdo, $id, '');
            $icon = '❌'; $status = 'Rejected';
        } else {
            if ($action === 'hold') {
                $res = do_wd_hold($pdo, $id, '');
                $icon = '⏸'; $status = 'Hold';
            } else {
                $res = do_wd_reject($pdo, $id, '');
                $icon = '❌'; $status = 'Rejected';
            }
        }

        if ($res === 'ok') {
            answer_cb($token, $cb_id, "{$icon} " . ucfirst($action) . " tanpa alasan.");
            
            if ($type === 'depo') {
                $row = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.id=?");
                $row->execute([$id]); $row = $row->fetch();
                $msg = "❌ <b>DEPOSIT QRIS DITOLAK (REJECTED)</b>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username'] ?? 'User') . "</code>\n";
                $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
                $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
                $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
                $msg .= "❌ <b>Status:</b> <code>Rejected via Bot</code>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "<i>Deposit ditolak oleh admin via Telegram Bot tanpa alasan.</i>";
            } else {
                $row = $pdo->prepare("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE w.id=?");
                $row->execute([$id]); $row = $row->fetch();
                $msg = "<b>💸 WITHDRAW DI-HANDLE ({$status})</b>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username'] ?? 'User') . "</code>\n";
                $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
                $msg .= "🏦 <b>Bank:</b> <code>" . htmlspecialchars($row['bank_name']) . " - " . htmlspecialchars($row['account_number']) . "</code>\n";
                $msg .= "👨‍💼 <b>a/n:</b> <code>" . htmlspecialchars($row['account_name']) . "</code>\n";
                $msg .= "📌 <b>Status:</b> <code>{$icon} {$status}</code>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "<i>Penarikan dana di-{$status} oleh admin via Telegram Bot tanpa alasan.</i>";
            }
            edit_msg($token, $chat_id, $msg_id, $msg, []);
        } else {
            answer_cb($token, $cb_id, '⚠️ ' . $res);
        }
        http_response_code(200); exit;
    }
    // Fallback for callback_query: do not exit, so it can fall through
}

// ── Message Handler (for reject reason text) ─────────────────────────────────

if (isset($update['message'])) {
    $msg     = $update['message'];
    $chat_id = $msg['chat']['id'] ?? '';
    $text    = trim($msg['text'] ?? '');
    $thread_id = $msg['message_thread_id'] ?? null;

    if (empty($text)) {
        return; // Fall through for other types of messages
    }

    // Command /menu (alias lama /dp): buka mini app Menu Admin — Deposit, Withdraw, Permintaan.
    // Hanya di chat pribadi bot dan khusus anggota grup admin.
    if (preg_match('/^\/(menu|dp)(@\w+)?$/i', $text, $cm)) {
        $from_id = (int)($msg['from']['id'] ?? 0);
        if (($msg['chat']['type'] ?? '') !== 'private') {
            send_msg($token, $chat_id, "ℹ️ Ketik /menu di chat pribadi dengan bot ini untuk membuka Menu Admin.", null, $thread_id);
        } elseif (!tg_dp_allowed($pdo, $from_id)) {
            send_msg($token, $chat_id, "⛔ Akses ditolak. Menu Admin hanya untuk anggota grup admin.");
        } else {
            $dp  = (int)$pdo->query("SELECT COUNT(*) FROM deposits WHERE status = 'pending'")->fetchColumn();
            $wd  = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'")->fetchColumn();
            $wdh = (int)$pdo->query("SELECT COUNT(*) FROM withdrawals WHERE status = 'hold'")->fetchColumn();
            $rq  = (int)$pdo->query("SELECT COUNT(*) FROM admin_requests WHERE status = 'pending'")->fetchColumn();
            $url = static fn(string $s) => preg_replace('#^http:#', 'https:', base_url('api/menu.php?s=' . $s));
            send_msg($token, $chat_id,
                "🛠 <b>Menu Admin</b>\n"
                . "💳 Deposit pending: <b>{$dp}</b>\n"
                . "💸 Withdraw pending: <b>{$wd}</b> · hold: <b>{$wdh}</b>\n"
                . "📨 Permintaan pending: <b>{$rq}</b>",
                [
                    [['text' => '📊 Buka Menu Admin', 'web_app' => ['url' => $url(strtolower($cm[1]) === 'dp' ? 'depo' : 'home')]]],
                    [['text' => "💳 Deposit ({$dp})", 'web_app' => ['url' => $url('depo')]], ['text' => "💸 Withdraw ({$wd})", 'web_app' => ['url' => $url('wd')]]],
                    [['text' => "📨 Permintaan ({$rq})", 'web_app' => ['url' => $url('req')]]],
                ]
            );
        }
        http_response_code(200); exit;
    }

    if (trim((string)$chat_id) !== trim((string)$admin_chat_id)) {
        return; // Fall through
    }

    // Command: !sethere [option] or /sethere [option]
    if (preg_match('/^[!\/]sethere\s+([a-zA-Z0-9_]+)/i', $text, $m)) {
        $option = strtolower($m[1]);
        $allowed = ['log', 'wd', 'depo', 'user_baru', 'permintaan'];
        if (in_array($option, $allowed)) {
            $key = 'tg_topic_' . $option;
            $val = $thread_id ? (string)$thread_id : '';
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$key, $val, $val]);
            send_msg($token, $chat_id, "✅ Topic untuk <b>{$option}</b> berhasil diset ke thread ini.", null, $thread_id);
        } else {
            send_msg($token, $chat_id, "⚠️ Option tidak valid. Gunakan: " . implode(', ', $allowed), null, $thread_id);
        }
        http_response_code(200); exit;
    }

    $state = get_tg_state($pdo, $chat_id);
    if (!$state) { return; }

    $parts = explode('|', $state, 7);
    
    if ($parts[0] === 'awaiting_refcut') {
        [, $uid, $orig_msg_id, $orig_b64, $prompt_msg_id] = array_pad($parts, 5, 0);
        $uid = (int)$uid;
        $orig_msg_id = (int)$orig_msg_id;
        $prompt_msg_id = (int)$prompt_msg_id;
        $new_cut = (int)$text;
        
        clear_tg_state($pdo, $chat_id);
        
        if ($new_cut >= 0 && $new_cut <= 100) {
            $pdo->prepare("UPDATE users SET refund_cut_percent=? WHERE id=?")->execute([$new_cut, $uid]);
            $msg = "✅ Persentase potongan refund untuk User ID {$uid} berhasil diubah menjadi <b>{$new_cut}%</b>.";
        } else {
            $msg = "⚠️ Angka tidak valid. Harus antara 0 - 100.";
        }
        
        if ($prompt_msg_id) {
            edit_msg($token, $chat_id, $prompt_msg_id, $msg, []);
        } else {
            send_msg($token, $chat_id, $msg, []);
        }
        http_response_code(200); exit;
    }

    if ($parts[0] === 'awaiting_req_reason') {
        [, $action, $id, $orig_msg_id, $orig_b64, $prompt_msg_id] = array_pad($parts, 6, 0);
        $id            = (int)$id;
        $orig_msg_id   = (int)$orig_msg_id;
        $prompt_msg_id = (int)$prompt_msg_id;
        $reason        = $text;
        
        clear_tg_state($pdo, $chat_id);
        
        $res = do_req_reject($pdo, $id, $reason);
        if ($res['status'] === 'ok') {
            if ($res['req_type'] === 'threads_campaign') {
                edit_caption($token, $chat_id, $orig_msg_id, $res['msg'], []);
            } else {
                edit_msg($token, $chat_id, $orig_msg_id, $res['msg'], []);
            }
            
            // Edit prompt message to show the reason has been processed
            if ($prompt_msg_id) {
                edit_msg($token, $chat_id, $prompt_msg_id,
                    "❌ <b>REQUEST #{$id} DITOLAK</b>\n📝 Alasan: <i>" . htmlspecialchars($reason) . "</i>");
            }
        } else {
            send_msg($token, $chat_id, "⚠️ Gagal: " . $res['message']);
        }
        http_response_code(200); exit;
    }

    if ($parts[0] !== 'awaiting_reason') { return; }

    [, $type, $action, $id, $orig_msg_id, $orig_b64, $prompt_msg_id] = array_pad($parts, 7, 0);
    $id            = (int)$id;
    $orig_msg_id   = (int)$orig_msg_id;
    $prompt_msg_id = (int)$prompt_msg_id;
    $orig_text     = base64_decode($orig_b64);
    $reason        = $text;

    clear_tg_state($pdo, $chat_id);

    if ($type === 'depo') {
        $res = do_depo_reject($pdo, $id, $reason);
        $icon = '❌'; $status = 'Rejected';
    } else {
        if ($action === 'hold') {
            $res = do_wd_hold($pdo, $id, $reason);
            $icon = '⏸'; $status = 'Hold';
        } else {
            $res = do_wd_reject($pdo, $id, $reason);
            $icon = '❌'; $status = 'Rejected';
        }
    }

    if ($res === 'ok') {
        if ($type === 'depo') {
            $row = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id=d.user_id WHERE d.id=?");
            $row->execute([$id]); $row = $row->fetch();
            $new_text = "❌ <b>DEPOSIT QRIS DITOLAK (REJECTED)</b>\n";
            $new_text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $new_text .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username'] ?? 'User') . "</code>\n";
            $new_text .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
            $new_text .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
            $new_text .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
            $new_text .= "❌ <b>Status:</b> <code>Rejected via Bot</code>\n";
            $new_text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $new_text .= "<i>Alasan: " . htmlspecialchars($reason) . "</i>";
        } else {
            $row = $pdo->prepare("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id=w.user_id WHERE w.id=?");
            $row->execute([$id]); $row = $row->fetch();
            $new_text = "<b>💸 WITHDRAW DI-HANDLE ({$status})</b>\n";
            $new_text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $new_text .= "👤 <b>User:</b> <code>" . htmlspecialchars($row['username'] ?? 'User') . "</code>\n";
            $new_text .= "💵 <b>Amount:</b> <code>" . format_rp((float)$row['amount']) . "</code>\n";
            $new_text .= "🏦 <b>Bank:</b> <code>" . htmlspecialchars($row['bank_name']) . " - " . htmlspecialchars($row['account_number']) . "</code>\n";
            $new_text .= "👨‍💼 <b>a/n:</b> <code>" . htmlspecialchars($row['account_name']) . "</code>\n";
            $new_text .= "📌 <b>Status:</b> <code>{$icon} {$status}</code>\n";
            $new_text .= "📝 <b>Note:</b> <code>" . htmlspecialchars($reason) . "</code>\n";
            $new_text .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $new_text .= "<i>Penarikan dana di-{$status} oleh admin via Telegram Bot dengan alasan.</i>";
        }
        edit_msg($token, $chat_id, $orig_msg_id, $new_text, []);
        
        $table = $type === 'depo' ? 'deposits' : 'withdrawals';
        $item = $pdo->prepare("SELECT u.username FROM {$table} t JOIN users u ON u.id=t.user_id WHERE t.id=?");
        $item->execute([$id]);
        $uname = $item->fetchColumn() ?: 'Unknown';

        // Edit the prompt message to confirm the reason was received
        if ($prompt_msg_id) {
            edit_msg($token, $chat_id, $prompt_msg_id,
                "{$icon} <b>" . strtoupper($type) . " #{$id} (User: {$uname}) " . ($action==='hold'?'di-hold':'ditolak') . "</b>\n📝 Alasan: <i>" . htmlspecialchars($reason) . "</i>");
        }
    } else {
        send_msg($token, $chat_id, "⚠️ Gagal: {$res}");
    }

    http_response_code(200); exit;
}
