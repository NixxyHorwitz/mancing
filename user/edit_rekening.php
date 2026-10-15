<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Fetch membership info including allow_edit_bank
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=? AND is_active=1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$can_edit_bank     = (bool)($user_mem['allow_edit_bank'] ?? 0);
$level_name        = $user_mem['name'] ?? 'Free';

// Promotor bypass: skip semua pembatasan level
$is_promotor = ((int)($user['is_promotor'] ?? 0) === 1);
if ($is_promotor) {
    $can_edit_bank   = true;
}

$min_saldo_edit = (float)($user['edit_bank_deposit_min'] ?? 0);
if ($min_saldo_edit <= 0) {
    $min_saldo_edit = 50000;
}
$has_enough_balance = $is_promotor || ((float)$user['balance_dep'] >= $min_saldo_edit);

$flash = $flashType = '';

// Cek apakah ada request ganti rekening yang masih pending
$stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPending->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPending->fetchColumn();

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($has_pending_bank) {
        $flash = 'Data pengajuan ganti rekening kamu sebelumnya masih dalam proses verifikasi admin.'; 
        $flashType = 'warn';
    } elseif (!$can_edit_bank) {
        $flash = 'Level akun kamu belum memiliki izin untuk mengubah data rekening.'; 
        $flashType = 'error';
    } elseif (!$has_enough_balance) {
        $flash = 'Kamu harus memiliki Saldo Beli minimal ' . format_rp($min_saldo_edit) . ' yang mengendap untuk mengubah rekening.'; 
        $flashType = 'error';
    } else {
        $new_bank    = trim($_POST['bank_name']      ?? '');
        $new_accnum  = trim($_POST['account_number'] ?? '');
        $new_accname = trim($_POST['account_name']   ?? '');

        if (!$new_bank || !$new_accnum || !$new_accname) {
            $flash = 'Semua kolom formulir wajib diisi dengan lengkap.'; 
            $flashType = 'error';
        } else {
            $payload = json_encode(['bank_name' => $new_bank, 'account_number' => $new_accnum, 'account_name' => $new_accname]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'change_bank', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "🏦 <b>REQUEST GANTI REKENING</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💳 Rekening Baru:\n";
            $msg .= "- Bank: <b>{$new_bank}</b>\n";
            $msg .= "- No. Rek: <code>{$new_accnum}</code>\n";
            $msg .= "- A.N: <b>{$new_accname}</b>\n";
            $kb = [
                [['text'=>'Approve', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'permintaan');
            
            $flash = 'Pengajuan perubahan rekening baru berhasil dikirim dan sedang diverifikasi admin!';
            $flashType = 'success';
            $has_pending_bank = true;
        }
    }
}

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

// Load available payment channels
$channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
}
$banks    = array_filter($channels, fn($c) => $c['type'] === 'bank');
$ewallets = array_filter($channels, fn($c) => $c['type'] === 'ewallet');

$pageTitle  = 'Edit Rekening';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';
$rk_initials = static function (string $n): string {
    $n = preg_replace('/[^A-Za-z0-9 ]/', '', $n);
    $parts = preg_split('/\s+/', trim($n)) ?: [];
    return strtoupper(count($parts) > 1 ? $parts[0][0] . $parts[1][0] : substr($n, 0, 3));
};
$rk_tones = ['#0891b2', '#7c3aed', '#e11d48', '#059669', '#ea580c', '#2563eb'];
$rk_tone  = static fn(string $n): string => $rk_tones[abs(crc32(strtolower($n))) % count($rk_tones)];
$user_wl  = $channel_logos[strtolower((string)($user['bank_name'] ?? ''))] ?? null;
$rk_state = $has_pending_bank ? 'pending' : (!$can_edit_bank ? 'locked' : (!$has_enough_balance ? 'saldo' : 'open'));
$rk_flash_tone = $flashType === 'error' ? 'err' : ($flashType === 'warn' ? 'warn' : 'ok');
?>

<style>
/* ══ EDIT REKENING — compact · living coral reef clay (seragam dengan Tarik Saldo) ══ */
.rk { width: 100%; max-width: 440px; margin: 0 auto; padding: 6px 12px 28px; display: flex; flex-direction: column; gap: 10px; }
.rk-top { display: flex; align-items: center; gap: 8px; }
.rk-back { width: 34px; height: 34px; border-radius: 11px; border: 1.5px solid #fff; background: #fff; display: inline-flex; align-items: center; justify-content: center; color: var(--reef-ink); font-size: 16px; text-decoration: none; box-shadow: 0 3px 8px rgba(30, 27, 46, .06); flex-shrink: 0; }
.rk-back:active { transform: scale(.92); }
.rk-top h1 { flex: 1; margin: 0; font-size: 15px; font-weight: 900; color: var(--reef-ink); letter-spacing: -.2px; }
.rk-chip { display: inline-flex; align-items: center; gap: 4px; padding: 5px 9px; border-radius: 999px; font-size: 10px; font-weight: 900; border: 1.5px solid #fff; }
.rk-chip.ok { color: #065f46; background: #d1fae5; } .rk-chip.no { color: #9a3412; background: #ffedd5; }

.rk-card { border: 2px solid #fff; border-radius: 20px; background: var(--reef-card); box-shadow: var(--reef-clay-shadow); overflow: hidden; }

/* Kartu rekening (aktif & pratinjau) */
.rk-acc { position: relative; overflow: hidden; border-radius: 16px; padding: 12px 13px; color: #fff; display: flex; align-items: center; gap: 11px; min-height: 74px;
  background: radial-gradient(circle at 92% 120%, rgba(254, 240, 138, .45) 0, transparent 45%), linear-gradient(135deg, #0e7490 0%, #0891b2 45%, #06b6d4 100%);
  box-shadow: inset 2px 2px 6px rgba(255, 255, 255, .3), inset -3px -4px 10px rgba(8, 51, 68, .25); }
.rk-acc::before { content: ''; position: absolute; top: 5px; left: 14px; width: 38%; height: 8px; border-radius: 999px; background: linear-gradient(90deg, rgba(255, 255, 255, .3), transparent); }
.rk-acc::after { content: ''; position: absolute; right: -18px; top: -22px; width: 90px; height: 90px; border-radius: 50%; border: 12px solid rgba(255, 255, 255, .08); }
.rk-acc.new { background: radial-gradient(circle at 92% 120%, rgba(254, 240, 138, .5) 0, transparent 45%), linear-gradient(135deg, #7c3aed 0%, #c026d3 40%, #f43f5e 75%, #fb923c 100%); }
.rk-acc.empty { background: linear-gradient(135deg, #94a3b8, #64748b); }
.rk-logo { width: 44px; height: 44px; border-radius: 13px; background: #fff; flex-shrink: 0; display: flex; align-items: center; justify-content: center; position: relative; z-index: 1;
  box-shadow: 0 4px 10px rgba(8, 51, 68, .25), inset -2px -2px 4px rgba(8, 145, 178, .15); font-size: 12px; font-weight: 900; }
.rk-logo img { width: 30px; height: 30px; object-fit: contain; }
.rk-acc-tx { flex: 1; min-width: 0; position: relative; z-index: 1; line-height: 1.2; }
.rk-acc-tx small { display: block; font-size: 9.5px; font-weight: 900; letter-spacing: .5px; text-transform: uppercase; opacity: .85; }
.rk-acc-tx b { display: block; font-size: 16px; font-weight: 900; letter-spacing: .6px; margin: 2px 0 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 4px rgba(8, 51, 68, .3); }
.rk-acc-tx span { display: block; font-size: 11px; font-weight: 800; opacity: .92; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.rk-note { display: flex; align-items: flex-start; gap: 8px; padding: 9px 11px; border-radius: 14px; font-size: 11px; font-weight: 800; line-height: 1.4; border: 1.5px solid #fff; }
.rk-note i { font-size: 16px; flex-shrink: 0; }
.rk-note b { display: block; font-size: 11.5px; font-weight: 900; }
.rk-note.err  { background: #fff1f2; color: #9f1239; }
.rk-note.warn { background: #fffbeb; color: #92400e; }
.rk-note.ok   { background: #ecfdf5; color: #065f46; }
.rk-note .act { margin-left: auto; align-self: center; flex-shrink: 0; padding: 6px 11px; border-radius: 10px; font-size: 11px; font-weight: 900; text-decoration: none; color: #78350f;
  background: linear-gradient(135deg, #fef08a, #f59e0b); border: 1.5px solid #fff; box-shadow: 0 2px 0 #b45309; }

/* Syarat saldo */
.rk-req { padding: 12px 13px; }
.rk-req-h { display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; font-weight: 900; color: var(--reef-ink); }
.rk-bar { height: 9px; border-radius: 99px; background: #ffedd5; margin: 8px 0 5px; overflow: hidden; box-shadow: inset 1px 1px 3px rgba(251, 146, 60, .25); }
.rk-bar i { display: block; height: 100%; border-radius: 99px; background: linear-gradient(90deg, #f59e0b, #10b981); }
.rk-req-f { display: flex; justify-content: space-between; font-size: 10px; font-weight: 800; color: var(--reef-muted); }

/* Form */
.rk-form { padding: 12px; }
.rk-step { display: flex; align-items: center; gap: 7px; font-size: 11.5px; font-weight: 900; color: var(--reef-ink); margin: 0 2px 8px; }
.rk-step em { width: 20px; height: 20px; border-radius: 50%; font-style: normal; font-size: 10.5px; color: #fff; display: inline-flex; align-items: center; justify-content: center;
  background: radial-gradient(circle at 32% 28%, #a5f3fc, #0891b2 70%); box-shadow: 0 2px 5px rgba(8, 145, 178, .35), inset 1px 1px 2px rgba(255, 255, 255, .6); }
.rk-step + .rk-step, .rk-step.sp { margin-top: 12px; }

.rk-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 3px; border-radius: 12px; background: #ffedd5; margin-bottom: 8px; box-shadow: inset 1px 1px 3px rgba(251, 146, 60, .2); }
.rk-tab { border: 0; border-radius: 9px; padding: 7px; font: 900 11.5px/1 'Nunito', sans-serif; color: #9a3412; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; }
.rk-tab.on { background: #fff; color: var(--reef-ink); box-shadow: 0 2px 6px rgba(251, 146, 60, .25); }

.rk-chs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 7px; }
.rk-chs[hidden] { display: none; }
.rk-ch { border: 2px solid #fff; border-radius: 14px; background: #fff; padding: 8px 2px 6px; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px;
  font: 900 10px/1.1 'Nunito', sans-serif; color: var(--reef-ink); box-shadow: 0 3px 8px rgba(30, 27, 46, .06), inset -2px -2px 4px rgba(251, 146, 60, .1);
  transition: transform .14s cubic-bezier(.34, 1.56, .64, 1), border-color .12s, box-shadow .12s; position: relative; }
.rk-ch:active { transform: scale(.93); }
.rk-ch .lg { width: 34px; height: 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; }
.rk-ch .lg img { width: 28px; height: 28px; object-fit: contain; }
.rk-ch .mono { color: #fff; font-size: 11px; letter-spacing: .3px; box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, .45), inset -1.5px -1.5px 3px rgba(0, 0, 0, .18); }
.rk-ch span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0 3px; }
.rk-ch.on { border-color: #22d3ee; transform: translateY(-2px); box-shadow: 0 6px 14px rgba(8, 145, 178, .25), 0 0 0 2px rgba(34, 211, 238, .3); }
.rk-ch.on::after { content: '\2713'; position: absolute; top: -6px; right: -6px; width: 17px; height: 17px; border-radius: 50%; background: #10b981; color: #fff; font-size: 10px;
  display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }

.rk-in { display: flex; align-items: center; gap: 8px; height: 44px; padding: 0 11px 0 7px; border-radius: 14px; border: 1.5px solid #fed7aa; background: #fff;
  box-shadow: inset 2px 2px 4px rgba(251, 146, 60, .12); transition: border-color .15s, box-shadow .15s; }
.rk-in + .rk-in { margin-top: 7px; }
.rk-in:focus-within { border-color: #22d3ee; box-shadow: 0 0 0 3.5px rgba(34, 211, 238, .18); }
.rk-in.bad { border-color: #f43f5e; box-shadow: 0 0 0 3.5px rgba(244, 63, 94, .15); }
.rk-in > i { width: 28px; height: 28px; border-radius: 10px; flex-shrink: 0; font-size: 14px; color: #fff; background: linear-gradient(145deg, #a5f3fc, #0891b2);
  box-shadow: 0 3px 6px rgba(8, 145, 178, .3), inset 1.5px 1.5px 3px rgba(255, 255, 255, .55), inset -1.5px -1.5px 3px rgba(0, 0, 0, .15); }
.rk-in input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: 800 13.5px/1 'Nunito', sans-serif; color: var(--reef-ink); }
.rk-in input::placeholder { color: #94a3b8; font-weight: 700; font-size: 12.5px; }
.rk-in input:disabled { cursor: not-allowed; }
.rk-hint { margin: 5px 4px 0; font-size: 10px; font-weight: 700; color: var(--reef-muted); }

.rk-prev-l { display: flex; align-items: center; gap: 6px; font-size: 10.5px; font-weight: 900; color: var(--reef-muted); margin: 12px 2px 6px; }

.rk-go { width: 100%; margin-top: 12px; padding: 13px; border-radius: 15px; border: 2px solid #fff; background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff;
  font: 900 13.5px/1 'Nunito', sans-serif; display: flex; align-items: center; justify-content: center; gap: 7px; cursor: pointer;
  box-shadow: 0 6px 14px rgba(8, 145, 178, .3), 0 3px 0 #0e7490; }
.rk-go:active { transform: translateY(3px); box-shadow: 0 1px 4px rgba(8, 145, 178, .3); }
.rk-go:disabled { background: #e2e8f0; color: #94a3b8; box-shadow: none; transform: none; cursor: not-allowed; }

/* Konfirmasi (bottom sheet, sama seperti Tarik Saldo) */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(15, 23, 42, .5); backdrop-filter: blur(3px); align-items: flex-end; justify-content: center; }
.cg-modal-box { width: 100%; max-width: 440px; background: var(--reef-card); border-radius: 22px 22px 0 0; border: 2px solid #fff; border-bottom: 0; padding: 8px 16px calc(16px + env(safe-area-inset-bottom)); animation: rkUp .22s cubic-bezier(.2, .9, .3, 1.1); }
@keyframes rkUp { from { transform: translateY(100%); } to { transform: none; } }
.cg-grab { width: 38px; height: 4px; border-radius: 4px; background: #cbd5e1; margin: 0 auto 12px; }
.cg-t { text-align: center; font-size: 13px; font-weight: 900; color: var(--reef-ink); margin-bottom: 10px; }
.cg-info { margin-top: 8px; padding: 8px 10px; border-radius: 11px; background: #fff; font-size: 10.5px; font-weight: 800; color: var(--reef-muted); display: flex; gap: 6px; align-items: flex-start; }
.cg-modal-actions { display: flex; gap: 8px; margin-top: 12px; }
.cg-btn-cancel, .cg-btn-confirm { padding: 11px; border-radius: 13px; border: 1.5px solid #fff; font: 900 12.5px/1 'Nunito', sans-serif; cursor: pointer; }
.cg-btn-cancel { flex: 1; background: #fff; color: var(--reef-muted); }
.cg-btn-confirm { flex: 1.6; background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff; box-shadow: 0 3px 10px rgba(8, 145, 178, .3); }
.cg-btn-confirm:disabled { opacity: .7; }
</style>

<div class="rk">

  <div class="rk-top">
    <a href="/profile" class="rk-back" title="Kembali"><i class="ph-bold ph-caret-left"></i></a>
    <h1>Rekening Penarikan</h1>
    <?php if ($has_bank): ?><span class="rk-chip ok"><i class="ph-fill ph-shield-check"></i>Terhubung</span><?php else: ?><span class="rk-chip no"><i class="ph-bold ph-warning"></i>Belum ada</span><?php endif; ?>
  </div>

  <!-- Rekening aktif -->
  <div class="rk-acc <?= $has_bank ? '' : 'empty' ?>">
    <span class="rk-logo" style="color:<?= $has_bank ? $rk_tone((string)$user['bank_name']) : '#64748b' ?>">
      <?php if ($user_wl): ?><img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars((string)$user['bank_name']) ?>">
      <?php elseif ($has_bank): ?><?= htmlspecialchars($rk_initials((string)$user['bank_name'])) ?>
      <?php else: ?><i class="ph-bold ph-bank" style="font-size:20px"></i><?php endif; ?>
    </span>
    <div class="rk-acc-tx">
      <small>Rekening aktif</small>
      <?php if ($has_bank): ?>
        <b><?= htmlspecialchars((string)$user['bank_name']) ?> · <?= htmlspecialchars(mask_account((string)$user['account_number'])) ?></b>
        <span>a.n. <?= htmlspecialchars((string)$user['account_name']) ?></span>
      <?php else: ?>
        <b>Belum diatur</b><span>Isi formulir di bawah</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash && !($has_pending_bank && $flashType === 'warn')): ?>
    <div class="rk-note <?= $rk_flash_tone ?>"><i class="ph-fill <?= $rk_flash_tone === 'err' ? 'ph-x-circle' : ($rk_flash_tone === 'warn' ? 'ph-clock' : 'ph-check-circle') ?>"></i><span><?= htmlspecialchars($flash) ?></span></div>
  <?php endif; ?>

  <?php if ($rk_state === 'pending'): ?>
    <div class="rk-note warn"><i class="ph-fill ph-hourglass-medium"></i><span><b>Sedang diverifikasi</b>Pengajuan rekening barumu sedang dicek admin.</span></div>

  <?php elseif ($rk_state === 'locked'): ?>
    <div class="rk-note err"><i class="ph-fill ph-lock-key"></i><span><b>Ganti rekening terkunci</b>Level <?= htmlspecialchars($level_name) ?> belum punya izin.</span>
      <a href="/upgrade" class="act"><i class="ph-fill ph-crown"></i> Upgrade</a></div>

  <?php elseif ($rk_state === 'saldo'):
    $pct = $min_saldo_edit > 0 ? min(100, max(0, ((float)$user['balance_dep'] / $min_saldo_edit) * 100)) : 0; ?>
    <div class="rk-card rk-req">
      <div class="rk-req-h"><span><i class="ph-fill ph-vault" style="color:#f59e0b"></i> Syarat saldo beli mengendap</span>
        <a href="/deposit" class="rk-chip no" style="text-decoration:none"><i class="ph-bold ph-plus"></i>Top up</a></div>
      <div class="rk-bar"><i style="width:<?= round($pct, 1) ?>%"></i></div>
      <div class="rk-req-f"><span>Saldo beli <?= format_rp((float)$user['balance_dep']) ?></span><span>Min. <?= format_rp($min_saldo_edit) ?></span></div>
    </div>

  <?php else: ?>
    <form method="POST" id="edit-rek-form" class="rk-card rk-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="bank_name" id="bank_name" value="">

      <div class="rk-step"><em>1</em>Pilih tujuan</div>
      <div class="rk-tabs" role="tablist">
        <button type="button" class="rk-tab on" data-tab="ewallet" onclick="rkTab(this)"><i class="ph-fill ph-wallet"></i>E-Wallet</button>
        <button type="button" class="rk-tab" data-tab="bank" onclick="rkTab(this)"><i class="ph-fill ph-bank"></i>Bank</button>
      </div>
      <?php foreach (['ewallet' => $ewallets, 'bank' => $banks] as $type => $list): ?>
        <div class="rk-chs" data-group="<?= $type ?>" <?= $type === 'ewallet' ? '' : 'hidden' ?>>
          <?php foreach ($list as $c): $tone = $rk_tone($c['name']); ?>
            <button type="button" class="rk-ch" data-name="<?= htmlspecialchars($c['name']) ?>" data-type="<?= $type ?>" data-logo="<?= htmlspecialchars((string)($c['logo'] ?? '')) ?>" data-tone="<?= $tone ?>" onclick="rkPick(this)">
              <?php if (!empty($c['logo'])): ?><span class="lg"><img src="/assets/banks/<?= htmlspecialchars($c['logo']) ?>" alt=""></span>
              <?php else: ?><span class="lg mono" style="background:<?= $tone ?>"><?= htmlspecialchars($rk_initials($c['name'])) ?></span><?php endif; ?>
              <span><?= htmlspecialchars($c['name']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="rk-step sp"><em>2</em>Isi data</div>
      <div class="rk-in" id="w-num"><i class="ph-bold ph-hash" id="ico-num"></i>
        <input type="text" name="account_number" id="account_number" placeholder="Pilih tujuan dulu" inputmode="numeric" autocomplete="off" disabled oninput="rkPreview()"></div>
      <div class="rk-in" id="w-name"><i class="ph-bold ph-user"></i>
        <input type="text" name="account_name" id="account_name" placeholder="Nama pemilik sesuai rekening" autocomplete="off" disabled oninput="rkPreview()"></div>
      <div class="rk-hint" id="rk-hint">Nama harus sama dengan pemilik rekening agar pencairan lancar.</div>

      <div class="rk-prev-l"><i class="ph-bold ph-arrow-down"></i>Rekening baru</div>
      <div class="rk-acc new">
        <span class="rk-logo" id="pv-logo" style="color:#7c3aed"><i class="ph-bold ph-question" style="font-size:20px"></i></span>
        <div class="rk-acc-tx">
          <small id="pv-type">Belum dipilih</small>
          <b id="pv-num">—</b>
          <span id="pv-name">a.n. —</span>
        </div>
      </div>

      <button type="button" class="rk-go" id="rk-go" onclick="showConfirm()" disabled><i class="ph-fill ph-paper-plane-tilt"></i>Ajukan Perubahan</button>
    </form>
  <?php endif; ?>

</div>

<div id="cg-modal" class="cg-modal" onclick="if(event.target===this)closeConfirm()">
  <div class="cg-modal-box">
    <div class="cg-grab"></div>
    <div class="cg-t">Kirim pengajuan rekening ini?</div>
    <div class="rk-acc new" id="cg-card"></div>
    <div class="cg-info"><i class="ph-fill ph-shield-check" style="color:#10b981;font-size:14px"></i><span>Admin akan memverifikasi dulu sebelum rekening diganti.</span></div>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="closeConfirm()">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="submitForm()">Ya, Kirim</button>
    </div>
  </div>
</div>

<script>
const rkEsc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let rkSel = null;

function rkTab(btn) {
  document.querySelectorAll('.rk-tab').forEach(t => t.classList.toggle('on', t === btn));
  document.querySelectorAll('.rk-chs').forEach(g => g.hidden = g.dataset.group !== btn.dataset.tab);
}

function rkPick(btn) {
  document.querySelectorAll('.rk-ch').forEach(c => c.classList.toggle('on', c === btn));
  rkSel = { name: btn.dataset.name, type: btn.dataset.type, logo: btn.dataset.logo, tone: btn.dataset.tone, mono: btn.querySelector('.mono')?.textContent || '' };
  document.getElementById('bank_name').value = rkSel.name;
  const num = document.getElementById('account_number'), nm = document.getElementById('account_name');
  num.disabled = nm.disabled = false;
  const ew = rkSel.type === 'ewallet';
  num.placeholder = ew ? 'No. HP ' + rkSel.name + ' (08xx)' : 'No. rekening ' + rkSel.name;
  nm.placeholder  = ew ? 'Nama pemilik akun ' + rkSel.name : 'Nama sesuai buku tabungan';
  document.getElementById('ico-num').className = 'ph-bold ' + (ew ? 'ph-device-mobile' : 'ph-credit-card');
  document.getElementById('rk-hint').textContent = ew ? 'Pastikan nomor HP sudah terdaftar di ' + rkSel.name + '.' : 'Nama harus sama dengan pemilik rekening ' + rkSel.name + '.';
  num.focus();
  rkPreview();
}

function rkLogoHtml() {
  if (!rkSel) return '<i class="ph-bold ph-question" style="font-size:20px"></i>';
  return rkSel.logo ? '<img src="/assets/banks/' + rkEsc(rkSel.logo) + '" alt="">' : rkEsc(rkSel.mono);
}

function rkPreview() {
  const num = document.getElementById('account_number').value.trim();
  const nm  = document.getElementById('account_name').value.trim();
  const logo = document.getElementById('pv-logo');
  logo.innerHTML = rkLogoHtml();
  logo.style.color = rkSel ? rkSel.tone : '#7c3aed';
  document.getElementById('pv-type').textContent = rkSel ? (rkSel.type === 'ewallet' ? 'E-Wallet · ' : 'Bank · ') + rkSel.name : 'Belum dipilih';
  document.getElementById('pv-num').textContent  = num || '—';
  document.getElementById('pv-name').textContent = 'a.n. ' + (nm || '—');
  document.getElementById('rk-go').disabled = !(rkSel && num && nm);
  document.getElementById('w-num').classList.remove('bad');
  document.getElementById('w-name').classList.remove('bad');
}

function showConfirm() {
  const num = document.getElementById('account_number').value.trim();
  const nm  = document.getElementById('account_name').value.trim();
  if (!rkSel) { if (typeof nToast !== 'undefined') nToast('Pilih bank atau e-wallet dulu ya!', 'warn'); return; }
  if (!num) { document.getElementById('w-num').classList.add('bad'); return; }
  if (!nm)  { document.getElementById('w-name').classList.add('bad'); return; }
  document.getElementById('cg-card').innerHTML = document.querySelector('.rk-form .rk-acc.new').innerHTML.replace(/ id="[^"]*"/g, '');
  document.getElementById('cg-modal').style.display = 'flex';
}

function closeConfirm() {
  document.getElementById('cg-modal').style.display = 'none';
}

function submitForm() {
  const btn = document.querySelector('.cg-btn-confirm');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner"></i> Mengirim...';
  document.getElementById('edit-rek-form').submit();
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
