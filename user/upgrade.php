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

// Auto-rename ranks to Amber Tier theme
$rankMap = [
  0 => ['name' => 'Golden Amber',   'icon' => 'ph-sparkle'],
  1 => ['name' => 'Royal Amber',    'icon' => 'ph-crown'],
  2 => ['name' => 'Imperial Amber', 'icon' => 'ph-shield-star'],
];
$pdo->query("UPDATE memberships SET name='Raw Amber', icon='ph-drop' WHERE id=1 AND name!='Raw Amber'");
$paid_ids = array_values(array_filter($memberships, fn($m) => (float)$m['price'] > 0));
foreach ($paid_ids as $idx => $m) {
  if (isset($rankMap[$idx])) {
    $pdo->prepare("UPDATE memberships SET name=?, icon=? WHERE id=?")->execute([
      $rankMap[$idx]['name'], $rankMap[$idx]['icon'], $m['id']
    ]);
  }
}
// Reload after rename
$memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();

$pageTitle  = 'Keanggotaan VIP — MancingCuan';
$activePage = 'upgrade';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════════════════
   UPGRADE PAGE — AMBER HONEY PALETTE + DARK FOREST TREE DECO
   ══════════════════════════════════════════════════════════ */
body {
  background-color: #fef8ee !important;
  background-image: 
    radial-gradient(#fde68a 0.85px, transparent 0.85px),
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='49' viewBox='0 0 28 49'%3E%3Cg fill-rule='evenodd'%3E%3Cg fill='%23f59e0b' fill-opacity='0.035'%3E%3Cpath d='M13.99 9.25l13 7.5v15l-13 7.5L1 31.75v-15l12.99-7.5zM3 17.9v12.7l11 6.35 11-6.35V17.9l-11-6.35L3 17.9zM0 49l14-8.08L28 49H0zm0-49h28L14 8.08 0 0z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") !important;
  background-size: 24px 24px, 28px 49px !important;
  color: #78350f;
  font-family: 'Nunito', -apple-system, sans-serif;
  margin: 0;
  padding: 0;
}

.up-page {
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 70px;
}

/* HERO CANOPY BANNER WITH DARK FOREST PINE TREELINE */
.up-hero {
  background: linear-gradient(160deg, #b45309 0%, #d97706 40%, #1a3c2b 100%);
  border-bottom: 3.5px solid #78350f;
  padding: 14px 14px 0;
  position: relative;
  overflow: hidden;
}
.up-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  position: relative;
  z-index: 3;
}
.up-hero-back {
  width: 36px; height: 36px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #78350f;
  font-size: 18px; font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s;
}
.up-hero-back:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.up-hero-badge {
  display: inline-flex; align-items: center; gap: 5px;
  background: #fef3c7; color: #78350f;
  border: 2px solid #78350f;
  border-radius: 20px;
  padding: 3.5px 12px;
  font-size: 11px; font-weight: 900;
  box-shadow: 0 2.5px 0 #78350f;
}

.up-mascot-row {
  display: flex;
  align-items: center;
  gap: 12px;
  position: relative;
  z-index: 3;
  margin-bottom: 12px;
}
.up-mascot-img {
  width: 60px; height: 60px;
  object-fit: contain;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));
  animation: upBeeFloat 3s ease-in-out infinite;
  flex-shrink: 0;
}
@keyframes upBeeFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}
.up-mascot-bubble {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 9px 12px;
  box-shadow: 0 3.5px 0 #78350f;
  position: relative;
  flex: 1;
}
.up-mascot-bubble::before {
  content: '';
  position: absolute; left: -8px; top: 50%;
  transform: translateY(-50%);
  border-width: 5px 8px 5px 0;
  border-style: solid;
  border-color: transparent #78350f transparent transparent;
}
.up-mascot-bubble-title {
  font-size: 12.5px; font-weight: 900; color: #78350f;
  margin-bottom: 2px; display: flex; align-items: center; gap: 5px;
}
.up-mascot-bubble-sub {
  font-size: 11px; font-weight: 700; color: #92400e; line-height: 1.35;
}

/* DARK FOREST TREES SILHOUETTE IN HERO */
.forest-trees-strip {
  width: 100%;
  height: 40px;
  position: relative;
  z-index: 2;
  margin-top: -6px;
  pointer-events: none;
}
.forest-trees-strip svg {
  width: 100%;
  height: 100%;
  display: block;
}

/* TRUST STRIP */
.up-trust-strip {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  padding: 10px 14px;
}
.up-trust-pill {
  background: #ffffff;
  border: 1.5px solid #78350f;
  border-radius: 12px;
  padding: 6px 4px;
  text-align: center;
  font-size: 9.5px; font-weight: 900; color: #78350f;
  box-shadow: 0 2px 0 #78350f;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.up-trust-pill i { font-size: 13.5px; color: #d97706; }

/* BODY */
.up-body {
  padding: 0 14px 20px;
}

/* SECTION HEADER */
.sh-honey {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px; margin-top: 10px;
}
.sh-honey__title {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 900; color: #78350f;
  text-transform: uppercase; letter-spacing: 0.5px;
}

/* LIVE TICKER */
.live-ticker {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 7px 12px;
  margin-bottom: 12px;
  box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; gap: 8px;
  font-size: 10.5px; font-weight: 800; color: #92400e;
}
.live-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #16a34a;
  box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.3);
  animation: livePulse 1.5s infinite;
  flex-shrink: 0;
}
@keyframes livePulse {
  0% { transform: scale(0.9); opacity: 0.8; }
  50% { transform: scale(1.2); opacity: 1; }
  100% { transform: scale(0.9); opacity: 0.8; }
}

/* SALDO TILE */
.saldo-card {
  background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 12px 14px;
  box-shadow: 0 4px 0 #78350f;
  margin-bottom: 10px;
  position: relative;
  overflow: hidden;
}
.saldo-card-bg {
  position: absolute; right: -8px; bottom: -8px;
  width: 65px; height: 65px; opacity: 0.12;
  pointer-events: none;
}
.saldo-lbl {
  font-size: 10.5px; font-weight: 800; color: #92400e;
  display: flex; align-items: center; gap: 5px; margin-bottom: 3px;
}
.saldo-val {
  font-size: 24px; font-weight: 900; color: #78350f;
  letter-spacing: -0.4px; margin-bottom: 4px;
}
.saldo-sub {
  font-size: 10px; font-weight: 700; color: #b45309;
}

/* QA GRID */
.qa-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  margin-bottom: 14px;
}
.qa-btn {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  border-radius: 12px; padding: 10px 8px;
  font-size: 11.5px; font-weight: 900; text-decoration: none;
  border: 2px solid #78350f; transition: transform 0.1s;
}
.qa-btn:active { transform: translateY(2px); }
.qa-btn--dep {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #ffffff; box-shadow: 0 3px 0 #064e3b;
}
.qa-btn--checkin {
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  color: #78350f; box-shadow: 0 3px 0 #b45309;
}

/* ACTIVE MEMBERSHIP STATUS */
.active-rank-card {
  background: #ecfdf5;
  border: 2px solid #059669;
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 3.5px 0 #064e3b;
  margin-bottom: 14px;
}
.active-rank-hdr {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 4px;
}
.active-rank-badge {
  background: #059669; color: #fff;
  border-radius: 10px; padding: 2px 7px;
  font-size: 9.5px; font-weight: 900;
}
.active-rank-name {
  font-size: 16px; font-weight: 900; color: #064e3b;
}
.active-rank-detail {
  font-size: 11px; font-weight: 800; color: #047857; margin-bottom: 8px;
}

/* ══════════════════════════════════════════════
   COMPACT & SOLID PRICING CARDS (AMBER + FOREST)
   ══════════════════════════════════════════════ */
.lvl-card {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 20px;
  padding: 14px 14px 12px;
  margin-bottom: 15px;
  position: relative;
  box-shadow: 0 5px 0 #78350f;
  transition: transform 0.12s;
  cursor: pointer;
  overflow: hidden;
}
.lvl-card:active {
  transform: translateY(3px);
  box-shadow: 0 2px 0 #78350f;
}

/* Corner Honeycomb & Forest Watermark */
.lvl-card-deco-bg {
  position: absolute; right: -5px; top: -5px;
  width: 95px; height: 95px;
  opacity: 0.08;
  pointer-events: none;
}

/* Card Skins */
.lvl-card--starter {
  background: linear-gradient(180deg, #ffffff 0%, #fefcf8 100%);
}
.lvl-card--popular {
  background: linear-gradient(180deg, #ffffff 0%, #fffdf5 100%);
  border-color: #78350f;
  box-shadow: 0 5.5px 0 #78350f;
}
.lvl-card--sultan {
  background: linear-gradient(145deg, #fffbeb 0%, #fef3c7 50%, #fde68a 100%);
  border-color: #78350f;
  box-shadow: 0 6px 0 #78350f;
}

/* RIBBONS */
.lvl-ribbon {
  position: absolute; top: 0; right: 14px;
  font-size: 9px; font-weight: 900;
  padding: 3px 9px; border-radius: 0 0 10px 10px;
  border: 1.5px solid #78350f; border-top: none;
  z-index: 2; letter-spacing: 0.3px;
}
.lvl-ribbon--starter {
  background: #dcfce7; color: #065f46;
}
.lvl-ribbon--popular {
  background: linear-gradient(135deg, #f97316, #ea580c);
  color: #fff;
}
.lvl-ribbon--sultan {
  background: linear-gradient(135deg, #fbbf24, #d97706);
  color: #78350f;
}

/* CARD HEADER */
.lvl-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 9px; margin-top: 3px;
}
.lvl-icon-box {
  width: 44px; height: 44px;
  border-radius: 13px;
  border: 2px solid #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  box-shadow: 0 2.5px 0 #78350f;
  flex-shrink: 0; background: #fff;
}
.lvl-head-meta {
  padding-left: 10px; flex: 1;
}
.lvl-title {
  font-size: 16.5px; font-weight: 900; color: #78350f; line-height: 1.15;
}
.lvl-dur-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 800; color: #92400e;
  background: #fef3c7; border: 1.5px solid #78350f;
  padding: 1.5px 7px; border-radius: 8px; margin-top: 2px;
}

/* PRICING */
.lvl-price-block { text-align: right; flex-shrink: 0; }
.lvl-price-old {
  font-size: 10.5px; font-weight: 800; color: #a8a29e;
  text-decoration: line-through;
}
.lvl-price-val {
  font-size: 18px; font-weight: 900; color: #059669;
  letter-spacing: -0.4px;
}

/* POTENSI CUAN STRIP */
.lvl-potensi-cuan {
  background: #f0fdf4;
  border: 1.5px dashed #16a34a;
  border-radius: 10px;
  padding: 6px 9px;
  display: flex; align-items: center; justify-content: space-between;
  font-size: 10.5px; font-weight: 800; color: #166534;
  margin-bottom: 9px;
}
.lvl-roi-pill {
  background: #dcfce7; color: #166534;
  border: 1.5px solid #166534;
  border-radius: 6px; padding: 1.5px 6px;
  font-size: 9px; font-weight: 900;
}

/* SPECS MATRIX 2x2 */
.lvl-specs {
  display: grid; grid-template-columns: 1fr 1fr; gap: 5.5px;
  margin-bottom: 11px;
}
.lvl-spec-item {
  background: rgba(255,255,255,0.9);
  border: 1.5px solid #78350f;
  border-radius: 9px;
  padding: 5px 7px;
  font-size: 10.5px; font-weight: 800; color: #78350f;
  display: flex; align-items: center; gap: 5px;
  box-shadow: 0 1.5px 0 rgba(120,53,15,0.15);
}
.lvl-spec-item--full { grid-column: 1 / -1; }
.lvl-spec-item i { font-size: 13px; flex-shrink: 0; }

/* ACTION BUTTON */
.lvl-btn-cta {
  display: block; width: 100%;
  padding: 10.5px; border-radius: 12px;
  font-size: 12.5px; font-weight: 900;
  text-align: center; cursor: pointer;
  border: 2px solid #78350f;
  transition: transform 0.1s;
  box-shadow: 0 3.5px 0 #78350f;
}
.lvl-btn-cta:active {
  transform: translateY(2px);
  box-shadow: 0 1.5px 0 #78350f !important;
}
.lvl-btn-cta--starter {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff;
}
.lvl-btn-cta--popular {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff;
}
.lvl-btn-cta--sultan {
  background: linear-gradient(135deg, #d97706, #b45309);
  color: #fff;
}
.lvl-btn-cta--disabled {
  background: #e2e8f0 !important;
  color: #94a3b8 !important;
  border-color: #cbd5e1 !important;
  box-shadow: 0 2.5px 0 #94a3b8 !important;
  cursor: not-allowed;
}

/* INFO STEP CARD */
.info-card {
  background: #fff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 14px;
  box-shadow: 0 4px 0 #78350f;
  margin-top: 10px; margin-bottom: 22px;
}
.step-item {
  display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px;
}
.step-num {
  width: 22px; height: 22px; border-radius: 6px;
  background: #fde68a; border: 1.5px solid #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 900; color: #78350f;
  box-shadow: 0 1.5px 0 #78350f; flex-shrink: 0;
}
.step-text {
  font-size: 10.5px; font-weight: 800; color: #78350f; line-height: 1.35; padding-top: 2px;
}

/* MODAL */
.cg-modal {
  display: none; position: fixed; inset: 0; z-index: 9999;
  background: rgba(0,0,0,0.65); align-items: center; justify-content: center;
  backdrop-filter: blur(3px); padding: 18px;
}
.cg-modal-card {
  background: #ffffff; border-radius: 22px;
  border: 3px solid #78350f;
  width: 100%; max-width: 370px;
  box-shadow: 0 7px 0 #78350f;
  padding: 18px;
  animation: upPopIn 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes upPopIn {
  0% { transform: scale(0.9); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.cg-mc-hdr {
  font-size: 16px; font-weight: 900; color: #78350f;
  margin-bottom: 3px; display: flex; align-items: center; gap: 7px;
}
.cg-mc-sub {
  font-size: 11px; font-weight: 700; color: #92400e; margin-bottom: 12px;
}
.cg-mc-box {
  background: #fef8ee; border: 2px solid #78350f;
  border-radius: 14px; padding: 12px; margin-bottom: 12px;
}
.cg-mc-lbl {
  font-size: 9.5px; font-weight: 800; color: #b45309; text-transform: uppercase;
  margin-bottom: 2px;
}
.cg-mc-val {
  font-size: 17px; font-weight: 900; color: #78350f; margin-bottom: 6px;
}
.cg-mc-price {
  font-size: 12px; font-weight: 800; color: #78350f;
}
.cg-mc-discount {
  font-size: 11px; font-weight: 800; color: #059669; display: none; margin-top: 2px;
}
.cg-mc-total {
  font-size: 14px; font-weight: 900; color: #d97706; margin-top: 5px;
  padding-top: 5px; border-top: 1.5px dashed #d97706; display: none;
}
.cg-mc-dur {
  font-size: 10.5px; font-weight: 800; color: #b45309; margin-top: 4px;
}
.cg-btn-row {
  display: grid; grid-template-columns: 1fr 1.3fr; gap: 8px;
}
.cg-btn {
  padding: 10px; border-radius: 11px; font-size: 12px; font-weight: 900;
  text-align: center; cursor: pointer; border: 2px solid #78350f;
}
.cg-btn--cancel {
  background: #f1f5f9; color: #64748b; border-color: #cbd5e1;
}
.cg-btn--confirm {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff; box-shadow: 0 3px 0 #064e3b;
}

@media (max-width: 360px) {
  .lvl-specs { grid-template-columns: 1fr; }
}
</style>

<div class="up-page">

  <!-- HERO CANOPY WITH DARK FOREST TREES -->
  <div class="up-hero">
    <div class="up-hero-top">
      <a href="/dashboard" class="up-hero-back" title="Kembali">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="up-hero-badge">
        <i class="ph-fill ph-crown"></i> Keanggotaan VIP MancingCuan
      </div>
      <div style="width:36px;"></div>
    </div>

    <!-- MASCOT VIP ROW -->
    <div class="up-mascot-row">
      <div style="width:58px;height:58px;border-radius:18px;background:linear-gradient(135deg,#f59e0b,#b45309);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;box-shadow:0 8px 20px rgba(245,158,11,0.4);border:2px solid #fff;flex-shrink:0;">
        <i class="ph-fill ph-crown-simple"></i>
      </div>
      <div class="up-mascot-bubble">
        <div class="up-mascot-bubble-title">
          <i class="ph-fill ph-sparkle"></i> Upgrade Level VIP Cuan!
        </div>
        <div class="up-mascot-bubble-sub">
          Pilih level keanggotaanmu, nikmati batas penarikan lebih tinggi &amp; prioritas pencairan 24 jam!
        </div>
      </div>
    </div>

    <!-- DARK FOREST PINE TREELINE SILHOUETTE -->
    <div class="forest-trees-strip">
      <svg viewBox="0 0 1000 70" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
        <!-- Background Layer: Misty Green Trees -->
        <path d="M0,70 L0,38 L20,18 L40,38 L65,12 L90,38 L115,22 L140,38 L170,10 L200,38 L225,18 L250,38 L280,14 L310,38 L335,22 L360,38 L390,10 L420,38 L445,20 L470,38 L500,12 L530,38 L555,22 L580,38 L610,10 L640,38 L665,18 L690,38 L720,14 L750,38 L775,20 L800,38 L830,10 L860,38 L885,22 L910,38 L940,14 L970,38 L1000,18 L1000,70 Z" fill="#0d2e1f" opacity="0.45"/>
        <!-- Foreground Layer: Deep Dark Forest Pine Trees -->
        <path d="M0,70 L0,48 L15,28 L30,48 L50,20 L70,48 L95,14 L120,48 L140,32 L160,48 L190,12 L220,48 L245,26 L270,48 L310,10 L350,48 L375,30 L400,48 L430,16 L460,48 L485,28 L510,48 L545,12 L580,48 L610,26 L640,48 L675,16 L710,48 L735,30 L760,48 L795,10 L830,48 L855,28 L880,48 L915,16 L950,48 L975,32 L1000,48 L1000,70 Z" fill="#071b12"/>
      </svg>
    </div>
  </div>

  <!-- TRUST STRIP -->
  <div class="up-trust-strip">
    <div class="up-trust-pill">
      <i class="ph-fill ph-shield-check"></i>
      <span>Saldo 100% Aman</span>
    </div>
    <div class="up-trust-pill">
      <i class="ph-fill ph-lightning"></i>
      <span>WD Prioritas 24 Jam</span>
    </div>
    <div class="up-trust-pill">
      <i class="ph-fill ph-chart-line-up"></i>
      <span>Balik Modal Kilat</span>
    </div>
  </div>

  <!-- BODY CONTENT -->
  <div class="up-body">

    <!-- FLASH MESSAGES -->
    <?php if ($flash): ?>
      <div style="padding:10px 12px;border-radius:12px;font-size:11.5px;font-weight:800;margin-bottom:12px;border:2px solid;<?= $flashType==='success' ? 'background:#dcfce7;border-color:#16a34a;color:#166534;' : 'background:#fee2e2;border-color:#ef4444;color:#991b1b;' ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- ACTIVE MEMBERSHIP STATUS -->
    <?php if ($active_membership): ?>
      <div class="active-rank-card">
        <div class="active-rank-hdr">
          <span class="active-rank-badge"><i class="ph-fill ph-check-circle"></i> Kasta Aktif</span>
          <?php if ($can_refund): ?>
            <button type="button" onclick="document.getElementById('refund-modal').style.display='flex'" style="background:none;border:none;color:#dc2626;font-size:10.5px;font-weight:900;cursor:pointer;display:flex;align-items:center;gap:3px;">
              <i class="ph-bold ph-arrow-u-up-left"></i> Ajukan Refund
            </button>
          <?php endif; ?>
        </div>
        <div class="active-rank-name"><?= htmlspecialchars($active_membership['name']) ?></div>
        <div class="active-rank-detail">
          Berlaku s/d: <strong><?= date('d M Y, H:i', strtotime($user['membership_expires_at'])) ?></strong> (<?= (int)$active_membership['watch_limit'] ?> Video/hari)
        </div>
      </div>
    <?php endif; ?>

    <!-- LIVE TICKER -->
    <div class="live-ticker">
      <span class="live-dot"></span>
      <span style="flex:1;"><strong>Live Panen:</strong> Member <code>@cuan***</code> baru bergabung ke <strong>Imperial Amber</strong>!</span>
    </div>

    <!-- SALDO BELI TILE -->
    <div class="sh-honey"><div class="sh-honey__title"><i class="ph-fill ph-wallet"></i> Saldo Deposit Aktif</div></div>
    <div class="saldo-card">
      <div class="saldo-lbl"><i class="ph-bold ph-coins"></i> Saldo Beli Tersedia</div>
      <div class="saldo-val"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div class="saldo-sub">Saldo ini digunakan untuk aktivasi paket keanggotaan VIP.</div>
    </div>

    <!-- QUICK BUTTONS -->
    <div class="qa-grid">
      <a href="/deposit" class="qa-btn qa-btn--dep">
        <i class="ph-bold ph-plus-circle" style="font-size:15px;"></i> + Isi Saldo Beli
      </a>
      <a href="/checkin" class="qa-btn qa-btn--checkin">
        <i class="ph-bold ph-calendar-check" style="font-size:15px;"></i> Hadiah Check-in
      </a>
    </div>

    <!-- PILIH PAKET MEMBERSHIP VIP -->
    <div class="sh-honey"><div class="sh-honey__title"><i class="ph-fill ph-crown"></i> Pilih Paket VIP</div></div>

    <div style="background:#fffbeb;border:1.5px dashed #f59e0b;border-radius:14px;padding:9px 12px;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
      <i class="ph-fill ph-calculator" style="color:#d97706;font-size:18px;flex-shrink:0;"></i>
      <div style="font-size:11px;font-weight:800;color:#92400e;line-height:1.4;">
        Estimasi panen dihitung otomatis berdasarkan <b>rata-rata reward video aktif (<?= format_rp($avg_video_reward) ?> / video)</b> dikalikan kuota harian masing-masing kasta.
      </div>
    </div>

    <form method="POST" id="upgrade-form">
      <?= csrf_field() ?>
      <input type="hidden" name="membership_id" id="chosen-id" value="">
      <input type="hidden" name="voucher_code" id="applied-voucher-code" value="">

      <?php
      $paid    = array_values(array_filter($memberships, fn($m) => (float)$m['price'] > 0));
      usort($paid, fn($a,$b) => (float)$a['price'] <=> (float)$b['price']);
      $kanopi   = $paid[0] ?? null;
      $rimba    = $paid[1] ?? null;
      $ratu     = $paid[2] ?? null;

      $h_kanopi = $kanopi ? get_harvest_calc($kanopi, $avg_video_reward) : null;
      $h_rimba  = $rimba  ? get_harvest_calc($rimba, $avg_video_reward)  : null;
      $h_ratu   = $ratu   ? get_harvest_calc($ratu, $avg_video_reward)   : null;
      ?>

      <!-- TIER 1: GOLDEN AMBER -->
      <?php if ($kanopi):
          $m = $kanopi;
          $active_price = get_active_price($m, $user);
          $can_afford = (float)$user['balance_dep'] >= $active_price;
      ?>
      <div class="lvl-card lvl-card--starter" onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= $active_price ?>, <?= $m['duration_days'] ?>)">
        <!-- Honeycomb & Forest Watermark -->
        <svg class="lvl-card-deco-bg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 0L93.3 25V75L50 100L6.7 75V25L50 0Z" stroke="#78350f" stroke-width="2.5"/>
          <path d="M50 16L80.3 33.5V66.5L50 84L19.7 66.5V33.5L50 16Z" stroke="#78350f" stroke-width="1.5"/>
          <path d="M70,80 L75,60 L80,80 L85,55 L90,80 L95,65 L100,80" stroke="#166534" stroke-width="2"/>
        </svg>

        <div class="lvl-ribbon lvl-ribbon--starter">✨ GOLDEN AMBER</div>
        
        <div class="lvl-head">
          <div class="lvl-icon-box" style="background:#fef3c7;color:#d97706;"><i class="ph-fill ph-sparkle"></i></div>
          <div class="lvl-head-meta">
            <div class="lvl-title"><?= htmlspecialchars($m['name']) ?></div>
            <span class="lvl-dur-badge"><i class="ph-bold ph-hourglass"></i> Aktif <?= $m['duration_days'] ?> Hari</span>
          </div>
          <div class="lvl-price-block">
            <div class="lvl-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <div class="lvl-price-val"><?= format_rp($active_price) ?></div>
          </div>
        </div>

        <div class="lvl-potensi-cuan">
          <span>🎯 Estimasi Panen: <strong><?= $h_kanopi['harvest_text'] ?></strong></span>
          <span class="lvl-roi-pill">⚡ <?= $h_kanopi['daily_text'] ?></span>
        </div>

        <div class="lvl-specs">
          <div class="lvl-spec-item"><i class="ph-bold ph-video-camera" style="color:#059669;"></i> <strong><?= $m['watch_limit'] ?> Video</strong> / hari</div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-down" style="color:#10b981;"></i> Min WD: <strong><?= format_rp((float)$m['min_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-up" style="color:#d97706;"></i> Max WD: <strong><?= format_rp((float)$m['max_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-clock" style="color:#64748b;"></i> Proses 1-24 Jam</div>
          <?php if (!empty($m['allow_edit_bank'])): ?>
          <div class="lvl-spec-item"><i class="ph-bold ph-pencil-simple" style="color:#2563eb;"></i> Bebas Ganti Rekening</div>
          <?php endif; ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="background:#fef3c7;border-color:#d97706;color:#92400e;">
            <i class="ph-fill ph-storefront" style="color:#d97706;"></i> <strong>Bonus: Lapak Golden Amber (Jual Rp 35/ml · Kuota 600 ml/hari)</strong>
          </div>
          <?php if ($m['description']): ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#92400e;"><i class="ph-bold ph-tree" style="color:#166534;"></i> <?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>

        <button type="button" class="lvl-btn-cta lvl-btn-cta--starter <?= !$can_afford ? 'lvl-btn-cta--disabled' : '' ?>">
          <?= $can_afford ? 'GABUNG LEVEL GOLDEN AMBER' : 'Saldo Kurang — Topup Dulu' ?>
        </button>
      </div>
      <?php endif; ?>

      <!-- TIER 2: ROYAL AMBER -->
      <?php if ($rimba):
          $m = $rimba;
          $active_price = get_active_price($m, $user);
          $can_afford = (float)$user['balance_dep'] >= $active_price;
      ?>
      <div class="lvl-card lvl-card--popular" onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= $active_price ?>, <?= $m['duration_days'] ?>)">
        <!-- Honeycomb & Forest Watermark -->
        <svg class="lvl-card-deco-bg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 0L93.3 25V75L50 100L6.7 75V25L50 0Z" stroke="#f59e0b" stroke-width="2.5"/>
          <path d="M50 16L80.3 33.5V66.5L50 84L19.7 66.5V33.5L50 16Z" stroke="#f59e0b" stroke-width="1.5"/>
          <path d="M70,80 L75,55 L80,80 L85,50 L90,80 L95,60 L100,80" stroke="#d97706" stroke-width="2"/>
        </svg>

        <div class="lvl-ribbon lvl-ribbon--popular">👑 ROYAL AMBER (FAVORIT)</div>

        <div class="lvl-head">
          <div class="lvl-icon-box" style="background:#fef3c7;color:#b45309;"><i class="ph-fill ph-crown"></i></div>
          <div class="lvl-head-meta">
            <div class="lvl-title"><?= htmlspecialchars($m['name']) ?></div>
            <span class="lvl-dur-badge" style="background:#fef3c7;"><i class="ph-bold ph-hourglass"></i> Aktif <?= $m['duration_days'] ?> Hari</span>
          </div>
          <div class="lvl-price-block">
            <div class="lvl-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <div class="lvl-price-val" style="color:#d97706;"><?= format_rp($active_price) ?></div>
          </div>
        </div>

        <div class="lvl-potensi-cuan" style="background:#fffbeb;border-color:#f59e0b;color:#92400e;">
          <span>🎯 Estimasi Panen: <strong><?= $h_rimba['harvest_text'] ?></strong></span>
          <span class="lvl-roi-pill" style="background:#fef3c7;color:#92400e;border-color:#b45309;">🔥 <?= $h_rimba['daily_text'] ?></span>
        </div>

        <div class="lvl-specs">
          <div class="lvl-spec-item"><i class="ph-bold ph-video-camera" style="color:#d97706;"></i> <strong><?= $m['watch_limit'] ?> Video</strong> / hari</div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-down" style="color:#10b981;"></i> Min WD: <strong><?= format_rp((float)$m['min_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-up" style="color:#d97706;"></i> Max WD: <strong><?= format_rp((float)$m['max_wd']) ?></strong></div>
          <?php if (!empty($m['allow_edit_bank'])): ?>
          <div class="lvl-spec-item"><i class="ph-bold ph-pencil-simple" style="color:#2563eb;"></i> Bebas Ganti Rekening</div>
          <?php endif; ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="background:#fef3c7;border-color:#b45309;color:#78350f;">
            <i class="ph-fill ph-storefront" style="color:#b45309;"></i> <strong>Bonus: Lapak Royal Amber (Jual Rp 55/ml · Kuota 2.000 ml/hari)</strong>
          </div>
          <?php if ($m['description']): ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#78350f;"><i class="ph-bold ph-tree" style="color:#166534;"></i> <?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>

        <button type="button" class="lvl-btn-cta lvl-btn-cta--popular <?= !$can_afford ? 'lvl-btn-cta--disabled' : '' ?>">
          <?= $can_afford ? 'GABUNG LEVEL ROYAL AMBER' : 'Saldo Kurang — Topup Dulu' ?>
        </button>
      </div>
      <?php endif; ?>

      <!-- TIER 3: IMPERIAL AMBER -->
      <?php if ($ratu):
          $m = $ratu;
          $active_price = get_active_price($m, $user);
          $can_afford = (float)$user['balance_dep'] >= $active_price;
      ?>
      <div class="lvl-card lvl-card--sultan" onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= $active_price ?>, <?= $m['duration_days'] ?>)">
        <!-- Honeycomb & Forest Watermark -->
        <svg class="lvl-card-deco-bg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 0L93.3 25V75L50 100L6.7 75V25L50 0Z" stroke="#78350f" stroke-width="2.5"/>
          <path d="M50 16L80.3 33.5V66.5L50 84L19.7 66.5V33.5L50 16Z" stroke="#78350f" stroke-width="1.5"/>
          <path d="M70,80 L75,50 L80,80 L85,45 L90,80 L95,55 L100,80" stroke="#78350f" stroke-width="2.5"/>
        </svg>

        <div class="lvl-ribbon lvl-ribbon--sultan">🛡️ IMPERIAL AMBER · 60 HARI (2 BULAN)</div>

        <div class="lvl-head">
          <div class="lvl-icon-box" style="background:#fef08a;color:#78350f;"><i class="ph-fill ph-shield-star"></i></div>
          <div class="lvl-head-meta">
            <div class="lvl-title"><?= htmlspecialchars($m['name']) ?></div>
            <span class="lvl-dur-badge" style="background:#fde68a;font-weight:900;"><i class="ph-bold ph-hourglass"></i> AKTIF 60 HARI (2 BULAN)</span>
          </div>
          <div class="lvl-price-block">
            <div class="lvl-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <div class="lvl-price-val" style="color:#78350f;"><?= format_rp($active_price) ?></div>
          </div>
        </div>

        <div class="lvl-potensi-cuan" style="background:#fff;border-color:#b45309;color:#78350f;">
          <span>🎯 Estimasi Panen: <strong><?= $h_ratu['harvest_text'] ?></strong></span>
          <span class="lvl-roi-pill" style="background:#fde68a;color:#78350f;border-color:#78350f;">👑 <?= $h_ratu['daily_text'] ?></span>
        </div>

        <div class="lvl-specs">
          <div class="lvl-spec-item"><i class="ph-bold ph-video-camera" style="color:#b45309;"></i> <strong><?= $m['watch_limit'] ?> Video</strong> / hari</div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-down" style="color:#10b981;"></i> Min WD: <strong><?= format_rp((float)$m['min_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-rocket-launch" style="color:#ea580c;"></i> Max WD: <strong><?= format_rp((float)$m['max_wd']) ?></strong></div>
          <?php if (!empty($m['allow_edit_bank'])): ?>
          <div class="lvl-spec-item"><i class="ph-bold ph-pencil-simple" style="color:#2563eb;"></i> Bebas Ganti Rekening</div>
          <?php endif; ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="background:#fef08a;border-color:#78350f;color:#78350f;">
            <i class="ph-fill ph-storefront" style="color:#78350f;"></i> <strong>Bonus: Lapak Imperial Amber (Jual Rp 85/ml · Kuota 6.000 ml/hari)</strong>
          </div>
          <div class="lvl-spec-item lvl-spec-item--full" style="background:#fef3c7;border-color:#d97706;color:#78350f;">
            <i class="ph-fill ph-crown" style="color:#d97706;"></i> <strong>VIP Express (Pencairan Otomatis Tanpa Antre)</strong>
          </div>
          <?php if ($m['description']): ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#92400e;"><i class="ph-bold ph-tree" style="color:#166534;"></i> <?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>

        <button type="button" class="lvl-btn-cta lvl-btn-cta--sultan <?= !$can_afford ? 'lvl-btn-cta--disabled' : '' ?>">
          <?= $can_afford ? 'KLAIM MAHKOTA IMPERIAL AMBER' : 'Saldo Kurang — Topup Dulu' ?>
        </button>
      </div>
      <?php endif; ?>

    </form>

    <!-- INFO STEP CARD (COMPACT) -->
    <div class="info-card">
      <div style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
        <i class="ph-bold ph-lightbulb" style="color:#f59e0b;font-size:16px;"></i> 3 Langkah Mudah Panen Nektar
      </div>
      <div class="step-item">
        <div class="step-num">1</div>
        <div class="step-text"><strong>Isi Saldo Beli</strong> instan via QRIS (DANA, GoPay, OVO, ShopeePay, BCA).</div>
      </div>
      <div class="step-item">
        <div class="step-num">2</div>
        <div class="step-text"><strong>Pilih Kasta Rimba</strong> yang sesuai dengan target cuan harianmu.</div>
      </div>
      <div class="step-item">
        <div class="step-num">3</div>
        <div class="step-text"><strong>Konfirmasi &amp; Panen!</strong> Kasta langsung aktif seketika tanpa nunggu lama.</div>
      </div>

      <div style="background:#fef8ee;border:2px dashed #f59e0b;border-radius:12px;padding:8px 10px;margin-top:10px;">
        <div style="font-size:10.5px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:4px;margin-bottom:2px;">
          <i class="ph-fill ph-shield-check" style="color:#16a34a;"></i> Garansi Kepuasan 12 Jam
        </div>
        <div style="font-size:10px;font-weight:700;color:#92400e;line-height:1.35;">
          Perlindungan dana aman. Kamu berhak mengajukan pengembalian saldo beli dalam kurun waktu 12 jam setelah aktivasi jika berubah pikiran.
        </div>
      </div>
    </div>

  </div>
</div>

<!-- CONFIRMATION MODAL -->
<div id="upgrade-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-mc-hdr"><i class="ph-fill ph-crown"></i> Konfirmasi Kasta Rimba</div>
    <div class="cg-mc-sub">Pastikan kasta pilihanmu sudah sesuai sebelum diproses!</div>
    <div class="cg-mc-box">
      <div class="cg-mc-lbl">Kasta Dipilih</div>
      <div class="cg-mc-val" id="modal-name">--</div>
      <div class="cg-mc-price" id="price-row">Biaya: <span id="modal-price">--</span></div>
      <div class="cg-mc-discount" id="discount-row">Diskon: -<span id="modal-discount">--</span> (<span id="modal-pct">--</span>)</div>
      <div class="cg-mc-total" id="final-price-row">Total Potong Saldo: <span id="modal-final-price">--</span></div>
      <div class="cg-mc-dur">Masa Aktif: <strong id="modal-days">--</strong> hari penuh</div>
    </div>
    <div style="margin-bottom:12px;">
      <button type="button" id="toggle-voucher-btn" onclick="toggleVoucher()" style="background:none;border:none;color:#0284c7;font-weight:900;font-size:11.5px;cursor:pointer;padding:0;display:flex;align-items:center;gap:4px;">
        <i class="ph-bold ph-tag"></i> Punya Kode Voucher Diskon?
      </button>
      <div id="voucher-box" style="display:none;margin-top:6px;gap:6px;">
        <input type="text" id="voucher-input" placeholder="KODE VOUCHER" style="flex:1;border:2px solid #78350f;border-radius:9px;padding:7px 10px;font-weight:900;text-transform:uppercase;font-size:11px;outline:none;">
        <button type="button" onclick="applyVoucher()" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:2px solid #78350f;border-radius:9px;padding:7px 12px;font-weight:900;font-size:11px;cursor:pointer;box-shadow:0 2px 0 #78350f;">Gunakan</button>
      </div>
      <div id="voucher-msg" style="font-size:10.5px;font-weight:800;margin-top:5px;display:none;"></div>
    </div>
    <div id="modal-warn" style="display:none;font-size:10.5px;color:#b91c1c;font-weight:800;margin-bottom:12px;background:#fee2e2;border:2px solid #ef4444;border-radius:9px;padding:7px 9px;"></div>
    <div style="font-size:10px;color:#92400e;font-weight:800;margin-bottom:12px;text-align:center;">Saldo Beli kamu otomatis terpotong saat konfirmasi.</div>
    <div class="cg-btn-row">
      <button type="button" class="cg-btn cg-btn--cancel" onclick="closeConfirm()">Batal</button>
      <button type="button" id="modal-confirm-btn" class="cg-btn cg-btn--confirm" onclick="submitUpgrade()">YA, AKTIFKAN!</button>
    </div>
  </div>
</div>

<!-- REFUND MODAL -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card" style="border-color:#b91c1c;box-shadow:0 7px 0 #b91c1c;">
    <div class="cg-mc-hdr" style="color:#b91c1c;"><i class="ph-bold ph-warning"></i> Ajukan Refund?</div>
    <div class="cg-mc-sub">Yakin ingin mengajukan pengembalian kasta aktifmu?</div>
    <div style="background:#fee2e2;border:2px solid #ef4444;border-radius:12px;padding:10px;margin-bottom:12px;font-size:10.5px;font-weight:800;color:#991b1b;line-height:1.4;">
      Saldo akan dikembalikan ke <strong>Saldo Beli</strong> setelah verifikasi sistem dengan potongan biaya admin. Level aktif kamu akan ditutup kembali ke Raw Amber!
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_level">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="document.getElementById('refund-modal').style.display='none'">Batal</button>
        <button type="submit" class="cg-btn" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border-color:#b91c1c;box-shadow:0 3px 0 #991b1b;">Ajukan Refund</button>
      </div>
    </form>
  </div>
</div>

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
      document.getElementById('discount-row').style.display='block';
      document.getElementById('final-price-row').style.display='block';
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