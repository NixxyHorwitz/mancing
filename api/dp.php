<?php
declare(strict_types=1);
// Tautan lama Deposit Manager: sekarang bagian dari Menu Admin (api/menu.php), langsung ke tab Deposit.
$_GET['s'] = $_GET['s'] ?? 'depo';
require __DIR__ . '/menu.php';
