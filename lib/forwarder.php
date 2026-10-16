<?php
declare(strict_types=1);

/**
 * Forwarder notifikasi pembayaran (aplikasi Android "Cuan Forwarder").
 * Aplikasi membaca notifikasi aplikasi pembayaran, lalu mengirimnya ke
 * /qris_callback.php dan /api/heartbeat.php dengan token rahasia.
 */

/** Token rahasia forwarder (dibuat otomatis bila belum ada). */
function forwarder_token(PDO $pdo, bool $regenerate = false): string {
    $tok = setting($pdo, 'forwarder_token', '');
    if ($tok === '' || $regenerate) {
        $tok = 'fwd_' . bin2hex(random_bytes(16));
        setting_set($pdo, 'forwarder_token', $tok);
    }
    return $tok;
}

/** Cek token dari header X-Forwarder-Token atau field "token" pada JSON. */
function forwarder_authorized(PDO $pdo, array $data): bool {
    $expected = setting($pdo, 'forwarder_token', '');
    if ($expected === '') return false;
    $given = (string)($_SERVER['HTTP_X_FORWARDER_TOKEN'] ?? ($data['token'] ?? ''));
    return $given !== '' && hash_equals($expected, $given);
}

/**
 * Ambil nominal rupiah dari teks notifikasi.
 * Contoh: "Rp25.003", "Rp 25.003,00", "IDR 25,003", "Rp. 1.250.000".
 */
function forwarder_parse_amount(string $text): float {
    if (!preg_match('/(?:Rp\.?|IDR)\s*([0-9][0-9.,]*)/i', $text, $m)) return 0.0;
    $num = rtrim($m[1], '.,');
    // Buang desimal ",00" / ".00" di akhir
    $num = preg_replace('/[.,]\d{2}$/', '', $num);
    $num = str_replace(['.', ','], '', $num);
    return (float)$num;
}
