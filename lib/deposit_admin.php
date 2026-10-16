<?php
declare(strict_types=1);

/**
 * Aksi manajemen deposit yang dipakai bersama oleh bot Telegram (webhook.php)
 * dan mini app Deposit Manager (/api/dp.php).
 *
 * "Acc tanpa komisi": deposit dikonfirmasi & saldo masuk ke user, tetapi
 * tidak ada komisi referral ke upline dan tidak dihitung di gaji promotor
 * (kolom deposits.no_commission = 1).
 */

function depo_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->query("SELECT no_commission FROM deposits LIMIT 0");
    } catch (\Throwable) {
        try {
            $pdo->exec("ALTER TABLE deposits ADD COLUMN IF NOT EXISTS no_commission TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_note");
        } catch (\Throwable) {}
    }
}

function depo_method_label(array $dep): string {
    return ($dep['method'] ?? '') === 'qris' ? 'QRIS Otomatis' : 'Transfer Bank';
}

/** Tombol Telegram untuk deposit pending. */
function depo_pending_kb(int $id): array {
    return [
        [['text' => '✅ Approve', 'callback_data' => 'depo_approve_' . $id], ['text' => '❌ Reject', 'callback_data' => 'depo_reject_' . $id]],
        [['text' => '🚫 Acc Tanpa Komisi', 'callback_data' => 'depo_approvenc_' . $id]],
        [['text' => '⚡ Acc Expired', 'callback_data' => 'depo_accexp_' . $id], ['text' => '🔄 Refresh Status', 'callback_data' => 'refresh_depo_' . $id]],
    ];
}

/**
 * Konfirmasi deposit (pending, expired, atau ditolak) dan kreditkan saldo user.
 * @return array{ok:bool, message:string, comm?:float, dep?:array}
 */
function depo_admin_confirm(PDO $pdo, int $id, bool $with_commission, string $via): array {
    depo_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = ? FOR UPDATE");
        $st->execute([$id]);
        $dep = $st->fetch();
        if (!$dep) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Deposit tidak ditemukan.'];
        }
        if ($dep['status'] === 'confirmed') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'Deposit sudah dikonfirmasi sebelumnya.'];
        }

        $was = $dep['status'] === 'pending' ? 'Approve' : 'Acc Expired';
        $note = $with_commission ? "Dikonfirmasi manual oleh Admin ({$via})" : "Dikonfirmasi tanpa komisi oleh Admin ({$via})";
        $pdo->prepare("UPDATE deposits SET status = 'confirmed', confirmed_at = NOW(), admin_note = ?, no_commission = ? WHERE id = ?")
            ->execute([$note, $with_commission ? 0 : 1, $id]);
        $pdo->prepare("UPDATE users SET balance_dep = balance_dep + ? WHERE id = ?")->execute([$dep['amount'], $dep['user_id']]);
        $comm = $with_commission ? credit_deposit_referral_commission($pdo, (int)$dep['user_id'], (float)$dep['amount']) : 0.0;
        $pdo->commit();

        $dep['status'] = 'confirmed';
        $dep['confirmed_at'] = date('Y-m-d H:i:s');
        $dep['no_commission'] = $with_commission ? 0 : 1;
        $dep['admin_note'] = $note;
        return ['ok' => true, 'message' => $was . ($with_commission ? '' : ' tanpa komisi') . ' berhasil.', 'comm' => $comm, 'dep' => $dep, 'was' => $was];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => 'Error: ' . $e->getMessage()];
    }
}

/** Tolak deposit pending. */
function depo_admin_reject(PDO $pdo, int $id, string $reason): array {
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT d.*, u.username FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = ? FOR UPDATE");
    $st->execute([$id]);
    $dep = $st->fetch();
    if (!$dep || $dep['status'] !== 'pending') {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'Deposit tidak ditemukan atau bukan pending.'];
    }
    $note = $reason !== '' ? $reason : 'Bukti transfer tidak valid atau belum masuk';
    $pdo->prepare("UPDATE deposits SET status = 'rejected', admin_note = ? WHERE id = ?")->execute([$note, $id]);
    $pdo->commit();
    $dep['status'] = 'rejected';
    $dep['admin_note'] = $note;
    return ['ok' => true, 'message' => 'Deposit ditolak.', 'dep' => $dep];
}

/**
 * Boleh membuka Deposit Manager? Hanya anggota grup admin Telegram (tg_chat_id).
 * Hasil dicek ke Telegram lalu disimpan 10 menit di settings.
 */
function tg_dp_allowed(PDO $pdo, int $tg_user_id): bool {
    if ($tg_user_id <= 0) return false;
    $key = 'tg_dp_ok_' . $tg_user_id;
    $cached = setting($pdo, $key, '');
    if ($cached !== '') {
        [$ok, $at] = array_pad(explode('|', $cached, 2), 2, '0');
        if (time() - (int)$at < 600) return $ok === '1';
    }

    $token   = setting($pdo, 'tg_bot_token', '');
    $chat_id = setting($pdo, 'tg_chat_id', '');
    if (!$token || !$chat_id) return false;

    $ch = curl_init("https://api.telegram.org/bot{$token}/getChatMember?" . http_build_query(['chat_id' => $chat_id, 'user_id' => $tg_user_id]));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 8]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = $res ? json_decode($res, true) : null;
    if (empty($data['ok'])) return false; // gagal cek: jangan di-cache

    $m = $data['result'] ?? [];
    $status = $m['status'] ?? '';
    $ok = in_array($status, ['creator', 'administrator', 'member'], true)
        || ($status === 'restricted' && !empty($m['is_member']));
    setting_set($pdo, $key, ($ok ? '1' : '0') . '|' . time());
    return $ok;
}

/**
 * Validasi initData Telegram Mini App (tanda tangan HMAC dengan token bot).
 * @return array|null data user Telegram bila valid
 */
function tg_webapp_user(PDO $pdo, string $init_data): ?array {
    $token = setting($pdo, 'tg_bot_token', '');
    if (!$token || $init_data === '') return null;
    parse_str($init_data, $fields);
    $hash = (string)($fields['hash'] ?? '');
    if ($hash === '') return null;
    unset($fields['hash']);
    ksort($fields);
    $check = implode("\n", array_map(fn($k, $v) => $k . '=' . $v, array_keys($fields), $fields));
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    if (!hash_equals(hash_hmac('sha256', $check, $secret), $hash)) return null;
    if (time() - (int)($fields['auth_date'] ?? 0) > 86400) return null;
    $user = json_decode((string)($fields['user'] ?? ''), true);
    return is_array($user) && !empty($user['id']) ? $user : null;
}

/** Teks Telegram untuk deposit yang baru dikonfirmasi admin. */
function depo_confirmed_tg_text(array $dep, float $comm, string $status_label, string $footer): string {
    $msg  = "✅ <b>DEPOSIT BERHASIL (CONFIRMED)</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars((string)$dep['username']) . "</code>\n";
    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
    if (!empty($dep['no_commission'])) {
        $msg .= "🚫 <b>Komisi:</b> <code>Tidak ada (acc tanpa komisi)</code>\n";
    } elseif ($comm > 0) {
        $msg .= "🎁 <b>Komisi Referral:</b> <code>" . format_rp($comm) . "</code> (dikirim ke upline)\n";
    }
    $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
    $msg .= "💳 <b>Method:</b> <code>" . depo_method_label($dep) . "</code>\n";
    $msg .= "✅ <b>Status:</b> <code>{$status_label}</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "<i>{$footer}</i>";
    return $msg;
}

/** Teks Telegram untuk deposit yang ditolak admin. */
function depo_rejected_tg_text(array $dep, string $via): string {
    $msg  = "❌ <b>DEPOSIT DITOLAK (REJECTED)</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars((string)$dep['username']) . "</code>\n";
    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
    $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
    $msg .= "💳 <b>Method:</b> <code>" . depo_method_label($dep) . "</code>\n";
    $msg .= "❌ <b>Status:</b> <code>Rejected via {$via}</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "<i>Alasan: " . htmlspecialchars((string)($dep['admin_note'] ?: '-')) . "</i>";
    return $msg;
}
