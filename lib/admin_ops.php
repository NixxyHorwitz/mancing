<?php
declare(strict_types=1);

/**
 * Aksi admin untuk Withdraw & Permintaan (admin_requests), dipakai bersama oleh
 * bot Telegram (webhook.php) dan mini app /menu (api/menu.php).
 */

const REQ_TYPE_LABELS = [
    'change_bank'            => 'Ganti Rekening',
    'refund_level'           => 'Refund Level',
    'refund_wd_hold'         => 'Refund WD Hold',
    'threads_campaign'       => 'Threads Langkah 1',
    'threads_campaign_step2' => 'Threads Langkah 2',
];

/** Kolom tg_msg_id di withdrawals & admin_requests (untuk sinkron pesan grup). */
function admin_ops_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['withdrawals', 'admin_requests'] as $t) {
        try {
            $pdo->query("SELECT tg_msg_id FROM {$t} LIMIT 0");
        } catch (\Throwable) {
            try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS tg_msg_id INT NULL DEFAULT NULL"); } catch (\Throwable) {}
        }
    }
}

/** Simpan message id Telegram untuk withdraw / permintaan. */
function tg_store_msg_id(PDO $pdo, string $table, int $id, ?int $msg_id): void {
    if (!$msg_id || !in_array($table, ['withdrawals', 'admin_requests'], true)) return;
    try {
        admin_ops_ensure_schema($pdo);
        $pdo->prepare("UPDATE {$table} SET tg_msg_id = ? WHERE id = ?")->execute([$msg_id, $id]);
    } catch (\Throwable) {}
}

/** Edit pesan grup (teks biasa atau caption foto) dan hapus tombolnya. */
function tg_sync_msg(PDO $pdo, ?int $msg_id, string $text, bool $is_photo = false): void {
    if (!$msg_id) return;
    if (!$is_photo) { edit_telegram_notif($pdo, $msg_id, $text, []); return; }
    $token = setting($pdo, 'tg_bot_token', '');
    $chat  = setting($pdo, 'tg_chat_id', '');
    if (!$token || !$chat) return;
    $ch = curl_init("https://api.telegram.org/bot{$token}/editMessageCaption");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'message_id' => $msg_id, 'caption' => $text, 'parse_mode' => 'HTML',
                               'reply_markup' => json_encode(['inline_keyboard' => []])],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function admin_notify_user(PDO $pdo, int $user_id, string $title, string $msg, string $type, string $icon, ?string $url = null, ?string $btn = null): void {
    $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at) VALUES (?, ?, ?, ?, 'single', ?, ?, ?, NOW())")
        ->execute([$title, $msg, $type, $icon, json_encode([$user_id]), $url, $btn]);
}

// ── WITHDRAW ────────────────────────────────────────────────────────────────

function wd_tg_text(array $wd, string $status_label, string $footer): string {
    $msg  = "<b>💸 WITHDRAW DI-HANDLE ({$status_label})</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars((string)$wd['username']) . "</code>\n";
    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$wd['amount']) . "</code>\n";
    $msg .= "🏦 <b>Bank:</b> <code>" . htmlspecialchars((string)$wd['bank_name']) . " - " . htmlspecialchars((string)$wd['account_number']) . "</code>\n";
    $msg .= "👨‍💼 <b>a/n:</b> <code>" . htmlspecialchars((string)$wd['account_name']) . "</code>\n";
    if (!empty($wd['admin_note'])) $msg .= "📝 <b>Note:</b> <code>" . htmlspecialchars((string)$wd['admin_note']) . "</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "<i>{$footer}</i>";
    return $msg;
}

/**
 * Proses withdraw: approve (pending/hold), reject (pending, saldo kembali),
 * hold (pending, saldo tidak kembali), refund (hold → saldo kembali).
 * @return array{ok:bool, message:string, wd?:array}
 */
function wd_admin_action(PDO $pdo, int $id, string $do, string $reason, string $via): array {
    admin_ops_ensure_schema($pdo);
    $allowed = ['approve' => ['pending', 'hold'], 'reject' => ['pending'], 'hold' => ['pending'], 'refund' => ['hold']];
    if (!isset($allowed[$do])) return ['ok' => false, 'message' => 'Aksi tidak dikenal.'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ? FOR UPDATE");
        $st->execute([$id]);
        $wd = $st->fetch();
        if (!$wd || !in_array($wd['status'], $allowed[$do], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Withdraw tidak ditemukan atau statusnya sudah berubah.'];
        }
        $amt = format_rp((float)$wd['amount']);
        $uid = (int)$wd['user_id'];

        switch ($do) {
            case 'approve':
                $pdo->prepare("UPDATE withdrawals SET status = 'approved', admin_note = NULL, processed_at = NOW() WHERE id = ?")->execute([$id]);
                admin_notify_user($pdo, $uid, 'Penarikan Berhasil Disetujui! 🎉',
                    "Penarikan sebesar {$amt} ke {$wd['bank_name']} ({$wd['account_number']}) telah berhasil disetujui & ditransfer.",
                    'success', '💰', '/withdraw', 'Lihat Status');
                $wd['status'] = 'approved'; $wd['admin_note'] = null;
                $label = '✅ Approved'; $msg = "WD #{$id} disetujui.";
                break;
            case 'reject':
                $note = $reason !== '' ? $reason : 'Ditolak admin (Data rekening tidak sesuai)';
                $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$wd['amount'], $uid]);
                $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ?, processed_at = NOW() WHERE id = ?")->execute([$note, $id]);
                admin_notify_user($pdo, $uid, 'Penarikan Dana Ditolak ⚠️',
                    "Penarikan sebesar {$amt} ditolak dan saldo telah dikembalikan. Alasan: {$note}", 'warning', '⚠️', '/withdraw', 'Cek Detail');
                $wd['status'] = 'rejected'; $wd['admin_note'] = $note;
                $label = '❌ Rejected'; $msg = "WD #{$id} ditolak, saldo {$amt} dikembalikan.";
                break;
            case 'hold':
                $note = $reason !== '' ? $reason : 'Peninjauan antrean audit keuangan';
                $pdo->prepare("UPDATE withdrawals SET status = 'hold', admin_note = ?, processed_at = NOW() WHERE id = ?")->execute([$note, $id]);
                admin_notify_user($pdo, $uid, 'Penarikan Sedang Ditahan (Hold) ⏸️',
                    "Penarikan sebesar {$amt} sedang ditahan untuk peninjauan. Catatan: {$note}", 'warning', '⏸️', '/withdraw', 'Cek Status');
                $wd['status'] = 'hold'; $wd['admin_note'] = $note;
                $label = '⏸ Hold'; $msg = "WD #{$id} di-hold (saldo tidak dikembalikan).";
                break;
            default: // refund
                $note = $reason !== '' ? $reason : 'Refund saldo dari status Hold';
                $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$wd['amount'], $uid]);
                $pdo->prepare("UPDATE withdrawals SET status = 'refunded', admin_note = ?, processed_at = NOW() WHERE id = ?")->execute([$note, $id]);
                admin_notify_user($pdo, $uid, 'Saldo Penarikan Dikembalikan ↩️',
                    "Saldo penarikan sebesar {$amt} telah dikembalikan ke akunmu. Catatan: {$note}", 'info', '↩️', '/withdraw', 'Cek Saldo');
                $wd['status'] = 'refunded'; $wd['admin_note'] = $note;
                $label = '↩️ Refunded'; $msg = "WD #{$id} di-refund, saldo {$amt} dikembalikan.";
        }
        $pdo->commit();
        tg_sync_msg($pdo, (int)($wd['tg_msg_id'] ?? 0), wd_tg_text($wd, $label . ' via ' . $via, 'Diproses admin lewat Menu Admin.'));
        return ['ok' => true, 'message' => $msg, 'wd' => $wd];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

// ── PERMINTAAN (admin_requests) ─────────────────────────────────────────────

/**
 * Setujui permintaan. Mengembalikan teks Telegram hasil (tg_text) dan apakah pesannya foto.
 * @return array{ok:bool, message:string, tg_text?:string, is_photo?:bool, req?:array}
 */
function req_admin_approve(PDO $pdo, int $id): array {
    admin_ops_ensure_schema($pdo);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT r.*, u.username, u.balance_dep FROM admin_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? FOR UPDATE");
        $st->execute([$id]);
        $req = $st->fetch();
        if (!$req || $req['status'] !== 'pending') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Permintaan sudah diproses atau tidak ditemukan.'];
        }
        $pdo->prepare("UPDATE admin_requests SET status = 'approved', updated_at = NOW() WHERE id = ?")->execute([$id]);
        $uname = htmlspecialchars((string)$req['username']);
        $payload = json_decode((string)$req['payload'], true) ?: [];

        if ($req['type'] === 'change_bank') {
            $pdo->prepare("UPDATE users SET bank_name = ?, account_number = ?, account_name = ? WHERE id = ?")
                ->execute([$payload['bank_name'], $payload['account_number'], $payload['account_name'], $req['user_id']]);
            $msg  = "✅ <b>REQUEST GANTI REKENING (APPROVED)</b>\n━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>{$uname}</code>\n";
            $msg .= "🏦 <b>Bank Baru:</b> <code>" . htmlspecialchars((string)$payload['bank_name']) . "</code>\n";
            $msg .= "💳 <b>Nomor:</b> <code>" . htmlspecialchars((string)$payload['account_number']) . "</code>\n";
            $msg .= "👨‍💼 <b>A.N:</b> <code>" . htmlspecialchars((string)$payload['account_name']) . "</code>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n<i>Perubahan rekening telah disetujui.</i>";
            $done = 'Rekening user diperbarui.';

        } elseif ($req['type'] === 'refund_level') {
            $s = $pdo->prepare("SELECT u.membership_id, u.refund_cut_percent, m.price, m.name FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id = ?");
            $s->execute([$req['user_id']]);
            $uInfo = $s->fetch();
            if (!$uInfo || !$uInfo['membership_id']) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'User tidak memiliki level aktif untuk di-refund.'];
            }
            $o = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id = ? AND membership_id = ? AND status = 'confirmed' ORDER BY id DESC LIMIT 1");
            $o->execute([$req['user_id'], $uInfo['membership_id']]);
            $base = (float)$o->fetchColumn() ?: (float)$uInfo['price'];
            $pct = (float)$uInfo['refund_cut_percent'];
            $refund = $base * ((100 - $pct) / 100);

            $wds = $pdo->prepare("SELECT id, amount FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'hold') FOR UPDATE");
            $wds->execute([$req['user_id']]);
            $wd_total = 0.0;
            foreach ($wds->fetchAll() as $w) {
                $wd_total += (float)$w['amount'];
                $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = 'Dibatalkan (Refund Level)', processed_at = NOW() WHERE id = ?")->execute([$w['id']]);
            }
            $pdo->prepare("UPDATE users SET balance_dep = balance_dep + ?, balance_wd = balance_wd + ?, membership_id = NULL, membership_expires_at = NULL WHERE id = ?")
                ->execute([$refund, $wd_total, $req['user_id']]);
            $note = "Refund untuk level {$uInfo['name']} telah disetujui. Saldo " . format_rp($refund) . " (setelah potongan {$pct}%) telah dikembalikan ke Saldo Beli kamu.";
            if ($wd_total > 0) $note .= " Semua penarikan yang tertunda juga dibatalkan dan saldo " . format_rp($wd_total) . " dikembalikan ke Saldo WD kamu.";
            admin_notify_user($pdo, (int)$req['user_id'], 'Refund Level Disetujui ✅', $note, 'success', '💰', '/user/upgrade.php', 'Cek Saldo');

            $msg  = "✅ <b>REQUEST REFUND LEVEL (APPROVED)</b>\n━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>{$uname}</code>\n";
            $msg .= "🏆 <b>Level Dibatalkan:</b> <code>" . htmlspecialchars((string)$uInfo['name']) . "</code>\n";
            $msg .= "💵 <b>Saldo Dikembalikan:</b> <code>" . format_rp($refund) . " (potongan {$pct}%)</code>\n";
            if ($wd_total > 0) $msg .= "🔙 <b>WD Dikembalikan:</b> <code>" . format_rp($wd_total) . "</code>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n<i>Refund level telah disetujui dan saldo berhasil dikembalikan.</i>";
            $done = 'Refund ' . format_rp($refund) . ' ke Saldo Beli.';

        } elseif ($req['type'] === 'refund_wd_hold') {
            $wid = (int)($payload['withdrawal_id'] ?? 0);
            $w = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? AND status = 'hold' FOR UPDATE");
            $w->execute([$wid]);
            $wd = $w->fetch();
            if (!$wd) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'WD tidak ditemukan atau sudah tidak berstatus Hold.'];
            }
            $pdo->prepare("UPDATE withdrawals SET status = 'refunded', admin_note = 'Dikembalikan ke Saldo Tarik', processed_at = NOW() WHERE id = ?")->execute([$wid]);
            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$wd['amount'], $req['user_id']]);
            admin_notify_user($pdo, (int)$req['user_id'], 'Refund WD Disetujui ✅',
                'Refund untuk WD senilai ' . format_rp((float)$wd['amount']) . ' telah disetujui. Saldo dikembalikan ke Saldo Tarik kamu secara utuh.',
                'success', '💰', '/history?tab=withdraw', 'Cek Saldo');
            $msg  = "✅ <b>REQUEST REFUND WD HOLD (APPROVED)</b>\n━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>{$uname}</code>\n";
            $msg .= "💵 <b>Dikembalikan:</b> <code>" . format_rp((float)$wd['amount']) . "</code> (ke Saldo Tarik)\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n<i>Refund telah disetujui dan saldo dikembalikan.</i>";
            $done = 'WD ' . format_rp((float)$wd['amount']) . ' dikembalikan ke Saldo Tarik.';

        } elseif ($req['type'] === 'threads_campaign' || $req['type'] === 'threads_campaign_step2') {
            $reward = (float)($payload['reward_amount'] ?? ($req['type'] === 'threads_campaign' ? 25000 : 50000));
            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$reward, $req['user_id']]);
            $step = $req['type'] === 'threads_campaign_step2' ? 'Langkah 2' : 'Langkah 1';
            $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids) VALUES (?, ?, 'success', '🌀', 'single', ?)")
                ->execute(["Kampanye Threads {$step} Disetujui! ✅",
                           "Selamat! Promosi Threads {$step} kamu disetujui. Cuan tambahan sebesar " . format_rp($reward) . " telah ditambahkan ke Saldo Tarik kamu.",
                           json_encode([$req['user_id']])]);
            $msg  = "✅ <b>PROMOSI THREADS - {$step} (APPROVED)</b>\n━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>{$uname}</code>\n";
            $msg .= "💵 <b>Reward:</b> <code>" . format_rp($reward) . "</code>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n<i>Reward {$step} telah ditambahkan ke Saldo Tarik user.</i>";
            $done = 'Reward ' . format_rp($reward) . ' ke Saldo Tarik.';

        } else {
            $msg = "✅ <b>REQUEST #{$id} (APPROVED)</b>\n👤 <b>User:</b> <code>{$uname}</code>";
            $done = 'Permintaan disetujui.';
        }

        $pdo->commit();
        $is_photo = str_starts_with((string)$req['type'], 'threads_campaign');
        return ['ok' => true, 'message' => "Permintaan #{$id} disetujui. {$done}", 'tg_text' => $msg, 'is_photo' => $is_photo, 'req' => $req];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => 'Error DB: ' . $e->getMessage()];
    }
}

/** Tolak permintaan (dipakai webhook & mini app). */
function do_req_reject(PDO $pdo, int $id, string $reason): array {
    $pdo->beginTransaction();
    $req = $pdo->prepare("SELECT r.*, u.username FROM admin_requests r JOIN users u ON u.id = r.user_id WHERE r.id=? FOR UPDATE");
    $req->execute([$id]);
    $req = $req->fetch();
    if (!$req || $req['status'] !== 'pending') {
        $pdo->rollBack();
        return ['status' => 'error', 'message' => 'Request tidak ditemukan atau sudah diproses.'];
    }

    $admin_note = $reason ?: 'Ditolak oleh Admin (Ketentuan belum terpenuhi)';
    $pdo->prepare("UPDATE admin_requests SET status='rejected', admin_note=?, updated_at=NOW() WHERE id=?")
        ->execute([$admin_note, $id]);

    if ($req['type'] === 'threads_campaign' || $req['type'] === 'threads_campaign_step2') {
        $stepText = $req['type'] === 'threads_campaign_step2' ? 'Langkah 2' : 'Langkah 1';
        $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids) VALUES (?, ?, 'warning', '🌀', 'single', ?)")
            ->execute(["Kampanye Threads {$stepText} Ditolak ❌", "Maaf, promosi Threads {$stepText} kamu ditolak. Alasan: " . $admin_note, json_encode([$req['user_id']])]);

        $msg = "❌ <b>PROMOSI THREADS - {$stepText} (REJECTED)</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($req['username']) . "</code>\n";
        $msg .= "📝 <b>Alasan:</b> <code>" . htmlspecialchars($admin_note) . "</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "<i>Permintaan {$stepText} ditolak oleh admin.</i>";
    } else {
        $title = $req['type'] === 'change_bank' ? 'GANTI REKENING' : ($req['type'] === 'refund_level' ? 'REFUND LEVEL' : 'REFUND WD HOLD');
        $msg = "❌ <b>REQUEST {$title} (REJECTED)</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($req['username']) . "</code>\n";
        $msg .= "📝 <b>Alasan:</b> <code>" . htmlspecialchars($admin_note) . "</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "<i>Permintaan ini telah DITOLAK.</i>";

        $typeName = $req['type'] === 'change_bank' ? 'Ganti Rekening' : ($req['type'] === 'refund_level' ? 'Refund Level' : 'Refund WD Hold');
        $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids) VALUES (?, ?, 'warning', '⚠️', 'single', ?)")
            ->execute(["Permintaan {$typeName} Ditolak ❌", "Maaf, permintaan {$typeName} kamu ditolak. Alasan: " . $admin_note, json_encode([$req['user_id']])]);
    }

    $pdo->commit();
    return ['status' => 'ok', 'msg' => $msg, 'req_type' => $req['type'], 'req' => $req];
}
