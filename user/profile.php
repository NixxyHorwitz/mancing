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

// Contact buttons
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }

$pageTitle  = 'Kabin Kapten';
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
/* ══════════════════════════════════════════════════════════
   KABIN KAPTEN — profil di dalam kabin kapal
   Dinding kayu hangat, jendela bulat (porthole) ke laut karang,
   tetap memakai palet & clay 3D yang sama dengan Beranda.
   ══════════════════════════════════════════════════════════ */
.cabin-stage {
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 6px 12px 22px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  position: relative;
  z-index: 1;
}
.cabin-clay {
  border: 2px solid #ffffff;
  border-radius: 20px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
}

/* Flash */
.cabin-flash {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border-radius: 14px;
  font-size: 11.5px;
  font-weight: 800;
  border: 2px solid #ffffff;
  box-shadow: inset 2px 2px 4px rgba(255, 255, 255, 0.8), 0 4px 10px rgba(0, 0, 0, 0.05);
}
.cabin-flash.success { background: #d1fae5; color: #065f46; }
.cabin-flash.error   { background: #ffe4e6; color: #9f1239; }

/* ── 1. HERO: DINDING KABIN KAYU + PORTHOLE ── */
.cabin-hero {
  position: relative;
  overflow: hidden;
  border: 2px solid #ffffff;
  border-radius: 24px;
  padding: 14px 14px 12px;
  background:
    radial-gradient(circle at 12% 0%, rgba(254, 240, 138, 0.55) 0, transparent 42%),
    repeating-linear-gradient(180deg, transparent 0 25px, rgba(180, 83, 9, 0.10) 25px 27px),
    linear-gradient(170deg, #fff7ed 0%, #fde7c8 60%, #fbd5a5 100%);
  box-shadow:
    0 16px 34px rgba(180, 83, 9, 0.16),
    0 6px 14px rgba(244, 63, 94, 0.08),
    inset 3px 3px 7px rgba(255, 255, 255, 0.9),
    inset -4px -5px 10px rgba(180, 83, 9, 0.16);
}
/* Lentera kabin menggantung */
.cabin-lantern {
  position: absolute;
  top: 0; left: 50%;
  width: 22px; height: 40px;
  pointer-events: none;
  transform-origin: 50% 0;
  animation: lanternSwing 4s ease-in-out infinite;
  z-index: 1;
}
@keyframes lanternSwing {
  0%, 100% { transform: rotate(-4deg); }
  50% { transform: rotate(4deg); }
}
.cabin-porthole {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 84px;
  height: 84px;
  pointer-events: none;
  z-index: 1;
  filter: drop-shadow(0 5px 8px rgba(120, 53, 15, 0.25));
}
.porthole-fish { animation: portFish 7s linear infinite; }
.porthole-fish--b { animation-duration: 9s; animation-delay: -4s; }
@keyframes portFish {
  0% { transform: translateX(-30px); }
  100% { transform: translateX(110px); }
}
.porthole-bubble { animation: portBubble 3.5s ease-in infinite; }
.porthole-bubble:nth-of-type(2) { animation-delay: -1.2s; }
.porthole-bubble:nth-of-type(3) { animation-delay: -2.4s; }
@keyframes portBubble {
  0% { transform: translateY(0); opacity: 0; }
  20% { opacity: 0.9; }
  100% { transform: translateY(-40px); opacity: 0; }
}

.cabin-id {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 10px;
  padding-right: 88px;
  min-height: 84px;
}
.cabin-avatar {
  position: relative;
  width: 52px;
  height: 52px;
  border-radius: 17px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  font-weight: 900;
  color: #ffffff;
  border: 2px solid #ffffff;
  background: linear-gradient(145deg, #fdba74 0%, #fb7185 50%, #f43f5e 100%);
  box-shadow:
    0 6px 14px rgba(244, 63, 94, 0.32),
    inset 2.5px 2.5px 4px rgba(255, 255, 255, 0.6),
    inset -2.5px -2.5px 5px rgba(190, 18, 60, 0.3);
}
.cabin-online {
  position: absolute;
  bottom: -2px; right: -2px;
  width: 12px; height: 12px;
  border-radius: 50%;
  background: #10b981;
  border: 2px solid #ffffff;
}
.cabin-who { min-width: 0; }
.cabin-eyebrow {
  font-size: 8.5px;
  font-weight: 900;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #b45309;
}
.cabin-name {
  font-size: 16px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: -0.3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cabin-email {
  font-size: 10px;
  font-weight: 700;
  color: var(--reef-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
}
.cabin-pills { display: flex; flex-wrap: wrap; gap: 4px; }
.cabin-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 9px;
  font-weight: 900;
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.8);
}
.cabin-pill--rank { background: #f3e8ff; color: #7e22ce; }
.cabin-pill--rank.is-vip { background: linear-gradient(135deg, #fef08a, #fbbf24); color: #78350f; }
.cabin-pill--days { background: #ffffff; color: #9a3412; }

/* Tiket kru (kode referral) */
.cabin-ticket {
  position: relative;
  z-index: 2;
  margin-top: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 8px 8px 12px;
  border-radius: 14px;
  background: #ffffff;
  box-shadow: 0 4px 10px rgba(180, 83, 9, 0.10), inset -2px -2px 5px rgba(251, 146, 60, 0.12);
}
.cabin-ticket::before,
.cabin-ticket::after {
  content: '';
  position: absolute;
  top: 50%;
  width: 12px; height: 12px;
  border-radius: 50%;
  background: #fbd9ae;
  transform: translateY(-50%);
}
.cabin-ticket::before { left: -6px; }
.cabin-ticket::after  { right: -6px; }
.ticket-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
.ticket-ico {
  width: 28px; height: 28px;
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(145deg, #c084fc, #8b5cf6);
  color: #fff;
  font-size: 15px;
  box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), 0 3px 6px rgba(139, 92, 246, 0.3);
  flex-shrink: 0;
}
.ticket-lbl { font-size: 8.5px; font-weight: 900; color: var(--reef-muted); letter-spacing: 0.8px; }
.ticket-code {
  font-size: 14px;
  font-weight: 900;
  color: var(--reef-ink);
  letter-spacing: 1.5px;
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
}
.ticket-copy {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 7px 11px;
  border: none;
  border-radius: 11px;
  background: linear-gradient(145deg, #fb7185, #f43f5e);
  color: #ffffff;
  font-family: inherit;
  font-size: 10.5px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 4px 10px rgba(244, 63, 94, 0.3), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -2px -2px 4px rgba(190, 18, 60, 0.3);
}
.ticket-copy:active { transform: scale(0.95); }

/* ── 2. RAK KARGO: kartu saldo & aktivitas geser horizontal ── */
.cabin-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4px;
  margin-bottom: -4px;
}
.csh-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 900;
  color: var(--reef-ink);
}
.csh-title i { color: #b45309; font-size: 15px; }
.csh-hint {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 9.5px;
  font-weight: 800;
  color: var(--reef-muted);
}
.cargo-rail {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 74%;
  gap: 10px;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  margin: 0 -12px;
  padding: 4px 12px 12px;
  scroll-padding-left: 12px;
  scrollbar-width: none;
}
.cargo-rail::-webkit-scrollbar { display: none; }
.cargo-card {
  scroll-snap-align: start;
  position: relative;
  overflow: hidden;
  min-height: 124px;
  padding: 12px;
  border: 2px solid #ffffff;
  border-radius: 20px;
  color: #ffffff;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-shadow:
    0 10px 22px var(--cc-sh),
    inset 2px 2px 5px rgba(255, 255, 255, 0.4),
    inset -3px -3px 7px rgba(0, 0, 0, 0.14);
}
.cargo-card::after {
  content: '';
  position: absolute;
  right: -22px;
  bottom: -22px;
  width: 88px;
  height: 88px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.14);
  pointer-events: none;
}
.cargo--wd   { background: linear-gradient(140deg, #fda4af, #f43f5e 60%, #e11d48); --cc-sh: rgba(244, 63, 94, 0.30); --cc-ink: #e11d48; }
.cargo--dep  { background: linear-gradient(140deg, #6ee7b7, #10b981 55%, #047857); --cc-sh: rgba(5, 150, 105, 0.30); --cc-ink: #047857; }
.cargo--earn { background: linear-gradient(140deg, #fde68a, #f59e0b 55%, #d97706); --cc-sh: rgba(245, 158, 11, 0.32); --cc-ink: #b45309; }
.cargo--log  { background: linear-gradient(140deg, #c084fc, #8b5cf6 55%, #6d28d9); --cc-sh: rgba(139, 92, 246, 0.32); --cc-ink: #6d28d9; }
.cargo-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cargo-lbl {
  font-size: 9.5px;
  font-weight: 900;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  opacity: 0.95;
}
.cargo-ico {
  width: 28px; height: 28px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  background: rgba(255, 255, 255, 0.24);
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.45);
}
.cargo-val {
  font-size: 21px;
  font-weight: 900;
  letter-spacing: -0.5px;
  white-space: nowrap;
  text-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
  margin: 6px 0 2px;
}
.cargo-meta { font-size: 9.5px; font-weight: 800; opacity: 0.95; }
.cargo-foot {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-top: 8px;
}
.cargo-act {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 11px;
  border-radius: 999px;
  background: #ffffff;
  color: var(--cc-ink);
  font-size: 10.5px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12), inset -1px -1px 2px rgba(0, 0, 0, 0.06);
}
.cargo-act:active { transform: scale(0.95); }
.cargo-mini {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  margin-top: 8px;
}
.cargo-mini div {
  padding: 5px 4px;
  border-radius: 10px;
  text-align: center;
  background: rgba(255, 255, 255, 0.2);
  box-shadow: inset 1px 1px 2px rgba(255, 255, 255, 0.35);
}
.cargo-mini b { display: block; font-size: 14px; font-weight: 900; line-height: 1.1; }
.cargo-mini span { font-size: 8.5px; font-weight: 800; opacity: 0.95; }

/* ── 3. JENDELA MENU: tombol porthole bulat berbingkai kuningan ── */
.porthole-menu {
  border: 2px solid #ffffff;
  border-radius: 22px;
  padding: 14px 6px 10px;
  background:
    repeating-linear-gradient(180deg, transparent 0 25px, rgba(180, 83, 9, 0.07) 25px 27px),
    linear-gradient(170deg, #fffaf3 0%, #fdebd3 100%);
  box-shadow: var(--reef-clay-shadow);
}
.pm-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  row-gap: 14px;
}
.pm-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-decoration: none;
}
.pm-ring {
  width: 50px;
  height: 50px;
  padding: 4px;
  border-radius: 50%;
  background: linear-gradient(145deg, #fef3c7 0%, #fbbf24 50%, #b45309 100%);
  box-shadow: 0 5px 10px rgba(180, 83, 9, 0.25), inset 1px 1px 2px rgba(255, 255, 255, 0.7);
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.pm-item:active .pm-ring { transform: scale(0.9); }
.pm-glass {
  position: relative;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 19px;
  color: #ffffff;
  background: var(--pm);
  box-shadow: inset 2px 2px 4px rgba(255, 255, 255, 0.45), inset -2px -2px 5px rgba(0, 0, 0, 0.2);
}
.pm-glass::before {
  content: '';
  position: absolute;
  top: 5px; left: 8px;
  width: 12px; height: 6px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.45);
  transform: rotate(-30deg);
}
.pm--coral  { --pm: linear-gradient(145deg, #fda4af, #f43f5e); }
.pm--green  { --pm: linear-gradient(145deg, #6ee7b7, #059669); }
.pm--violet { --pm: linear-gradient(145deg, #c084fc, #7c3aed); }
.pm--gold   { --pm: linear-gradient(145deg, #fde68a, #d97706); }
.pm--lagoon { --pm: linear-gradient(145deg, #67e8f9, #0891b2); }
.pm-lbl {
  font-size: 9.5px;
  font-weight: 900;
  color: #44403c;
  text-align: center;
  line-height: 1.15;
}

/* ── 4. LACI KABIN: tombol pembuka bottom sheet ── */
.drawer-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.drawer-btn {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
  padding: 10px;
  border: 2px solid #ffffff;
  border-radius: 16px;
  background: var(--reef-card);
  box-shadow: var(--reef-clay-shadow);
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  transition: transform 0.12s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.drawer-btn:active { transform: scale(0.96); }
.drawer-btn strong { display: block; font-size: 11.5px; font-weight: 900; color: var(--reef-ink); }
.drawer-btn small { display: block; font-size: 9px; font-weight: 700; color: var(--reef-muted); line-height: 1.25; }
.dr-ico {
  width: 30px; height: 30px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px;
  color: #ffffff;
  box-shadow: inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.5), inset -1.5px -1.5px 3px rgba(0, 0, 0, 0.14);
}
.dr-ico--doc  { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.dr-ico--lock { background: linear-gradient(145deg, #6ee7b7, #059669); }
.dr-ico--help { background: linear-gradient(145deg, #c084fc, #7c3aed); }

.cabin-exit {
  align-self: center;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 999px;
  background: #fff1f2;
  color: #e11d48;
  font-size: 11.5px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(244, 63, 94, 0.14);
}
.cabin-exit:active { transform: scale(0.97); }

/* ── BOTTOM SHEET ── */
.sheet-backdrop {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: rgba(30, 27, 46, 0.45);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s;
}
.sheet-backdrop.open { opacity: 1; pointer-events: auto; }
.cabin-sheet {
  position: fixed;
  left: 50%;
  bottom: 0;
  z-index: 10001;
  width: 100%;
  max-width: 440px;
  max-height: 85vh;
  overflow-y: auto;
  padding: 8px 16px calc(18px + env(safe-area-inset-bottom));
  border-radius: 24px 24px 0 0;
  background: var(--reef-card);
  box-shadow: 0 -10px 30px rgba(30, 27, 46, 0.18), inset 0 2px 4px #ffffff;
  transform: translate(-50%, 105%);
  transition: transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
  visibility: hidden;
}
.cabin-sheet.open { transform: translate(-50%, 0); visibility: visible; }
.sheet-grab {
  width: 40px; height: 5px;
  margin: 0 auto 10px;
  border-radius: 999px;
  background: #fed7aa;
}
.sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.sheet-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 900;
  color: var(--reef-ink);
}
.sheet-close {
  width: 30px; height: 30px;
  border: none;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: #ffffff;
  color: var(--reef-muted);
  font-size: 14px;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06), inset -1px -1px 2px rgba(251, 146, 60, 0.15);
}

/* Isi sheet: dokumen kru */
.doc-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.doc-cell {
  min-width: 0;
  padding: 8px 10px;
  border-radius: 12px;
  background: #ffffff;
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(251, 146, 60, 0.12);
}
.doc-cell--wide { grid-column: span 2; }
.doc-k {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 8.5px;
  font-weight: 900;
  color: var(--reef-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.doc-k i { color: #b45309; font-size: 11px; }
.doc-v {
  margin-top: 2px;
  font-size: 12px;
  font-weight: 900;
  color: var(--reef-ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Isi sheet: form kata sandi */
.input-lbl {
  display: block;
  margin: 4px 0 4px 2px;
  font-size: 10px;
  font-weight: 900;
  color: var(--reef-muted);
}
.prof-input-ctrl {
  width: 100%;
  height: 42px;
  padding: 0 12px;
  margin-bottom: 8px;
  border: 2px solid #ffffff;
  border-radius: 12px;
  background: #fff7ed;
  font-family: inherit;
  font-size: 12px;
  font-weight: 800;
  color: var(--reef-ink);
  outline: none;
  box-shadow: inset 2px 2px 5px rgba(251, 146, 60, 0.18), inset -2px -2px 4px #ffffff;
}
.prof-input-ctrl:focus { box-shadow: inset 2px 2px 5px rgba(251, 146, 60, 0.18), 0 0 0 3px rgba(251, 113, 133, 0.25); }
.prof-submit-btn {
  width: 100%;
  height: 42px;
  margin-top: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: none;
  border-radius: 12px;
  background: linear-gradient(145deg, #34d399, #059669);
  color: #ffffff;
  font-family: inherit;
  font-size: 12px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 5px 12px rgba(5, 150, 105, 0.3), inset 2px 2px 3px rgba(255, 255, 255, 0.45), inset -2px -2px 4px rgba(6, 95, 70, 0.3);
}
.prof-submit-btn:active { transform: scale(0.98); }
.cabin-sheet .cabin-flash { margin-bottom: 10px; }

/* Isi sheet: bantuan */
.help-list { display: flex; flex-direction: column; gap: 8px; }
.help-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 14px;
  background: #ffffff;
  text-decoration: none;
  box-shadow: inset 2px 2px 4px #ffffff, inset -2px -2px 4px rgba(251, 146, 60, 0.12);
}
.help-row:active { transform: scale(0.98); }
.ch-btn {
  width: 36px; height: 36px;
  flex-shrink: 0;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #ffffff;
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.12), inset 1.5px 1.5px 3px rgba(255, 255, 255, 0.45), inset -2px -2px 4px rgba(0, 0, 0, 0.15);
}
.ch-btn--wa   { background: linear-gradient(145deg, #4ade80, #16a34a); }
.ch-btn--tele { background: linear-gradient(145deg, #67e8f9, #0891b2); }
.ch-btn--ig   { background: linear-gradient(145deg, #fb7185, #c026d3); }
.ch-btn--fb   { background: linear-gradient(145deg, #93c5fd, #2563eb); }
.ch-btn--cs   { background: linear-gradient(145deg, #c084fc, #8b5cf6); }
.help-name { flex: 1; font-size: 12px; font-weight: 900; color: var(--reef-ink); }
.help-row > i { color: var(--reef-muted); }
.ch-empty { font-size: 11px; font-weight: 700; color: var(--reef-muted); text-align: center; padding: 10px 0; }

@media (max-width: 360px) {
  .cabin-porthole { width: 70px; height: 70px; }
  .cabin-id { padding-right: 72px; }
  .cabin-name { font-size: 14.5px; }
  .cargo-rail { grid-auto-columns: 82%; }
  .pm-ring { width: 44px; height: 44px; }
  .pm-glass { font-size: 17px; }
}
@media (prefers-reduced-motion: reduce) {
  .cabin-lantern, .porthole-fish, .porthole-bubble { animation: none !important; }
  .cabin-sheet, .sheet-backdrop { transition: none; }
}
</style>

<div class="cabin-stage">
  <!-- ── 1. DINDING KABIN: IDENTITAS KAPTEN + JENDELA LAUT ── -->
  <div class="cabin-hero">
    <!-- Lentera gantung -->
    <svg class="cabin-lantern" viewBox="0 0 22 40" fill="none" aria-hidden="true">
      <line x1="11" y1="0" x2="11" y2="12" stroke="#92400e" stroke-width="1.5"/>
      <circle cx="11" cy="27" r="11" fill="#fef08a" opacity="0.45"/>
      <rect x="6" y="12" width="10" height="3" rx="1.5" fill="#b45309"/>
      <rect x="5" y="15" width="12" height="15" rx="4" fill="#fde68a" stroke="#b45309" stroke-width="1.5"/>
      <rect x="8.5" y="19" width="5" height="7" rx="2.5" fill="#f59e0b"/>
      <rect x="6" y="30" width="10" height="3" rx="1.5" fill="#b45309"/>
    </svg>

    <!-- Porthole: jendela bulat ke laut karang -->
    <svg class="cabin-porthole" viewBox="0 0 90 90" fill="none" aria-hidden="true">
      <defs>
        <linearGradient id="phBrass" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="#fef3c7"/>
          <stop offset="45%" stop-color="#fbbf24"/>
          <stop offset="100%" stop-color="#b45309"/>
        </linearGradient>
        <linearGradient id="phSea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#a5f3fc"/>
          <stop offset="60%" stop-color="#22d3ee"/>
          <stop offset="100%" stop-color="#0891b2"/>
        </linearGradient>
        <clipPath id="phGlass"><circle cx="45" cy="45" r="31"/></clipPath>
      </defs>
      <circle cx="45" cy="45" r="43" fill="url(#phBrass)"/>
      <circle cx="45" cy="45" r="35" fill="#92400e" opacity="0.35"/>
      <g fill="#92400e" opacity="0.55">
        <circle cx="45" cy="6" r="2"/><circle cx="45" cy="84" r="2"/><circle cx="6" cy="45" r="2"/><circle cx="84" cy="45" r="2"/>
        <circle cx="17.5" cy="17.5" r="2"/><circle cx="72.5" cy="17.5" r="2"/><circle cx="17.5" cy="72.5" r="2"/><circle cx="72.5" cy="72.5" r="2"/>
      </g>
      <g clip-path="url(#phGlass)">
        <rect x="0" y="0" width="90" height="90" fill="url(#phSea)"/>
        <path d="M28 10 L36 10 L24 80 L16 80 Z" fill="#ffffff" opacity="0.2"/>
        <!-- dasar karang -->
        <path d="M10 72 C25 64, 45 70, 60 66 C70 63, 80 66, 85 68 V90 H10 Z" fill="#fdba74"/>
        <g stroke="#f43f5e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
          <path d="M58 72 V60 L52 52 M58 63 L64 54"/>
        </g>
        <g fill="#fda4af"><circle cx="52" cy="52" r="2"/><circle cx="64" cy="54" r="2"/></g>
        <g stroke="#c084fc" stroke-width="2.4" stroke-linecap="round">
          <path d="M30 70 C29 64, 26 61, 25 57"/><path d="M33 70 C33 63, 33 60, 34 56"/><path d="M36 70 C38 64, 40 62, 42 58"/>
        </g>
        <path d="M74 72 C70 64, 77 60, 72 52" stroke="#10b981" stroke-width="2.6" stroke-linecap="round"/>
        <!-- ikan lewat -->
        <g class="porthole-fish">
          <g transform="translate(0 34)">
            <path d="M-9 0 L-14 -4 L-14 4 Z" fill="#ea580c"/>
            <ellipse cx="0" cy="0" rx="9" ry="5.5" fill="#fb923c"/>
            <path d="M-3 -5.2 C-2 -2, -2 2, -3 5.2 L-0.5 5.4 C0 2, 0 -2, -0.5 -5.4 Z" fill="#ffffff"/>
            <path d="M3.5 -4.6 C4.4 -2, 4.4 2, 3.5 4.6 L5.4 4 C6 2, 6 -2, 5.4 -4 Z" fill="#ffffff"/>
            <circle cx="6.6" cy="-1.2" r="1.3" fill="#ffffff"/><circle cx="7" cy="-1.2" r="0.7" fill="#1e1b2e"/>
          </g>
        </g>
        <g class="porthole-fish porthole-fish--b">
          <g transform="translate(0 52) scale(0.75)">
            <path d="M-8 0 L-13 -4 L-13 4 Z" fill="#eab308"/>
            <ellipse cx="0" cy="0" rx="8" ry="6.5" fill="#fde047"/>
            <circle cx="4.6" cy="-1.4" r="1.3" fill="#ffffff"/><circle cx="5" cy="-1.4" r="0.7" fill="#1e1b2e"/>
          </g>
        </g>
        <g fill="#ffffff" opacity="0.85">
          <circle class="porthole-bubble" cx="40" cy="60" r="1.8"/>
          <circle class="porthole-bubble" cx="46" cy="64" r="1.2"/>
          <circle class="porthole-bubble" cx="66" cy="58" r="1.5"/>
        </g>
      </g>
      <circle cx="45" cy="45" r="31" stroke="#fef3c7" stroke-width="2.5"/>
      <path d="M26 30 A24 24 0 0 1 44 20" stroke="#ffffff" stroke-width="3" stroke-linecap="round" opacity="0.75"/>
    </svg>

    <div class="cabin-id">
      <div class="cabin-avatar">
        <span><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
        <span class="cabin-online"></span>
      </div>
      <div class="cabin-who">
        <div class="cabin-eyebrow">Kabin Kapten</div>
        <div class="cabin-name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="cabin-email"><?= htmlspecialchars($user['email']) ?></div>
        <div class="cabin-pills">
          <span class="cabin-pill cabin-pill--rank <?= $is_premium ? 'is-vip' : '' ?>">
            <i class="ph-fill ph-crown-simple"></i> <?= htmlspecialchars($membership_name) ?>
          </span>
          <span class="cabin-pill cabin-pill--days" title="Bergabung <?= $member_since ?>">
            <i class="ph-fill ph-anchor"></i> <?= number_format($days_member) ?> hari berlayar
          </span>
        </div>
      </div>
    </div>

    <!-- Tiket kru: kode referral -->
    <div class="cabin-ticket">
      <div class="ticket-left">
        <span class="ticket-ico"><i class="ph-fill ph-ticket"></i></span>
        <div>
          <div class="ticket-lbl">TIKET KRU · KODE REFERRAL</div>
          <div class="ticket-code" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></div>
        </div>
      </div>
      <button class="ticket-copy" onclick="copyRef()" type="button">
        <i class="ph-bold ph-copy"></i> Salin
      </button>
    </div>
  </div>

  <!-- ── 2. RAK KARGO: saldo & aktivitas (geser) ── -->
  <div class="cabin-section-head">
    <div class="csh-title"><i class="ph-fill ph-package"></i> Rak Kargo</div>
    <span class="csh-hint">Geser <i class="ph-bold ph-arrows-left-right"></i></span>
  </div>
  <div class="cargo-rail">
    <div class="cargo-card cargo--wd">
      <div>
        <div class="cargo-top">
          <span class="cargo-lbl">Saldo Tarik</span>
          <span class="cargo-ico"><i class="ph-fill ph-coins"></i></span>
        </div>
        <div class="cargo-val"><?= format_rp((float)$user['balance_wd']) ?></div>
        <div class="cargo-meta"><?= number_format($total_withdrawals) ?>x penarikan berhasil</div>
      </div>
      <div class="cargo-foot">
        <a href="/withdraw" class="cargo-act">Tarik <i class="ph-bold ph-arrow-up-right"></i></a>
      </div>
    </div>

    <div class="cargo-card cargo--dep">
      <div>
        <div class="cargo-top">
          <span class="cargo-lbl">Saldo Beli</span>
          <span class="cargo-ico"><i class="ph-fill ph-wallet"></i></span>
        </div>
        <div class="cargo-val"><?= format_rp((float)$user['balance_dep']) ?></div>
        <div class="cargo-meta"><?= number_format($total_deposits) ?>x top up disetujui</div>
      </div>
      <div class="cargo-foot">
        <a href="/deposit" class="cargo-act">Top Up <i class="ph-bold ph-plus"></i></a>
      </div>
    </div>

    <div class="cargo-card cargo--earn">
      <div>
        <div class="cargo-top">
          <span class="cargo-lbl">Total Pendapatan</span>
          <span class="cargo-ico"><i class="ph-fill ph-trophy"></i></span>
        </div>
        <div class="cargo-val"><?= format_rp((float)$user['total_earned']) ?></div>
        <div class="cargo-meta">Hasil panen &amp; komisi sejak <?= $member_since ?></div>
      </div>
      <div class="cargo-foot">
        <a href="/history" class="cargo-act">Riwayat <i class="ph-bold ph-receipt"></i></a>
      </div>
    </div>

    <div class="cargo-card cargo--log">
      <div class="cargo-top">
        <span class="cargo-lbl">Catatan Pelayaran</span>
        <span class="cargo-ico"><i class="ph-fill ph-book-bookmark"></i></span>
      </div>
      <div class="cargo-mini">
        <div><b><?= number_format($total_watches) ?></b><span>Video</span></div>
        <div><b><?= number_format($refs) ?></b><span>Kru</span></div>
        <div><b><?= number_format($active_investments) ?></b><span>Invest</span></div>
      </div>
    </div>
  </div>

  <!-- ── 3. JENDELA MENU (porthole bulat) ── -->
  <div class="cabin-section-head">
    <div class="csh-title"><i class="ph-fill ph-steering-wheel"></i> Ruang Kemudi</div>
  </div>
  <div class="porthole-menu">
    <div class="pm-grid">
      <a href="/videos" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--violet"><i class="ph-fill ph-film-strip"></i></span></span>
        <span class="pm-lbl">Tonton<br>Video</span>
      </a>
      <a href="/minigame" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--coral"><i class="ph-fill ph-fish-simple"></i></span></span>
        <span class="pm-lbl">Mancing<br>Cuan</span>
      </a>
      <a href="/missions" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--green"><i class="ph-fill ph-target"></i></span></span>
        <span class="pm-lbl">Pusat<br>Misi</span>
      </a>
      <a href="/upgrade" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--gold"><i class="ph-fill ph-crown-simple"></i></span></span>
        <span class="pm-lbl">Upgrade<br>Member</span>
      </a>
      <a href="/history" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--lagoon"><i class="ph-fill ph-chart-line-up"></i></span></span>
        <span class="pm-lbl">Riwayat<br>Keuangan</span>
      </a>
      <a href="/edit-rekening" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--green"><i class="ph-fill ph-credit-card"></i></span></span>
        <span class="pm-lbl">Rekening<br>Bank</span>
      </a>
      <a href="/referral" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--coral"><i class="ph-fill ph-users-three"></i></span></span>
        <span class="pm-lbl">Ajak<br>Kru</span>
      </a>
      <a href="/panduan" class="pm-item">
        <span class="pm-ring"><span class="pm-glass pm--violet"><i class="ph-fill ph-book-open"></i></span></span>
        <span class="pm-lbl">Buku<br>Panduan</span>
      </a>
    </div>
  </div>

  <!-- ── 4. LACI KABIN (pengaturan via bottom sheet) ── -->
  <div class="cabin-section-head">
    <div class="csh-title"><i class="ph-fill ph-archive-box"></i> Laci Kabin</div>
  </div>
  <div class="drawer-row">
    <button type="button" class="drawer-btn" onclick="openCabinSheet('doc')">
      <span class="dr-ico dr-ico--doc"><i class="ph-fill ph-identification-card"></i></span>
      <span><strong>Dokumen Kru</strong><small>Data akun &amp; bank</small></span>
    </button>
    <button type="button" class="drawer-btn" onclick="openCabinSheet('pass')">
      <span class="dr-ico dr-ico--lock"><i class="ph-fill ph-lock-key"></i></span>
      <span><strong>Kunci Kabin</strong><small>Ganti kata sandi</small></span>
    </button>
    <button type="button" class="drawer-btn" onclick="openCabinSheet('help')">
      <span class="dr-ico dr-ico--help"><i class="ph-fill ph-lifebuoy"></i></span>
      <span><strong>Bantuan</strong><small>Kontak &amp; komunitas</small></span>
    </button>
  </div>

  <a href="/logout" class="cabin-exit">
    <i class="ph-bold ph-sign-out"></i> Turun dari Kapal
  </a>
</div>

<!-- ══════════ BOTTOM SHEETS ══════════ -->
<div class="sheet-backdrop" id="cabin-sheet-backdrop" onclick="closeCabinSheet()"></div>

<!-- Sheet: Dokumen Kru -->
<div class="cabin-sheet" id="sheet-doc" role="dialog" aria-modal="true" aria-labelledby="sheet-doc-title">
  <div class="sheet-grab"></div>
  <div class="sheet-head">
    <div class="sheet-title" id="sheet-doc-title"><span class="dr-ico dr-ico--doc"><i class="ph-fill ph-identification-card"></i></span> Dokumen Kru</div>
    <button type="button" class="sheet-close" onclick="closeCabinSheet()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="doc-grid">
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-user"></i> Username</div>
      <div class="doc-v"><?= htmlspecialchars($user['username']) ?></div>
    </div>
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-phone"></i> WhatsApp</div>
      <div class="doc-v"><?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?: '-' ?></div>
    </div>
    <div class="doc-cell doc-cell--wide">
      <div class="doc-k"><i class="ph-bold ph-envelope"></i> Email</div>
      <div class="doc-v"><?= htmlspecialchars($user['email']) ?></div>
    </div>
    <div class="doc-cell doc-cell--wide">
      <div class="doc-k"><i class="ph-bold ph-bank"></i> Bank Tujuan</div>
      <div class="doc-v"><?= $user['bank_name'] ? htmlspecialchars($user['bank_name'] . ' - ' . mask_account($user['account_number'] ?? '')) : 'Belum Didaftarkan' ?></div>
    </div>
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-crown"></i> Membership</div>
      <div class="doc-v"><?= htmlspecialchars($membership_name) ?></div>
    </div>
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-calendar-blank"></i> Bergabung</div>
      <div class="doc-v"><?= $member_since ?></div>
    </div>
  </div>
</div>

<!-- Sheet: Kunci Kabin (ganti kata sandi) -->
<div class="cabin-sheet" id="sheet-pass" role="dialog" aria-modal="true" aria-labelledby="sheet-pass-title">
  <div class="sheet-grab"></div>
  <div class="sheet-head">
    <div class="sheet-title" id="sheet-pass-title"><span class="dr-ico dr-ico--lock"><i class="ph-fill ph-lock-key"></i></span> Kunci Kabin</div>
    <button type="button" class="sheet-close" onclick="closeCabinSheet()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <?php if ($flash): ?>
  <div class="cabin-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>
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

<!-- Sheet: Bantuan -->
<div class="cabin-sheet" id="sheet-help" role="dialog" aria-modal="true" aria-labelledby="sheet-help-title">
  <div class="sheet-grab"></div>
  <div class="sheet-head">
    <div class="sheet-title" id="sheet-help-title"><span class="dr-ico dr-ico--help"><i class="ph-fill ph-lifebuoy"></i></span> Pusat Bantuan</div>
    <button type="button" class="sheet-close" onclick="closeCabinSheet()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="help-list">
    <?php if (empty($_contact_btns)): ?>
      <div class="ch-empty">Belum ada kontak bantuan.</div>
    <?php endif; ?>
    <?php foreach ($_contact_btns as $cb): ?>
      <?php
        $t = strtolower((string)$cb['icon_value']);
        $svg = $_psvg[$t] ?? $_psvg['cs'];
        $cls = isset($_psvg[$t]) ? $t : 'cs';
      ?>
      <a href="<?= htmlspecialchars($cb['url']) ?>" class="help-row" target="_blank" rel="noopener">
        <span class="ch-btn ch-btn--<?= $cls ?>"><?= $svg ?></span>
        <span class="help-name"><?= htmlspecialchars($cb['label'] ?? 'Hubungi Kami') ?></span>
        <i class="ph-bold ph-arrow-up-right"></i>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<script>
let __cabinSheetOpen = null;
function openCabinSheet(name) {
  const sheet = document.getElementById('sheet-' + name);
  const backdrop = document.getElementById('cabin-sheet-backdrop');
  if (!sheet || !backdrop) return;
  if (__cabinSheetOpen) __cabinSheetOpen.classList.remove('open');
  sheet.classList.add('open');
  backdrop.classList.add('open');
  __cabinSheetOpen = sheet;
}

function closeCabinSheet() {
  const backdrop = document.getElementById('cabin-sheet-backdrop');
  if (__cabinSheetOpen) __cabinSheetOpen.classList.remove('open');
  if (backdrop) backdrop.classList.remove('open');
  __cabinSheetOpen = null;
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeCabinSheet();
});

<?php if ($flash || $active_section === 'password'): ?>
document.addEventListener('DOMContentLoaded', () => openCabinSheet('pass'));
<?php endif; ?>

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
