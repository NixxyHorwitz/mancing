<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'claim';
    
    // -- AJAX Check Endpoint --
    if ($action === 'check') {
        header('Content-Type: application/json');
        $code_input = strtoupper(trim($_POST['code'] ?? ''));
        if (!$code_input) { echo json_encode(['error' => 'Masukkan kode redeem kamu dulu ya!']); exit; }
        
        $stmt = $pdo->prepare("SELECT * FROM redeem_codes WHERE code = ?");
        $stmt->execute([$code_input]);
        $codeData = $stmt->fetch();
        
        if (!$codeData) { echo json_encode(['error' => 'Kode redeem-nya gak ketemu atau udah gak valid nih.']); exit; }
        if ($codeData['expires_at'] && strtotime($codeData['expires_at']) < time()) { echo json_encode(['error' => 'Kode redeem ini udah kedaluwarsa ya.']); exit; }
        if ($codeData['max_claims'] > 0 && $codeData['claims_count'] >= $codeData['max_claims']) { echo json_encode(['error' => 'Kuota klaim buat kode redeem ini udah abis nih.']); exit; }
        if (!empty($codeData['target_users'])) {
            $allowed = array_map('trim', explode(',', $codeData['target_users']));
            if (!in_array($user['username'], $allowed, true) && !in_array($user['email'], $allowed, true)) {
                echo json_encode(['error' => 'Kode redeem ini khusus buat user tertentu aja ya.']);
                exit;
            }
        }
        
        $chk = $pdo->prepare("SELECT id FROM user_redeems WHERE user_id = ? AND code_id = ?");
        $chk->execute([$user['id'], $codeData['id']]);
        if ($chk->fetch()) { echo json_encode(['error' => 'Kamu udah pernah klaim kode ini sebelumnya.']); exit; }
        
        $msg_parts = [];
        if ($codeData['reward_wd'] > 0) $msg_parts[] = 'Saldo Penarikan: ' . format_rp((float)$codeData['reward_wd']);
        if ($codeData['reward_dep'] > 0) $msg_parts[] = 'Saldo Beli: ' . format_rp((float)$codeData['reward_dep']);
        if ($codeData['reward_level_id']) {
            $ls = $pdo->prepare("SELECT name FROM memberships WHERE id = ?");
            $ls->execute([$codeData['reward_level_id']]);
            if ($lname = $ls->fetchColumn()) $msg_parts[] = 'Level: ' . $lname;
        }
        
        echo json_encode(['ok' => true, 'details' => $msg_parts]);
        exit;
    }
    // -- End AJAX Check --

    $code_input = strtoupper(trim($_POST['code'] ?? ''));
    if (!$code_input) {
        $flash = 'Masukkan kode redeem kamu dulu ya!';
        $flashType = 'error';
    } else {
        $pdo->beginTransaction();
        
        // Cek kode exist dan valid
        $stmt = $pdo->prepare("SELECT * FROM redeem_codes WHERE code = ? FOR UPDATE");
        $stmt->execute([$code_input]);
        $codeData = $stmt->fetch();
        
        if (!$codeData) {
            $flash = 'Kode redeem-nya gak ketemu atau udah gak valid nih.';
            $flashType = 'error';
        } else {
            if ($codeData['expires_at'] && strtotime($codeData['expires_at']) < time()) {
                $flash = 'Kode redeem ini udah kedaluwarsa ya.';
                $flashType = 'error';
            } elseif ($codeData['max_claims'] > 0 && $codeData['claims_count'] >= $codeData['max_claims']) {
                $flash = 'Kuota klaim buat kode redeem ini udah abis nih.';
                $flashType = 'error';
            } elseif (!empty($codeData['target_users']) && 
                      !in_array($user['username'], array_map('trim', explode(',', $codeData['target_users'])), true) && 
                      !in_array($user['email'], array_map('trim', explode(',', $codeData['target_users'])), true)) {
                $flash = 'Kode redeem ini khusus buat user tertentu aja ya.';
                $flashType = 'error';
            } else {
                // Cek apakah user sudah pernah klaim
                $chk = $pdo->prepare("SELECT id FROM user_redeems WHERE user_id = ? AND code_id = ?");
                $chk->execute([$user['id'], $codeData['id']]);
                if ($chk->fetch()) {
                    $flash = 'Kamu udah pernah klaim kode ini sebelumnya.';
                    $flashType = 'error';
                } else {
                    // Record klaim
                    $pdo->prepare("INSERT INTO user_redeems (user_id, code_id) VALUES (?, ?)")
                        ->execute([$user['id'], $codeData['id']]);
                        
                    $pdo->prepare("UPDATE redeem_codes SET claims_count = claims_count + 1 WHERE id = ?")
                        ->execute([$codeData['id']]);
                        
                    // Berikan reward ke user
                    $r_wd  = (float)$codeData['reward_wd'];
                    $r_dep = (float)$codeData['reward_dep'];
                    $r_lvl = $codeData['reward_level_id'];
                    
                    $updateSql = "UPDATE users SET balance_wd = balance_wd + ?, balance_dep = balance_dep + ?, total_earned = total_earned + ?";
                    $updateParams = [$r_wd, $r_dep, $r_wd]; // hanya WD yang dihitung total earned (opsional, tergantung logic bisnis)
 
                    $level_name = '';
                    if ($r_lvl) {
                        // Get level duration
                        $ls = $pdo->prepare("SELECT name, duration_days FROM memberships WHERE id = ?");
                        $ls->execute([$r_lvl]);
                        $levelData = $ls->fetch();
                        if ($levelData) {
                            $level_name = $levelData['name'];
                            $days = (int)$levelData['duration_days'];
                            $updateSql .= ", membership_id = ?, membership_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)";
                            $updateParams[] = $r_lvl;
                            $updateParams[] = $days;
                        }
                    }
 
                    $updateSql .= " WHERE id = ?";
                    $updateParams[] = $user['id'];
                    
                    $pdo->prepare($updateSql)->execute($updateParams);
                        
                    // Re-fetch user to reflect changes
                    $usrStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $usrStmt->execute([$user['id']]);
                    $user = $usrStmt->fetch();
                    
                    $pdo->commit();
                    
                    $msg_parts = [];
                    if ($r_wd > 0) $msg_parts[] = 'Saldo Penarikan ' . format_rp($r_wd);
                    if ($r_dep > 0) $msg_parts[] = 'Saldo Beli ' . format_rp($r_dep);
                    if ($level_name) $msg_parts[] = 'Level ' . $level_name;
                    
                    $flash = '🎉 Hore! Kamu dapet ' . implode(', ', $msg_parts) . '. Selamat ya!';
                    $flashType = 'success';
                    goto done_redeem;
                }
            }
        }
        $pdo->rollBack();
    }
}
done_redeem:

$pageTitle  = 'Redeem Code  ';
$activePage = 'redeem';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════════
   TREASURE ISLAND REDEEM VAULT — PETI HADIAH SAMUDRA
   Tema: Living Coral Reef Fantasy & 3D Tactile Claymorphism
   ══════════════════════════════════════════════════════════════ */

.rdm-reef-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 34px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  position: relative;
  z-index: 1;
}

/* Claymorphism Base */
.rdm-clay {
  border: 2px solid #ffffff;
  border-radius: 20px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  position: relative;
  overflow: hidden;
}

/* Ambient Marine Floating Bubbles */
.rdm-bubble {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.95) 0%, rgba(103, 232, 249, 0.35) 60%, rgba(244, 63, 94, 0.15) 100%);
  border: 1px solid rgba(255, 255, 255, 0.85);
  box-shadow: inset 1px 1px 2px #ffffff, 0 2px 6px rgba(8, 145, 178, 0.15);
  pointer-events: none;
  z-index: 0;
}
.rdm-bubble--1 {
  width: 18px;
  height: 18px;
  top: 12px;
  right: 18px;
  animation: rdmBubbleFloat 5.2s infinite ease-in-out alternate;
}
.rdm-bubble--2 {
  width: 26px;
  height: 26px;
  bottom: 14px;
  right: 14px;
  animation: rdmBubbleFloat 6.8s infinite ease-in-out alternate-reverse;
  opacity: 0.65;
}
@keyframes rdmBubbleFloat {
  0% { transform: translateY(0) scale(0.95); }
  50% { transform: translateY(-7px) scale(1.05); }
  100% { transform: translateY(2px) scale(0.98); }
}

/* ── 1. COMPACT TOP NAVIGATOR ── */
.rdm-top-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 2px;
}
.rdm-back-btn {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  border: 1.5px solid #ffffff;
  background: #ffffff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--reef-ink);
  font-size: 17px;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(30, 27, 46, 0.05), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.95);
  transition: transform 0.12s ease;
}
.rdm-back-btn:active { transform: scale(0.92); }

.rdm-nav-title {
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 255, 255, 0.92);
  border: 1.5px solid #ffffff;
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 13px;
  font-weight: 900;
  color: var(--reef-ink);
  box-shadow: 0 2px 8px rgba(30, 27, 46, 0.04);
}

.rdm-secret-badge {
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
  border: 1.5px solid #ffffff;
  color: #78350f;
  font-weight: 900;
  font-size: 11px;
  padding: 6px 11px;
  border-radius: 14px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 2px 8px rgba(245, 158, 11, 0.22), inset 1px 1px 2px #ffffff;
}

/* Flash Notice Box */
.rdm-flash-box {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 13px;
  border-radius: 14px;
  font-size: 11.5px;
  font-weight: 800;
  border: 1.5px solid #ffffff;
}
.rdm-flash-box.error {
  background: #ffe4e6;
  color: #9f1239;
  border-color: #fecdd3;
}
.rdm-flash-box.success {
  background: #d1fae5;
  color: #065f46;
  border-color: #a7f3d0;
}

/* ── 2. THE TREASURE CHEST VAULT CARD ── */
.rdm-vault-card {
  padding: 20px 18px 22px;
  text-align: center;
}
.rdm-chest-icon {
  width: 58px;
  height: 58px;
  border-radius: 18px;
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 50%, #d97706 100%);
  border: 2px solid #ffffff;
  margin: 0 auto 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: #ffffff;
  box-shadow: 0 6px 18px rgba(245, 158, 11, 0.3), inset 1px 1px 3px rgba(255, 255, 255, 0.8);
}
.rdm-vault-title {
  font-size: 16px;
  font-weight: 900;
  color: var(--reef-ink);
  margin-bottom: 3px;
}
.rdm-vault-sub {
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.4;
  margin-bottom: 16px;
}

/* Input Code Box */
.rdm-input-wrap {
  margin-bottom: 12px;
}
.rdm-code-input {
  width: 100%;
  background: #ffffff;
  border: 2px solid #fed7aa;
  border-radius: 16px;
  padding: 13px 14px;
  font-size: 19px;
  font-weight: 900;
  color: #ea580c;
  font-family: inherit;
  text-transform: uppercase;
  letter-spacing: 3.5px;
  text-align: center;
  outline: none;
  transition: all 0.18s ease;
  box-shadow: inset 1px 2px 5px rgba(30, 27, 46, 0.04);
  box-sizing: border-box;
}
.rdm-code-input:focus {
  border-color: var(--reef-coral-500);
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.16);
}
.rdm-code-input::placeholder {
  color: #cbd5e1;
  font-weight: 800;
  letter-spacing: 2px;
}

/* 3D Tactile Submit Button */
.btn-rdm-submit {
  width: 100%;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 2px solid #ffffff;
  border-radius: 16px;
  padding: 13px 16px;
  font-size: 13.5px;
  font-weight: 900;
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35), 0 3px 0 #047857, inset 1px 1px 2px rgba(255, 255, 255, 0.6);
  transition: all 0.15s ease;
  font-family: inherit;
  user-select: none;
}
.btn-rdm-submit:hover { filter: brightness(1.03); }
.btn-rdm-submit:active {
  transform: translateY(3px);
  box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3), 0 0 0 #047857;
}
.btn-rdm-submit:disabled {
  opacity: 0.65;
  cursor: not-allowed;
  transform: none;
}

/* ── 3. THE MARINE GUIDE TABLET ── */
.rdm-guide-card {
  padding: 14px 16px;
}
.rdm-guide-title {
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
}
.rdm-guide-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.rdm-guide-item {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  font-size: 11px;
  font-weight: 700;
  color: var(--reef-muted);
  line-height: 1.4;
}
.rdm-guide-item i {
  color: #10b981;
  font-size: 15px;
  flex-shrink: 0;
  margin-top: 1px;
}
.rdm-guide-item strong {
  color: var(--reef-ink);
}

/* ── 4. CONFIRMATION MODAL (CLAYMORPHIC) ── */
.cg-modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 16px;
  backdrop-filter: blur(4px);
}
.cg-modal-box {
  background: var(--reef-card);
  width: 100%;
  max-width: 320px;
  border-radius: 22px;
  border: 2px solid #ffffff;
  box-shadow: 0 16px 36px rgba(0, 0, 0, 0.2);
  overflow: hidden;
  animation: cgModalPop 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes cgModalPop {
  from { transform: scale(0.9); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}
.cg-modal-hdr {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  padding: 12px;
  text-align: center;
  color: #ffffff;
  font-weight: 900;
  font-size: 13.5px;
  border-bottom: 1.5px solid #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.cg-modal-bd {
  padding: 16px 16px 14px;
}
.cg-modal-list {
  list-style: none;
  margin: 0 0 12px;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.cg-modal-list li {
  background: #ffffff;
  border: 1px solid #a7f3d0;
  border-radius: 10px;
  padding: 7px 11px;
  font-size: 12px;
  font-weight: 900;
  color: #065f46;
  text-align: center;
}
.cg-modal-actions {
  display: flex;
  gap: 8px;
  padding: 0 16px 16px;
}
.cg-btn-cancel {
  flex: 1;
  padding: 10px;
  background: #ffffff;
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  font-weight: 800;
  color: var(--reef-muted);
  font-size: 12px;
  box-shadow: 0 2px 6px rgba(30, 27, 46, 0.05);
  cursor: pointer;
  text-align: center;
}
.cg-btn-confirm {
  flex: 1.4;
  padding: 10px;
  background: linear-gradient(135deg, #10b981, #059669);
  border: 1.5px solid #ffffff;
  border-radius: 12px;
  font-weight: 900;
  color: #ffffff;
  box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);
  font-size: 12px;
  cursor: pointer;
  text-align: center;
}
</style>

<div class="rdm-reef-stage">

  <!-- ── 1. COMPACT TOP NAVIGATOR ── -->
  <div class="rdm-top-nav">
    <a href="/home" class="rdm-back-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-caret-left"></i>
    </a>
    <div class="rdm-nav-title">
      <i class="ph-fill ph-gift" style="color:var(--reef-coral-500);"></i>
      <span>Peti Hadiah Samudra</span>
    </div>
    <div class="rdm-secret-badge">
      <i class="ph-bold ph-key"></i>
      <span>Voucher Kupon</span>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="rdm-flash-box <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-bold <?= $flashType === 'error' ? 'ph-warning-circle' : 'ph-sparkle' ?>" style="font-size:16px;"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <!-- ── 2. THE TREASURE CHEST VAULT CARD ── -->
  <div class="rdm-clay rdm-vault-card">
    <div class="rdm-bubble rdm-bubble--1"></div>
    <div class="rdm-bubble rdm-bubble--2"></div>

    <div class="rdm-chest-icon">
      <i class="ph-fill ph-treasure-chest"></i>
    </div>
    <div class="rdm-vault-title">Tukarkan Kode Voucher Samudra</div>
    <div class="rdm-vault-sub">
      Masukkan kode hadiah resmi untuk membuka saldo penarikan, saldo beli, atau upgrade membership gratis!
    </div>

    <form id="form-claim" method="POST" onsubmit="checkRedeem(event)">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="claim">

      <div class="rdm-input-wrap">
        <input class="rdm-code-input" type="text" name="code" placeholder="KODE HADIAH" autocomplete="off" required maxlength="50">
      </div>

      <button type="submit" id="btn-check" class="btn-rdm-submit">
        <i class="ph-bold ph-sparkle"></i>
        <span>Cek &amp; Buka Peti Hadiah</span>
      </button>
    </form>
  </div>

  <!-- ── 3. THE MARINE GUIDE TABLET ── -->
  <div class="rdm-clay rdm-guide-card">
    <div class="rdm-guide-title">
      <i class="ph-fill ph-lightbulb" style="color:#f59e0b;font-size:16px;"></i>
      <span>Petunjuk Kode Voucher</span>
    </div>
    <ul class="rdm-guide-list">
      <li class="rdm-guide-item">
        <i class="ph-bold ph-check-circle"></i>
        <div>Kode tidak sensitif huruf besar/kecil (otomatis disesuaikan).</div>
      </li>
      <li class="rdm-guide-item">
        <i class="ph-bold ph-check-circle"></i>
        <div>Setiap kode hadiah hanya dapat diklaim <strong>1 kali</strong> per akun.</div>
      </li>
      <li class="rdm-guide-item">
        <i class="ph-bold ph-check-circle"></i>
        <div>Reward dapat berupa <strong>Saldo Penarikan, Saldo Beli, atau Durasi Membership VIP</strong>.</div>
      </li>
    </ul>
  </div>

</div>

<!-- ── 4. CONFIRMATION MODAL (CLAYMORPHIC) ── -->
<div class="cg-modal" id="brutal-confirm">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">
      <i class="ph-fill ph-gift"></i>
      <span>Peti Hadiah Ditemukan!</span>
    </div>
    <div class="cg-modal-bd">
      <div style="font-size:11px;font-weight:800;color:var(--reef-muted);margin-bottom:8px;text-align:center;">Kode ini memuat hadiah berikut:</div>
      <ul class="cg-modal-list" id="brutal-confirm-list"></ul>
      <div style="font-size:11px;font-weight:800;color:var(--reef-ink);text-align:center;margin-bottom:6px;">Buka dan klaim hadiah ini sekarang?</div>
    </div>
    <div class="cg-modal-actions">
      <button type="button" onclick="document.getElementById('brutal-confirm').style.display='none'" class="cg-btn-cancel">Batal</button>
      <button type="button" onclick="confirmBrutalClaim()" class="cg-btn-confirm">Buka Sekarang!</button>
    </div>
  </div>
</div>

<script>
let tempForm = null;

function confirmBrutalClaim() {
  if (tempForm) { tempForm.onsubmit = null; tempForm.submit(); }
}

function checkRedeem(e) {
  e.preventDefault();
  const form = document.getElementById('form-claim');
  const code = form.querySelector('input[name="code"]').value;
  const btn  = document.getElementById('btn-check');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memeriksa Peti...';

  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=check&code=' + encodeURIComponent(code) + '&_csrf=' + encodeURIComponent(form.querySelector('input[name="_csrf"]')?.value || '')
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-sparkle"></i> Cek & Buka Peti Hadiah';
    if (res.error) {
      if (typeof nToast !== 'undefined') nToast(res.error, 'error');
      else alert(res.error);
    } else {
      const list = document.getElementById('brutal-confirm-list');
      list.innerHTML = res.details.map(d => `<li>🎁 ${d}</li>`).join('');
      tempForm = form;
      document.getElementById('brutal-confirm').style.display = 'flex';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-sparkle"></i> Cek & Buka Peti Hadiah';
    if (typeof nToast !== 'undefined') nToast('Terjadi kesalahan jaringan.', 'error');
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
