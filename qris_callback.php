<?php
declare(strict_types=1);
/**
 * Callback pembayaran QRIS dari aplikasi Cuan Forwarder (notifikasi HP).
 * Nominal di notifikasi (sudah termasuk kode unik) dicocokkan ke deposit pending.
 *
 * JSON: { token, uid, package, app, title, text, posted_at, device }
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/forwarder.php';

header('Content-Type: application/json; charset=utf-8');

$reply = static function (array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $reply(['error' => 'Method Not Allowed'], 405);

$rawBody = file_get_contents('php://input');
$data = json_decode((string)$rawBody, true);
if (!is_array($data)) $reply(['error' => 'Invalid JSON Payload'], 400);

if (!forwarder_authorized($pdo, $data)) $reply(['status' => 'unauthorized', 'error' => 'Token forwarder salah atau belum diatur.'], 401);

// Jangan simpan token di log
unset($data['token']);
$logPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

// Notifikasi yang sama terkirim ulang (retry) → abaikan
$uid = (string)($data['uid'] ?? '');
if ($uid !== '') {
    $dup = $pdo->prepare("SELECT id, status, deposit_id FROM payment_gateway_logs WHERE created_at >= NOW() - INTERVAL 2 DAY AND payload LIKE ? ORDER BY id DESC LIMIT 1");
    $dup->execute(['%"uid":' . json_encode($uid) . '%']);
    if ($row = $dup->fetch()) {
        $reply(['status' => 'duplicate', 'log_id' => (int)$row['id'], 'previous_status' => $row['status'], 'deposit_id' => $row['deposit_id']]);
    }
}

try {
    $pdo->prepare("INSERT INTO payment_gateway_logs (payload, status) VALUES (?, 'unmatched')")->execute([$logPayload]);
    $log_id = (int)$pdo->lastInsertId();
} catch (\Throwable $e) {
    $reply(['error' => 'Failed to initialize log record', 'details' => $e->getMessage()], 500);
}
$setLog = static function (string $status, ?float $amount = null, ?int $depId = null) use ($pdo, $log_id): void {
    $pdo->prepare("UPDATE payment_gateway_logs SET status = ?, extracted_amount = COALESCE(?, extracted_amount), deposit_id = COALESCE(?, deposit_id) WHERE id = ?")
        ->execute([$status, $amount, $depId, $log_id]);
};

// Nominal: cari di teks, lalu judul & big text
$amount = 0.0;
foreach (['text', 'big_text', 'title'] as $f) {
    $amount = forwarder_parse_amount((string)($data[$f] ?? ''));
    if ($amount > 0) break;
}
if ($amount <= 0) {
    $setLog('failed', 0.0);
    $reply(['status' => 'ignored', 'message' => 'No valid amount found in text.']);
}
$setLog('unmatched', $amount);

if (setting($pdo, 'depo_unique_code_enabled', '0') !== '1') {
    $setLog('disabled');
    $reply(['status' => 'ignored', 'message' => 'Auto deposit disabled (unique code setting is off).']);
}

// 1) Deposit pending dengan nominal persis sama (sudah termasuk kode unik)
$st = $pdo->prepare("SELECT id FROM deposits WHERE amount = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 1");
$st->execute([$amount]);
$depId = (int)$st->fetchColumn();
$late = false;

// 2) Bayar telat: deposit expired (≤ 6 jam sejak dibuat) dengan nominal sama
if (!$depId) {
    $st = $pdo->prepare("SELECT id FROM deposits WHERE amount = ? AND status = 'rejected' AND admin_note = ? AND created_at >= NOW() - INTERVAL 6 HOUR ORDER BY created_at DESC LIMIT 1");
    $st->execute([$amount, DEPO_EXPIRED_NOTE]);
    $depId = (int)$st->fetchColumn();
    $late = $depId > 0;
}

if (!$depId) $reply(['status' => 'unmatched', 'log_id' => $log_id, 'amount' => $amount, 'message' => 'No matching pending deposit found for amount ' . $amount]);

$res = depo_admin_confirm($pdo, $depId, true, 'Forwarder');
if (!$res['ok']) {
    $setLog('failed', null, $depId);
    $reply(['status' => 'failed', 'error' => $res['message']], 500);
}
$setLog($late ? 'matched_expired' : 'matched', null, $depId);

$dep  = $res['dep'];
$comm = (float)$res['comm'];
$msg  = depo_confirmed_tg_text($dep, $comm, $late ? 'Sukses via Forwarder (bayar setelah expired)' : 'Sukses via Forwarder',
    'Pembayaran terverifikasi otomatis dari notifikasi ' . htmlspecialchars((string)($data['app'] ?? $data['package'] ?? 'HP')) . '.');
if (!empty($dep['tg_msg_id'])) {
    edit_telegram_notif($pdo, (int)$dep['tg_msg_id'], $msg, []);
} else {
    send_telegram_notif($pdo, $msg, [], 'depo');
}

$reply([
    'status'     => $late ? 'matched_expired' : 'matched',
    'message'    => 'Deposit confirmed successfully.',
    'log_id'     => $log_id,
    'deposit_id' => $depId,
    'user_id'    => (int)$dep['user_id'],
    'amount'     => (float)$dep['amount'],
]);
