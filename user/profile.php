<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';
$flash = $flashType = '';
$active_section = 'main';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($old, $user['password_hash'])) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $flash = 'Password berhasil diubah!';
            $flashType = 'success';
        }
        $active_section = 'password';
    }
    $ru = $pdo->prepare("SELECT * FROM users WHERE id=?"); $ru->execute([$user['id']]); $user = $ru->fetch();
}

// Stats
$st = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?"); $st->execute([$user['id']]); $total_watches = (int)$st->fetchColumn();
$refs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?"); $refs->execute([$user['referral_code']]); $refs = (int)$refs->fetchColumn();

// Additional stats
$total_deposits = 0; $total_withdrawals = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id=? AND status='approved'"); $s->execute([$user['id']]); $total_deposits = (int)$s->fetchColumn(); } catch (\Throwable) {}
try { $s = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'"); $s->execute([$user['id']]); $total_withdrawals = (int)$s->fetchColumn(); } catch (\Throwable) {}

// Active investments
$active_investments = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM user_investments WHERE user_id=? AND status='active'"); $s->execute([$user['id']]); $active_investments = (int)$s->fetchColumn(); } catch (\Throwable) {}

// Membership
$membership_name = '';
$membership_allow_edit_bank = false;
$is_premium = false;

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=?"); $ms->execute([$user['membership_id']]);
    $ms = $ms->fetch();
    $membership_name = $ms['name'] ?? '';
    $membership_allow_edit_bank = (bool)($ms['allow_edit_bank'] ?? false);
    $is_premium = true;
}

if (!$membership_name) {
    $ms_free = $pdo->query("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetch();
    $membership_name = $ms_free['name'] ?? 'Free Member';
    $membership_allow_edit_bank = (bool)($ms_free['allow_edit_bank'] ?? false);
    $is_premium = false;
}

$edit_bank_min_dep = (int)($user['edit_bank_deposit_min'] ?? 50000);
$dep_ok_for_edit   = (float)$user['balance_dep'] >= $edit_bank_min_dep;
$is_promotor_prof  = ((int)($user['is_promotor'] ?? 0) === 1);
$show_edit_rek_btn = $membership_allow_edit_bank || $is_promotor_prof;

// Member since
$member_since = date('d M Y', strtotime($user['created_at']));
$days_member  = max(1, (int)((time() - strtotime($user['created_at'])) / 86400));
$initial_tab  = ($active_section === 'password') ? 'security' : 'summary';

// Contact buttons
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }

$pageTitle  = 'Profil Peternak';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';

$_psvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>

<style>
/* ══════════════════════════════════════════════
   MANCINGCUAN INNOVATIVE PROFILE
   NO EMOJIS — CDN ICONS & VECTOR ART ONLY
   ══════════════════════════════════════════════ */
:root {
  --honey-50:  #fffbeb;
  --honey-100: #fef3c7;
  --honey-200: #fde68a;
  --honey-300: #fcd34d;
  --honey-400: #fbbf24;
  --honey-500: #f59e0b;
  --honey-600: #d97706;
  --honey-700: #b45309;
  --honey-800: #92400e;
  --honey-900: #78350f;
  --honey-ink: #451a03;
}

body {
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 18px 18px !important;
  color: var(--honey-900);
}

.prof-container {
  max-width: 480px;
  margin: 0 auto;
  padding: 14px 14px 110px;
}

/* ── 1. DIGITAL BEEKEEPER PASS CARD ── */
.pass-card {
  position: relative;
  background: linear-gradient(140deg, #f59e0b 0%, #d97706 48%, #92400e 100%);
  border: 2.5px solid var(--honey-900);
  border-radius: 22px;
  padding: 16px 16px 14px;
  color: #fff;
  box-shadow: 0 6px 0 var(--honey-900), 0 16px 28px -6px rgba(180, 83, 9, 0.35);
  overflow: hidden;
  margin-bottom: 14px;
}

.pass-card::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1.5px, transparent 1.5px);
  background-size: 14px 14px;
  pointer-events: none;
}

.pass-sheen {
  position: absolute;
  top: -40%;
  right: -20%;
  width: 220px;
  height: 220px;
  background: radial-gradient(circle, rgba(254, 243, 199, 0.35) 0%, rgba(245, 158, 11, 0) 70%);
  transform: rotate(25deg);
  pointer-events: none;
}

/* Header inside pass */
.pass-header {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.pass-brand {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 10px;
  font-weight: 900;
  letter-spacing: 1px;
  color: #fef3c7;
  text-transform: uppercase;
}
.pass-brand i {
  font-size: 15px;
  color: #fde68a;
}
.pass-tier {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(120, 53, 15, 0.85);
  border: 1.5px solid #fde68a;
  border-radius: 12px;
  padding: 3px 10px;
  font-size: 10px;
  font-weight: 900;
  color: #fde68a;
  letter-spacing: 0.3px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.15);
}
.pass-tier.is-vip {
  background: linear-gradient(135deg, #78350f, #451a03);
  border-color: #fef08a;
  color: #fef08a;
  box-shadow: 0 0 10px rgba(251, 191, 36, 0.4);
}

/* Body inside pass */
.pass-body {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.pass-avatar-wrap {
  position: relative;
  flex-shrink: 0;
}
.pass-avatar {
  width: 58px;
  height: 58px;
  border-radius: 16px;
  background: linear-gradient(135deg, #fffbeb, #fde68a);
  border: 2.5px solid var(--honey-900);
  box-shadow: 0 3px 0 var(--honey-900), 0 4px 10px rgba(0,0,0,0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  font-weight: 900;
  color: var(--honey-900);
}
.pass-status-dot {
  position: absolute;
  bottom: -2px;
  right: -2px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #10b981;
  border: 2.5px solid var(--honey-900);
}
.pass-user-info {
  flex: 1;
  min-width: 0;
}
.pass-username {
  font-size: 17px;
  font-weight: 900;
  color: #ffffff;
  line-height: 1.2;
  text-shadow: 0 1.5px 0 var(--honey-900);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pass-email {
  font-size: 11px;
  font-weight: 700;
  color: #fef3c7;
  opacity: 0.9;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-bottom: 3px;
}
.pass-meta {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 9.5px;
  font-weight: 800;
  color: rgba(255, 255, 255, 0.85);
}
.pass-meta i {
  font-size: 12px;
  color: #fde68a;
}
.pass-chip {
  flex-shrink: 0;
  width: 38px;
  height: 28px;
  border-radius: 6px;
  background: linear-gradient(135deg, #fde68a 0%, #fbbf24 60%, #b45309 100%);
  border: 1.5px solid var(--honey-900);
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: inset 0 1px 2px rgba(255,255,255,0.6), 0 2px 0 var(--honey-900);
  color: var(--honey-900);
}
.pass-chip i {
  font-size: 18px;
}

/* Footer inside pass */
.pass-footer {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(69, 26, 3, 0.4);
  border: 1.5px solid rgba(253, 230, 138, 0.35);
  border-radius: 14px;
  padding: 8px 12px;
  backdrop-filter: blur(4px);
}
.pass-ref-box {
  display: flex;
  flex-direction: column;
}
.pass-ref-label {
  font-size: 8px;
  font-weight: 900;
  color: #fef3c7;
  letter-spacing: 0.8px;
}
.pass-ref-code {
  font-size: 14px;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: 1px;
}
.pass-copy-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #fde68a;
  color: var(--honey-900);
  border: 1.5px solid var(--honey-900);
  border-radius: 10px;
  padding: 5px 10px;
  font-size: 10.5px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 2.5px 0 var(--honey-900);
  transition: transform 0.1s, box-shadow 0.1s;
  font-family: inherit;
}
.pass-copy-btn:active {
  transform: translateY(2px);
  box-shadow: 0 0.5px 0 var(--honey-900);
}

/* ── 2. DUAL-FLOW VAULT CAPSULE (INOVASI FINANCIAL HUB) ── */
.vault-capsule {
  background: #ffffff;
  border: 2.5px solid var(--honey-900);
  border-radius: 20px;
  padding: 14px;
  box-shadow: 0 5px 0 var(--honey-900);
  margin-bottom: 14px;
  position: relative;
  overflow: hidden;
}

.vault-capsule::after {
  content: '';
  position: absolute;
  right: -25px;
  bottom: -25px;
  width: 90px;
  height: 90px;
  background: rgba(245, 158, 11, 0.06);
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  pointer-events: none;
}

/* Zone 1: Rupiah Wallets */
.vault-fiat-grid {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 8px;
  padding-bottom: 12px;
  border-bottom: 2px dashed #fde68a;
}
.vault-fiat-item {
  display: flex;
  flex-direction: column;
}
.vf-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 9px;
  font-weight: 900;
  text-transform: uppercase;
  color: var(--honey-800);
  margin-bottom: 3px;
}
.vf-badge i {
  font-size: 13px;
  color: var(--honey-600);
}
.vf-amount {
  font-size: 14px;
  font-weight: 900;
  color: var(--honey-ink);
  line-height: 1.2;
  margin-bottom: 6px;
}
.vf-action-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 5px 8px;
  border-radius: 9px;
  font-size: 10px;
  font-weight: 900;
  text-decoration: none;
  border: 1.5px solid var(--honey-900);
  box-shadow: 0 2px 0 var(--honey-900);
  transition: transform 0.1s;
}
.vf-action-btn:active {
  transform: translateY(1.5px);
  box-shadow: 0 0.5px 0 var(--honey-900);
}
.vf-action-btn.wd {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  color: var(--honey-900);
}
.vf-action-btn.dep {
  background: linear-gradient(135deg, #fef08a, #f59e0b);
  color: var(--honey-900);
}
.vf-divider {
  width: 2px;
  height: 54px;
  background: #fde68a;
  border-radius: 2px;
}

/* Zone 2: Honey Reservoir Tank */
.vault-reserves {
  display: block;
  padding-top: 10px;
}
.silo-meter {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.silo-info {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.silo-lbl {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 9.5px;
  font-weight: 900;
  color: var(--honey-800);
  text-transform: uppercase;
}
.silo-lbl i {
  color: #ea580c;
  font-size: 12px;
}
.silo-val {
  font-size: 11px;
  font-weight: 900;
  color: #ea580c;
}
.silo-bar-track {
  width: 100%;
  height: 12px;
  background: #fffbeb;
  border: 1.5px solid var(--honey-900);
  border-radius: 10px;
  overflow: hidden;
  padding: 1.5px;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
}
.silo-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #ea580c);
  border-radius: 6px;
  transition: width 0.4s ease;
  position: relative;
}

/* ── 3. SEGMENTED TAB SWITCHER ── */
.prof-tabs {
  display: flex;
  background: #ffffff;
  border: 2.5px solid var(--honey-900);
  border-radius: 16px;
  padding: 4px;
  gap: 4px;
  box-shadow: 0 4px 0 var(--honey-900);
  margin-bottom: 14px;
}
.prof-tab-btn {
  flex: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 9px 4px;
  border-radius: 12px;
  border: none;
  background: transparent;
  color: var(--honey-800);
  font-size: 11px;
  font-weight: 900;
  cursor: pointer;
  transition: all 0.15s ease;
  font-family: inherit;
}
.prof-tab-btn i {
  font-size: 15px;
  color: var(--honey-600);
}
.prof-tab-btn.active {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
  box-shadow: 0 2px 0 var(--honey-900);
}
.prof-tab-btn.active i {
  color: #ffffff;
}

/* TAB PANELS */
.tab-content {
  display: none;
}
.tab-content.active {
  display: block;
  animation: tabFadeIn 0.22s ease-out;
}
@keyframes tabFadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

/* ── 4. TAB 1: RINGKASAN & METRIK PETERNAKAN ── */
/* Colony Ribbon */
.colony-ribbon {
  background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 12px 14px;
  margin-bottom: 12px;
  box-shadow: 0 4px 0 var(--honey-900);
}
.cr-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.cr-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 900;
  text-transform: uppercase;
  color: var(--honey-900);
}
.cr-title i {
  font-size: 15px;
  color: var(--honey-600);
}
.cr-link {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 10px;
  font-weight: 900;
  color: #b45309;
  text-decoration: none;
}
.cr-grid {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border: 1.5px solid #fde68a;
  border-radius: 12px;
  padding: 8px 12px;
}
.cr-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  flex: 1;
}
.cr-item-val {
  font-size: 16px;
  font-weight: 900;
  color: var(--honey-ink);
}
.cr-item-lbl {
  font-size: 9px;
  font-weight: 800;
  color: var(--honey-800);
  text-transform: uppercase;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.cr-sep {
  width: 1.5px;
  height: 28px;
  background: #fde68a;
}

/* Activity & Milestone Stream */
.stream-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 6px 12px;
  box-shadow: 0 4px 0 var(--honey-900);
  margin-bottom: 12px;
}
.stream-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1.5px dashed #fde68a;
}
.stream-row:last-child {
  border-bottom: none;
}
.stream-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  border: 1.5px solid var(--honey-900);
}
.si-gold   { background: #fef3c7; color: #b45309; }
.si-green  { background: #dcfce7; color: #15803d; border-color: #15803d; }
.si-blue   { background: #e0f2fe; color: #0369a1; border-color: #0369a1; }
.si-purple { background: #f3e8ff; color: #7e22ce; border-color: #7e22ce; }
.si-amber  { background: #fed7aa; color: #c2410c; border-color: #c2410c; }

.stream-info {
  flex: 1;
  min-width: 0;
}
.stream-title {
  font-size: 11px;
  font-weight: 900;
  color: var(--honey-900);
  line-height: 1.2;
}
.stream-desc {
  font-size: 9.5px;
  font-weight: 700;
  color: #92400e;
  opacity: 0.8;
}
.stream-value {
  font-size: 13px;
  font-weight: 900;
  color: var(--honey-ink);
  text-align: right;
  flex-shrink: 0;
}

/* ── 5. TAB 2: FITUR & LAYANAN (LIST TILE MODERN) ── */
.service-group {
  margin-bottom: 14px;
}
.service-group-title {
  font-size: 10.5px;
  font-weight: 900;
  color: var(--honey-800);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.service-group-title::after {
  content: '';
  flex: 1;
  height: 2px;
  background: #fde68a;
  border-radius: 2px;
}
.service-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 4px 0 var(--honey-900);
}
.service-tile {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 12px;
  text-decoration: none;
  color: inherit;
  border-bottom: 1.5px solid #fef3c7;
  transition: background 0.15s;
}
.service-tile:last-child {
  border-bottom: none;
}
.service-tile:active {
  background: #fffbeb;
}
.st-icon-box {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 19px;
  flex-shrink: 0;
  border: 1.5px solid var(--honey-900);
  box-shadow: 0 2px 0 var(--honey-900);
}
.st-text {
  flex: 1;
  min-width: 0;
}
.st-name {
  font-size: 12px;
  font-weight: 900;
  color: var(--honey-900);
  line-height: 1.2;
}
.st-desc {
  font-size: 9.5px;
  font-weight: 700;
  color: #92400e;
  opacity: 0.85;
}
.st-arrow {
  font-size: 14px;
  color: #d97706;
  flex-shrink: 0;
}

/* ── 6. TAB 3: AKUN, KEAMANAN & HELP ── */
.spec-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 6px 12px;
  box-shadow: 0 4px 0 var(--honey-900);
  margin-bottom: 12px;
}
.spec-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 9px 0;
  border-bottom: 1.5px dashed #fde68a;
  font-size: 11px;
}
.spec-row:last-child {
  border-bottom: none;
}
.spec-key {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 800;
  color: var(--honey-800);
}
.spec-key i {
  font-size: 14px;
  color: var(--honey-600);
}
.spec-val {
  font-weight: 900;
  color: var(--honey-ink);
  text-align: right;
  max-width: 60%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Expandable form cards */
.accordion-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  margin-bottom: 10px;
  box-shadow: 0 4px 0 var(--honey-900);
  overflow: hidden;
}
.acc-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 14px;
  background: #fffbeb;
  cursor: pointer;
  user-select: none;
  font-size: 11.5px;
  font-weight: 900;
  color: var(--honey-900);
  transition: background 0.15s;
}
.acc-header:hover {
  background: #fef3c7;
}
.acc-header-left {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.acc-header-left i {
  font-size: 16px;
  color: var(--honey-600);
}
.acc-chevron {
  font-size: 13px;
  color: var(--honey-600);
  transition: transform 0.25s ease;
}
.acc-header.open .acc-chevron {
  transform: rotate(180deg);
}
.acc-body {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.3s ease;
  padding: 0 14px;
}
.acc-body.open {
  max-height: 380px;
  padding: 12px 14px 14px;
  border-top: 1.5px solid #fde68a;
}

/* Inputs & Submit Button */
.input-lbl {
  font-size: 10px;
  font-weight: 900;
  color: var(--honey-900);
  margin-bottom: 4px;
  display: block;
}
.prof-input-ctrl {
  width: 100%;
  background: #ffffff;
  border: 2px solid #fde68a;
  border-radius: 10px;
  padding: 9px 12px;
  font-size: 12px;
  font-weight: 800;
  color: var(--honey-ink);
  margin-bottom: 10px;
  outline: none;
  box-sizing: border-box;
  font-family: inherit;
  transition: border-color 0.2s;
}
.prof-input-ctrl:focus {
  border-color: var(--honey-600);
  box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.15);
}
.prof-submit-btn {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2px solid var(--honey-900);
  border-radius: 10px;
  padding: 10px;
  font-size: 11.5px;
  font-weight: 900;
  color: #ffffff;
  box-shadow: 0 3px 0 var(--honey-900);
  cursor: pointer;
  font-family: inherit;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: transform 0.1s;
}
.prof-submit-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 var(--honey-900);
}

/* Contact Strip */
.contact-hub {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 12px;
  box-shadow: 0 4px 0 var(--honey-900);
  margin-bottom: 14px;
}
.ch-title {
  font-size: 10px;
  font-weight: 900;
  color: var(--honey-800);
  text-transform: uppercase;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.ch-buttons {
  display: flex;
  gap: 8px;
  justify-content: center;
  flex-wrap: wrap;
}
.ch-btn {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid var(--honey-900);
  box-shadow: 0 3px 0 var(--honey-900);
  transition: transform 0.1s;
  text-decoration: none;
  color: #ffffff;
}
.ch-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 var(--honey-900);
}

/* Logout action */
.logout-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: #fee2e2;
  border: 2.5px solid #dc2626;
  border-radius: 14px;
  padding: 12px;
  font-size: 12.5px;
  font-weight: 900;
  color: #dc2626;
  text-decoration: none;
  box-shadow: 0 4px 0 #dc2626;
  transition: transform 0.1s;
}
.logout-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #dc2626;
}

/* Flash notification banner */
.prof-banner-flash {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 900;
  margin-bottom: 12px;
  border: 2px solid;
}
.prof-banner-flash.success {
  background: #ecfdf5;
  border-color: #10b981;
  color: #065f46;
}
.prof-banner-flash.error {
  background: #fef2f2;
  border-color: #ef4444;
  color: #b91c1c;
}
</style>

<div class="prof-container">
  <?php if ($flash): ?>
  <div class="prof-banner-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- 1. DIGITAL MEMBER PASS CARD -->
  <div class="pass-card">
    <div class="pass-sheen"></div>

    <div class="pass-header">
      <div class="pass-brand">
        <i class="ph-fill ph-shield-check"></i>
        <span>MancingCuan Citizen</span>
      </div>
      <div class="pass-tier <?= $is_premium ? 'is-vip' : '' ?>">
        <i class="ph-fill ph-crown"></i>
        <span><?= htmlspecialchars($membership_name) ?></span>
      </div>
    </div>

    <div class="pass-body">
      <div class="pass-avatar-wrap">
        <div class="pass-avatar">
          <span><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        </div>
        <div class="pass-status-dot"></div>
      </div>
      <div class="pass-user-info">
        <div class="pass-username"><?= htmlspecialchars($user['username']) ?></div>
        <div class="pass-email"><?= htmlspecialchars($user['email']) ?></div>
        <div class="pass-meta">
          <i class="ph-bold ph-calendar-blank"></i>
          <span>Bergabung <?= $member_since ?> (<?= number_format($days_member) ?> Hari)</span>
        </div>
      </div>
      <div class="pass-chip" title="Verified Member Chip">
        <i class="ph-bold ph-cpu"></i>
      </div>
    </div>

    <div class="pass-footer">
      <div class="pass-ref-box">
        <span class="pass-ref-label">KODE REFERRAL</span>
        <span class="pass-ref-code" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></span>
      </div>
      <button class="pass-copy-btn" onclick="copyRef()" type="button">
        <i class="ph-bold ph-copy"></i>
        <span>Salin Kode</span>
      </button>
    </div>
  </div>

  <!-- 2. DUAL-FLOW VAULT CAPSULE -->
  <div class="vault-capsule">
    <!-- Zone 1: Rupiah Balances -->
    <div class="vault-fiat-grid">
      <div class="vault-fiat-item">
        <div class="vf-badge">
          <i class="ph-fill ph-wallet"></i> Saldo Tarik
        </div>
        <div class="vf-amount"><?= format_rp((float)$user['balance_wd']) ?></div>
        <a href="/withdraw" class="vf-action-btn wd">
          <span>Tarik Dana</span>
          <i class="ph-bold ph-arrow-up-right"></i>
        </a>
      </div>

      <div class="vf-divider"></div>

      <div class="vault-fiat-item">
        <div class="vf-badge">
          <i class="ph-fill ph-coins"></i> Saldo Beli
        </div>
        <div class="vf-amount"><?= format_rp((float)$user['balance_dep']) ?></div>
        <a href="/deposit" class="vf-action-btn dep">
          <span>Top Up</span>
          <i class="ph-bold ph-plus-circle"></i>
        </a>
      </div>
    </div>

    <!-- Zone 2: Total Video Ditonton -->
    <div class="vault-reserves">
      <div class="silo-meter">
        <div class="silo-info">
          <span class="silo-lbl">
            <i class="ph-fill ph-film-strip"></i> Total Video Ditonton
          </span>
          <span class="silo-val"><?= number_format($total_watches) ?> video</span>
        </div>
        <div class="silo-bar-track">
          <?php
            $watch_pct = min(100, max(12, ($total_watches / 50) * 100));
          ?>
          <div class="silo-bar-fill" style="width: <?= $watch_pct ?>%;"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. SEGMENTED TAB SWITCHER -->
  <div class="prof-tabs">
    <button class="prof-tab-btn <?= $initial_tab === 'summary' ? 'active' : '' ?>" id="btn-tab-summary" onclick="switchProfTab('summary')">
      <i class="ph-bold ph-chart-polar"></i>
      <span>Ringkasan</span>
    </button>
    <button class="prof-tab-btn" id="btn-tab-services" onclick="switchProfTab('services')">
      <i class="ph-bold ph-squares-four"></i>
      <span>Fitur & Menu</span>
    </button>
    <button class="prof-tab-btn <?= $initial_tab === 'security' ? 'active' : '' ?>" id="btn-tab-security" onclick="switchProfTab('security')">
      <i class="ph-bold ph-shield-check"></i>
      <span>Akun & Sandi</span>
    </button>
  </div>

  <!-- ════════ TAB 1: RINGKASAN & AKTIVITAS ════════ -->
  <div class="tab-content <?= $initial_tab === 'summary' ? 'active' : '' ?>" id="pane-summary">
    <!-- Portfolio Ribbon -->
    <div class="colony-ribbon">
      <div class="cr-header">
        <div class="cr-title">
          <i class="ph-fill ph-chart-polar"></i>
          <span>Ringkasan Portofolio</span>
        </div>
        <a href="/history" class="cr-link">
          <span>Riwayat</span>
          <i class="ph-bold ph-caret-right"></i>
        </a>
      </div>
      <div class="cr-grid">
        <div class="cr-item">
          <span class="cr-item-val"><?= number_format($total_watches) ?></span>
          <span class="cr-item-lbl"><i class="ph-bold ph-film-strip"></i> Tonton</span>
        </div>
        <div class="cr-sep"></div>
        <div class="cr-item">
          <span class="cr-item-val"><?= number_format($refs) ?></span>
          <span class="cr-item-lbl"><i class="ph-bold ph-users"></i> Referral</span>
        </div>
        <div class="cr-sep"></div>
        <div class="cr-item">
          <span class="cr-item-val"><?= number_format($active_investments) ?></span>
          <span class="cr-item-lbl"><i class="ph-bold ph-chart-line-up"></i> Invest</span>
        </div>
      </div>
    </div>

    <!-- Milestones & Activity List -->
    <div class="stream-card">
      <div class="stream-row">
        <div class="stream-icon si-gold">
          <i class="ph-fill ph-trophy"></i>
        </div>
        <div class="stream-info">
          <div class="stream-title">Total Pendapatan</div>
          <div class="stream-desc">Akumulasi hasil panen & komisi</div>
        </div>
        <div class="stream-value"><?= format_rp((float)$user['total_earned']) ?></div>
      </div>

      <div class="stream-row">
        <div class="stream-icon si-green">
          <i class="ph-bold ph-arrow-down-left"></i>
        </div>
        <div class="stream-info">
          <div class="stream-title">Deposit Disetujui</div>
          <div class="stream-desc">Riwayat top up saldo sukses</div>
        </div>
        <div class="stream-value"><?= number_format($total_deposits) ?> transaksi</div>
      </div>

      <div class="stream-row">
        <div class="stream-icon si-amber">
          <i class="ph-bold ph-arrow-up-right"></i>
        </div>
        <div class="stream-info">
          <div class="stream-title">Penarikan Dana</div>
          <div class="stream-desc">Pencairan saldo ke rekening</div>
        </div>
        <div class="stream-value"><?= number_format($total_withdrawals) ?> transaksi</div>
      </div>

      <div class="stream-row">
        <div class="stream-icon si-blue">
          <i class="ph-fill ph-play-circle"></i>
        </div>
        <div class="stream-info">
          <div class="stream-title">Video Ditonton</div>
          <div class="stream-desc">Tayangan iklan tugas harian</div>
        </div>
        <div class="stream-value"><?= number_format($total_watches) ?> video</div>
      </div>

      <div class="stream-row">
        <div class="stream-icon si-purple">
          <i class="ph-fill ph-users-three"></i>
        </div>
        <div class="stream-info">
          <div class="stream-title">Undang Teman (Referral)</div>
          <div class="stream-desc">Teman bergabung lewat kodemu</div>
        </div>
        <div class="stream-value"><?= number_format($refs) ?> mitra</div>
      </div>
    </div>
  </div>

  <!-- ════════ TAB 2: FITUR & LAYANAN ════════ -->
  <div class="tab-content" id="pane-services">
    <!-- Group: Game & Hiburan -->
    <div class="service-group">
      <div class="service-group-title">
        <i class="ph-bold ph-game-controller"></i>
        <span>Aktivitas &amp; Hiburan</span>
      </div>
      <div class="service-card">
        <a href="/minigame" class="service-tile">
          <div class="st-icon-box" style="background: #dcfce7; color: #166534; border-color: #166534;">
            <i class="ph-fill ph-game-controller"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Minigame Cuan</div>
            <div class="st-desc">Tantangan harian &amp; klaim bonus instan</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>

        <a href="/videos" class="service-tile">
          <div class="st-icon-box" style="background: #e0e7ff; color: #3730a3; border-color: #3730a3;">
            <i class="ph-fill ph-film-strip"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Tonton Video Cuan</div>
            <div class="st-desc">Selesaikan durasi video untuk komisi harian</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>

        <a href="/missions" class="service-tile">
          <div class="st-icon-box" style="background: #fce7f3; color: #9d174d; border-color: #9d174d;">
            <i class="ph-fill ph-target"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Pusat Misi & Tantangan</div>
            <div class="st-desc">Klaim bonus saldo & reward tugas harian</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>
      </div>
    </div>

    <!-- Group: Finansial & Bisnis -->
    <div class="service-group">
      <div class="service-group-title">
        <i class="ph-bold ph-bank"></i>
        <span>Finansial & Bisnis</span>
      </div>
      <div class="service-card">
        <a href="/upgrade" class="service-tile">
          <div class="st-icon-box" style="background: #fef3c7; color: #b45309; border-color: #b45309;">
            <i class="ph-fill ph-rocket-launch"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Upgrade Paket Member</div>
            <div class="st-desc">Tingkatkan limit withdraw & penghasilan harian</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>

        <a href="/invest" class="service-tile">
          <div class="st-icon-box" style="background: #fef08a; color: #854d0e; border-color: #854d0e;">
            <i class="ph-fill ph-chart-line-up"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Paket Investasi Cuan</div>
            <div class="st-desc">Kembangkan aset dengan return stabil</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>

        <a href="/edit-rekening" class="service-tile">
          <div class="st-icon-box" style="background: #ccfbf1; color: #0f766e; border-color: #0f766e;">
            <i class="ph-fill ph-credit-card"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Pengaturan Rekening Bank</div>
            <div class="st-desc">Daftarkan atau perbarui rekening penarikan</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>

        <a href="/history" class="service-tile">
          <div class="st-icon-box" style="background: #fed7aa; color: #9a3412; border-color: #9a3412;">
            <i class="ph-fill ph-receipt"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Riwayat Transaksi & Mutasi</div>
            <div class="st-desc">Laporan keluar masuk saldo secara rinci</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>

        <a href="/panduan" class="service-tile">
          <div class="st-icon-box" style="background: #f3f4f6; color: #374151; border-color: #374151;">
            <i class="ph-fill ph-book-open"></i>
          </div>
          <div class="st-text">
            <div class="st-name">Buku Panduan & Aturan</div>
            <div class="st-desc">Pelajari panduan upgrade & tips profit optimal</div>
          </div>
          <i class="ph-bold ph-caret-right st-arrow"></i>
        </a>
      </div>
    </div>
  </div>

  <!-- ════════ TAB 3: AKUN, KEAMANAN & HELP ════════ -->
  <div class="tab-content <?= $initial_tab === 'security' ? 'active' : '' ?>" id="pane-security">
    <!-- Account Specs -->
    <div class="spec-card">
      <div class="spec-row">
        <span class="spec-key"><i class="ph-bold ph-user"></i> Username</span>
        <span class="spec-val"><?= htmlspecialchars($user['username']) ?></span>
      </div>
      <div class="spec-row">
        <span class="spec-key"><i class="ph-bold ph-envelope"></i> Email</span>
        <span class="spec-val"><?= htmlspecialchars($user['email']) ?></span>
      </div>
      <div class="spec-row">
        <span class="spec-key"><i class="ph-bold ph-phone"></i> WhatsApp</span>
        <span class="spec-val"><?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?></span>
      </div>
      <div class="spec-row">
        <span class="spec-key"><i class="ph-bold ph-bank"></i> Bank Tujuan</span>
        <span class="spec-val"><?= $user['bank_name'] ? htmlspecialchars($user['bank_name'] . ' - ' . mask_account($user['account_number'] ?? '')) : 'Belum Didaftarkan' ?></span>
      </div>
      <div class="spec-row">
        <span class="spec-key"><i class="ph-bold ph-crown"></i> Membership</span>
        <span class="spec-val"><?= htmlspecialchars($membership_name) ?></span>
      </div>
    </div>


    <!-- Accordion: Change Password -->
    <div class="accordion-card">
      <div class="acc-header <?= $active_section === 'password' ? 'open' : '' ?>" onclick="toggleAccordion('pass')" id="hdr-pass">
        <div class="acc-header-left">
          <i class="ph-bold ph-lock-key"></i>
          <span>Ganti Kata Sandi</span>
        </div>
        <i class="ph-bold ph-caret-down acc-chevron"></i>
      </div>
      <div class="acc-body <?= $active_section === 'password' ? 'open' : '' ?>" id="body-pass">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <label class="input-lbl">Password Lama</label>
          <input class="prof-input-ctrl" type="password" name="old_password" placeholder="Masukkan password saat ini" required minlength="6">
          <label class="input-lbl">Password Baru (Minimal 6 Karakter)</label>
          <input class="prof-input-ctrl" type="password" name="new_password" placeholder="Masukkan password baru" required minlength="6">
          <button class="prof-submit-btn" type="submit">
            <i class="ph-bold ph-key"></i>
            <span>Perbarui Password</span>
          </button>
        </form>
      </div>
    </div>

    <!-- Contact & Community Hub -->
    <div class="contact-hub">
      <div class="ch-title">
        <i class="ph-bold ph-headset"></i>
        <span>Pusat Bantuan & Komunitas</span>
      </div>
      <div class="ch-buttons">
        <?php foreach ($_contact_btns as $cb): ?>
          <?php
            $t = strtolower($cb['icon_value']);
            $svg = $_psvg[$t] ?? $_psvg['cs'];
            $c = match($t) {
                'wa' => 'background:linear-gradient(135deg, #22c55e, #15803d);border-color:#14532d;box-shadow:0 3px 0 #14532d;',
                'tele' => 'background:linear-gradient(135deg, #38bdf8, #0284c7);border-color:#0369a1;box-shadow:0 3px 0 #0369a1;',
                'ig' => 'background:linear-gradient(135deg, #f43f5e, #be123c);border-color:#881337;box-shadow:0 3px 0 #881337;',
                'fb' => 'background:linear-gradient(135deg, #3b82f6, #1d4ed8);border-color:#1e3a8a;box-shadow:0 3px 0 #1e3a8a;',
                default => 'background:linear-gradient(135deg, #94a3b8, #475569);border-color:#1e293b;box-shadow:0 3px 0 #1e293b;'
            };
          ?>
          <a href="<?= htmlspecialchars($cb['url']) ?>" class="ch-btn" target="_blank" style="<?= $c ?>" title="<?= htmlspecialchars($cb['title'] ?? 'Hubungi') ?>">
            <?= $svg ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Logout Action -->
    <a href="/logout" class="logout-btn">
      <i class="ph-bold ph-sign-out"></i>
      <span>Keluar dari Akun</span>
    </a>
  </div>
</div>

<script>
function switchProfTab(tabName) {
  // Update buttons
  document.querySelectorAll('.prof-tab-btn').forEach(b => b.classList.remove('active'));
  const btn = document.getElementById('btn-tab-' + tabName);
  if (btn) btn.classList.add('active');

  // Update panels
  document.querySelectorAll('.tab-content').forEach(p => p.classList.remove('active'));
  const pane = document.getElementById('pane-' + tabName);
  if (pane) pane.classList.add('active');
}

function toggleAccordion(id) {
  const b = document.getElementById('body-' + id);
  const h = document.getElementById('hdr-' + id);
  if (!b || !h) return;
  const isOpen = b.classList.contains('open');

  document.querySelectorAll('.acc-body').forEach(el => el.classList.remove('open'));
  document.querySelectorAll('.acc-header').forEach(el => el.classList.remove('open'));

  if (!isOpen) {
    b.classList.add('open');
    h.classList.add('open');
  }
}

function copyRef() {
  const codeEl = document.getElementById('ref-code');
  if (!codeEl) return;
  const txt = codeEl.innerText.trim();

  if (typeof nToast !== 'undefined' && nToast.copy) {
    nToast.copy(txt, 'Kode Referral');
  } else {
    navigator.clipboard.writeText(txt).then(() => {
      if (typeof nToast === 'function') {
        nToast('Kode Referral disalin: ' + txt, 'success');
      }
    });
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
