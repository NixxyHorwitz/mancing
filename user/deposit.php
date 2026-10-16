<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$min_deposit  = (float) setting($pdo, 'min_deposit', '10000');
$bank_enabled = setting($pdo, 'bank_enabled', '1') === '1';
$qris_enabled = setting($pdo, 'qris_enabled', '1') === '1';
$bankName     = setting($pdo, 'bank_name', 'BCA');
$bankAccount  = setting($pdo, 'bank_account', '-');
$bankHolder   = setting($pdo, 'bank_holder', 'Admin');
$qris_raw     = $_ENV['QRIS_RAW'] ?? '';

$u_enabled = setting($pdo, 'depo_unique_code_enabled', '0') === '1';
$u_min = (int)setting($pdo, 'depo_unique_code_min', '1');
$u_max = (int)setting($pdo, 'depo_unique_code_max', '999');
$u_start = max(1, min($u_min, $u_max));
$u_cap   = max(1, $u_min, $u_max);

/**
 * Kode unik terkecil (mulai +1) yang belum dipakai untuk nominal dasar yang sama.
 * Nominal dianggap terpakai bila masih pending ATAU dibuat dalam 3 jam terakhir (status apa pun,
 * termasuk yang sudah expired/dibatalkan), supaya pembayaran telat tidak tertukar ke deposit lain.
 * Bila semua kode sampai batas maks terpakai, lanjut naik sampai ketemu yang kosong.
 */
const DEPO_UNIQUE_LOCK_HOURS = 3;
function depo_next_unique_code(PDO $pdo, int $base, int $start, int $cap): int {
    $s = $pdo->prepare("SELECT DISTINCT CAST(amount AS UNSIGNED) FROM deposits
                        WHERE (status = 'pending' OR created_at >= DATE_SUB(NOW(), INTERVAL " . DEPO_UNIQUE_LOCK_HOURS . " HOUR))
                          AND amount > ? AND amount <= ?");
    $s->execute([$base, $base + $cap + 1000]);
    $used = array_flip(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    for ($c = $start; $c < $cap + 1000; $c++) {
        if (!isset($used[$base + $c])) return $c;
    }
    return $cap + 1000;
}

/** Simpan deposit dengan kode unik di dalam kunci, supaya dua request bersamaan tidak dapat kode yang sama. */
function depo_insert_with_code(PDO $pdo, bool $enabled, int $base, int $start, int $cap, callable $insert): array {
    if (!$enabled) return [$insert($base), 0];
    $pdo->query("SELECT GET_LOCK('depo_unique_code', 5)");
    try {
        $code = depo_next_unique_code($pdo, $base, $start, $cap);
        return [$insert($base + $code), $code];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('depo_unique_code')");
    }
}

// AJAX: pratinjau kode unik untuk nominal tertentu (belum dipesan, final ditentukan saat kirim)
if (($_GET['action'] ?? '') === 'unique_preview') {
    header('Content-Type: application/json');
    $base = (int)preg_replace('/\D/', '', (string)($_GET['amount'] ?? '0'));
    $code = ($u_enabled && $base > 0) ? depo_next_unique_code($pdo, $base, $u_start, $u_cap) : 0;
    echo json_encode(['code' => $code, 'total' => $base + $code]);
    exit;
}

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk = 'dep_form_token';
if (empty($_SESSION[$_ftk])) $_SESSION[$_ftk] = bin2hex(random_bytes(16));
$_form_token = $_SESSION[$_ftk];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reconnect MySQL in case connection has gone away (error 2006/2013)
    pdo_reconnect($pdo);
    
    $submitted_ftk = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk] ?? '', $submitted_ftk)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
        goto end_dep;
    }
    // Invalidate immediately to prevent double-submit
    unset($_SESSION[$_ftk]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_bank') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$bank_enabled) {
        $flash = 'Transfer bank lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        $proof = null;
        if (!empty($_FILES['proof']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $flash = 'Bukti transfer harus format JPG/PNG/WEBP ya.'; $flashType = 'error';
                goto end_dep;
            }
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $proof = 'deposits/' . $fname;
        }
        [$dep_id] = depo_insert_with_code($pdo, $u_enabled, $amount, $u_start, $u_cap, function (int $total) use ($pdo, $user, $proof, &$amount) {
            $amount = $total;
            $pdo->prepare("INSERT INTO deposits (user_id,amount,method,proof_image) VALUES (?,?,?,?)")
                ->execute([$user['id'], $total, 'transfer', $proof]);
            return $pdo->lastInsertId();
        });
        
        $msg = "<b>📢 DEPOSIT BARU (Transfer)</b>\nUser: {$user['username']}\nAmount: " . format_rp((float)$amount) . "\nStatus: Pending";
        $kb = depo_pending_kb((int)$dep_id);
        $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
        if ($tg_msg_id) {
            $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
        }
        
        // Success — regenerate token for next request
        $_SESSION[$_ftk] = bin2hex(random_bytes(16));
        $flash = '✅ Bukti transfer berhasil dikirim! Admin bakal memproses dalam 1×24 jam ya.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_qris') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$qris_enabled || empty($qris_raw)) {
        $flash = 'QRIS lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        [$dep_id] = depo_insert_with_code($pdo, $u_enabled, $amount, $u_start, $u_cap, function (int $total) use ($pdo, $user, &$amount) {
            $amount = $total;
            $pdo->prepare("INSERT INTO deposits (user_id,amount,method,status) VALUES (?,?,'qris','pending')")
                ->execute([$user['id'], $total]);
            return $pdo->lastInsertId();
        });
        
        $merchant_name = 'Unknown';
        $idx = 0;
        while ($idx < strlen($qris_raw) - 4) {
            $tag = substr($qris_raw, $idx, 2);
            $len = (int)substr($qris_raw, $idx+2, 2);
            if ($tag === '59') {
                $merchant_name = substr($qris_raw, $idx+4, $len);
                break;
            }
            $idx += 4 + $len;
        }

        $fmt_amount = format_rp((float)$amount);
        $msg = "📢 <b>DEPOSIT BARU ({$fmt_amount})</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$amount) . "</code>\n";
        $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
        $msg .= "🏪 <b>QRIS:</b> <code>" . htmlspecialchars($merchant_name) . "</code>\n";
        $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
        $msg .= "⏳ <b>Status:</b> <code>Pending</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "<i>Sistem memantau secara otomatis. Status di Telegram ini akan diperbarui ketika sukses terbayar via callback!</i>";
        
        $kb = depo_pending_kb((int)$dep_id);
        
        $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
        if ($tg_msg_id) {
            $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
        }

        redirect('/pay?id=' . $dep_id);
    }
}
end_dep:

$deps = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$deps->execute([$user['id']]); $deps = $deps->fetchAll();

$pageTitle  = 'Isi Saldo  ';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════
   COMPACT & MINIMALIST REEF DEPOSIT — NO EXCESSIVE SCROLLING
   Tema: Living Coral Reef Fantasy + 3D Tactile Claymorphism (Sleek)
   ══════════════════════════════════════════════════════════════ */
.depo-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 4px 10px 16px;
  display: flex;
  flex-direction: column;
  gap: 9px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Compact Card */
.depo-clay {
  border: 2px solid #ffffff;
  border-radius: 18px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
}

/* Keyframes */
@keyframes depoShake {
  0%, 100% { transform: translateX(0); }
  25%, 75% { transform: translateX(-5px); }
  50% { transform: translateX(5px); }
}

/* ── 1. COMPACT TOP NAV ── */
.depo-compact-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.depo-back-btn {
  width: 34px;
  height: 34px;
  border-radius: 11px;
  border: 1.5px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 16px;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.06), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.9);
  transition: transform 0.12s ease;
}
.depo-back-btn:active { transform: scale(0.92); }
.depo-nav-title {
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 6px;
}
.depo-live-tag {
  font-size: 10px;
  font-weight: 800;
  color: #065f46;
  background: #d1fae5;
  border: 1px solid #a7f3d0;
  padding: 3px 8px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.depo-live-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #10b981;
}

/* ── 2. COMPACT SALDO VAULT BAR ── */
.depo-vault-compact {
  padding: 10px 14px;
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background:
    radial-gradient(circle at 90% 20%, rgba(254, 240, 138, 0.4) 0, transparent 40%),
    linear-gradient(155deg, #ffffff 0%, #fff7ed 50%, #ffedd5 100%);
}
.depo-vault-left {
  display: flex;
  align-items: center;
  gap: 9px;
}
.depo-vault-icon {
  width: 36px;
  height: 36px;
  border-radius: 11px;
  background: linear-gradient(135deg, #fb7185, #f43f5e);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  box-shadow: 0 4px 10px rgba(244, 63, 94, 0.25);
}
.depo-vault-meta-lbl {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.depo-vault-val {
  font-size: 19px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.15;
}
.depo-vault-val .rp {
  font-size: 13px;
  color: var(--reef-sun-500);
}
.depo-vault-min-badge {
  font-size: 9.5px;
  font-weight: 800;
  color: #92400e;
  background: rgba(254, 243, 199, 0.85);
  border: 1px solid #fde68a;
  padding: 3px 8px;
  border-radius: 8px;
  text-align: right;
  white-space: nowrap;
}

/* Flash alerts */
.depo-flash-compact {
  padding: 8px 12px;
  border-radius: 12px;
  border: 1.5px solid #ffffff;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 8px;
}
.depo-flash-compact.success { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
.depo-flash-compact.error   { background: #ffe4e6; color: #9f1239; border-color: #fecdd3; }

/* ── 3. METHOD SWITCHER (COMPACT SEGMENTED CONTROL) ── */
.depo-segment {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  background: rgba(255, 255, 255, 0.8);
  padding: 4px;
  border-radius: 16px;
  border: 1.5px solid #ffffff;
  box-shadow: 0 4px 12px rgba(30, 27, 46, 0.05);
}
.depo-segment-btn {
  border: 1.5px solid transparent;
  background: transparent;
  border-radius: 12px;
  padding: 8px 6px;
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  transition: all 0.15s cubic-bezier(0.34, 1.56, 0.64, 1);
  user-select: none;
}
.depo-segment-btn:active { transform: scale(0.97); }
.depo-segment-btn.active {
  background: #ffffff;
  color: var(--reef-ink);
  border-color: #ffffff;
  box-shadow:
    0 4px 12px rgba(244, 63, 94, 0.12),
    inset 1px 1px 2px rgba(255, 255, 255, 0.95);
}
.depo-segment-btn.active.qris {
  border-color: #34d399;
  color: #065f46;
}
.depo-segment-btn.active.bank {
  border-color: #fbbf24;
  color: #92400e;
}
.depo-segment-badge {
  font-size: 8.5px;
  font-weight: 900;
  padding: 2px 5px;
  border-radius: 5px;
}
.depo-segment-badge.qris { background: #dcfce7; color: #15803d; }
.depo-segment-badge.bank { background: #fef3c7; color: #b45309; }

/* QRIS Official Logo Image in Options */
.depo-seg-qris-img {
  height: 20px;
  max-width: 52px;
  object-fit: contain;
  vertical-align: middle;
}
.depo-btn-qris-img {
  height: 20px;
  max-width: 52px;
  object-fit: contain;
  filter: brightness(0) invert(1);
  vertical-align: middle;
}

/* ── 4. QUICK AMOUNT & INPUT COMPACT CARD ── */
.depo-main-card {
  padding: 12px 14px;
}
.depo-input-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.depo-label-title {
  font-size: 11.5px;
  font-weight: 900;
  color: var(--reef-ink);
}
.depo-label-min {
  font-size: 10px;
  font-weight: 800;
  color: var(--reef-muted);
}

/* Kapsul input nominal */
.depo-capsule-input {
  display: flex;
  align-items: center;
  background: #ffffff;
  border: 2px solid #ffffff;
  border-radius: 15px;
  padding: 2px 8px 2px 12px;
  box-shadow:
    inset 1.5px 1.5px 4px rgba(0, 0, 0, 0.05),
    0 3px 10px rgba(30, 27, 46, 0.04);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.depo-capsule-input:focus-within {
  border-color: var(--reef-coral-400);
  box-shadow:
    0 0 0 3px rgba(244, 63, 94, 0.15),
    inset 1.5px 1.5px 3px rgba(0, 0, 0, 0.04);
}
.depo-prefix {
  font-size: 15px;
  font-weight: 900;
  color: var(--reef-coral-500);
  margin-right: 6px;
}
.depo-amount-field {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: 17px;
  font-weight: 900;
  color: var(--reef-ink);
  font-family: inherit;
  padding: 6px 0;
  min-width: 0;
}
.depo-amount-field::placeholder {
  color: #cbd5e1;
  font-weight: 800;
}
.depo-clear-btn {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: none;
  background: #f1f5f9;
  color: #64748b;
  display: none;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  cursor: pointer;
}
.depo-clear-btn.show { display: flex; }

/* Quick amount chips SIMPEL (Grid 3x2 ultra-compact, hanya angka bersih) */
.depo-quick-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  margin-top: 8px;
}
.depo-quick-chip {
  border: 1.5px solid #ffffff;
  border-radius: 11px;
  background: #ffffff;
  padding: 6px 4px;
  text-align: center;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.04), inset 1px 1px 2px rgba(255, 255, 255, 0.9);
  cursor: pointer;
  font-size: 11.5px;
  font-weight: 900;
  color: var(--reef-ink);
  transition: all 0.12s ease;
  user-select: none;
}
.depo-quick-chip:hover {
  transform: translateY(-1px);
}
.depo-quick-chip:active {
  transform: scale(0.95);
}
.depo-quick-chip.active {
  border-color: var(--reef-sun-500);
  background: linear-gradient(135deg, #ffffff 0%, #fef3c7 100%);
  color: #92400e;
  box-shadow: 0 3px 10px rgba(245, 158, 11, 0.22);
}

/* Unique code pill compact */
.depo-u-pill {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 7px;
  padding: 4px 8px;
  border-radius: 8px;
  background: #fef3c7;
  border: 1px solid #fde68a;
  font-size: 10px;
  font-weight: 800;
  color: #92400e;
}

/* ── 5. FORM WRAPPERS (QRIS & BANK) ── */
.depo-form-box {
  display: none;
  margin-top: 10px;
}
.depo-form-box.active {
  display: block;
}

/* Compact Bank Details */
.depo-bank-box {
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-radius: 14px;
  padding: 8px 10px;
  margin-bottom: 9px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.depo-bank-meta {
  flex: 1;
  min-width: 0;
}
.depo-bank-badge-title {
  font-size: 10px;
  font-weight: 900;
  color: #92400e;
}
.depo-bank-num-clean {
  font-size: 15px;
  font-weight: 900;
  color: var(--reef-ink);
  font-family: monospace, sans-serif;
  letter-spacing: 0.5px;
}
.depo-bank-holder-txt {
  font-size: 9.5px;
  font-weight: 800;
  color: #b45309;
}
.depo-copy-btn-mini {
  border: none;
  background: var(--reef-sun-500);
  color: #ffffff;
  font-size: 10px;
  font-weight: 900;
  padding: 6px 10px;
  border-radius: 9px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3);
}
.depo-copy-btn-mini.copied {
  background: #10b981;
}

/* Compact Upload Struk Box */
.depo-upload-compact {
  border: 1.5px dashed #f43f5e;
  border-radius: 12px;
  background: #fff1f2;
  padding: 8px 10px;
  position: relative;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 9px;
  transition: background 0.15s ease;
}
.depo-upload-compact:hover { background: #ffe4e6; }
.depo-upload-input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
}
.depo-upload-icon {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  background: #ffffff;
  color: var(--reef-coral-500);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}
.depo-upload-txt {
  flex: 1;
  min-width: 0;
}
.depo-upload-main-txt {
  font-size: 11px;
  font-weight: 900;
  color: var(--reef-ink);
}
.depo-upload-sub-txt {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
}
.depo-proof-thumb {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  object-fit: cover;
  display: none;
  border: 1px solid #fecdd3;
}

/* Compact Submit Button */
.depo-action-btn {
  width: 100%;
  border: 2px solid #ffffff;
  border-radius: 15px;
  padding: 11px 14px;
  font-size: 13.5px;
  font-weight: 900;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  transition: all 0.12s ease;
  user-select: none;
}
.depo-action-btn:hover { transform: translateY(-1px); }
.depo-action-btn:active { transform: scale(0.97); }
.depo-action-btn.qris {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  box-shadow:
    0 8px 18px rgba(16, 185, 129, 0.32),
    inset 2px 2px 4px rgba(255, 255, 255, 0.5),
    inset -2px -2px 5px rgba(4, 120, 87, 0.35);
}
.depo-action-btn.bank {
  background: linear-gradient(135deg, #fb7185 0%, #f43f5e 55%, #e11d48 100%);
  box-shadow:
    0 8px 18px rgba(244, 63, 94, 0.32),
    inset 2px 2px 4px rgba(255, 255, 255, 0.5),
    inset -2px -2px 5px rgba(159, 18, 57, 0.35);
}

/* Minimalist subtitle under QRIS button */
.depo-wallets-subline {
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
  text-align: center;
  margin-top: 6px;
}

/* ── 6. COMPACT EXPEDITION HISTORY ── */
.depo-history-card {
  padding: 10px 12px;
}
.depo-history-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.depo-history-title {
  font-size: 11.5px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 5px;
}
.depo-history-link {
  font-size: 10px;
  font-weight: 900;
  color: var(--reef-coral-500);
  text-decoration: none;
}
.depo-history-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px dashed rgba(226, 232, 240, 0.8);
}
.depo-history-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
.depo-history-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.depo-history-icon {
  width: 28px;
  height: 28px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  flex-shrink: 0;
}
.depo-history-icon.qris { background: #dcfce7; color: #15803d; }
.depo-history-icon.bank { background: #fef3c7; color: #b45309; }
.depo-history-amt {
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  line-height: 1.15;
}
.depo-history-date {
  font-size: 9px;
  font-weight: 800;
  color: var(--reef-muted);
}
.depo-history-right {
  display: flex;
  align-items: center;
  gap: 5px;
}
.depo-badge-mini {
  font-size: 8.5px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  text-transform: uppercase;
}
.depo-badge-mini.pending { background: #fef3c7; color: #92400e; }
.depo-badge-mini.confirmed, .depo-badge-mini.approved { background: #d1fae5; color: #065f46; }
.depo-badge-mini.rejected { background: #fee2e2; color: #991b1b; }
.depo-pay-fast {
  font-size: 9px;
  font-weight: 900;
  color: #ffffff;
  background: #0284c7;
  padding: 2px 6px;
  border-radius: 6px;
  text-decoration: none;
}
</style>

<div class="depo-stage">

  <!-- ── 1. COMPACT TOP NAV ── -->
  <div class="depo-compact-nav">
    <a href="/home" class="depo-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="depo-nav-title">
      <i class="ph-fill ph-wallet" style="color:var(--reef-sun-500);font-size:15px;"></i>
      <span>Isi Saldo Kas</span>
    </div>
    <div class="depo-live-tag">
      <span class="depo-live-dot"></span>
      <span>Proses Cepat</span>
    </div>
  </div>

  <!-- ── 2. COMPACT SALDO VAULT BAR ── -->
  <div class="depo-clay depo-vault-compact">
    <div class="depo-vault-left">
      <div class="depo-vault-icon">
        <i class="ph-fill ph-wallet"></i>
      </div>
      <div>
        <div class="depo-vault-meta-lbl">Saldo Beli Kamu</div>
        <div class="depo-vault-val">
          <span class="rp">Rp</span> <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?>
        </div>
      </div>
    </div>
    <div class="depo-vault-min-badge">
      Min. <?= format_rp($min_deposit) ?>
    </div>
  </div>

  <!-- Flash Messages -->
  <?php if ($flash): ?>
  <div class="depo-flash-compact <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning' : 'check' ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
  <div class="depo-flash-compact error">
    <i class="ph-bold ph-warning-octagon"></i>
    <span>Metode deposit sedang offline. Hubungi admin ya.</span>
  </div>
  <?php else: ?>

  <!-- ── 3. METHOD SWITCHER (COMPACT SEGMENTED CONTROL) ── -->
  <div class="depo-segment">
    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <button type="button" class="depo-segment-btn qris active" id="seg-btn-qris" onclick="switchMethod('qris')">
      <img src="/assets/qris.png?v=3" alt="QRIS" class="depo-seg-qris-img">
      <span class="depo-segment-badge qris">⚡ Otomatis</span>
    </button>
    <?php endif; ?>

    <?php if ($bank_enabled): ?>
    <button type="button" class="depo-segment-btn bank <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="seg-btn-bank" onclick="switchMethod('bank')">
      <i class="ph-bold ph-bank"></i>
      <span>Transfer Bank</span>
      <span class="depo-segment-badge bank"><?= htmlspecialchars($bankName) ?></span>
    </button>
    <?php endif; ?>
  </div>

  <!-- ── 4. QUICK AMOUNT & INPUT COMPACT CARD ── -->
  <div class="depo-clay depo-main-card">
    <div class="depo-input-head">
      <label class="depo-label-title" for="master-amount-input">Nominal Isi Saldo</label>
      <span class="depo-label-min" id="live-amount-preview">Pilih atau ketik nominal</span>
    </div>

    <!-- Capsule Input -->
    <div class="depo-capsule-input" id="master-amount-capsule">
      <span class="depo-prefix">Rp</span>
      <input
        type="number"
        id="master-amount-input"
        class="depo-amount-field"
        min="<?= $min_deposit ?>"
        step="any"
        placeholder="<?= number_format($min_deposit, 0, '', '') ?>"
        oninput="handleManualInput(this)"
      >
      <button type="button" class="depo-clear-btn" id="master-amount-clear" onclick="clearAmount()" title="Hapus">
        <i class="ph-bold ph-x"></i>
      </button>
    </div>

    <!-- Quick Amount Chips SIMPEL (Hanya angka bersih: 10k, 25k, 50k, 100k, 200k, 500k) -->
    <div class="depo-quick-grid">
      <?php
      $quick_list = [10000, 25000, 50000, 100000, 200000, 500000];
      foreach ($quick_list as $q):
        $fmt_label = ($q >= 1000000) ? ($q / 1000000) . ' Jt' : ($q / 1000) . ' Rb';
      ?>
      <div class="depo-quick-chip" onclick="applyAmount(<?= $q ?>, this)">
        <?= $fmt_label ?>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($u_enabled): ?>
    <div class="depo-u-pill">
      <i class="ph-fill ph-lightning"></i>
      <span id="u-code-txt">Kode unik <strong>+Rp <?= $u_start ?></strong> ditambahkan otomatis (naik kalau sudah dipakai).</span>
    </div>
    <?php endif; ?>

    <!-- ── 5. FORM SUBMIT SECTION (QRIS) ── -->
    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <div class="depo-form-box active" id="form-box-qris">
      <form method="POST" id="form-qris-submit" onsubmit="return validateDepoSubmit(event, 'qris')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_qris">
        <input type="hidden" name="amount" id="qris-hidden-amount" value="<?= (int)$min_deposit ?>">

        <button type="submit" class="depo-action-btn qris no-dbl-submit" id="btn-submit-qris">
          <img src="/assets/qris.png?v=3" alt="QRIS" class="depo-btn-qris-img">
          <span>Lanjut Bayar via QRIS</span>
          <i class="ph-bold ph-arrow-right"></i>
        </button>
      </form>
      <div class="depo-wallets-subline">
        DANA &bull; OVO &bull; GoPay &bull; ShopeePay &bull; BCA &bull; Mandiri &bull; BRI &bull; BNI
      </div>
    </div>
    <?php endif; ?>

    <!-- ── 5. FORM SUBMIT SECTION (BANK TRANSFER) ── -->
    <?php if ($bank_enabled): ?>
    <div class="depo-form-box <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="form-box-bank">
      <!-- Rekening Resmi Mini Box -->
      <div class="depo-bank-box">
        <div class="depo-bank-meta">
          <div class="depo-bank-badge-title">Bank <?= htmlspecialchars($bankName) ?></div>
          <div class="depo-bank-num-clean" id="bank-acc-display"><?= htmlspecialchars($bankAccount) ?></div>
          <div class="depo-bank-holder-txt">a.n <?= htmlspecialchars($bankHolder) ?></div>
        </div>
        <button type="button" class="depo-copy-btn-mini" id="btn-copy-acc" onclick="copyAccNumber()">
          <i class="ph-bold ph-copy"></i>
          <span id="copy-btn-txt">Salin</span>
        </button>
      </div>

      <form method="POST" enctype="multipart/form-data" id="form-bank-submit" onsubmit="return validateDepoSubmit(event, 'bank')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_bank">
        <input type="hidden" name="amount" id="bank-hidden-amount" value="<?= (int)$min_deposit ?>">

        <!-- Dropzone Mini Struk -->
        <div class="depo-upload-compact">
          <input type="file" name="proof" id="proof-file-input" class="depo-upload-input" accept="image/*" required onchange="handleProofSelect(this)">
          <div class="depo-upload-icon" id="proof-icon-box">
            <i class="ph-bold ph-camera"></i>
          </div>
          <img src="" alt="Bukti" id="proof-preview-img" class="depo-proof-thumb">
          <div class="depo-upload-txt">
            <div class="depo-upload-main-txt" id="proof-label-main">Sentuh untuk pilih foto struk</div>
            <div class="depo-upload-sub-txt" id="proof-label-sub">JPG, PNG, atau WEBP</div>
          </div>
        </div>

        <button type="submit" class="depo-action-btn bank no-dbl-submit" id="btn-submit-bank">
          <i class="ph-bold ph-paper-plane-tilt" style="font-size:16px;"></i>
          <span>Kirim Bukti Transfer</span>
        </button>
      </form>
    </div>
    <?php endif; ?>

  </div>
  <?php endif; ?>

  <!-- ── 6. COMPACT EXPEDITION HISTORY ── -->
  <?php if (!empty($deps)): ?>
  <div class="depo-clay depo-history-card">
    <div class="depo-history-head">
      <div class="depo-history-title">
        <i class="ph-fill ph-clock-counter-clockwise" style="color:var(--reef-sun-500);"></i>
        <span>Riwayat Terakhir</span>
      </div>
      <a href="/history" class="depo-history-link">Semua &rarr;</a>
    </div>

    <div>
      <?php foreach (array_slice($deps, 0, 4) as $d): ?>
        <?php
        $st = strtolower($d['status'] ?? 'pending');
        $bclass = 'error';
        if ($st === 'pending') $bclass = 'pending';
        elseif ($st === 'confirmed' || $st === 'approved') $bclass = 'confirmed';
        elseif ($st === 'rejected') $bclass = 'rejected';
        ?>
        <div class="depo-history-row">
          <div class="depo-history-left">
            <div class="depo-history-icon <?= $d['method'] === 'qris' ? 'qris' : 'bank' ?>">
              <i class="<?= $d['method'] === 'qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
            </div>
            <div>
              <div class="depo-history-amt"><?= format_rp((float)$d['amount']) ?></div>
              <div class="depo-history-date"><?= strtoupper($d['method']) ?> &bull; <?= date('d M, H:i', strtotime($d['created_at'])) ?></div>
            </div>
          </div>
          <div class="depo-history-right">
            <span class="depo-badge-mini <?= $bclass ?>"><?= ucfirst($d['status']) ?></span>
            <?php if ($st === 'pending' && $d['method'] === 'qris'): ?>
            <a href="/pay?id=<?= $d['id'] ?>" class="depo-pay-fast">Bayar</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
let currentMethod = '<?= ($qris_enabled && !empty($qris_raw)) ? "qris" : "bank" ?>';
const minDeposit = <?= (int)$min_deposit ?>;

function formatRp(n) {
  return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function switchMethod(method) {
  currentMethod = method;
  const btnQris = document.getElementById('seg-btn-qris');
  const btnBank = document.getElementById('seg-btn-bank');
  const boxQris = document.getElementById('form-box-qris');
  const boxBank = document.getElementById('form-box-bank');

  if (btnQris) btnQris.classList.toggle('active', method === 'qris');
  if (btnBank) btnBank.classList.toggle('active', method === 'bank');
  if (boxQris) boxQris.classList.toggle('active', method === 'qris');
  if (boxBank) boxBank.classList.toggle('active', method === 'bank');
}

const uniqueEnabled = <?= $u_enabled ? 'true' : 'false' ?>;
let uniqueTimer = null;
function previewUniqueCode(val) {
  const txt = document.getElementById('u-code-txt');
  if (!uniqueEnabled || !txt || val < minDeposit) return;
  clearTimeout(uniqueTimer);
  uniqueTimer = setTimeout(() => {
    fetch('/deposit?action=unique_preview&amount=' + val, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d || !d.code) return;
        txt.innerHTML = 'Kode unik <strong>+Rp ' + d.code + '</strong> &rarr; bayar <strong>' + formatRp(d.total) + '</strong>';
      }).catch(() => {});
  }, 250);
}

function updateLivePreview(val) {
  const lbl = document.getElementById('live-amount-preview');
  previewUniqueCode(val);
  if (!lbl) return;
  if (val >= minDeposit) {
    lbl.style.color = '#059669';
    lbl.textContent = formatRp(val);
  } else if (val > 0) {
    lbl.style.color = '#dc2626';
    lbl.textContent = 'Min. ' + formatRp(minDeposit);
  } else {
    lbl.style.color = 'var(--reef-muted)';
    lbl.textContent = 'Pilih atau ketik nominal';
  }
}

function applyAmount(val, btnEl) {
  const input = document.getElementById('master-amount-input');
  const clearBtn = document.getElementById('master-amount-clear');
  input.value = val;
  if (clearBtn) clearBtn.classList.add('show');

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = val;
  if (bankHidden) bankHidden.value = val;

  updateLivePreview(val);

  document.querySelectorAll('.depo-quick-chip').forEach(b => b.classList.remove('active'));
  if (btnEl) btnEl.classList.add('active');
}

function handleManualInput(inputEl) {
  const val = parseInt(inputEl.value.replace(/\D/g, '')) || 0;
  const clearBtn = document.getElementById('master-amount-clear');
  if (clearBtn) clearBtn.classList.toggle('show', inputEl.value.length > 0);

  document.querySelectorAll('.depo-quick-chip').forEach(b => b.classList.remove('active'));

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = val;
  if (bankHidden) bankHidden.value = val;

  updateLivePreview(val);
}

function clearAmount() {
  const input = document.getElementById('master-amount-input');
  const clearBtn = document.getElementById('master-amount-clear');
  input.value = '';
  if (clearBtn) clearBtn.classList.remove('show');
  document.querySelectorAll('.depo-quick-chip').forEach(b => b.classList.remove('active'));

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = minDeposit;
  if (bankHidden) bankHidden.value = minDeposit;

  updateLivePreview(0);
}

function validateDepoSubmit(e, type) {
  const hidden = document.getElementById(type + '-hidden-amount');
  const val = parseInt(hidden ? hidden.value : 0) || 0;
  if (val < minDeposit) {
    e.preventDefault();
    const capsule = document.getElementById('master-amount-capsule');
    const input = document.getElementById('master-amount-input');
    if (capsule) {
      capsule.style.animation = 'depoShake 0.35s ease';
      setTimeout(() => { capsule.style.animation = ''; }, 400);
    }
    if (input) input.focus();

    if (typeof nToast === 'function') {
      nToast('Minimal isi saldo adalah ' + formatRp(minDeposit), 'warning');
    } else {
      alert('Minimal isi saldo adalah ' + formatRp(minDeposit));
    }
    return false;
  }
  return true;
}

function copyAccNumber() {
  const acc = document.getElementById('bank-acc-display').textContent.trim();
  const btn = document.getElementById('btn-copy-acc');
  const txt = document.getElementById('copy-btn-txt');

  function onSuccess() {
    if (btn) btn.classList.add('copied');
    if (txt) txt.textContent = '✓ Tersalin';
    if (typeof nToast !== 'undefined' && nToast.copy) {
      nToast.copy(acc, 'Nomor rekening ' + acc);
    } else if (typeof nToast === 'function') {
      nToast('Nomor rekening disalin: ' + acc, 'copy');
    }
    setTimeout(() => {
      if (btn) btn.classList.remove('copied');
      if (txt) txt.textContent = 'Salin';
    }, 2000);
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(acc).then(onSuccess).catch(() => {
      fallbackCopy(acc, onSuccess);
    });
  } else {
    fallbackCopy(acc, onSuccess);
  }
}

function fallbackCopy(text, cb) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    if (cb) cb();
  } catch (e) {}
  document.body.removeChild(ta);
}

function handleProofSelect(input) {
  const file = input.files && input.files[0];
  const icon = document.getElementById('proof-icon-box');
  const img = document.getElementById('proof-preview-img');
  const lblMain = document.getElementById('proof-label-main');
  const lblSub = document.getElementById('proof-label-sub');

  if (!file) return;

  const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!validTypes.includes(file.type)) {
    alert('Harap pilih file gambar JPG, PNG, atau WEBP.');
    input.value = '';
    return;
  }

  if (lblMain) lblMain.textContent = file.name;
  if (lblSub) lblSub.textContent = (file.size / 1024).toFixed(1) + ' KB (Sentuh untuk ganti)';

  const reader = new FileReader();
  reader.onload = function(e) {
    if (img) {
      img.src = e.target.result;
      img.style.display = 'block';
    }
    if (icon) icon.style.display = 'none';
  };
  reader.readAsDataURL(file);
}

document.addEventListener('DOMContentLoaded', () => {
  // Preselect chip pertama
  const chips = document.querySelectorAll('.depo-quick-chip');
  if (chips.length > 0) {
    chips[0].click();
  }

  // Handle enter key on input
  const masterInput = document.getElementById('master-amount-input');
  if (masterInput) {
    masterInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (currentMethod === 'qris') {
          document.getElementById('btn-submit-qris')?.click();
        } else {
          document.getElementById('btn-submit-bank')?.click();
        }
      }
    });
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
