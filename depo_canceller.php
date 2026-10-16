<?php
declare(strict_types=1);

/**
 * Deposit pending yang tidak dibayar 3 jam → otomatis expired (status 'rejected' + catatan khusus).
 * Setiap deposit yang di-expire-kan, pesannya di Telegram ikut diperbarui jadi "EXPIRED"
 * (tetap ada tombol Acc Expired bila ternyata user sudah bayar).
 */
const DEPO_EXPIRE_HOURS = 3;
const DEPO_EXPIRED_NOTE = 'Expired otomatis (tidak dibayar 3 jam)';

/** Teks & tombol Telegram untuk deposit yang expired (dipakai juga oleh webhook Refresh Status). */
function depo_expired_tg(array $dep): array {
    $method = ($dep['method'] ?? '') === 'qris' ? 'QRIS Otomatis' : 'Transfer Bank';
    $msg  = "⌛ <b>DEPOSIT EXPIRED</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars((string)($dep['username'] ?? '-')) . "</code>\n";
    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
    $msg .= "🕒 <b>Dibuat:</b> <code>" . date('d-m-Y H:i:s', strtotime((string)$dep['created_at'])) . " WIB</code>\n";
    $msg .= "💳 <b>Method:</b> <code>{$method}</code>\n";
    $msg .= "⌛ <b>Status:</b> <code>Expired (tidak dibayar " . DEPO_EXPIRE_HOURS . " jam)</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "<i>Bila ternyata user sudah membayar, tekan Acc Expired untuk mengkreditkan saldo.</i>";
    $id = (int)$dep['id'];
    $kb = [
        [['text' => '⚡ Acc Expired', 'callback_data' => 'depo_accexp_' . $id], ['text' => '🚫 Acc Tanpa Komisi', 'callback_data' => 'depo_accexpnc_' . $id]],
        [['text' => '🔄 Refresh Status', 'callback_data' => 'refresh_depo_' . $id]],
    ];
    return [$msg, $kb];
}

if (!isset($pdo)) {
    return;
}

try {
    $due = $pdo->query("
        SELECT d.id, d.amount, d.method, d.created_at, d.tg_msg_id, u.username
        FROM deposits d LEFT JOIN users u ON u.id = d.user_id
        WHERE d.status = 'pending' AND d.created_at < DATE_SUB(NOW(), INTERVAL " . DEPO_EXPIRE_HOURS . " HOUR)
        ORDER BY d.id ASC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $expire = $pdo->prepare("UPDATE deposits SET status = 'rejected', admin_note = ? WHERE id = ? AND status = 'pending'");
    foreach ($due as $dep) {
        $expire->execute([DEPO_EXPIRED_NOTE, (int)$dep['id']]);
        if ($expire->rowCount() !== 1) continue; // sudah diproses request lain → jangan kirim notif dobel

        try {
            [$msg, $kb] = depo_expired_tg($dep);
            if (!empty($dep['tg_msg_id'])) {
                edit_telegram_notif($pdo, (int)$dep['tg_msg_id'], $msg, $kb);
            } else {
                send_telegram_notif($pdo, $msg, $kb, 'depo');
            }
        } catch (\Throwable) {
            // Notif gagal tidak boleh mengganggu request user
        }
    }
} catch (\Throwable $th) {
    // Silently fail to not interrupt the request
}
