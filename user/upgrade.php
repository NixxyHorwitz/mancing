<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

function get_active_price(array $membership, array $user): float {
    $price = (float)$membership['price'];
    if (!empty($membership['is_genjutsu'])) {
        $balance = (float)($user['balance_dep'] ?? 0);
        if ($balance >= $price) {
            $price = (float)$membership['price_genjutsu'];
        }
    }
    return $price;
}

// Hitung rata-rata reward video aktif untuk estimasi panen yang akurat
$v_stats = $pdo->query("SELECT AVG(reward_amount) AS avg_reward, COUNT(*) AS total_videos FROM videos WHERE is_active=1")->fetch();
$avg_video_reward = (float)($v_stats['avg_reward'] ?? 7200);
$total_active_videos = (int)($v_stats['total_videos'] ?? 0);

/**
 * Menghitung estimasi panen berdasarkan limit tonton harian dan rata-rata reward video aktif
 */
function get_harvest_calc(?array $m, float $avg_reward): ?array {
    if (!$m) return null;
    $watch_limit   = (int)($m['watch_limit'] ?? 0);
    $duration_days = (int)($m['duration_days'] ?? 30);
    $price         = (float)($m['price'] ?? 0);

    $daily_income  = $watch_limit * $avg_reward;
    $total_harvest = $daily_income * $duration_days;
    $break_even_days = ($daily_income > 0 && $price > 0) ? max(1, (int)ceil($price / $daily_income)) : 1;

    $rounded_total = round($total_harvest / 1000) * 1000;
    $rounded_daily = round($daily_income / 1000) * 1000;

    if ($duration_days == 60) {
        $period_label = ' / 2 bln';
    } elseif ($duration_days == 30) {
        $period_label = '/bln';
    } else {
        $period_label = " / {$duration_days} hari";
    }

    return [
        'daily_income'    => $daily_income,
        'total_harvest'   => $total_harvest,
        'break_even_days' => $break_even_days,
        'period_label'    => $period_label,
        'harvest_text'    => '~' . format_rp($rounded_total) . $period_label,
        'daily_text'      => '~' . format_rp($rounded_daily) . '/hari',
    ];
}

$all_memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
$memberships = [];
foreach ($all_memberships as $ms) {
    if (!empty($ms['is_genjutsu_hilang'])) {
        $price = (float)$ms['price'];
        $balance = (float)($user['balance_dep'] ?? 0);
        if ($balance >= $price) {
            continue;
        }
    }
    $memberships[] = $ms;
}
$flash = $flashType = '';

// Active membership info
$active_membership = null;
$can_refund = false;
$user_settings = ['refund_cut_percent' => 20, 'is_refund_enabled' => 1];

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $active_membership = $ms->fetch();
    
    $uSet = $pdo->prepare("SELECT refund_cut_percent, is_refund_enabled FROM users WHERE id=?");
    $uSet->execute([$user['id']]);
    $user_settings = $uSet->fetch() ?: $user_settings;
    
    if ($user_settings['is_refund_enabled']) {
        $lastUp = $pdo->prepare("SELECT confirmed_at FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
        $lastUp->execute([$user['id'], $active_membership['id']]);
        $last_confirmed = $lastUp->fetchColumn();
        if ($last_confirmed && strtotime($last_confirmed) > time() - (12 * 3600)) {
            $can_refund = true;
        }
    }
}

// AJAX Check Voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_voucher') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['error' => 'Sesi kedaluwarsa, muat ulang halaman.']); exit; }
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $mid  = (int)($_POST['membership_id'] ?? 0);
    
    if (!$code) { echo json_encode(['error' => 'Masukkan kode voucher.']); exit; }
    if (!$mid) { echo json_encode(['error' => 'Pilih paket terlebih dahulu.']); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ?");
    $stmt->execute([$code]);
    $v = $stmt->fetch();
    
    if (!$v) { echo json_encode(['error' => 'Kode voucher tidak ditemukan atau tidak valid.']); exit; }
    if ($v['expires_at'] && strtotime($v['expires_at']) < time()) { echo json_encode(['error' => 'Voucher ini sudah kedaluwarsa.']); exit; }
    if ($v['max_claims'] > 0 && $v['claims_count'] >= $v['max_claims']) { echo json_encode(['error' => 'Kuota voucher ini sudah habis.']); exit; }
    
    $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
    $chk->execute([$user['id'], $v['id']]);
    if ($chk->fetch()) { echo json_encode(['error' => 'Kamu sudah menggunakan voucher ini sebelumnya.']); exit; }
    
    $discounts = json_decode($v['discounts'], true) ?: [];
    
    if (isset($discounts['*'])) {
        $val = $discounts['*'];
    } elseif (isset($discounts[$mid])) {
        $val = $discounts[$mid];
    } else {
        echo json_encode(['error' => 'Voucher ini tidak dapat digunakan untuk paket pilihanmu.']); exit;
    }
    
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen_m = $ms->fetch();
    if (!$chosen_m || (!empty($chosen_m['is_genjutsu_hilang']) && (float)($user['balance_dep'] ?? 0) >= (float)$chosen_m['price'])) {
        echo json_encode(['error' => 'Paket tidak valid.']); exit;
    }
    $price = get_active_price($chosen_m, $user);
    if (!$price) { echo json_encode(['error' => 'Paket tidak valid.']); exit; }
    
    $is_rp = false;
    $pct = 0;
    if (is_string($val) && stripos($val, 'rp') !== false) {
        $discount_amount = (float)str_ireplace('rp', '', $val);
        $is_rp = true;
    } elseif (is_numeric($val) && $val > 100) {
        $discount_amount = (float)$val; // legacy format fallback
        $is_rp = true;
    } else {
        $pct = (float)$val;
        $discount_amount = ($price * $pct) / 100;
    }
    
    $final_price = $price - $discount_amount;
    if ($final_price < 0) $final_price = 0;
    
    $discount_text = $is_rp ? 'Rp ' . number_format($discount_amount, 0, ',', '.') : $pct . '%';
    
    echo json_encode([
        'ok' => true,
        'discount_text' => $discount_text,
        'discount_amount' => $discount_amount,
        'discount_amount_formatted' => format_rp($discount_amount),
        'final_price' => $final_price,
        'final_price_formatted' => format_rp($final_price)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_verify()) {
        $flash = 'Sesi keamanan kedaluwarsa. Muat ulang halaman lalu coba lagi.'; $flashType = 'error';
        goto end_post;
    }
    
    if ($action === 'refund_level') {
        $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_level' AND status='pending'");
        $stmtPending->execute([$user['id']]);
        
        if ($stmtPending->fetchColumn()) {
            $flash = '❌ Permintaan pengembalian dana kamu sebelumnya masih dalam proses verifikasi otomatis.'; $flashType = 'error';
        } else {
            $s = $pdo->prepare("SELECT m.id as membership_id, m.name, m.price, u.refund_cut_percent, u.is_refund_enabled FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
            $s->execute([$user['id']]);
            $uInfo = $s->fetch();
            $mName = $uInfo['name'] ?? null;
            
            if (!$mName) {
                $flash = '❌ Kamu tidak memiliki paket aktif.'; $flashType = 'error';
            } elseif (!$uInfo['is_refund_enabled']) {
                $flash = '❌ Akses refund kamu telah dinonaktifkan.'; $flashType = 'error';
            } else {
                $pdo->prepare("INSERT INTO admin_requests (user_id, type) VALUES (?, 'refund_level')")->execute([$user['id']]);
                $req_id = $pdo->lastInsertId();
                
                $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
                $oStmt->execute([$user['id'], $uInfo['membership_id']]);
                $basePrice = (float)$oStmt->fetchColumn();
                if (!$basePrice) $basePrice = (float)$uInfo['price'];
                
                $pct = (float)$uInfo['refund_cut_percent'];
                $cutAmount = ($basePrice * $pct) / 100;
                $afterCut = $basePrice - $cutAmount;
                
                $msg  = "💰 <b>REQUEST REFUND LEVEL</b>\n\n";
                $msg .= "👤 User: <code>{$user['username']}</code>\n";
                $msg .= "🏆 Level: <b>{$mName}</b>\n";
                $msg .= "💵 Harga Awal: <b>" . format_rp($basePrice) . "</b>\n";
                $msg .= "✂️ Setelah Dipotong ({$pct}%): <b>" . format_rp($afterCut) . "</b>\n\n";
                $msg .= "⚠️ <i>Refund ini akan membatalkan level user dan mengembalikan saldo dengan potongan {$pct}% (jika di-Approve).</i>\n";
                $kb = [
                    [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]],
                    [['text'=>"⚙️ Ubah Potongan ({$pct}%)", 'callback_data'=>'edit_refcut_'.$user['id']], ['text'=>'➡️ Cabut Akses Refund', 'callback_data'=>'toggle_ref_'.$user['id']]]
                ];
                send_telegram_notif($pdo, $msg, $kb, 'permintaan');
                
                $flash = '✅ Permintaan pengembalian dana kamu telah masuk dan sedang diverifikasi oleh sistem secara otomatis.';
            }
        }
        goto end_post;
    }

    $mid = (int)($_POST['membership_id'] ?? 0);
    $ms  = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen = $ms->fetch();

    if (!$chosen) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif (!empty($chosen['is_genjutsu_hilang']) && (float)($user['balance_dep'] ?? 0) >= (float)$chosen['price']) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif ((float)$chosen['price'] == 0) {
        $flash = 'Paket Free gak usah diupgrade ya!'; $flashType = 'error';
    } else {
        $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
        $price = get_active_price($chosen, $user);
        $final_price = $price;
        $v_data = null;
        
        if ($voucher_code !== '') {
            $v_stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ? FOR UPDATE");
            $v_stmt->execute([$voucher_code]);
            $v_data = $v_stmt->fetch();
            
            if (!$v_data) {
                $flash = 'Kode vouchermu gak valid nih.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['expires_at'] && strtotime($v_data['expires_at']) < time()) {
                $flash = 'Wah, voucher diskon ini udah kedaluwarsa.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['max_claims'] > 0 && $v_data['claims_count'] >= $v_data['max_claims']) {
                $flash = 'Kuota voucher diskon ini udah abis ya.'; $flashType = 'error';
                goto end_post;
            }
            
            $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
            $chk->execute([$user['id'], $v_data['id']]);
            if ($chk->fetch()) {
                $flash = 'Kamu udah pernah pakai voucher diskon ini sebelumnya.'; $flashType = 'error';
                goto end_post;
            }
            
            $discounts = json_decode($v_data['discounts'], true) ?: [];
            
            if (isset($discounts['*'])) {
                $val = $discounts['*'];
            } elseif (isset($discounts[$mid])) {
                $val = $discounts[$mid];
            } else {
                $flash = 'Voucher diskon ini gak bisa dipakai buat paket pilihanmu ya.'; $flashType = 'error';
                goto end_post;
            }
            
            if (is_string($val) && stripos($val, 'rp') !== false) {
                $discount_amount = (float)str_ireplace('rp', '', $val);
            } elseif (is_numeric($val) && $val > 100) {
                $discount_amount = (float)$val; // legacy format fallback
            } else {
                $pct = (float)$val;
                $discount_amount = ($price * $pct) / 100;
            }
            
            $final_price = $price - $discount_amount;
            if ($final_price < 0) $final_price = 0;
        }
        
        if ((float)$user['balance_dep'] < $final_price) {
            $flash = 'Saldo Beli kamu kurang nih. Yuk deposit dulu!'; $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("UPDATE users SET balance_dep=balance_dep-? WHERE id=? AND balance_dep >= ?");
                $stmt->execute([$final_price, $user['id'], $final_price]);
                
                if ($stmt->rowCount() > 0) {
                    if ($v_data) {
                        $v_lock = $pdo->prepare("SELECT claims_count, max_claims FROM discount_vouchers WHERE id = ? FOR UPDATE");
                        $v_lock->execute([$v_data['id']]);
                        $v_current = $v_lock->fetch();
                        if ($v_current['max_claims'] > 0 && $v_current['claims_count'] >= $v_current['max_claims']) {
                            throw new \Exception("Kuota voucher diskon sudah habis.");
                        }
                        
                        $pdo->prepare("INSERT INTO user_discount_claims (user_id, voucher_id) VALUES (?, ?)")
                            ->execute([$user['id'], $v_data['id']]);
                        
                        $pdo->prepare("UPDATE discount_vouchers SET claims_count = claims_count + 1 WHERE id = ?")
                            ->execute([$v_data['id']]);
                    }
                    
                    $pdo->prepare("INSERT INTO upgrade_orders (user_id,membership_id,amount,status,confirmed_at) VALUES (?,?,?,'confirmed',NOW())")
                        ->execute([$user['id'], $mid, $final_price]);
                    
                    $new_expires = date('Y-m-d H:i:s', strtotime("+{$chosen['duration_days']} days"));
                    $pdo->prepare("UPDATE users SET membership_id=?, membership_expires_at=? WHERE id=?")
                        ->execute([$mid, $new_expires, $user['id']]);
                    
                    $pdo->commit();
                    
                    $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
                    $flash = '🎉 Hore! Upgrade ke ' . htmlspecialchars($chosen['name']) . ' berhasil! Berlaku s/d ' . date('d M Y', strtotime($new_expires)) . ' ya.';
                    $active_membership = $chosen;
                    $can_refund = true; // just upgraded, well within 12 hours
                    
                    $msgNotif = "🎉 <b>MEMBER UPGRADE LEVEL</b>\n\n";
                    $msgNotif .= "👤 User: <code>{$user['username']}</code>\n";
                    $msgNotif .= "🏆 Level Baru: <b>{$chosen['name']}</b>\n";
                    $msgNotif .= "💰 Harga: " . format_rp((float)$final_price) . "\n";
                    $msgNotif .= "🕒 Waktu: " . date('d M Y H:i:s');
                    send_telegram_notif($pdo, $msgNotif, [], 'log');
                } else {
                    $pdo->rollBack();
                    $flash = 'Saldo Beli kamu kurang nih. Transaksi gagal ya.'; $flashType = 'error';
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Terjadi kesalahan: ' . $e->getMessage(); $flashType = 'error';
            }
        }
    }
}
end_post:

// Migrasi sekali jalan: nama pangkat tema Amber -> tema nelayan (hanya jika masih bernama lama)
$rankRename = [
  'Raw Amber'      => ['Nelayan Pemula',    'ph-fish-simple',    'Pangkat awal. Kuota nonton harian dasar.'],
  'Golden Amber'   => ['Juragan Perahu',    'ph-sailboat',       'Kuota nonton lebih banyak & batas tarik lebih tinggi.'],
  'Royal Amber'    => ['Kapten Kapal',      'ph-steering-wheel', 'Pilihan favorit: kuota besar & tarik lebih cepat.'],
  'Imperial Amber' => ['Laksamana Samudra', 'ph-crown',          'Pangkat tertinggi: kuota maksimal & pencairan prioritas.'],
];
$renStmt = $pdo->prepare("UPDATE memberships SET name = ?, icon = ?, description = ? WHERE name = ?");
foreach ($rankRename as $old => [$new, $icon, $desc]) $renStmt->execute([$new, $icon, $desc, $old]);
// Reload after rename
$memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
// Ambil ulang pangkat aktif agar nama/ikon sesuai data terbaru
if ($active_membership) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$active_membership['id']]);
    $active_membership = $ms->fetch() ?: $active_membership;
}

$pageTitle  = 'Naik Pangkat';
$activePage = 'upgrade';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════════════════
   NAIK PANGKAT — Living Coral Reef, ringkas & minim teks
   Token warna & latar laut dari partials/header.php (--reef-*)
   ══════════════════════════════════════════════════════════ */
.pk-wrap { position: relative; z-index: 1; max-width: 480px; margin: 0 auto; padding: 8px 12px 96px; color: var(--reef-ink); }
.pk-flash { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; padding: 8px 11px; border: 2px solid #fff; border-radius: 16px; font-size: 11.5px; font-weight: 800; box-shadow: var(--reef-clay-shadow); }
.pk-flash.success { background: linear-gradient(145deg, #ecfdf5, #d1fae5); color: #065f46; }
.pk-flash.error { background: linear-gradient(145deg, #fff1f2, #ffe4e6); color: #9f1239; }

/* Strip status: saldo beli + pangkat sekarang */
.pk-status { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.pk-st { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 8px 9px; border: 2px solid #fff; border-radius: 18px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.pk-st .ic { width: 32px; height: 32px; flex-shrink: 0; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 16px; color: #fff; box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -2px -2px 4px rgba(0, 0, 0, 0.14); }
.pk-st .ic.wal { background: linear-gradient(145deg, #c084fc, #7c3aed); }
.pk-st .ic.rank { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.pk-st .tx { flex: 1; min-width: 0; }
.pk-st small { display: block; font-size: 8.5px; font-weight: 900; letter-spacing: 0.4px; text-transform: uppercase; color: var(--reef-muted); }
.pk-st b { display: block; font-size: 12.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-st em { display: block; font-style: normal; font-size: 9px; font-weight: 800; color: var(--reef-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-st .add { flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; color: #fff; font-size: 14px; background: linear-gradient(145deg, #6ee7b7, #059669); border: 2px solid #fff; box-shadow: 0 3px 8px rgba(5, 150, 105, 0.3); }
.pk-refund { flex-shrink: 0; border: none; background: none; padding: 0; cursor: pointer; color: #e11d48; font-size: 16px; }

/* Pemilih pangkat */
.pk-tiers { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: 5px; margin: 10px 0 8px; padding: 4px; border: 2px solid #fff; border-radius: 18px; background: rgba(255, 255, 255, 0.6); box-shadow: inset 1.5px 1.5px 4px rgba(8, 145, 178, 0.12); }
.pk-tier { position: relative; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 6px 4px 5px; border: none; border-radius: 14px; background: none; cursor: pointer; font-family: inherit; color: var(--reef-muted); }
.pk-tier i { font-size: 18px; }
.pk-tier b { font-size: 10.5px; font-weight: 900; line-height: 1.1; text-align: center; }
.pk-tier small { font-size: 9px; font-weight: 800; }
.pk-tier .hot { position: absolute; top: -9px; left: 50%; transform: translateX(-50%); padding: 1px 6px; border-radius: 999px; font-size: 7.5px; font-weight: 900; letter-spacing: 0.4px; color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); border: 1.5px solid #fff; white-space: nowrap; }
.pk-tier.on { color: #fff; box-shadow: 0 5px 12px rgba(30, 27, 46, 0.18), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
.pk-tier.on.t0 { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.pk-tier.on.t1 { background: linear-gradient(145deg, #fb7185, #e11d48); }
.pk-tier.on.t2 { background: linear-gradient(145deg, #fcd34d, #d97706); }
.pk-tier .cur { position: absolute; top: 3px; right: 4px; font-size: 11px; color: #10b981; }
.pk-tier.on .cur { color: #fff; }

/* Kartu harga */
.pk-card { display: none; position: relative; overflow: hidden; border: 2px solid #fff; border-radius: 24px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); }
.pk-card.on { display: block; animation: pkIn 0.3s ease; }
@keyframes pkIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
.pk-top { position: relative; display: flex; align-items: center; gap: 11px; padding: 13px 14px 12px; color: #fff; }
.pk-card.t0 .pk-top { background: linear-gradient(150deg, #0e7490, #22d3ee); }
.pk-card.t1 .pk-top { background: linear-gradient(150deg, #be123c, #fb7185); }
.pk-card.t2 .pk-top { background: linear-gradient(150deg, #b45309, #fbbf24); }
.pk-top::after { content: ''; position: absolute; right: -24px; top: -34px; width: 120px; height: 120px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.25), transparent 70%); }
.pk-emb { position: relative; width: 50px; height: 50px; flex-shrink: 0; border-radius: 17px; display: flex; align-items: center; justify-content: center; font-size: 26px; background: rgba(255, 255, 255, 0.22); border: 2px solid rgba(255, 255, 255, 0.75); box-shadow: inset 2px 2px 4px rgba(255, 255, 255, 0.35); }
.pk-name { position: relative; flex: 1; min-width: 0; }
.pk-name b { display: block; font-size: 17px; font-weight: 900; line-height: 1.1; text-shadow: 0 2px 3px rgba(0, 0, 0, 0.2); }
.pk-name span { display: inline-flex; align-items: center; gap: 3px; margin-top: 4px; padding: 1px 8px; border-radius: 999px; font-size: 9.5px; font-weight: 900; background: rgba(255, 255, 255, 0.25); }
.pk-price { position: relative; text-align: right; flex-shrink: 0; }
.pk-price s { display: block; font-size: 10px; font-weight: 800; opacity: 0.8; }
.pk-price b { display: block; font-size: 19px; font-weight: 900; line-height: 1.1; text-shadow: 0 2px 3px rgba(0, 0, 0, 0.2); }
.pk-price em { display: inline-block; margin-top: 2px; padding: 0 6px; border-radius: 999px; font-style: normal; font-size: 9px; font-weight: 900; background: #fff; color: #e11d48; }
.pk-body { padding: 11px 12px 12px; }
.pk-nums { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.pk-num { display: flex; align-items: center; gap: 7px; padding: 7px 8px; border-radius: 14px; background: rgba(255, 255, 255, 0.85); box-shadow: inset 1px 1px 3px rgba(251, 146, 60, 0.15); min-width: 0; }
.pk-num i { font-size: 17px; flex-shrink: 0; }
.pk-num b { display: block; font-size: 12.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-num small { display: block; font-size: 8.5px; font-weight: 800; color: var(--reef-muted); text-transform: uppercase; letter-spacing: 0.3px; }
.pk-num.c1 i { color: #0891b2; } .pk-num.c2 i { color: #059669; } .pk-num.c3 i { color: #7c3aed; } .pk-num.c4 i { color: #e11d48; }
.pk-perks { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.pk-perks span { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 900; color: #047857; background: #d1fae5; }
.pk-cta { width: 100%; height: 46px; margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 7px; border: 2.5px solid #fff; border-radius: 16px; cursor: pointer; font-family: inherit; font-size: 13.5px; font-weight: 900; color: #fff;
  box-shadow: 0 9px 18px rgba(30, 27, 46, 0.2), inset 3px 3px 5px rgba(255, 255, 255, 0.4), inset -3px -4px 8px rgba(0, 0, 0, 0.18); }
.pk-cta i { font-size: 18px; }
.pk-card.t0 .pk-cta { background: linear-gradient(145deg, #22d3ee, #0e7490); }
.pk-card.t1 .pk-cta { background: linear-gradient(145deg, #fb7185, #be123c); }
.pk-card.t2 .pk-cta { background: linear-gradient(145deg, #fbbf24, #b45309); }
.pk-cta.low { background: linear-gradient(145deg, #e2e8f0, #cbd5e1) !important; color: #475569; box-shadow: inset 2px 2px 3px #fff; }
.pk-cta.is-cur { background: linear-gradient(145deg, #6ee7b7, #059669) !important; }
.pk-note { margin-top: 6px; text-align: center; font-size: 9.5px; font-weight: 700; color: var(--reef-muted); }

/* Jaminan (ikon saja) */
.pk-trust { display: flex; justify-content: space-between; gap: 6px; margin-top: 10px; }
.pk-trust span { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 7px 4px; border: 2px solid #fff; border-radius: 15px; background: rgba(255, 255, 255, 0.7); font-size: 9.5px; font-weight: 900; text-align: center; box-shadow: 0 3px 8px rgba(8, 145, 178, 0.06); }
.pk-trust i { font-size: 18px; }
.pk-trust span:nth-child(1) i { color: #059669; } .pk-trust span:nth-child(2) i { color: #f59e0b; } .pk-trust span:nth-child(3) i { color: #0891b2; }
/* Bonus joran */
.pk-rod-ttl { display: flex; align-items: center; gap: 5px; margin: 10px 2px 5px; font-size: 10.5px; font-weight: 900; color: #0e7490; }
.pk-rod-ttl i { font-size: 14px; }
.pk-rod-ttl small { margin-left: auto; font-size: 9px; font-weight: 800; color: var(--reef-muted); }
.pk-rod { display: flex; align-items: center; gap: 9px; padding: 7px 9px; border-radius: 14px; background: rgba(255, 255, 255, 0.9); box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--rc) 22%, transparent); }
.pk-rod + .pk-rod { margin-top: 5px; }
.pk-rod-ic { width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: linear-gradient(145deg, color-mix(in srgb, var(--rc) 55%, #fff), var(--rc)); border: 2px solid #fff; box-shadow: 0 4px 9px color-mix(in srgb, var(--rc) 35%, transparent), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.45); }
.pk-rod-ic svg { width: 24px; height: 24px; }
.pk-rod-tx { flex: 1; min-width: 0; }
.pk-rod-tx b { display: block; font-size: 11.5px; font-weight: 900; color: var(--rc); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-rod-b { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 2px; }
.pk-rod-b em { display: inline-flex; align-items: center; gap: 3px; padding: 1px 7px; border-radius: 999px; font-style: normal; font-size: 9.5px; font-weight: 900; color: var(--reef-ink); background: color-mix(in srgb, var(--rc) 12%, #fff); }
/* Panduan lipat */
.pk-guide { margin-top: 8px; border: 2px solid #fff; border-radius: 18px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }
.pk-guide summary { display: flex; align-items: center; gap: 6px; padding: 10px 12px; cursor: pointer; list-style: none; font-size: 12px; font-weight: 900; }
.pk-guide summary::-webkit-details-marker { display: none; }
.pk-guide summary > i:first-child { font-size: 16px; color: #0891b2; }
.pk-guide summary .chev { margin-left: auto; font-size: 13px; color: var(--reef-muted); transition: transform 0.2s; }
.pk-guide[open] summary .chev { transform: rotate(180deg); }
.pk-steps { list-style: none; margin: 0; padding: 0 12px 10px; display: flex; flex-direction: column; gap: 6px; }
.pk-steps li { display: flex; align-items: flex-start; gap: 9px; }
.pk-steps li > span { width: 22px; height: 22px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; color: #fff; background: linear-gradient(145deg, #67e8f9, #0891b2); border: 2px solid #fff; box-shadow: 0 3px 6px rgba(8, 145, 178, 0.25); }
.pk-steps b { display: block; font-size: 11.5px; font-weight: 900; }
.pk-steps small { display: block; font-size: 10px; font-weight: 700; color: var(--reef-muted); line-height: 1.35; }
.pk-glos { margin: 0; padding: 0 12px 10px; display: flex; flex-direction: column; gap: 6px; }
.pk-glos > div { padding: 6px 9px; border-radius: 12px; background: rgba(255, 255, 255, 0.85); }
.pk-glos dt { display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 900; }
.pk-glos dt i { font-size: 14px; }
.pk-glos dd { margin: 1px 0 0 19px; font-size: 10px; font-weight: 700; color: var(--reef-muted); line-height: 1.35; }
.pk-empty { padding: 20px; text-align: center; border-radius: 20px; border: 2px dashed rgba(8, 145, 178, 0.35); background: rgba(255, 255, 255, 0.65); font-size: 11.5px; font-weight: 800; color: var(--reef-muted); }

/* Modal */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 9999; align-items: center; justify-content: center; padding: 20px; background: rgba(30, 27, 46, 0.55); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
.cg-modal-card { width: 100%; max-width: 330px; padding: 16px; border: 2px solid #fff; border-radius: 24px; background: var(--reef-card); box-shadow: 0 24px 50px rgba(30, 27, 46, 0.3), inset 3px 3px 6px #fff; animation: pkPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes pkPop { from { transform: scale(0.88); opacity: 0; } to { transform: none; opacity: 1; } }
.cg-mc-hdr { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 15px; font-weight: 900; }
.cg-mc-hdr i { color: #e11d48; font-size: 19px; }
.cg-mc-box { margin: 10px 0; padding: 10px 12px; border-radius: 16px; background: rgba(255, 255, 255, 0.9); box-shadow: inset 1px 1px 3px rgba(251, 146, 60, 0.15); }
.cg-mc-val { font-size: 16px; font-weight: 900; color: #0e7490; text-align: center; margin-bottom: 6px; }
.cg-line { display: flex; justify-content: space-between; padding: 3px 0; font-size: 11.5px; font-weight: 800; color: var(--reef-muted); }
.cg-line b, .cg-line span { font-weight: 900; color: var(--reef-ink); }
#discount-row { color: #059669; } #discount-row span { color: #059669; }
#final-price-row { margin-top: 3px; padding-top: 6px; border-top: 1.5px dashed rgba(251, 146, 60, 0.3); font-size: 13px; }
#final-price-row span { color: #e11d48; }
.cg-voucher-btn { display: inline-flex; align-items: center; gap: 4px; padding: 0; border: none; background: none; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 900; color: #0891b2; }
#voucher-box { margin-top: 6px; gap: 6px; }
#voucher-input { flex: 1; min-width: 0; padding: 8px 10px; border: 2px solid #fff; border-radius: 12px; outline: none; font-family: inherit; font-size: 11.5px; font-weight: 900; text-transform: uppercase; background: #fff; box-shadow: inset 1px 1px 4px rgba(8, 145, 178, 0.18); }
.cg-voucher-apply { padding: 8px 12px; border: 2px solid #fff; border-radius: 12px; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 900; color: #fff; background: linear-gradient(145deg, #67e8f9, #0891b2); }
#voucher-msg { margin-top: 5px; font-size: 10.5px; font-weight: 800; display: none; }
#modal-warn { display: none; margin: 8px 0; padding: 7px 9px; border-radius: 12px; background: #fff1f2; font-size: 10.5px; font-weight: 800; color: #be123c; }
.cg-btn-row { display: flex; gap: 8px; margin-top: 10px; }
.cg-btn { flex: 1; padding: 11px; border: 2px solid #fff; border-radius: 15px; cursor: pointer; font-family: inherit; font-size: 12.5px; font-weight: 900; }
.cg-btn--cancel { background: #fff; color: var(--reef-muted); box-shadow: inset 1.5px 1.5px 3px #fff, 0 3px 8px rgba(30, 27, 46, 0.08); }
.cg-btn--confirm { color: #fff; background: linear-gradient(145deg, #6ee7b7, #059669); box-shadow: 0 6px 14px rgba(5, 150, 105, 0.3), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
.cg-btn--danger { color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 6px 14px rgba(225, 29, 72, 0.3), inset 2px 2px 3px rgba(255, 255, 255, 0.4); }
.cg-sub { margin-top: 4px; text-align: center; font-size: 11px; font-weight: 700; color: var(--reef-muted); line-height: 1.45; }
</style>

<?php
$paid = array_values(array_filter($memberships, fn($m) => (float)$m['price'] > 0));
usort($paid, fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);
// Benefit joran mancing: joran ber-syarat pangkat (req_tier = sort_order pangkat), lihat Console › Fish System › Joran
$rods_by_tier = [];
try {
    require_once dirname(__DIR__) . '/lib/fishing.php';
    foreach (fishing_rod_catalog($pdo) as $rk => $rd) {
        if ($rd['tier'] > 0) $rods_by_tier[$rd['tier']][] = $rd + ['key' => $rk];
    }
} catch (\Throwable $e) { $rods_by_tier = []; }
$ROD_COLOR = ['biasa' => '#0891b2', 'bagus' => '#059669', 'langka' => '#e11d48', 'super' => '#7c3aed', 'jackpot' => '#d97706'];
$free_rank  = current(array_filter($memberships, fn($m) => (float)$m['price'] == 0)) ?: null;
$cur_id     = $active_membership ? (int)$active_membership['id'] : 0;
$hot_idx    = count($paid) >= 3 ? 1 : -1;
// Pangkat terpilih awal: satu tingkat di atas pangkat aktif, atau yang populer
$sel_idx = $hot_idx >= 0 ? $hot_idx : 0;
foreach ($paid as $i => $m) { if ((int)$m['id'] === $cur_id) { $sel_idx = min($i + 1, count($paid) - 1); break; } }
$short = static fn(float $n): string => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . 'jt' : ($n >= 1000 ? number_format($n / 1000, 0, ',', '.') . 'rb' : number_format($n, 0, ',', '.'));
?>
<div class="pk-wrap">

  <?php if ($flash): ?>
    <div class="pk-flash <?= $flashType === 'error' ? 'error' : 'success' ?>"><span><?= htmlspecialchars($flash) ?></span></div>
  <?php endif; ?>

  <!-- STATUS -->
  <div class="pk-status">
    <div class="pk-st">
      <span class="ic wal"><i class="ph-fill ph-wallet"></i></span>
      <span class="tx"><small>Saldo Beli</small><b><?= format_rp((float)$user['balance_dep']) ?></b></span>
      <a href="/deposit" class="add" aria-label="Isi Saldo Beli"><i class="ph-bold ph-plus"></i></a>
    </div>
    <div class="pk-st">
      <span class="ic rank"><i class="ph-fill <?= htmlspecialchars((string)($active_membership['icon'] ?? ($free_rank['icon'] ?? 'ph-fish-simple'))) ?>"></i></span>
      <span class="tx">
        <small>Pangkatmu</small>
        <b><?= htmlspecialchars((string)($active_membership['name'] ?? ($free_rank['name'] ?? 'Nelayan Pemula'))) ?></b>
        <em><?= $active_membership ? 's/d ' . date('d M Y', strtotime($user['membership_expires_at'])) : 'Gratis' ?></em>
      </span>
      <?php if ($can_refund): ?>
        <button type="button" class="pk-refund" onclick="document.getElementById('refund-modal').style.display='flex'" aria-label="Ajukan refund" title="Ajukan refund"><i class="ph-bold ph-arrow-u-up-left"></i></button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$paid): ?>
    <div class="pk-empty" style="margin-top:10px">Belum ada pangkat berbayar yang tersedia.</div>
  <?php else: ?>

  <!-- PEMILIH PANGKAT -->
  <div class="pk-tiers" role="tablist">
    <?php foreach ($paid as $i => $m): ?>
      <button type="button" class="pk-tier t<?= $i % 3 ?> <?= $i === $sel_idx ? 'on' : '' ?>" data-tier="<?= $i ?>" onclick="pickTier(<?= $i ?>)" role="tab">
        <?php if ($i === $hot_idx): ?><span class="hot">FAVORIT</span><?php endif; ?>
        <?php if ((int)$m['id'] === $cur_id): ?><i class="ph-fill ph-check-circle cur"></i><?php endif; ?>
        <i class="ph-fill <?= htmlspecialchars((string)$m['icon']) ?>"></i>
        <b><?= htmlspecialchars($m['name']) ?></b>
        <small><?= $short(get_active_price($m, $user)) ?></small>
      </button>
    <?php endforeach; ?>
  </div>

  <form method="POST" id="upgrade-form">
    <?= csrf_field() ?>
    <input type="hidden" name="membership_id" id="chosen-id" value="">
    <input type="hidden" name="voucher_code" id="applied-voucher-code" value="">
  </form>

  <!-- KARTU HARGA -->
  <?php foreach ($paid as $i => $m):
    $active_price = get_active_price($m, $user);
    $can_afford   = (float)$user['balance_dep'] >= $active_price;
    $h            = get_harvest_calc($m, $avg_video_reward);
    $orig         = (float)$m['original_price'];
    $off          = $orig > $active_price && $orig > 0 ? (int)round((1 - $active_price / $orig) * 100) : 0;
    $is_cur       = (int)$m['id'] === $cur_id;
    $is_top       = $i === count($paid) - 1;
  ?>
    <div class="pk-card t<?= $i % 3 ?> <?= $i === $sel_idx ? 'on' : '' ?>" data-card="<?= $i ?>">
      <div class="pk-top">
        <div class="pk-emb"><i class="ph-fill <?= htmlspecialchars((string)$m['icon']) ?>"></i></div>
        <div class="pk-name">
          <b><?= htmlspecialchars($m['name']) ?></b>
          <span><i class="ph-bold ph-hourglass"></i> <?= (int)$m['duration_days'] ?> hari</span>
        </div>
        <div class="pk-price">
          <?php if ($orig > $active_price): ?><s><?= format_rp($orig) ?></s><?php endif; ?>
          <b><?= format_rp($active_price) ?></b>
          <?php if ($off > 0): ?><em>-<?= $off ?>%</em><?php endif; ?>
        </div>
      </div>
      <div class="pk-body">
        <div class="pk-nums">
          <div class="pk-num c1"><i class="ph-fill ph-play-circle"></i><span><b><?= (int)$m['watch_limit'] ?> video</b><small>Per hari</small></span></div>
          <div class="pk-num c2"><i class="ph-fill ph-trend-up"></i><span><b><?= $h ? '±' . format_rp(round($h['daily_income'] / 1000) * 1000) : '-' ?></b><small>Estimasi / hari</small></span></div>
          <div class="pk-num c3"><i class="ph-fill ph-arrow-circle-down"></i><span><b><?= format_rp((float)$m['min_wd']) ?></b><small>Min tarik</small></span></div>
          <div class="pk-num c4"><i class="ph-fill ph-arrow-circle-up"></i><span><b><?= (float)$m['max_wd'] > 0 ? format_rp((float)$m['max_wd']) : 'Tanpa batas' ?></b><small>Maks tarik</small></span></div>
        </div>
        <?php if (!empty($m['allow_edit_bank']) || $is_top || ($h && $h['break_even_days'] > 0)): ?>
        <div class="pk-perks">
          <?php if ($h && $h['break_even_days'] > 0): ?><span><i class="ph-bold ph-lightning"></i> Balik modal ±<?= (int)$h['break_even_days'] ?> hari</span><?php endif; ?>
          <?php if (!empty($m['allow_edit_bank'])): ?><span><i class="ph-bold ph-pencil-simple"></i> Bebas ganti rekening</span><?php endif; ?>
          <?php if ($is_top): ?><span><i class="ph-bold ph-crown"></i> Pencairan prioritas</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
          $tier_no   = (int)$m['sort_order'];
          $tier_rods = $rods_by_tier[$tier_no] ?? [];
          $all_rods  = 0; foreach ($rods_by_tier as $t => $list) if ($t <= $tier_no) $all_rods += count($list);
        ?>
        <?php if ($tier_rods): ?>
        <div class="pk-rod-ttl"><i class="ph-fill ph-fish-simple"></i> Bonus Joran Mancing<?php if ($all_rods > count($tier_rods)): ?><small>+<?= $all_rods - count($tier_rods) ?> joran pangkat bawah</small><?php endif; ?></div>
        <?php foreach ($tier_rods as $rd): $rc = $ROD_COLOR[$rd['rarity']] ?? '#0891b2'; ?>
        <div class="pk-rod" style="--rc: <?= $rc ?>">
          <span class="pk-rod-ic">
            <svg viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M5 28L26 5" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/><circle cx="11.5" cy="21.5" r="3.6" fill="#fde68a" stroke="#fff" stroke-width="1.3"/><path d="M26 5 C29 11 28 17 24 20" stroke="#fff" stroke-width="1.2" fill="none" opacity=".85"/><circle cx="24" cy="21" r="2.2" fill="#fb7185" stroke="#fff" stroke-width="1"/></svg>
          </span>
          <span class="pk-rod-tx">
            <b><?= htmlspecialchars($rd['name']) ?></b>
            <span class="pk-rod-b"><em><i class="ph-bold ph-arrows-clockwise"></i> Tarik +<?= (int)$rd['reel'] ?>%</em><em><i class="ph-bold ph-timer"></i> Umpan +<?= (int)$rd['bite'] ?>%</em></span>
          </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <button type="button" class="pk-cta <?= $is_cur ? 'is-cur' : (!$can_afford ? 'low' : '') ?>"
          onclick="<?= !$can_afford && !$is_cur ? "location.href='/deposit'" : 'openConfirm(' . (int)$m['id'] . ", '" . htmlspecialchars($m['name'], ENT_QUOTES) . "', " . $active_price . ', ' . (int)$m['duration_days'] . ')' ?>">
          <?php if ($is_cur): ?><i class="ph-bold ph-arrows-clockwise"></i> Perpanjang Pangkat
          <?php elseif ($can_afford): ?><i class="ph-fill ph-rocket-launch"></i> Naik ke <?= htmlspecialchars($m['name']) ?>
          <?php else: ?><i class="ph-bold ph-plus-circle"></i> Isi Saldo · kurang <?= format_rp($active_price - (float)$user['balance_dep']) ?><?php endif; ?>
        </button>
        <?php if ($h): ?><div class="pk-note">Estimasi dari rata-rata <?= format_rp($avg_video_reward) ?>/video</div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="pk-trust">
    <span><i class="ph-fill ph-shield-check"></i>Garansi 12 jam</span>
    <span><i class="ph-fill ph-lightning"></i>Aktif instan</span>
    <span><i class="ph-fill ph-clock"></i>WD 1×24 jam</span>
  </div>

  <details class="pk-guide" open>
    <summary><i class="ph-fill ph-compass"></i> Cara naik pangkat <i class="ph-bold ph-caret-down chev"></i></summary>
    <ol class="pk-steps">
      <li><span>1</span><div><b>Isi Saldo Beli</b><small>Tekan <i class="ph-bold ph-plus"></i> di atas, bayar via QRIS.</small></div></li>
      <li><span>2</span><div><b>Pilih pangkat</b><small>Geser pilihan di atas, cek harga &amp; bonusnya.</small></div></li>
      <li><span>3</span><div><b>Tekan tombol &amp; konfirmasi</b><small>Saldo Beli terpotong, pangkat langsung aktif.</small></div></li>
      <li><span>4</span><div><b>Nikmati bonusnya</b><small>Kuota video naik, joran baru terbuka di menu <b>Joran</b> saat mancing.</small></div></li>
    </ol>
  </details>

  <details class="pk-guide">
    <summary><i class="ph-fill ph-info"></i> Arti angka di kartu <i class="ph-bold ph-caret-down chev"></i></summary>
    <dl class="pk-glos">
      <div><dt><i class="ph-fill ph-play-circle" style="color:#0891b2"></i> Video / hari</dt><dd>Jumlah video berhadiah yang bisa ditonton tiap hari.</dd></div>
      <div><dt><i class="ph-fill ph-trend-up" style="color:#059669"></i> Estimasi / hari</dt><dd>Perkiraan cuan: kuota video × rata-rata hadiah video. Bukan jaminan.</dd></div>
      <div><dt><i class="ph-fill ph-arrow-circle-down" style="color:#7c3aed"></i> Min &amp; maks tarik</dt><dd>Batas nominal sekali penarikan saldo.</dd></div>
      <div><dt><i class="ph-bold ph-lightning" style="color:#047857"></i> Balik modal</dt><dd>Perkiraan hari sampai estimasi cuan menutup harga pangkat.</dd></div>
      <div><dt><i class="ph-bold ph-arrows-clockwise" style="color:#e11d48"></i> Tarik +%</dt><dd>Gulungan joran lebih cepat saat menarik ikan.</dd></div>
      <div><dt><i class="ph-bold ph-timer" style="color:#d97706"></i> Umpan +%</dt><dd>Ikan lebih cepat menyambar umpan.</dd></div>
      <div><dt><i class="ph-fill ph-shield-check" style="color:#059669"></i> Garansi 12 jam</dt><dd>Berubah pikiran? Ajukan refund lewat ikon <i class="ph-bold ph-arrow-u-up-left"></i> di kartu pangkatmu (dipotong biaya admin).</dd></div>
      <div><dt><i class="ph-bold ph-arrows-clockwise" style="color:#0891b2"></i> Perpanjang</dt><dd>Beli lagi pangkat yang sama untuk memulai masa aktif baru.</dd></div>
    </dl>
  </details>
</div>

<!-- MODAL KONFIRMASI -->
<div id="upgrade-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-mc-hdr"><i class="ph-fill ph-rocket-launch"></i> Konfirmasi Pangkat</div>
    <div class="cg-mc-box">
      <div class="cg-mc-val" id="modal-name">--</div>
      <div class="cg-line" id="price-row">Harga <span id="modal-price">--</span></div>
      <div class="cg-line" id="discount-row" style="display:none">Diskon (<span id="modal-pct">--</span>) <span>-<span id="modal-discount">--</span></span></div>
      <div class="cg-line" id="final-price-row" style="display:none">Total bayar <span id="modal-final-price">--</span></div>
      <div class="cg-line">Masa aktif <b><span id="modal-days">--</span> hari</b></div>
    </div>
    <button type="button" id="toggle-voucher-btn" class="cg-voucher-btn" onclick="toggleVoucher()"><i class="ph-bold ph-tag"></i> Punya voucher?</button>
    <div id="voucher-box" style="display:none">
      <input type="text" id="voucher-input" placeholder="KODE VOUCHER">
      <button type="button" class="cg-voucher-apply" onclick="applyVoucher()">Pakai</button>
    </div>
    <div id="voucher-msg"></div>
    <div id="modal-warn"></div>
    <div class="cg-btn-row">
      <button type="button" class="cg-btn cg-btn--cancel" onclick="closeConfirm()">Batal</button>
      <button type="button" id="modal-confirm-btn" class="cg-btn cg-btn--confirm" onclick="submitUpgrade()">YA, AKTIFKAN!</button>
    </div>
  </div>
</div>

<!-- MODAL REFUND -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-mc-hdr"><i class="ph-bold ph-arrow-u-up-left"></i> Ajukan Refund?</div>
    <div class="cg-sub">Saldo kembali ke <b>Saldo Beli</b> setelah dipotong biaya admin. Pangkat kembali ke <b><?= htmlspecialchars((string)($free_rank['name'] ?? 'pangkat awal')) ?></b>.</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_level">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="document.getElementById('refund-modal').style.display='none'">Batal</button>
        <button type="submit" class="cg-btn cg-btn--danger">Ajukan Refund</button>
      </div>
    </form>
  </div>
</div>

<script>
function pickTier(i) {
  document.querySelectorAll('.pk-tier').forEach((b) => b.classList.toggle('on', +b.dataset.tier === i));
  document.querySelectorAll('.pk-card').forEach((c) => c.classList.toggle('on', +c.dataset.card === i));
}
</script>

<script>
const userBal = <?= (float)$user['balance_dep'] ?>;
let currentPrice = 0;

function checkAffordability(p) {
  const btn = document.getElementById('modal-confirm-btn');
  const warn = document.getElementById('modal-warn');
  if (userBal < p) {
    btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
    btn.innerText = 'Saldo Kurang';
    warn.style.display = 'block';
    warn.innerHTML = 'Saldo kurang <strong>Rp ' + (p - userBal).toLocaleString('id-ID') + '</strong>. Silakan deposit dulu.';
  } else {
    btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    btn.innerText = 'YA, AKTIFKAN!';
    warn.style.display = 'none';
  }
}
function openConfirm(id, name, price, days) {
  currentPrice = price;
  document.getElementById('chosen-id').value = id;
  document.getElementById('modal-name').textContent = name;
  document.getElementById('modal-price').textContent = 'Rp ' + price.toLocaleString('id-ID');
  document.getElementById('modal-days').textContent = days;
  document.getElementById('applied-voucher-code').value = '';
  document.getElementById('discount-row').style.display = 'none';
  document.getElementById('final-price-row').style.display = 'none';
  const vi = document.getElementById('voucher-input'); if(vi) vi.value = '';
  const vm = document.getElementById('voucher-msg'); if(vm) vm.style.display = 'none';
  const vb = document.getElementById('voucher-box'); if(vb) vb.style.display = 'none';
  const tb = document.getElementById('toggle-voucher-btn'); if(tb){ tb.style.display='flex'; tb.innerHTML='<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?'; }
  checkAffordability(price);
  document.getElementById('upgrade-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeConfirm() {
  document.getElementById('upgrade-modal').style.display = 'none';
  document.body.style.overflow = '';
}
function toggleVoucher() {
  const vb = document.getElementById('voucher-box');
  const tb = document.getElementById('toggle-voucher-btn');
  if(vb.style.display === 'none') { vb.style.display='flex'; tb.innerHTML='<i class="ph-bold ph-x"></i> Tutup Voucher'; }
  else { vb.style.display='none'; tb.innerHTML='<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?'; }
}
function applyVoucher() {
  const code = document.getElementById('voucher-input').value.toUpperCase().trim();
  const mid = document.getElementById('chosen-id').value;
  const msg = document.getElementById('voucher-msg');
  if(!code){ msg.style.color='#ef4444'; msg.innerText='Masukkan kode voucher.'; msg.style.display='block'; return; }
  msg.style.color='#94a3b8'; msg.innerText='Mengecek...'; msg.style.display='block';
  fetch('', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=check_voucher&code='+encodeURIComponent(code)+'&membership_id='+encodeURIComponent(mid)+'&_csrf='+encodeURIComponent(document.querySelector('input[name="_csrf"]')?.value||'')
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.error){ msg.style.color='#ef4444'; msg.innerText=res.error; msg.style.display='block';
      document.getElementById('applied-voucher-code').value='';
      document.getElementById('discount-row').style.display='none';
      document.getElementById('final-price-row').style.display='none';
      checkAffordability(currentPrice);
    } else {
      msg.style.color='#10b981'; msg.innerText='Diskon '+res.discount_text+' aktif!'; msg.style.display='block';
      document.getElementById('applied-voucher-code').value=code;
      document.getElementById('modal-discount').textContent=res.discount_amount_formatted;
      document.getElementById('modal-pct').textContent=res.discount_text;
      document.getElementById('modal-final-price').textContent=res.final_price_formatted;
      document.getElementById('discount-row').style.display='flex';
      document.getElementById('final-price-row').style.display='flex';
      document.getElementById('voucher-box').style.display='none';
      document.getElementById('toggle-voucher-btn').style.display='none';
      checkAffordability(res.final_price);
    }
  })
  .catch(()=>{ msg.style.color='#ef4444'; msg.innerText='Gagal cek voucher.'; msg.style.display='block'; });
}
function submitUpgrade() {
  const btn = document.getElementById('modal-confirm-btn');
  btn.disabled = true; btn.textContent = 'Memproses...';
  document.getElementById('upgrade-form').submit();
}
document.getElementById('upgrade-modal').addEventListener('click', function(e){ if(e.target===this) closeConfirm(); });
document.getElementById('refund-modal').addEventListener('click', function(e){ if(e.target===this) e.target.style.display='none'; });
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
