<?php
declare(strict_types=1);
// Pusat Analisis sudah digabung menjadi Dashboard (console/index.php). Alamat lama tetap berfungsi.
require_once __DIR__ . '/auth.php';
$range = (string)($_GET['range'] ?? '');
redirect('/console/' . (in_array($range, ['today', '7', '14', '30'], true) ? '?range=' . $range : ''));
