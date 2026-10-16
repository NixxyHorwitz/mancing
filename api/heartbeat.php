<?php
declare(strict_types=1);
/** Heartbeat dari aplikasi Cuan Forwarder (tanda HP masih aktif mengirim notifikasi). */
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/forwarder.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode((string)$rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON Payload']);
    exit;
}

if (!forwarder_authorized($pdo, $data)) {
    http_response_code(401);
    echo json_encode(['status' => 'unauthorized', 'error' => 'Token forwarder salah atau belum diatur.']);
    exit;
}
unset($data['token']);

$interval_minutes = isset($data['interval_minutes']) ? max(1, (int)$data['interval_minutes']) : 5;
$device_info      = mb_substr((string)($data['device_info'] ?? 'CuanForwarder'), 0, 190);

try {
    $pdo->prepare("INSERT INTO forwarder_heartbeats (interval_minutes, device_info, payload_text, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$interval_minutes, $device_info, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    // Simpan seperlunya: buang heartbeat lebih dari 7 hari
    if (random_int(1, 50) === 1) {
        $pdo->exec("DELETE FROM forwarder_heartbeats WHERE created_at < NOW() - INTERVAL 7 DAY");
    }
    echo json_encode(['status' => 'success', 'message' => 'Heartbeat received', 'server_time' => date('Y-m-d H:i:s')]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save heartbeat', 'details' => $e->getMessage()]);
}
